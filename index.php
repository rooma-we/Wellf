<?php
// index.php — نقطه‌ی ورود و مسیریاب (معادل main.py)
// تمام مسیرها به همین فایل هدایت می‌شوند (mod_rewrite در .htaccess)

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// نشانه‌ی بارگذاری مجاز — فایل‌های lib/templates با این گارد از اجرای مستقیم محافظت می‌شوند
$GLOBALS['WV_PANEL'] = true;

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/state.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/links.php';
require_once __DIR__ . '/lib/stats.php';
require_once __DIR__ . '/lib/router.php';
require_once __DIR__ . '/lib/subs.php';
require_once __DIR__ . '/lib/subscription.php';

// ── مسیر جاری ────────────────────────────────────────────────────────────────
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = rtrim($uri, '/');
if ($uri === '') {
    $uri = '/';
}
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    route($uri, $method);
} catch (Throwable $e) {
    log_error($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    bump_error_counter();
    if (str_starts_with($uri, '/api/')) {
        json_response(['detail' => 'internal error'], 500);
    }
    html_response('<h2 style="font-family:sans-serif;padding:40px">خطای سرور</h2>', 500);
}

function route(string $uri, string $method): never
{
    // ── Basic endpoints ──────────────────────────────────────────────────────
    // ریشه‌ی سایت مستقیم به پنل می‌رود: بازدیدکننده → /login، ادمین لاگین‌شده → /dashboard
    if ($uri === '/' && $method === 'GET') {
        redirect(is_authenticated() ? '/dashboard' : '/login');
    }
    if ($uri === '/api/info' && $method === 'GET') {
        json_response([
            'service' => 'WelfVita Gateway',
            'version' => '9.2',
            'status' => 'active',
            'runtime' => 'PHP',
        ]);
    }
    if ($uri === '/health' && $method === 'GET') {
        json_response(['status' => 'ok', 'connections' => count(live_connections()), 'uptime' => uptime_string()]);
    }

    // ── Auth ─────────────────────────────────────────────────────────────────
    if ($uri === '/api/login' && $method === 'POST') {
        handle_login();
    }
    if ($uri === '/api/logout' && $method === 'POST') {
        handle_logout();
    }
    if ($uri === '/api/me' && $method === 'GET') {
        handle_me();
    }
    if ($uri === '/api/change-password' && $method === 'POST') {
        handle_change_password();
    }

    // ── Links ────────────────────────────────────────────────────────────────
    if ($uri === '/api/links' && $method === 'POST') {
        handle_create_link();
    }
    if ($uri === '/api/links' && $method === 'GET') {
        handle_list_links();
    }
    if (preg_match('#^/api/links/([0-9a-fA-F-]{36})$#', $uri, $m) && $method === 'PATCH') {
        handle_update_link($m[1]);
    }
    if (preg_match('#^/api/links/([0-9a-fA-F-]{36})$#', $uri, $m) && $method === 'DELETE') {
        handle_delete_link($m[1]);
    }

    // ── Subs ─────────────────────────────────────────────────────────────────
    if ($uri === '/api/subs' && $method === 'POST') {
        handle_create_sub();
    }
    if ($uri === '/api/subs' && $method === 'GET') {
        handle_list_subs();
    }
    if (preg_match('#^/api/subs/([a-z0-9-]{8,64})$#i', $uri, $m) && $method === 'PATCH') {
        handle_update_sub($m[1]);
    }
    if (preg_match('#^/api/subs/([a-z0-9-]{8,64})$#i', $uri, $m) && $method === 'DELETE') {
        handle_delete_sub($m[1]);
    }
    if (preg_match('#^/api/subs/([a-z0-9-]{8,64})/links$#i', $uri, $m) && $method === 'POST') {
        handle_assign_link_to_sub($m[1]);
    }

    // ── Stats / system / activity / connections ─────────────────────────────
    if ($uri === '/stats' && $method === 'GET') {
        handle_stats();
    }
    if ($uri === '/api/activity' && $method === 'GET') {
        handle_activity();
    }
    if ($uri === '/api/connections' && $method === 'GET') {
        handle_connections();
    }
    if ($uri === '/api/server/location' && $method === 'GET') {
        handle_server_location();
    }
    if ($uri === '/api/system' && $method === 'GET') {
        require_auth();
        json_response(system_stats());
    }

    // ── Backup ───────────────────────────────────────────────────────────────
    if ($uri === '/api/backup/export' && $method === 'GET') {
        handle_backup_export();
    }
    if ($uri === '/api/backup/import' && $method === 'POST') {
        handle_backup_import();
    }

    // ── Settings ─────────────────────────────────────────────────────────────
    if ($uri === '/api/settings/logging' && $method === 'GET') {
        handle_get_logging();
    }
    if ($uri === '/api/settings/logging' && $method === 'POST') {
        handle_set_logging();
    }

    // ── Subscription feeds (همیشه قبل از صفحات HTML) ────────────────────────
    if (preg_match('#^/sub/([0-9a-fA-F-]{36})$#', $uri) && $method === 'GET') {
        handle_subscription_single(substr($uri, 5));
    }
    if ($uri === '/sub-all' && $method === 'GET') {
        handle_subscription_all();
    }
    if (preg_match('#^/sub-group/([A-Za-z0-9_-]{8,64})$#', $uri) && $method === 'GET') {
        handle_sub_group(substr($uri, 11));
    }
    if (preg_match('#^/p/([A-Za-z0-9_-]{8,64})$#', $uri) && $method === 'GET') {
        handle_public_group_page(substr($uri, 3));
    }

    // ── Public subscription JSON APIs ───────────────────────────────────────
    if (preg_match('#^/api/public/single/([0-9a-fA-F-]{36})$#', $uri, $m) && $method === 'GET') {
        handle_public_single($m[1]);
    }
    if (preg_match('#^/api/public/sub/([A-Za-z0-9_-]{8,64})$#', $uri, $m) && $method === 'GET') {
        handle_public_sub_data($m[1]);
    }

    // ── HTML Pages ───────────────────────────────────────────────────────────
    if ($uri === '/login' && $method === 'GET') {
        if (is_authenticated()) {
            redirect('/dashboard');
        }
        html_response(render_login_page());
    }
    if ($uri === '/dashboard' && $method === 'GET') {
        if (!is_authenticated()) {
            redirect('/login');
        }
        ensure_default_link();
        html_response(render_dashboard_page());
    }

    json_response(['detail' => 'Not Found'], 404);
}

/** آمار سیستم (معادل /api/system با psutil) — بدون psutil معادل PHP، حداقل‌های مفید */
function system_stats(): array
{
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];
    $disk = @disk_total_space('/') ?: @disk_total_space(__DIR__) ?: 0;
    $diskFree = @disk_free_space('/') ?: @disk_free_space(__DIR__) ?: 0;
    $mem = [
        'total' => null,
        'percent' => null,
    ];
    if (PHP_OS_FAMILY === 'Linux') {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo) {
            preg_match('/MemTotal:\s+(\d+)/', $meminfo, $mt);
            preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $ma);
            if ($mt && $ma) {
                $totalKb = (int) $mt[1];
                $availKb = (int) $ma[1];
                $mem = [
                    'total' => $totalKb * 1024,
                    'percent' => $totalKb > 0 ? round(($totalKb - $availKb) / $totalKb * 100, 1) : null,
                ];
            }
        }
    }
    return [
        'available' => true,
        'cpu' => [
            'percent' => null,
            'load_avg' => array_map(fn($x) => round((float) $x, 2), (array) $load),
            'note' => 'cpu-percent روی هاست اشتراکی PHP در دسترس نیست',
        ],
        'memory' => $mem,
        'disk' => [
            'total' => $disk,
            'free' => $diskFree,
            'percent' => $disk > 0 ? round(($disk - $diskFree) / $disk * 100, 1) : null,
        ],
        'php_version' => PHP_VERSION,
        'server_time' => date('c'),
    ];
}

// ── صفحات HTML (login + dashboard) از templates بارگذاری می‌شوند ─────────────
function render_login_page(): string
{
    ob_start();
    require __DIR__ . '/templates/login.php';
    return (string) ob_get_clean();
}

function render_dashboard_page(): string
{
    ob_start();
    require __DIR__ . '/templates/dashboard.php';
    return (string) ob_get_clean();
}
