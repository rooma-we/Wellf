<?php
// http.php — توابع کمکی پاسخ HTTP و بادی درخواست (معادل Response/JSONResponse فست‌اپی)

declare(strict_types=1);
// PANEL_LOAD_GUARD — جلوگیری از اجرای مستقیم این فایل از وب (دفاع عمیق، علاوه بر .htaccess)
if (!isset($GLOBALS['WV_PANEL'])) { http_response_code(403); exit('Forbidden'); }


function json_response(array $data, int $status = 200, array $headers = []): never
{
    http_response_code($status);
    foreach ($headers as $k => $v) {
        header("$k: $v");
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function html_response(string $html, int $status = 200, array $headers = []): never
{
    http_response_code($status);
    foreach ($headers as $k => $v) {
        header("$k: $v");
    }
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

function text_response(string $text, int $status = 200, array $headers = []): never
{
    http_response_code($status);
    foreach ($headers as $k => $v) {
        header("$k: $v");
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo $text;
    exit;
}

function redirect(string $to): never
{
    header("Location: $to");
    exit;
}

/** بادی JSON درخواست (معادل await request.json()) */
function request_json(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string) $raw, true);
    $cache = is_array($decoded) ? $decoded : [];
    return $cache;
}

function query_param(string $name, ?string $default = null): ?string
{
    if (!isset($_GET[$name]) || !is_scalar($_GET[$name])) {
        return $default;
    }
    return (string) $_GET[$name];
}

function body_param(array $body, string $name, mixed $default = null): mixed
{
    return array_key_exists($name, $body) ? $body[$name] : $default;
}

/** IP واقعی کلاینت با احتساب هدرهای پراکسی (معادل client_ip) */
function client_ip(): string
{
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
    if ($fwd) {
        return trim(explode(',', $fwd)[0]);
    }
    $real = $_SERVER['HTTP_X_REAL_IP'] ?? null;
    if ($real) {
        return trim((string) $real);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function request_user_agent(): string
{
    return $_SERVER['HTTP_USER_AGENT'] ?? '';
}

function base_url_host(): string
{
    // معادل get_host(): اگر PUBLIC_DOMAIN تنظیم شده از آن استفاده کن، وگرنه هدر Host
    $env = getenv('PUBLIC_DOMAIN');
    if ($env && $env !== '') {
        return $env;
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // حذف پورت غیر استاندارد از دامنه (پنل معمولاً پشت CDN/TLS روی 443 است)
    return preg_replace('/:(80|443)$/', '', $host) ?: $host;
}

function is_https(): bool
{
    if (($_SERVER['HTTPS'] ?? '') === 'on') {
        return true;
    }
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        return true;
    }
    // پنل‌های Railway/هاست اشتراکی همیشه TLS دارند
    $env = getenv('PUBLIC_DOMAIN');
    if ($env && $env !== '' && !str_starts_with($env, 'localhost') && !str_starts_with($env, '127.')) {
        return true;
    }
    return false;
}
