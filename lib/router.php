<?php
// router.php — تمام اندپوینت‌های API (معادل روت‌های main.py)
// ساختار مسیرها یک‌به‌یک با نسخه‌ی پایتون حفظ شده است.

declare(strict_types=1);
// PANEL_LOAD_GUARD — جلوگیری از اجرای مستقیم این فایل از وب (دفاع عمیق، علاوه بر .htaccess)
if (!isset($GLOBALS['WV_PANEL'])) { http_response_code(403); exit('Forbidden'); }


require_once __DIR__ . '/http.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/links.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/subs.php';

// ── Helpers ──────────────────────────────────────────────────────────────────

function api_fail(string $detail, int $status = 400): never
{
    json_response(['detail' => $detail], $status);
}

/** خروجی استاندارد یک لینک برای لیست داشبورد (معادل حلقه‌ی list_links) */
function link_public_shape(string $uid, array $d, string $host): array
{
    $proto = $d['protocol'] ?? DEFAULT_PROTOCOL;
    $display = public_link_label($uid, $d);
    return [
        'uuid' => $uid,
        'label' => $display,
        'note' => $d['note'] ?? '',
        'protocol' => $proto,
        'active' => (bool) ($d['active'] ?? true),
        'expired' => is_link_expired($d),
        'limit_bytes' => (int) ($d['limit_bytes'] ?? 0),
        'used_bytes' => (int) ($d['used_bytes'] ?? 0),
        'created_at' => $d['created_at'] ?? null,
        'expires_at' => $d['expires_at'] ?? null,
        'alpn' => $d['alpn'] ?? 'h2',
        'fingerprint' => $d['fingerprint'] ?? 'chrome',
        'sub_id' => $d['sub_id'] ?? null,
        'is_default' => (bool) ($d['is_default'] ?? false),
        'ss_cipher' => $d['ss_cipher'] ?? null,
        'mtproto_public_host' => $d['mtproto_public_host'] ?? null,
        'mtproto_public_port' => $d['mtproto_public_port'] ?? null,
        'vless_link' => generate_share_link($uid, $host, $display, $proto),
        'sub_url' => 'https://' . $host . '/sub/' . $uid,
    ];
}

/** تشخیص کلاینت اشتراک از مرورگر (معادل should_render_subscription_page) */
function is_subscription_client(): bool
{
    $view = strtolower((string) query_param('view', ''));
    if (in_array($view, ['raw', 'text', 'base64', 'subscription'], true)) {
        return true;
    }
    $ua = strtolower(request_user_agent());
    $clients = [
        'v2rayng', 'v2rayn', 'v2box', 'sing-box', 'singbox', 'shadowrocket',
        'clash', 'mihomo', 'hiddify', 'nekoray', 'nekobox', 'surfboard',
        'stash', 'loon', 'quantumult', 'kitsunebi', 'happ', 'v2raytun',
        'npvtunnel', 'npv', 'incy', 'streisand', 'okhttp',
    ];
    foreach ($clients as $c) {
        if (str_contains($ua, $c)) {
            return true;
        }
    }
    return false;
}

// ══════════════════════════════════════════════════════════════════════════════
// Auth endpoints — POST /api/login, /api/logout, GET /api/me, POST /api/change-password
// ══════════════════════════════════════════════════════════════════════════════

function handle_login(): never
{
    $body = request_json();
    $ip = client_ip();
    $pw = (string) ($body['password'] ?? '');
    if (hash_password($pw) !== get_password_hash()) {
        log_activity('auth', "تلاش ورود ناموفق از $ip", 'err');
        api_fail('رمز عبور اشتباه است', 401);
    }
    $token = create_session();
    log_activity('auth', "ورود موفق به پنل از $ip", 'ok');
    start_session_cookie($token);
    json_response(['ok' => true]);
}

function handle_logout(): never
{
    destroy_session(current_session_token());
    clear_session_cookie();
    json_response(['ok' => true]);
}

