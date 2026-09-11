<?php
// stats.php — شمارنده‌های ترافیک و لاگ‌ها (معادل stats / hourly_traffic / activity_logs در main.py)
// شمارنده‌های زنده‌ی درون-حافظه + ماندگاری سریع در data/wv_runtime.json

declare(strict_types=1);
// PANEL_LOAD_GUARD — جلوگیری از اجرای مستقیم این فایل از وب (دفاع عمیق، علاوه بر .htaccess)
if (!isset($GLOBALS['WV_PANEL'])) { http_response_code(403); exit('Forbidden'); }


require_once __DIR__ . '/state.php';

function &runtime(): array
{
    static $runtime = null;
    if ($runtime === null) {
        $runtime = [
            'total_bytes' => 0,
            'total_requests' => 0,
            'total_errors' => 0,
            'start_time' => time(),
            'hourly' => [],
        ];
        $f = data_dir() . '/wv_runtime.json';
        if (is_file($f)) {
            $d = json_decode((string) @file_get_contents($f), true);
            if (is_array($d)) {
                $runtime = array_merge($runtime, $d);
                if (!isset($runtime['hourly']) || !is_array($runtime['hourly'])) {
                    $runtime['hourly'] = [];
                }
            }
        }
    }
    return $runtime;
}

function persist_runtime(): void
{
    $r = &runtime();
    @mkdir(data_dir(), 0775, true);
    @file_put_contents(data_dir() . '/wv_runtime.json', json_encode($r, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** برچسب ساعت جاری به وقت ایران (معادل now_ir().strftime("%H:00")) */
function iran_hour_label(): string
{
    try {
        $dt = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
        return $dt->format('H:00');
    } catch (Exception) {
        return date('H:00');
    }
}

function bump_traffic_counters(int $n): void
{
    $r = &runtime();
    $r['total_bytes'] += $n;
    $h = iran_hour_label();
    $r['hourly'][$h] = (int) ($r['hourly'][$h] ?? 0) + $n;
    persist_runtime();
}

function bump_request_counter(): void
{
    $r = &runtime();
    $r['total_requests']++;
    persist_runtime();
}

function bump_error_counter(): void
{
    $r = &runtime();
    $r['total_errors']++;
    persist_runtime();
}

// ── لاگ فعالیت (activity_logs) ────────────────────────────────────────────────
// حداکثر ۲۰۰ مورد آخر در runtime نگه داشته می‌شود.

function &activity_logs(): array
{
    static $logs = null;
    if ($logs === null) {
        $f = data_dir() . '/wv_activity.json';
        $logs = [];
        if (is_file($f)) {
            $d = json_decode((string) @file_get_contents($f), true);
            if (is_array($d)) {
                $logs = $d;
            }
        }
    }
    return $logs;
}

function persist_activity(): void
{
    @mkdir(data_dir(), 0775, true);
    @file_put_contents(data_dir() . '/wv_activity.json', json_encode(activity_logs(), JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** معادل log_activity() — kind: system|auth|link|sub|connection، level: ok|err|warn|info */
function log_activity(string $kind, string $message, string $level = 'info'): void
{
    $logs = &activity_logs();
    $logs[] = [
        'kind' => $kind,
        'level' => $level,
        'message' => $message,
        'time' => date('c'),
    ];
    if (count($logs) > 200) {
        $logs = array_slice($logs, -200);
    }
    persist_activity();
}

// ── لاگ خطا (error_logs) — حداکثر ۵۰ مورد ────────────────────────────────────
function &error_logs(): array
{
    static $errs = null;
    if ($errs === null) {
        $f = data_dir() . '/wv_errors.json';
        $errs = [];
        if (is_file($f)) {
            $d = json_decode((string) @file_get_contents($f), true);
            if (is_array($d)) {
                $errs = $d;
            }
        }
    }
    return $errs;
}

function log_error(string $error): void
{
    // مطابق _ErrorLogDeque در main.py: با فعال‌بودن توقف لاگ، خطاها ثبت نمی‌شوند
    $st = &state();
    if (!empty($st['disable_logging'])) {
        return;
    }
    $errs = &error_logs();
    $errs[] = ['error' => mb_substr($error, 0, 500), 'time' => date('c')];
    if (count($errs) > 50) {
        $errs = array_slice($errs, -50);
    }
    @file_put_contents(data_dir() . '/wv_errors.json', json_encode($errs, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** معادل uptime() — HH:MM:SS از شروع سرور */
function uptime_string(): string
{
    $r = &runtime();
    $secs = max(0, time() - (int) $r['start_time']);
    return sprintf('%02d:%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60), $secs % 60);
}

// ── اتصالات زنده ──────────────────────────────────────────────────────────────
// روی هاست اشتراکی PHP ریل TCP نداریم؛ اتصالاتِ ثبت‌شده از فید ساب و صفحات
// عمومی (بازدیدهای سشن‌دار) به‌صورت سبک ردیابی می‌شوند تا صفحه‌ی اتصالات
// همان معنا را داشته باشد: گروه‌بندی بر اساس IP با شمارش و ترافیک.

function &live_connections(): array
{
    static $conns = null;
    if ($conns === null) {
        $f = data_dir() . '/wv_connections.json';
        $conns = [];
        if (is_file($f)) {
            $d = json_decode((string) @file_get_contents($f), true);
            if (is_array($d)) {
                $conns = $d;
            }
        }
    }
    return $conns;
}

/** ثبت/به‌روزرسانی یک اتصال زنده؛ اتصالات قدیمی‌تر از 10 دقیقه حذف می‌شوند */
function track_connection(string $uuid, string $ip, string $transport, int $bytes = 0): void
{
    $conns = &live_connections();
    $now = time();
    // پاک‌سازی اتصالات خاموش
    foreach ($conns as $cid => $c) {
        if (($c['last_seen'] ?? 0) < $now - 600) {
            unset($conns[$cid]);
        }
    }
    $key = md5($ip . '|' . $uuid);
    if (isset($conns[$key])) {
        $conns[$key]['bytes'] += $bytes;
        $conns[$key]['last_seen'] = $now;
        $conns[$key]['sessions']++;
    } else {
        $conns[$key] = [
            'uuid' => $uuid,
            'ip' => $ip,
            'transport' => $transport,
            'connected_at' => date('c'),
            'last_seen' => $now,
            'bytes' => $bytes,
            'sessions' => 1,
        ];
    }
    @file_put_contents(data_dir() . '/wv_connections.json', json_encode($conns, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
