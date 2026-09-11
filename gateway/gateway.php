<?php
// gateway/gateway.php — ریلی WelfVita Gateway در PHP (Workerman، یک پورت واحد)
// معماری: Worker با protocol http؛ WebSocket handshake دستی و بعد از آن protocol
// اتصال به Websocket تغییر می‌کند تا فریم‌ها decode شوند (دقیقاً یک پورت مثل پایتون).
//
// مسیرها (یک‌به‌یک با نسخه پایتون):
//   WS      : /ws/{uuid}  → VLESS   |  /trojan-ws → Trojan  |  /ss-ws → Shadowsocks
//   XHTTP   : GET  /xhttp-siz10/{mode}/{uuid}/{session_id}           (دانلینک VLESS)
//           : POST /xhttp-siz10/stream-up/{uuid}/{session_id}        (آپلینک پیوسته)
//           : POST /xhttp-siz10/packet-up/{uuid}/{session_id}/{seq}  (آپلینک پکت)
//           : همان‌ها با پیشوند /txhttp-siz10 برای Trojan
//
// اجرا:  GATEWAY_PORT=8000 php gateway.php start

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require __DIR__ . '/workerman/Autoloader.php';
require __DIR__ . '/state_bridge.php';
require __DIR__ . '/proto.php';

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Protocols\Http\Chunk;
use Workerman\Lib\Timer;

const TCP_CONNECT_TIMEOUT = 10.0;
const SESSION_IDLE = 30;
const SESSION_IDLE_ACTIVE = 90;
const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

Worker::$logFile = data_dir() . '/gateway.log';
Worker::$stdoutFile = data_dir() . '/gateway-stdout.log';
Worker::$pidFile = data_dir() . '/gateway.pid';

