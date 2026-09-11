<?php
// auth.php — احراز هویت (معادل بخش Auth در main.py)
// مدل دقیقاً یکسان: فقط رمز عبور، هش sha256(password + secret) در state،
// سشن با توکن تصادفی در کوکی welfvita_session با TTL هفت روز.

declare(strict_types=1);
// PANEL_LOAD_GUARD — جلوگیری از اجرای مستقیم این فایل از وب (دفاع عمیق، علاوه بر .htaccess)
if (!isset($GLOBALS['WV_PANEL'])) { http_response_code(403); exit('Forbidden'); }


require_once __DIR__ . '/state.php';

const SESSION_COOKIE = 'welfvita_session';
const SESSION_TTL = 60 * 60 * 24 * 7; // هفت روز

function &sessions(): array
{
    static $sessions = null;
    if ($sessions === null) {
        $path = data_dir() . '/.welfvita_sessions';
        $sessions = [];
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $sessions = $decoded;
            }
        }
        // پاک‌سازی سشن‌های منقضی
        $now = time();
        foreach ($sessions as $tok => $exp) {
            if (!is_int($exp) || $exp < $now) {
                unset($sessions[$tok]);
            }
        }
    }
    return $sessions;
}

function persist_sessions(): void
{
    @mkdir(data_dir(), 0775, true);
    @file_put_contents(data_dir() . '/.welfvita_sessions', json_encode(sessions()), LOCK_EX);
}

function get_panel_secret(): string
{
    static $secret = null;
    if ($secret === null) {
        $secret = get_or_create_secret();
    }
    return $secret;
}

function hash_password(string $pw): string
{
    return hash('sha256', $pw . get_panel_secret());
}

function get_password_hash(): string
{
    $st = &state();
    if (!empty($st['password_hash']) && is_string($st['password_hash'])) {
        return $st['password_hash'];
    }
    // پیش‌فرض معادل ADMIN_PASSWORD=123456 در main.py
    return hash_password('123456');
}

function set_password_hash(string $hash): void
{
    $st = &state();
    $st['password_hash'] = $hash;
    save_state();
}

function create_session(): string
{
    $token = bin2hex(random_bytes(32));
    $s = &sessions();
    $s[$token] = time() + SESSION_TTL;
    persist_sessions();
    return $token;
}

function is_valid_session(?string $token): bool
{
    if (!$token) {
        return false;
    }
    $s = &sessions();
    $exp = $s[$token] ?? null;
    if ($exp === null || !is_int($exp)) {
        return false;
    }
    if ($exp < time()) {
        unset($s[$token]);
        persist_sessions();
        return false;
    }
    return true;
}

function destroy_session(?string $token): void
{
    if (!$token) {
        return;
    }
    $s = &sessions();
    if (isset($s[$token])) {
        unset($s[$token]);
        persist_sessions();
    }
}

function current_session_token(): ?string
{
    return $_COOKIE[SESSION_COOKIE] ?? null;
}

/** معادل require_auth در main.py — اگر لاگین نباشد 401 برمی‌گرداند و اجرا تمام می‌شود */
function require_auth(): string
{
    $token = current_session_token();
    if (!is_valid_session($token)) {
        json_response(['detail' => 'unauthorized'], 401);
    }
    return $token;
}

function is_authenticated(): bool
{
    return is_valid_session(current_session_token());
}

function start_session_cookie(string $token): void
{
    setcookie(SESSION_COOKIE, $token, [
        'expires' => time() + SESSION_TTL,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    ]);
}

function clear_session_cookie(): void
{
    setcookie(SESSION_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}