function handle_me(): never
{
    json_response(['authenticated' => is_authenticated()]);
}

function handle_change_password(): never
{
    require_auth();
    $body = request_json();
    if (hash_password((string) ($body['current_password'] ?? '')) !== get_password_hash()) {
        api_fail('رمز فعلی اشتباه است');
    }
    $new = (string) ($body['new_password'] ?? '');
    if (mb_strlen($new) < 4) {
        api_fail('رمز جدید باید حداقل ۴ کاراکتر باشد');
    }
    set_password_hash(hash_password($new));
    log_activity('auth', 'رمز عبور پنل تغییر کرد', 'ok');
    json_response(['ok' => true]);
}

// ══════════════════════════════════════════════════════════════════════════════
// Links endpoints — POST/GET /api/links, PATCH/DELETE /api/links/{uid}
// ══════════════════════════════════════════════════════════════════════════════

function handle_create_link(): never
{
    require_auth();
    $body = request_json();
    $created = create_link_core($body);
    log_activity('link', "کانفیگ «{$created['label']}» ساخته شد", 'ok');
    json_response($created);
}

function handle_list_links(): never
{
    require_auth();
    $host = base_url_host();
    $st = &state();
    $result = [];
    foreach ($st['links'] as $uid => $d) {
        $result[] = link_public_shape($uid, $d, $host);
    }
    usort($result, fn($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
    json_response(['links' => $result]);
}

function handle_update_link(string $uid): never
{
    require_auth();
    $body = request_json();
    $st = &state();
    if (!isset($st['links'][$uid])) {
        api_fail('link not found', 404);
    }
    $link = &$st['links'][$uid];
    $label = (string) ($link['label'] ?? $uid);

    if (array_key_exists('active', $body)) {
        $new_active = (bool) $body['active'];
        $changed = $new_active !== (bool) ($link['active'] ?? true);
        $link['active'] = $new_active;
        log_activity('link', "کانفیگ «$label» " . ($new_active ? 'فعال' : 'غیرفعال') . ' شد', $new_active ? 'ok' : 'warn');
    }
    if (array_key_exists('label', $body)) {
        $link['label'] = clean_public_label($body['label'], $uid);
    }
    if (array_key_exists('note', $body)) {
        $link['note'] = mb_substr((string) $body['note'], 0, 200);
    }
    if (!empty($body['reset_usage'])) {
        $link['used_bytes'] = 0;
        log_activity('link', "مصرف کانفیگ «$label» ریست شد", 'info');
    }
    if (array_key_exists('limit_value', $body)) {
        $lv = (float) ($body['limit_value'] ?? 0);
        $lu = (string) ($body['limit_unit'] ?? 'GB');
        $link['limit_bytes'] = $lv <= 0 ? 0 : parse_size_to_bytes($lv, $lu);
    }
    if (array_key_exists('expires_days', $body)) {
        $ed = (int) ($body['expires_days'] ?? 0);
        $link['expires_at'] = $ed > 0 ? date('c', time() + $ed * 86400) : null;
    }
    if (array_key_exists('alpn', $body)) {
        $alpn = trim((string) $body['alpn']);
        if ($alpn !== '') {
            $link['alpn'] = mb_substr($alpn, 0, 60);
        }
    }
    if (array_key_exists('fingerprint', $body)) {
        $fp = (string) $body['fingerprint'];
        $link['fingerprint'] = in_array($fp, ['chrome', 'firefox', 'ios'], true) ? $fp : 'chrome';
    }
    if (array_key_exists('mtproto_public_host', $body)) {
        $link['mtproto_public_host'] = trim((string) $body['mtproto_public_host']) ?: null;
    }
    if (array_key_exists('mtproto_public_port', $body)) {
        $pp = (int) ($body['mtproto_public_port'] ?? 0);
        $link['mtproto_public_port'] = $pp > 0 ? $pp : null;
    }
    if (array_key_exists('sub_id', $body)) {
        $old_sub = $link['sub_id'] ?? null;
        $new_sub = $body['sub_id'] ?: null;
        $link['sub_id'] = $new_sub;
        if ($old_sub && isset($st['subs'][$old_sub])) {
            $ids = $st['subs'][$old_sub]['link_ids'] ?? [];
            $st['subs'][$old_sub]['link_ids'] = array_values(array_diff($ids, [$uid]));
        }
        if ($new_sub && isset($st['subs'][$new_sub])) {
            $st['subs'][$new_sub]['link_ids'] ??= [];
            if (!in_array($uid, $st['subs'][$new_sub]['link_ids'], true)) {
                $st['subs'][$new_sub]['link_ids'][] = $uid;
            }
        }
    }
    if (array_intersect_key($body, array_flip(['label', 'note', 'limit_value', 'expires_days', 'alpn', 'fingerprint']))) {
        log_activity('link', "کانفیگ «{$link['label']}» ویرایش شد", 'info');
    }
    save_state();
    json_response(['ok' => true]);
}

function handle_delete_link(string $uid): never
{
    require_auth();
    $st = &state();
    if (!isset($st['links'][$uid])) {
        api_fail('link not found', 404);
    }
    $label = (string) ($st['links'][$uid]['label'] ?? $uid);
    $old_sub = $st['links'][$uid]['sub_id'] ?? null;
    unset($st['links'][$uid]);
    if ($old_sub && isset($st['subs'][$old_sub])) {
        $ids = $st['subs'][$old_sub]['link_ids'] ?? [];
        $st['subs'][$old_sub]['link_ids'] = array_values(array_diff($ids, [$uid]));
    }
    save_state();
    log_activity('link', "کانفیگ «$label» حذف شد", 'err');
    json_response(['ok' => true]);
}

// ══════════════════════════════════════════════════════════════════════════════
// Stats / system / activity / connections
// ══════════════════════════════════════════════════════════════════════════════

function handle_stats(): never
{
    require_auth();
    $st = &state();
    $r = &runtime();
    $active = 0;
    $expired = 0;
    foreach ($st['links'] as $l) {
        if (is_link_allowed($l)) {
            $active++;
        }
        if (is_link_expired($l)) {
            $expired++;
        }
    }
    $conns = live_connections();
    $hourly = $r['hourly'];
    // فید مرتب‌شده و ممتد برای نمودار (۲۴ ساعت اخیر)
    ksort($hourly);
    json_response([
        'active_connections' => count($conns),
        'total_traffic_mb' => round($r['total_bytes'] / 1024 ** 2, 2),
        'total_requests' => $r['total_requests'],
        'total_errors' => $r['total_errors'],
        'uptime' => uptime_string(),
        'timestamp' => date('c'),
        'hourly' => $hourly,
        'recent_errors' => array_slice(error_logs(), -10),
        'links_count' => count($st['links']),
        'active_links' => $active,
        'expired_links' => $expired,
        'subs_count' => count($st['subs']),
    ]);
}

function handle_activity(): never
{
    require_auth();
    json_response(['logs' => array_slice(activity_logs(), -150)]);
}

function handle_connections(): never
{
    require_auth();
    $st = &state();
    $grouped = [];
    foreach (live_connections() as $c) {
        $ip = $c['ip'] ?? 'unknown';
        $uid = $c['uuid'] ?? '';
        $link = $st['links'][$uid] ?? null;
        $label = $link ? (string) $link['label'] : 'نامشخص';
        if (!isset($grouped[$ip])) {
            $grouped[$ip] = [
                'ip' => $ip,
                'sessions' => 0,
                'bytes' => 0,
                'labels' => [],
                'transports' => [],
                'last_connected_at' => null,
            ];
        }
        $grouped[$ip]['sessions'] += (int) ($c['sessions'] ?? 1);
        $grouped[$ip]['bytes'] += (int) ($c['bytes'] ?? 0);
        $grouped[$ip]['labels'][$label] = true;
        $grouped[$ip]['transports'][$c['transport'] ?? 'vless-ws'] = true;
        $la = $c['last_seen'] ?? 0;
        if ($la && ($grouped[$ip]['last_connected_at'] === null || $la > $grouped[$ip]['last_connected_at'])) {
            $grouped[$ip]['last_connected_at'] = date('c', (int) $la);
        }
    }
    $result = [];
    foreach ($grouped as $g) {
        $labels = array_keys($g['labels']);
        $result[] = [
            'ip' => $g['ip'],
            'sessions' => $g['sessions'],
            'labels' => $labels,
            'label' => $labels ? implode(' · ', $labels) : 'نامشخص',
            'transports' => array_keys($g['transports']),
            'bytes' => $g['bytes'],
            'bytes_fmt' => fmt_bytes($g['bytes']),
            'last_connected_at' => $g['last_connected_at'],
        ];
    }
    usort($result, fn($a, $b) => strcmp((string) $b['last_connected_at'], (string) $a['last_connected_at']));
    json_response(['connections' => $result, 'count' => count($result)]);
}

function handle_server_location(): never
{
    require_auth();
    $resp = @file_get_contents('http://ip-api.com/json/?fields=status,country,city,lat,lon,query', false, stream_context_create(['http' => ['timeout' => 5]]));
    if ($resp === false) {
        api_fail('دریافت موقعیت سرور ممکن نشد', 502);
    }
    $d = json_decode($resp, true);
    if (!is_array($d) || ($d['status'] ?? '') !== 'success') {
        api_fail('دریافت موقعیت سرور ممکن نشد', 502);
    }
    json_response([
        'ip' => $d['query'] ?? null,
        'city' => $d['city'] ?? null,
        'country' => $d['country'] ?? null,
        'lat' => $d['lat'] ?? null,
        'lon' => $d['lon'] ?? null,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// Backup — GET /api/backup/export, POST /api/backup/import
// ══════════════════════════════════════════════════════════════════════════════

function handle_backup_export(): never
{
    require_auth();
    $st = &state();
    $data = [
        'kind' => 'welfvita-backup',
        'version' => '9.2',
        'exported_at' => date('c'),
        'host' => base_url_host(),
        'links' => $st['links'],
        'subs' => $st['subs'],
        'password_hash' => $st['password_hash'],
    ];
    $filename = 'welfvita-backup-' . date('Ymd-His') . '.json';
    log_activity('system', 'فایل بکاپ دانلود شد', 'info');
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function handle_backup_import(): never
{
    require_auth();
    $body = request_json();
    $data = $body['data'] ?? null;
    if (!is_array($data)) {
        api_fail('فایل بکاپ نامعتبر است');
    }
    $links = $data['links'] ?? null;
    $subs = $data['subs'] ?? null;
    if (!is_array($links) || !is_array($subs)) {
        api_fail('ساختار فایل بکاپ نامعتبر است');
    }
    $st = &state();
    $st['links'] = $links;
    $st['subs'] = $subs;
    $keep_pw = (bool) ($body['keep_current_password'] ?? true);
    if (!$keep_pw && !empty($data['password_hash'])) {
        $st['password_hash'] = (string) $data['password_hash'];
    }
    save_state();
    log_activity('system', 'بکاپ با موفقیت روی پنل بازیابی شد', 'ok');
    json_response([
        'ok' => true,
        'links_count' => count($st['links']),
        'subs_count' => count($st['subs']),
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// Settings — GET/POST /api/settings/logging
// ══════════════════════════════════════════════════════════════════════════════

function handle_get_logging(): never
{
    require_auth();
    $st = &state();
    json_response(['disabled' => (bool) ($st['disable_logging'] ?? false)]);
}

function handle_set_logging(): never
{
    require_auth();
    $body = request_json();
    $disabled = (bool) ($body['disabled'] ?? false);
    $st = &state();
    $st['disable_logging'] = $disabled;
    save_state();
    json_response(['ok' => true, 'disabled' => $disabled]);
}