function gwlog(string $msg): void
{
    echo '[' . date('m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

$bridge = new StateBridge(3);
$GLOBALS['bridge'] = $bridge;
$GLOBALS['xhttpSessions'] = [];

function &sessions(): array
{
    return $GLOBALS['xhttpSessions'];
}

// ═════════ WebSocket handshake (روی http worker، یک پورت مشترک) ══════════════

function ws_try_handshake(TcpConnection $conn, Request $request): bool
{
    $upgrade = strtolower((string) $request->header('upgrade', ''));
    $key = (string) $request->header('sec-websocket-key', '');
    if ($upgrade !== 'websocket' || $key === '') {
        return false;
    }
    $accept = base64_encode(sha1($key . WS_GUID, true));
    // raw=true تا Http::encode آن را در 200 نپیچد
    $conn->send(
        "HTTP/1.1 101 Switching Protocols\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Accept: $accept\r\n\r\n",
        true
    );
    // از این‌جا اتصال WebSocket است؛ protocol داخلی Workerman فریم‌ها را decode
    // می‌کند و onMessage با payload خام (string) صدا زده می‌شود.
    $conn->protocol = '\Workerman\Protocols\Websocket';
    $conn->context->websocketHandshake = true;
    $conn->websocketType = "\x82"; // فریم باینری برای پاسخ‌ها
    $conn->context->wsPath = $request->path();
    return true;
}

// ═════════ upstream TCP ══════════════════════════════════════════════════════

function open_tcp(string $address, int $port, callable $onOpen, callable $onFail, callable $onData, callable $onClose): ?Workerman\Connection\AsyncTcpConnection
{
    $ctx = ['socket' => ['tcp_nodelay' => 1, 'so_sndbuf' => 4194304, 'so_rcvbuf' => 4194304]];
    try {
        $tcp = new Workerman\Connection\AsyncTcpConnection("tcp://$address:$port", $ctx);
    } catch (Throwable $e) {
        gwlog('open_tcp failed: ' . $e->getMessage());
        return null;
    }
    $tcp->onConnect = $onOpen;
    $tcp->onError = $onFail;
    $tcp->onMessage = $onData;
    $tcp->onClose = $onClose;
    $tcp->connect();
    return $tcp;
}

// ═════════ XHTTP sessions (معادل xhttp_core.py) ══════════════════════════════

function &xhttp_get_or_create(string $sessionId, string $uuid, string $mode, bool $isTrojan, string $ip): array
{
    $bridge = $GLOBALS['bridge'];
    $sessions = &sessions();
    if (isset($sessions[$sessionId])) {
        $sessions[$sessionId]['lastSeen'] = time();
        return $sessions[$sessionId];
    }
    $bridge->trackConn($uuid, $ip, "xhttp-$mode");
    gwlog("new XHTTP[$mode] [" . substr($sessionId, 0, 8) . "] uuid=" . substr($uuid, 0, 8) . " ip=$ip");
    $sessions[$sessionId] = [
        'uuid' => $uuid, 'mode' => $mode, 'isTrojan' => $isTrojan,
        'tcp' => null, 'downClients' => new SplObjectStorage(),
        'lastSeen' => time(), 'closed' => false,
        'seqBuf' => [], 'nextSeq' => 0,
        'quotaPending' => 0, 'quotaLast' => microtime(true), 'quotaOk' => true,
        'firstDown' => true, 'downQ' => [],
    ];
    return $sessions[$sessionId];
}

function xhttp_teardown(string $sessionId, string $reason = ''): void
{
    $sessions = &sessions();
    $s = $sessions[$sessionId] ?? null;
    if (!$s) return;
    $s['closed'] = true;
    unset($sessions[$sessionId]);
    if ($s['tcp']) {
        try { $s['tcp']->close(); } catch (Throwable) {}
    }
    foreach ($s['downClients'] as $c) {
        try { $c->close(); } catch (Throwable) {}
    }
    gwlog("closed XHTTP[{$s['mode']}] [" . substr($sessionId, 0, 8) . "] total=" . count($sessions) . ($reason ? " reason=$reason" : ''));
}

function xhttp_quota_add(array &$s, int $bytes): bool
{
    if (!$s['quotaOk']) return false;
    $s['quotaPending'] += $bytes;
    $now = microtime(true);
    if ($s['quotaPending'] >= 262144 || $now - $s['quotaLast'] >= 0.25) {
        $flush = $s['quotaPending'];
        $s['quotaPending'] = 0;
        $s['quotaLast'] = $now;
        if (!$GLOBALS['bridge']->consume($s['uuid'], $flush)) {
            $s['quotaOk'] = false;
            return false;
        }
    }
    return true;
}

function xhttp_quota_flush(array &$s): void
{
    if ($s['quotaPending'] > 0 && $s['quotaOk']) {
        if (!$GLOBALS['bridge']->consume($s['uuid'], $s['quotaPending'])) {
            $s['quotaOk'] = false;
        }
        $s['quotaPending'] = 0;
    }
}

/** دانلینک → همه‌ی GET ها یا صف؛ prefix \x00\x00 فقط برای اولین چانک VLESS */
function xhttp_push_down(array &$s, string $data): void
{
    if (!$s['isTrojan'] && $s['firstDown']) {
        $data = "\x00\x00" . $data;
        $s['firstDown'] = false;
    }
    if ($s['downClients']->count() > 0) {
        foreach ($s['downClients'] as $c) {
            try { $c->send($data, true); } catch (Throwable) {}
        }
    } elseif (count($s['downQ']) < 512) {
        $s['downQ'][] = $data;
    }
}

function xhttp_open_tcp(string $sessionId, array &$s, string $firstChunk): void
{
    $bridge = $GLOBALS['bridge'];
    try {
        if ($s['isTrojan']) {
            [$pwHash, $cmd, $address, $port, $payload] = parse_trojan_header($firstChunk);
            if ($bridge->findByTrojanHash($pwHash) === null) throw new RuntimeException('trojan auth failed');
        } else {
            [$cmd, $address, $port, $payload] = parse_vless_header($firstChunk);
        }
    } catch (Throwable $e) {
        gwlog("XHTTP[$sessionId] header FAILED: " . $e->getMessage());
        xhttp_teardown($sessionId, 'bad-header');
        return;
    }
    gwlog("connect XHTTP[{$s['mode']}] [$sessionId] -> $address:$port");

    $tcp = open_tcp(
        $address, $port,
        function ($tcp) use ($sessionId, $payload) {
            $sessions = &sessions();
            if (!isset($sessions[$sessionId])) { $tcp->close(); return; }
            $s = &$sessions[$sessionId];
            $s['tcp'] = $tcp;
            if ($payload !== '') $tcp->send($payload);
            foreach ($s['downQ'] as $i => $chunk) {
                foreach ($s['downClients'] as $c) {
                    try { $c->send($chunk, true); } catch (Throwable) {}
                }
                unset($s['downQ'][$i]);
            }
            $s['downQ'] = array_values($s['downQ']);
            // packet-up: فلاش پکت‌های بافرشده به ترتیب seq (ریس بین seq=0 و open_tcp)
            ksort($s['seqBuf']);
            while (isset($s['seqBuf'][$s['nextSeq']])) {
                $tcp->send($s['seqBuf'][$s['nextSeq']]);
                unset($s['seqBuf'][$s['nextSeq']]);
                $s['nextSeq']++;
            }
        },
        function ($tcp, $code, $msg) use ($sessionId) {
            gwlog("XHTTP[$sessionId] connect failed: $msg");
            xhttp_teardown($sessionId, 'connect-failed');
        },
        function ($tcp, string $data) use ($sessionId) {
            $sessions = &sessions();
            if (!isset($sessions[$sessionId])) { $tcp->close(); return; }
            $s = &$sessions[$sessionId];
            if ($s['closed']) return;
            if (!xhttp_quota_add($s, strlen($data))) {
                xhttp_teardown($sessionId, 'quota-exceeded');
                return;
            }
            xhttp_push_down($s, $data);
        },
        function () use ($sessionId) {
            xhttp_teardown($sessionId, 'remote-eof');
        }
    );
    if ($tcp === null) {
        xhttp_teardown($sessionId, 'open-failed');
    }
}

// ═════════ WebSocket relay logic ═════════════════════════════════════════════

function ws_setup_relay(TcpConnection $conn, string $uuid, string $transport, bool $isSs, bool $vlessPrefix, string $address, int $port, string $initialPayload, ?array $ssState): void
{
    $bridge = $GLOBALS['bridge'];
    gwlog("WS $transport [" . substr($uuid, 0, 8) . "] -> $address:$port");

    $tcp = open_tcp(
        $address, $port,
        function ($tcp) use ($conn, $initialPayload) {
            if (!empty($conn->context->wsClosed)) { $tcp->close(); return; }
            $conn->context->upstream = $tcp;
            if ($initialPayload !== '') $tcp->send($initialPayload);
        },
        function ($tcp, $code, $msg) use ($conn) {
            gwlog('WS upstream fail: ' . $msg);
            try { $conn->close(); } catch (Throwable) {}
        },
        function ($tcp, string $d) use ($conn, $bridge, $uuid, $vlessPrefix, $isSs) {
            if (!empty($conn->context->wsClosed)) return;
            $bridge->consume($uuid, strlen($d));
            if ($isSs) {
                $out = '';
                if (empty($conn->context->ssSent)) {
                    $out .= $conn->context->ssSalt;
                    $conn->context->ssSent = true;
                }
                $ctr = $conn->context->ssCtr ?? 0;
                $out .= ss_encrypt_chunk($conn->context->ssCipher, $conn->context->ssKey, $d, $ctr);
                $conn->context->ssCtr = $ctr;
                $conn->send($out);
            } else {
                if ($vlessPrefix && empty($conn->context->prefixSent)) {
                    $d = "\x00\x00" . $d;
                    $conn->context->prefixSent = true;
                }
                $conn->send($d);
            }
        },
        function () use ($conn) {
            try { $conn->close(); } catch (Throwable) {}
        }
    );
    if ($tcp === null) {
        try { $conn->close(); } catch (Throwable) {}
        return;
    }
    $conn->context->handshook = true;
    $conn->context->uuid = $uuid;
    $conn->context->vlessPrefix = $vlessPrefix;
    $conn->context->isSs = $isSs;
    if ($isSs && $ssState) {
        $conn->context->ssKey = $ssState['rkey'];
        $conn->context->ssSalt = $ssState['rsalt'];
        $conn->context->ssCtr = 0;
        $conn->context->ssCipher = $ssState['cipher'];
        $conn->context->ssDec = $ssState['dec'];
    }
}

/** انکود فریم باینری WebSocket سرور→کلاینت (بدون mask) */
function ws_frame(string $payload): string
{
    $len = strlen($payload);
    $head = "\x82"; // FIN + binary
    if ($len < 126) {
        $head .= chr($len);
    } elseif ($len < 65536) {
        $head .= chr(126) . pack('n', $len);
    } else {
        $head .= chr(127) . pack('J', $len);
    }
    return $head . $payload;
}

/** هر فریم WS کلاینت: اولین = هدر پروتکل، بقیه = آپلینک */
function ws_handle_frame(TcpConnection $conn, string $payload): void
{
    $bridge = $GLOBALS['bridge'];
    $ctx = $conn->context;

    if (empty($ctx->handshook)) {
        $firstChunk = ($ctx->firstChunk ?? '') . $payload;
        $ctx->firstChunk = '';
        $path = $ctx->wsPath ?? '';
        try {
            if (preg_match('#^/ws/([0-9a-fA-F-]{36})$#', $path)) {
                $uuid = substr($path, 4);
                $link = $bridge->getLink($uuid);
                if (!$link) { $conn->close(); return; }
                [$cmd, $address, $port, $initPayload] = parse_vless_header($firstChunk);
                if (!$bridge->consume($uuid, strlen($firstChunk))) { $conn->close(); return; }
                ws_setup_relay($conn, $uuid, 'vless-ws', false, true, $address, $port, $initPayload, null);
                return;
            }
            if ($path === '/trojan-ws') {
                [$pwHash, $cmd, $address, $port, $initPayload] = parse_trojan_header($firstChunk);
                $uuid = $bridge->findByTrojanHash($pwHash);
                if ($uuid === null || !$bridge->getLink($uuid)) { $conn->close(); return; }
                if (!$bridge->consume($uuid, strlen($firstChunk))) { $conn->close(); return; }
                ws_setup_relay($conn, $uuid, 'trojan-ws', false, false, $address, $port, $initPayload, null);
                return;
            }
            if ($path === '/ss-ws') {
                $resolved = null; $dec = null; $chunks = [];
                foreach ($bridge->ssCandidates() as $suid) {
                    $link = $bridge->getLink($suid);
                    if (!$link) continue;
                    $master = ss_derive_key((string) $link['ss_password']);
                    $dec = new SsDecryptor((string) ($link['ss_cipher'] ?? 'chacha20-ietf-poly1305'), $master);
                    $dec->feed($firstChunk);
                    try {
                        $chunks = $dec->tryDecrypt();
                        if ($chunks) { $resolved = $suid; break; }
                    } catch (Throwable) { continue; }
                }
                if ($resolved === null) { $conn->close(); return; }
                $uuid = $resolved;
                [$address, $port, $hlen] = parse_socks5_addr($chunks[0]);
                $initPayload = substr($chunks[0], $hlen) . implode('', array_slice($chunks, 1));
                if (!$bridge->consume($uuid, strlen($firstChunk))) { $conn->close(); return; }
                $link = $bridge->getLink($uuid);
                $master = ss_derive_key((string) $link['ss_password']);
                $rsalt = random_bytes(SS_SALT_LEN);
                $ssState = [
                    'rkey' => ss_subkey($master, $rsalt),
                    'rsalt' => $rsalt,
                    'cipher' => (string) ($link['ss_cipher'] ?? 'chacha20-ietf-poly1305'),
                    'dec' => $dec,
                ];
                ws_setup_relay($conn, $uuid, 'shadowsocks-ws', true, false, $address, $port, $initPayload, $ssState);
                return;
            }
            $conn->close();
        } catch (Throwable $e) {
            gwlog('WS handshake error: ' . $e->getMessage());
            try { $conn->close(); } catch (Throwable) {}
        }
        return;
    }

    // آپلینک کلاینت → مقصد
    $up = $ctx->upstream ?? null;
    if (!$up) { $conn->close(); return; }
    if (!$bridge->consume($ctx->uuid, strlen($payload))) { $conn->close(); return; }
    if ($ctx->isSs) {
        $dec = $ctx->ssDec;
        $dec->feed($payload);
        try {
            foreach ($dec->tryDecrypt() as $chunk) {
                $up->send($chunk);
            }
        } catch (Throwable) {
            try { $conn->close(); } catch (Throwable) {}
        }
    } else {
        $up->send($payload);
    }
}

// ═════════ Worker اصلی ═══════════════════════════════════════════════════════

$gwPort = (int) (getenv('GATEWAY_PORT') ?: (getenv('PORT') ?: 8000));
$worker = new Worker('http://0.0.0.0:' . $gwPort);
$worker->name = 'welfvita-gateway';
$worker->count = 1;
$worker->maxSendBufferSize = 8 * 1024 * 1024;

$worker->onWorkerStart = function () {
    gwlog('WelfVita Gateway (PHP relay) v9.2 started on port ' . (getenv('GATEWAY_PORT') ?: (getenv('PORT') ?: 8000)));
    Timer::add(10, function () {
        $sessions = &sessions();
        $now = time();
        foreach ($sessions as $sid => $s) {
            $idle = $now - $s['lastSeen'];
            if ($s['tcp'] ? $idle > SESSION_IDLE_ACTIVE : $idle > SESSION_IDLE) {
                xhttp_teardown($sid, 'idle-timeout');
            }
        }
    });
};

$worker->onMessage = function (TcpConnection $conn, mixed $data) {
    // بعد از WS handshake: protocol=websocket → data = payload خام (string)
    // قبل از آن: protocol=http → data = Request object
    if (is_string($data)) {
        ws_handle_frame($conn, $data);
        return;
    }
    $request = $data;
    $method = $request->method();
    $path = $request->path();
    $bridge = $GLOBALS['bridge'];

    // ۱) WebSocket upgrade
    if ($method === 'GET' && strtolower((string) $request->header('upgrade', '')) === 'websocket') {
        if (!preg_match('#^/(ws/[0-9a-fA-F-]{36}|trojan-ws|ss-ws)$#', $path)) {
            $conn->close();
            return;
        }
        if (!ws_try_handshake($conn, $request)) {
            $conn->close();
            return;
        }
        return;
    }

    // ۲) XHTTP GET دانلینک
    if ($method === 'GET' && preg_match('#^/(tx|x)http-siz10/([a-z-]+)/([0-9a-fA-F-]{36})/([A-Za-z0-9_-]+)$#', $path, $m)) {
        $isTrojan = $m[1] === 'tx';
        $mode = $m[2]; $uuid = $m[3]; $sessionId = $m[4];
        $link = $bridge->getLink($uuid);
        if (!$link) {
            $conn->send(new Response(403, [], 'forbidden'));
            return;
        }
        $ip = (string) ($request->header('x-forwarded-for', $conn->getRemoteIp()) ?? $conn->getRemoteIp());
        $ip = trim(explode(',', $ip)[0]);
        $s = &xhttp_get_or_create($sessionId, $uuid, $mode, $isTrojan, $ip);
        if ($s['closed']) {
            $conn->send(new Response(404, [], 'session closed'));
            return;
        }
        // پاسخ استریمی خام — بدون Content-Length و بدون chunked؛ اتصال باز می‌ماند
        // و بایت‌ها همان‌طور که از مقصد می‌آیند رد می‌شوند (مثل StreamingResponse پایتون)
        $conn->send(
            "HTTP/1.1 200 OK\r\n"
            . "content-type: application/grpc\r\n"
            . "cache-control: no-cache, no-store\r\n"
            . "x-accel-buffering: no\r\n"
            . "server: cloudflare\r\n"
            . "connection: keep-alive\r\n\r\n",
            true
        );
        $s['downClients']->attach($conn);
        $conn->context->downSession = $sessionId;
        foreach ($s['downQ'] as $i => $chunk) {
            $conn->send($chunk, true);
            unset($s['downQ'][$i]);
        }
        $s['downQ'] = array_values($s['downQ']);
        return;
    }

    // ۳) XHTTP POST آپلینک
    if ($method === 'POST' && preg_match('#^/(tx|x)http-siz10/stream-up/([0-9a-fA-F-]{36})/([A-Za-z0-9_-]+)$#', $path, $m)) {
        handle_xhttp_upload($conn, $m[1] === 'tx', 'stream-up', $m[2], $m[3], -1, (string) $request->rawBody());
        return;
    }
    if ($method === 'POST' && preg_match('#^/(tx|x)http-siz10/packet-up/([0-9a-fA-F-]{36})/([A-Za-z0-9_-]+)/(\d+)$#', $path, $m)) {
        handle_xhttp_upload($conn, $m[1] === 'tx', 'packet-up', $m[2], $m[3], (int) $m[4], (string) $request->rawBody());
        return;
    }

    // ۴) بقیه مسیرها = پنل وب — پروکسی به سرور داخلی PHP (index.php)
    panel_proxy($conn, $request, $method, $path);
};

/** پروکسی درخواست‌های پنل به سرور داخلی php -S (index.php) */
function panel_proxy(TcpConnection $conn, Request $request, string $method, string $path): void
{
    $headers = [];
    foreach (($request->header() ?? []) as $k => $v) {
        $lk = strtolower($k);
        if (in_array($lk, ['host', 'connection', 'content-length', 'transfer-encoding'], true)) continue;
        if (is_array($v)) $v = implode(', ', $v);
        $headers[] = ucfirst($k) . ': ' . $v;
    }
    $body = (string) $request->rawBody();
    $headers[] = 'Content-Length: ' . strlen($body);
    $qs = $request->queryString();
    $target = $path . ($qs ? '?' . $qs : '');
    $rawRequest = "$method $target HTTP/1.1\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body;
    panel_proxy_send($conn, $rawRequest);
}

/** ارسال درخواست به سرور داخلی و برگرداندن پاسخ خام */
function panel_proxy_send(TcpConnection $conn, string $rawRequest): void
{
    $port = (int) (getenv('PANEL_INTERNAL_PORT') ?: 8081);
    $tcp = open_tcp(
        '127.0.0.1', $port,
        function ($tcp) use ($conn, $rawRequest) {
            $tcp->send($rawRequest, true);
        },
        function ($tcp, $code, $msg) use ($conn) {
            gwlog('panel proxy failed: ' . $msg);
            try { $conn->send("HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\n\r\n", true); $conn->close(); } catch (Throwable) {}
        },
        function ($tcp, string $data) use ($conn) {
            try { $conn->send($data, true); } catch (Throwable) {}
        },
        function () use ($conn) {
            try { $conn->close(); } catch (Throwable) {}
        }
    );
    if (!$tcp) {
        try { $conn->send("HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\n\r\n", true); $conn->close(); } catch (Throwable) {}
    }
}

/** آپلینک XHTTP — معادل packet-up/stream-up در xhttp_core.py */
function handle_xhttp_upload(TcpConnection $conn, bool $isTrojan, string $mode, string $uuid, string $sessionId, int $seq, string $body): void
{
    $bridge = $GLOBALS['bridge'];
    $link = $bridge->getLink($uuid);
    if (!$link) {
        $conn->send(new Response(403, [], '{"detail":"quota/disabled/unknown"}'));
        return;
    }
    $ip = (string) $conn->getRemoteIp();
    $s = &xhttp_get_or_create($sessionId, $uuid, $mode, $isTrojan, $ip);
    if ($s['closed']) {
        $conn->send(new Response(404, [], '{"detail":"session closed"}'));
        return;
    }
    $s['lastSeen'] = time();

    if ($body === '') {
        $conn->send(new Response(200, [], '{"ok":true}'));
        return;
    }
    if (!xhttp_quota_add($s, strlen($body))) {
        xhttp_teardown($sessionId, 'quota');
        $conn->send(new Response(403, [], '{"detail":"quota/disabled/unknown"}'));
        return;
    }

    if ($mode === 'packet-up') {
        if ($s['tcp'] === null) {
            if ($seq !== 0) {
                $s['seqBuf'][$seq] = $body;
                $conn->send(new Response(200, [], '{"ok":true,"buffered":true}'));
                return;
            }
            xhttp_open_tcp($sessionId, $s, $body);
            $s['nextSeq'] = 1;
            while (isset($s['seqBuf'][$s['nextSeq']]) && $s['tcp']) {
                $s['tcp']->send($s['seqBuf'][$s['nextSeq']]);
                unset($s['seqBuf'][$s['nextSeq']]);
                $s['nextSeq']++;
            }
            $conn->send(new Response(200, [], '{"ok":true,"connected":true}'));
            return;
        }
        if ($seq === $s['nextSeq']) {
            $s['tcp']->send($body);
            $s['nextSeq']++;
            while (isset($s['seqBuf'][$s['nextSeq']])) {
                $s['tcp']->send($s['seqBuf'][$s['nextSeq']]);
                unset($s['seqBuf'][$s['nextSeq']]);
                $s['nextSeq']++;
            }
        } else {
            $s['seqBuf'][$seq] = $body;
        }
        $conn->send(new Response(200, [], '{"ok":true}'));
        return;
    }

    // stream-up
    if ($s['tcp'] === null) {
        xhttp_open_tcp($sessionId, $s, $body);
    } else {
        $s['tcp']->send($body);
    }
    xhttp_quota_flush($s);
    $conn->send(new Response(200, [], '{"ok":true}'));
}

$worker->onClose = function (TcpConnection $conn) {
    $ctx = $conn->context;
    $sid = $ctx->downSession ?? null;
    if ($sid) {
        $sessions = &sessions();
        if (isset($sessions[$sid])) {
            $sessions[$sid]['downClients']->detach($conn);
        }
    }
    $up = $ctx->upstream ?? null;
    if ($up) {
        try { $up->close(); } catch (Throwable) {}
    }
};

Worker::runAll();
