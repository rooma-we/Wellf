<?php
// state.php — ذخیره‌سازی وضعیت (معادل بخش Persistence در main.py)
// همه‌ی داده‌ها در یک فایل JSON (معادل welfvita_state.json پایتون) نگه داشته می‌شوند
// و با قفل فایل (flock) و نوشتن اتمیک (tmp + rename) ذخیره می‌شوند.

declare(strict_types=1);
// PANEL_LOAD_GUARD — جلوگیری از اجرای مستقیم این فایل از وب (دفاع عمیق، علاوه بر .htaccess)
if (!isset($GLOBALS['WV_PANEL'])) { http_response_code(403); exit('Forbidden'); }


function data_dir(): string
{
    $d = getenv('DATA_DIR');
    if ($d && is_string($d) && $d !== '') {
        return $d;
    }
    return __DIR__ . '/../data';
}

function state_file(): string
{
    return data_dir() . '/welfvita_state.json';
}

function secret_file(): string
{
    return data_dir() . '/.welfvita_secret';
}

/**
 * کلید امنیتی: اگر SECRET_KEY تنظیم شده باشد از آن استفاده می‌شود،
 * در غیر این صورت یک بار تولید و روی دیسک ذخیره می‌شود (پایدار بین ری‌استارت‌ها).
 */
function get_or_create_secret(): string
{
    $env = getenv('SECRET_KEY');
    if ($env && is_string($env) && $env !== '') {
        return $env;
    }
    $file = secret_file();
    if (is_file($file)) {
        $val = trim((string) file_get_contents($file));
        if ($val !== '') {
            return $val;
        }
    }
    $secret = bin2hex(random_bytes(32));
    @mkdir(data_dir(), 0775, true);
    @file_put_contents($file, $secret, LOCK_EX);
    return $secret;
}

/** کل وضعیت: links / subs / password_hash / disable_logging / node_keys / nodes */
function &state(): array
{
    static $state = null;
    if ($state === null) {
        $state = load_state_from_disk();
    }
    return $state;
}

function load_state_from_disk(): array
{
    $defaults = [
        'links' => [],
        'subs' => [],
        'node_keys' => [],
        'nodes' => [],
        'password_hash' => null,
        'disable_logging' => false,
        'saved_at' => null,
    ];
    $f = state_file();
    if (!is_file($f)) {
        return $defaults;
    }
    $raw = @file_get_contents($f);
    if ($raw === false || $raw === '') {
        return $defaults;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $defaults;
    }
    foreach ($defaults as $k => $v) {
        if (!array_key_exists($k, $data)) {
            $data[$k] = $v;
        }
    }
    return $data;
}

/** ذخیره‌ی اتمیک وضعیت (معادل save_state در main.py با debounce ساده‌ی فایل-محور) */
function save_state(?array $new_state = null): void
{
    $st = &state();
    if ($new_state !== null) {
        $st = $new_state;
    }
    $st['saved_at'] = gmdate('c');
    @mkdir(data_dir(), 0775, true);
    $tmp = state_file() . '.tmp';
    $json = json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (file_put_contents($tmp, $json, LOCK_EX) !== false) {
        rename($tmp, state_file());
    }
    unset($st);
}

/** قفل سبک روی فایل برای جلوگیری از خراب‌شدن state با درخواست‌های همزمان */
function state_lock_acquire(int $timeout_ms = 2000): bool
{
    @mkdir(data_dir(), 0775, true);
    $lock = fopen(data_dir() . '/.welfvita_lock', 'c');
    if (!$lock) {
        return false;
    }
    $deadline = microtime(true) + $timeout_ms / 1000;
    while (microtime(true) < $deadline) {
        if (flock($lock, LOCK_EX | LOCK_NB)) {
            return true; // توجه: $lock عمداً باز می‌ماند تا پایان درخواست
        }
        usleep(20000);
    }
    fclose($lock);
    return false;
}

function state_lock_release(): void
{
    if (is_file(data_dir() . '/.welfvita_lock')) {
        $lock = fopen(data_dir() . '/.welfvita_lock', 'c');
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
