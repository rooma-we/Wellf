<?php
// links.php — مدل داده‌ی لینک، تولید لینک اشتراک و منطق سهمیه
// (معادل بخش‌های LINKS / generate_share_link / is_link_allowed / client_display_label در main.py)

declare(strict_types=1);
// PANEL_LOAD_GUARD — جلوگیری از اجرای مستقیم این فایل از وب (دفاع عمیق، علاوه بر .htaccess)
if (!isset($GLOBALS['WV_PANEL'])) { http_response_code(403); exit('Forbidden'); }


require_once __DIR__ . '/state.php';
require_once __DIR__ . '/http.php';

const PROTOCOLS = [
    'vless-ws', 'xhttp-packet-up', 'xhttp-stream-up',
    'trojan-ws', 'trojan-xhttp-packet-up', 'trojan-xhttp-stream-up',
    'mtproto', 'shadowsocks', 'tunnel',
];
const DEFAULT_PROTOCOL = 'vless-ws';
const DEFAULT_CIPHER = 'chacha20-ietf-poly1305';
const SS_CIPHERS = ['chacha20-ietf-poly1305', 'aes-256-gcm'];

const LABEL_ALPHABET = 'abcdefghijklmnopqrstuvwxyz';
const LEGACY_DEFAULT_LABELS = [
    '', 'welfvita', 'welfvita subscription', 'welfvita-all', 'configuration', 'config',
    'link', 'new link', 'لینک جدید', 'کانفیگ', 'کانفیگ جدید', 'گروه جدید',
];

/** معادل generate_uuid() — UUID نسخه‌ی تصادفی */
function generate_uuid(): string
{
    $h = bin2hex(random_bytes(16));
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($h, 0, 8),
        substr($h, 8, 4),
        substr($h, 12, 4),
        substr($h, 16, 4),
        substr($h, 20, 16)
    );
}

/** معادل default_public_label() — هویت پایدار هفت‌حرفی مینوسکول از هش seed */
function default_public_label(string $seed): string
{
    $digest = hash('sha256', $seed, true);
    $out = '';
    for ($i = 0; $i < 7; $i++) {
        $out .= LABEL_ALPHABET[ord($digest[$i]) % strlen(LABEL_ALPHABET)];
    }
    return $out;
}

/** معادل clean_public_label() — حفظ نام دلخواه، حذف برندینگ قبلی و پرکردن ایمن نام خالی */
function clean_public_label(mixed $value, string $seed): string
{
    $label = trim((string) ($value ?? ''));
    $lowered = mb_strtolower($label);
    if (str_starts_with($lowered, 'welfvita-')) {
        $label = trim(substr($label, 4));
    } elseif (str_starts_with($lowered, 'welfvita ')) {
        $label = trim(substr($label, 4));
    }
    if ($label === '' || in_array(mb_strtolower($label), LEGACY_DEFAULT_LABELS, true)) {
        return default_public_label($seed);
    }
    return mb_substr($label, 0, 60);
}

function public_link_label(string $uid, ?array $link): string
{
    return clean_public_label($link['label'] ?? '', $uid);
}

/** معادل public_announcement() — فقط یادداشت‌های عمومی واقعی، هرگز مقادیر placeholder سرور */
function public_announcement(mixed $value): string
{
    $note = trim((string) ($value ?? ''));
    $lowered = mb_strtolower($note);
    if (in_array($lowered, ['', 'root', 'admin', 'administrator', 'welfvita'], true)) {
        return '';
    }
    return mb_substr($note, 0, 200);
}

/** معادل fmt_bytes() */
function fmt_bytes(int|float $b): string
{
    $b = (float) $b;
    if ($b < 1024) {
        return $b . ' B';
    }
    if ($b < 1024 ** 2) {
        return round($b / 1024, 1) . ' KB';
    }
    if ($b < 1024 ** 3) {
        return round($b / 1024 ** 2, 2) . ' MB';
    }
    return round($b / 1024 ** 3, 2) . ' GB';
}

/** معادل parse_size_to_bytes() */
function parse_size_to_bytes(float $value, string $unit): int
{
    $unit = strtoupper($unit);
    return (int) match (true) {
        $unit === 'GB' => $value * 1024 ** 3,
        $unit === 'MB' => $value * 1024 ** 2,
        $unit === 'KB' => $value * 1024,
        default => $value,
    };
}

/** معادل is_link_expired() */
function is_link_expired(?array $link): bool
{
    if (!$link) {
        return false;
    }
    $exp = $link['expires_at'] ?? null;
    if (!$exp) {
        return false;
    }
    try {
        $dt = new DateTimeImmutable((string) $exp);
        return new DateTimeImmutable('now') > $dt;
    } catch (Exception) {
        return false;
    }
}

/** معادل is_link_allowed() — فعال + منقضی‌نشده + سهمیه باقی‌مانده */
function is_link_allowed(?array $link): bool
{
    if ($link === null) {
        return false;
    }
    if (!($link['active'] ?? true)) {
        return false;
    }
    if (is_link_expired($link)) {
        return false;
    }
    $lb = (int) ($link['limit_bytes'] ?? 0);
    if ($lb > 0 && (int) ($link['used_bytes'] ?? 0) >= $lb) {
        return false;
    }
    return true;
}

/** معادل client_display_label() — عنوان کلاینت: نام | باقی‌مانده حجم | باقی‌مانده زمان */
function client_display_label(string $seed, ?array $link): string
{
    $data = $link ?? [];
    $base = clean_public_label($data['label'] ?? '', $seed);
    $limit = max((int) ($data['limit_bytes'] ?? 0), 0);
    $used = max((int) ($data['used_bytes'] ?? 0), 0);
    $remaining_volume = $limit <= 0 ? '∞' : str_replace(' ', '', fmt_bytes(max($limit - $used, 0)));

    $remaining_time = '∞';
    $expires_at = $data['expires_at'] ?? null;
    if ($expires_at) {
        try {
            $expiry = new DateTimeImmutable((string) $expires_at);
            $seconds = max(0, $expiry->getTimestamp() - time());
            $remaining_time = (string) intdiv($seconds + 86399, 86400) . 'D';
        } catch (Exception) {
            $remaining_time = '—';
        }
    }
    return "$base|📊$remaining_volume|⏳$remaining_time";
}

/** معادل generate_ss_link() — ss:// با پلاگین v2ray-plugin (WS+TLS) */
function generate_ss_link(string $host, int $port, string $cipher, string $password, string $remark): string
{
    $userinfo = rtrim(strtr(base64_encode("$cipher:$password"), '+/', '-_'), '=');
    $plugin = rawurlencode("v2ray-plugin;tls;mux=0;path=/ss-ws;host=$host");
    return "ss://$userinfo@$host:$port/?plugin=$plugin#" . rawurlencode($remark);
}

/**
 * معادل generate_share_link() — فرمت دقیق هر پروتکل مطابق main.py:
 *   vless-ws   : vless://{uuid}@{host}:443?type=ws&path=/ws/{uuid}&security=tls...
 *   xhttp-*    : vless://{uuid}@{host}:443?type=xhttp&path=/xhttp-siz10/{mode}/{uuid}...
 *   trojan-ws  : trojan://{uuid}@{host}:443?type=ws&path=/trojan-ws...
 *   trojan-xhttp-*: trojan://{uuid}@{host}:443?type=xhttp&path=/txhttp-siz10/{mode}/{uuid}...
 *   shadowsocks: ss://base64(cipher:password)@{host}:443/?plugin=v2ray-plugin...
 *   mtproto    : tg://proxy?server={pub_host}&port={pub_port}&secret=...
 */
function generate_share_link(string $uuid, string $host, string $remark = '', ?string $protocol = null): string
{
    $st = &state();
    $link = $st['links'][$uuid] ?? [];
    $protocol = $protocol ?? ($link['protocol'] ?? DEFAULT_PROTOCOL);
    // عنوان کلاینت همیشه از سیاست نام کاربر/کانفیگ پیروی می‌کند
    if ($link) {
        $remark = client_display_label($uuid, $link);
    } else {
        $remark = clean_public_label($remark, $uuid);
    }
    $alpn = $link['alpn'] ?? 'h2';
    $fp = $link['fingerprint'] ?? 'chrome';

    if ($protocol === 'mtproto') {
        $secret = $link['mtproto_secret'] ?? null;
        $pubHost = $link['mtproto_public_host'] ?? null;
        $pubPort = $link['mtproto_public_port'] ?? null;
        if (!$secret || !$pubHost || !$pubPort) {
            return "tg://proxy?server=$host&port=0&secret=not_ready#" . rawurlencode($remark);
        }
        return "tg://proxy?server=$pubHost&port=$pubPort&secret=$secret#" . rawurlencode($remark);
    }

    if ($protocol === 'shadowsocks') {
        $cipher = $link['ss_cipher'] ?? DEFAULT_CIPHER;
        $password = $link['ss_password'] ?? '';
        return generate_ss_link($host, 443, $cipher, $password, $remark);
    }

    if ($protocol === 'trojan-ws') {
        $params = [
            'security' => 'tls', 'type' => 'ws', 'host' => $host,
            'path' => '/trojan-ws', 'sni' => $host, 'fp' => $fp, 'alpn' => $alpn,
        ];
        $query = build_link_query($params);
        return "trojan://$uuid@$host:443?$query#" . rawurlencode($remark);
    }

    if (str_starts_with($protocol, 'trojan-xhttp-')) {
        $mode = str_replace('trojan-xhttp-', '', $protocol);
        $path = "/txhttp-siz10/$mode/$uuid";
        $params = [
            'security' => 'tls', 'type' => 'xhttp', 'mode' => $mode, 'host' => $host,
            'path' => $path, 'sni' => $host, 'fp' => $fp, 'alpn' => $alpn,
        ];
        $query = build_link_query($params);
        return "trojan://$uuid@$host:443?$query#" . rawurlencode($remark);
    }

    if ($protocol === 'vless-ws') {
        $params = [
            'encryption' => 'none',
            'security' => 'tls',
            'type' => 'ws',
            'host' => $host,
            'path' => "/ws/$uuid",
            'sni' => $host,
            'fp' => $fp,
            'alpn' => $alpn,
        ];
    } else {
        // xhttp-* برای VLESS
        $mode = str_replace('xhttp-', '', $protocol);
        $params = [
            'encryption' => 'none',
            'security' => 'tls',
            'type' => 'xhttp',
            'mode' => $mode,
            'host' => $host,
            'path' => "/xhttp-siz10/$mode/$uuid",
            'sni' => $host,
            'fp' => $fp,
            'alpn' => $alpn,
        ];
    }
    $query = build_link_query($params);
    return "vless://$uuid@$host:443?$query#" . rawurlencode($remark);
}

function build_link_query(array $params): string
{
    $parts = [];
    foreach ($params as $k => $v) {
        $parts[] = $k . '=' . rawurlencode((string) $v);
    }
    return implode('&', $parts);
}

/** معادل build_sub_headers() — هدرهای استاندارد سابسکریپشن برای کلاینت‌های VPN */
function build_sub_headers(string $label, int $used_bytes, int $limit_bytes, ?string $expires_at, string $support_url = ''): array
{
    $total = $limit_bytes > 0 ? $limit_bytes : 0;
    $expire_ts = 0;
    if ($expires_at) {
        try {
            $expire_ts = (new DateTimeImmutable($expires_at))->getTimestamp();
        } catch (Exception) {
            $expire_ts = 0;
        }
    }
    $userinfo = "upload=0; download=$used_bytes; total=$total; expire=$expire_ts";
    $title_b64 = base64_encode($label);
    return [
        'profile-title' => "base64:$title_b64",
        'subscription-userinfo' => $userinfo,
        'profile-update-interval' => '6',
        'profile-web-page-url' => $support_url,
    ];
}

/** ساخت و ثبت لینک جدید (معادل _create_link_core بدون MTProto/تونل که در PHP روی هاست اشتراکی معنا ندارند) */
function create_link_core(array $body): array
{
    $uid = generate_uuid();
    $label = clean_public_label($body['label'] ?? null, $uid);
    $lv = (float) ($body['limit_value'] ?? 0);
    $lu = (string) ($body['limit_unit'] ?? 'GB');
    $limit_bytes = $lv <= 0 ? 0 : parse_size_to_bytes($lv, $lu);
    $exp_days = (int) ($body['expires_days'] ?? 0);
    $expires_at = $exp_days > 0 ? date('c', time() + $exp_days * 86400) : null;
    $note = mb_substr(trim((string) ($body['note'] ?? '')), 0, 200);
    $sub_id = ($body['sub_id'] ?? null) ?: null;
    $protocol = (string) ($body['protocol'] ?? DEFAULT_PROTOCOL);
    if (!in_array($protocol, PROTOCOLS, true) || $protocol === 'tunnel') {
        $protocol = DEFAULT_PROTOCOL;
    }

    $alpn_val = mb_substr(trim((string) ($body['alpn'] ?? 'h2,http/1.1')), 0, 60);
    $fp_val = (string) ($body['fingerprint'] ?? 'chrome');
    if (!in_array($fp_val, ['chrome', 'firefox', 'ios'], true)) {
        $fp_val = 'chrome';
    }

    $link_data = [
        'label' => $label,
        'limit_bytes' => $limit_bytes,
        'used_bytes' => 0,
        'created_at' => date('c'),
        'alpn' => $alpn_val,
        'fingerprint' => $fp_val,
        'active' => true,
        'expires_at' => $expires_at,
        'note' => $note,
        'is_default' => false,
        'sub_id' => $sub_id,
        'protocol' => $protocol,
    ];

    if ($protocol === 'shadowsocks') {
        $ss_cipher = (string) ($body['ss_cipher'] ?? DEFAULT_CIPHER);
        if (!in_array($ss_cipher, SS_CIPHERS, true)) {
            $ss_cipher = DEFAULT_CIPHER;
        }
        $link_data['ss_cipher'] = $ss_cipher;
        $link_data['ss_password'] = base64_encode(random_bytes(12));
    }

    if ($protocol === 'mtproto') {
        // روی هاست اشتراکی TCP Proxy خودکار معنا ندارد؛ سکرت و پورت دستی پذیرفته می‌شود
        $link_data['mtproto_secret'] = strtoupper(bin2hex(random_bytes(16)));
        $pubHost = trim((string) ($body['mtproto_public_host'] ?? ''));
        $pubPort = (int) ($body['mtproto_public_port'] ?? 0);
        if ($pubHost !== '' && $pubPort > 0) {
            $link_data['mtproto_public_host'] = $pubHost;
            $link_data['mtproto_public_port'] = $pubPort;
        }
    }

    $st = &state();
    $st['links'][$uid] = $link_data;

    if ($sub_id && isset($st['subs'][$sub_id])) {
        $st['subs'][$sub_id]['link_ids'] ??= [];
        if (!in_array($uid, $st['subs'][$sub_id]['link_ids'], true)) {
            $st['subs'][$sub_id]['link_ids'][] = $uid;
        }
    }
    save_state();

    $host = base_url_host();
    return array_merge(
        ['uuid' => $uid],
        $link_data,
        [
            'expired' => false,
            'vless_link' => generate_share_link($uid, $host, $label, $protocol),
            'sub_url' => 'https://' . $host . '/sub/' . $uid,
        ]
    );
}

/** معادل ensure_default_link() */
function ensure_default_link(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $st = &state();
    $hasDefault = false;
    foreach ($st['links'] as $l) {
        if (!empty($l['is_default'])) {
            $hasDefault = true;
            break;
        }
    }
    if (!$hasDefault) {
        $uid = hash('sha256', 'default' . get_panel_secret());
        $uid = substr($uid, 0, 8) . '-' . substr($uid, 8, 4) . '-' . substr($uid, 12, 4) . '-' . substr($uid, 16, 4) . '-' . substr($uid, 20, 12);
        if (!isset($st['links'][$uid])) {
            $st['links'][$uid] = [
                'label' => 'لینک پیش‌فرض',
                'limit_bytes' => 0,
                'used_bytes' => 0,
                'created_at' => date('c'),
                'active' => true,
                'expires_at' => null,
                'note' => '',
                'is_default' => true,
                'sub_id' => null,
                'protocol' => DEFAULT_PROTOCOL,
            ];
            save_state();
        }
    }
    $done = true;
}

/** ثبت ترافیک مصرفی یک لینک (معادل check_and_use) — خروجی false یعنی باید قطع شود */
function check_and_use(string $uid, int $n): bool
{
    $st = &state();
    $link = $st['links'][$uid] ?? null;
    if ($link === null || !is_link_allowed($link)) {
        return false;
    }
    $st['links'][$uid]['used_bytes'] = (int) $link['used_bytes'] + $n;
    bump_traffic_counters($n);
    save_state();
    return true;
}
