<?php
// subscription.php — فید اشتراک (معادل /sub/{uuid} و /sub-group/{uuid_key} در main.py)
// همان رفتار URL واحد: مرورگر صفحه پروفایل HTML می‌بیند، کلاینت VPN فید Base64

declare(strict_types=1);
// PANEL_LOAD_GUARD — جلوگیری از اجرای مستقیم این فایل از وب (دفاع عمیق، علاوه بر .htaccess)
if (!isset($GLOBALS['WV_PANEL'])) { http_response_code(403); exit('Forbidden'); }


require_once __DIR__ . '/http.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/links.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/subs.php';
require_once __DIR__ . '/subpage.php';

// ── /sub/{uuid} — اشتراک تک‌لینک ─────────────────────────────────────────────
function handle_subscription_single(string $uuid): never
{
    $st = &state();
    $link = $st['links'][$uuid] ?? null;
    if (!$link) {
        json_response(['detail' => 'not found'], 404);
    }

    $host = base_url_host();
    $proto = $link['protocol'] ?? DEFAULT_PROTOCOL;
    $display = public_link_label($uuid, $link);
    $sub_url = 'https://' . $host . '/sub/' . $uuid;
    $share_link = generate_share_link($uuid, $host, $display, $proto);
    $allowed = is_link_allowed($link);

    // مرورگر → صفحه پروفایل؛ کلاینت VPN → فید Base64
    if (!is_subscription_client()) {
        track_connection($uuid, client_ip(), 'sub-page-view');
        $page_data = build_sub_page_data(
            subscription_id: $uuid,
            label: $display,
            host: $host,
            enabled: $allowed,
            used_bytes: (int) ($link['used_bytes'] ?? 0),
            limit_bytes: (int) ($link['limit_bytes'] ?? 0),
            expires_at: $link['expires_at'] ?? null,
            links: [$share_link],
            is_online: false,
            announcement: public_announcement($link['note'] ?? ''),
        );
        html_response(render_sub_page($page_data));
    }

    if (!$allowed) {
        json_response(['detail' => 'not found or inactive'], 404);
    }

    bump_request_counter();
    track_connection($uuid, client_ip(), 'subscription');
    $content = base64_encode($share_link);
    $headers = build_sub_headers(
        $display,
        (int) ($link['used_bytes'] ?? 0),
        (int) ($link['limit_bytes'] ?? 0),
        $link['expires_at'] ?? null,
        $sub_url
    );
    text_response($content, 200, $headers);
}

// ── /sub-all — فید همه (نیازمند لاگین ادمین) ─────────────────────────────────
function handle_subscription_all(): never
{
    require_auth();
    $host = base_url_host();
    $st = &state();
    $lines = [];
    $total_used = 0;
    $total_limit = 0;
    $expiries = [];
    foreach ($st['links'] as $uid => $link) {
        if (!is_link_allowed($link)) {
            continue;
        }
        $lines[] = generate_share_link($uid, $host, public_link_label($uid, $link), $link['protocol'] ?? DEFAULT_PROTOCOL);
        $total_used += (int) ($link['used_bytes'] ?? 0);
        $total_limit += (int) ($link['limit_bytes'] ?? 0);
        if (!empty($link['expires_at'])) {
            $expiries[] = $link['expires_at'];
        }
    }
    $nearest = $expiries ? min($expiries) : null;
    $headers = build_sub_headers('همه کانفیگ‌ها', $total_used, $total_limit, $nearest, 'https://' . $host . '/sub-all');
    text_response(base64_encode(implode("\n", $lines)), 200, $headers);
}

// ── /sub-group/{uuid_key} — فید گروه اشتراک ──────────────────────────────────
function handle_sub_group(string $uuid_key): never
{
    $sub = find_sub_by_key($uuid_key);
    if (!$sub) {
        json_response(['detail' => 'not found'], 404);
    }

    if (!is_subscription_client()) {
        track_connection($uuid_key, client_ip(), 'sub-page-view');
        html_response(render_public_group_page($uuid_key));
    }

    if (!empty($sub['password_hash'])) {
        $pw = (string) query_param('pw', '');
        if (hash_password($pw) !== $sub['password_hash']) {
            json_response(['detail' => 'wrong password'], 403);
        }
    }

    $host = base_url_host();
    $st = &state();
    $lines = [];
    $total_used = 0;
    $total_limit = 0;
    $expiries = [];
    foreach ($sub['link_ids'] ?? [] as $lid) {
        $link = $st['links'][$lid] ?? null;
        if (!$link || !is_link_allowed($link)) {
            continue;
        }
        $lines[] = generate_share_link($lid, $host, public_link_label($lid, $link), $link['protocol'] ?? DEFAULT_PROTOCOL);
        $total_used += (int) ($link['used_bytes'] ?? 0);
        $total_limit += (int) ($link['limit_bytes'] ?? 0);
        if (!empty($link['expires_at'])) {
            $expiries[] = $link['expires_at'];
        }
    }
    $nearest = $expiries ? min($expiries) : null;
    $headers = build_sub_headers(
        clean_public_label($sub['name'] ?? '', $uuid_key),
        $total_used,
        $total_limit,
        $nearest,
        'https://' . $host . '/sub-group/' . $uuid_key
    );
    bump_request_counter();
    track_connection($uuid_key, client_ip(), 'subscription');
    text_response(base64_encode(implode("\n", $lines)), 200, $headers);
}

// ── /api/public/sub/{uuid_key} — داده JSON صفحه عمومی گروه ───────────────────
function handle_public_sub_data(string $uuid_key): never
{
    $sub = find_sub_by_key($uuid_key);
    if (!$sub) {
        json_response(['detail' => 'not found'], 404);
    }
    $host = base_url_host();
    $locked = false;
    if (!empty($sub['password_hash'])) {
        $pw = (string) query_param('pw', '');
        if (hash_password($pw) !== $sub['password_hash']) {
            json_response(['locked' => true, 'name' => $sub['name']]);
        }
    }
    $st = &state();
    $links_out = [];
    $total_used = 0;
    $total_limit = 0;
    $expiries = [];
    foreach ($sub['link_ids'] ?? [] as $lid) {
        $link = $st['links'][$lid] ?? null;
        if (!$link) {
            continue;
        }
        $allowed = is_link_allowed($link);
        $proto = $link['protocol'] ?? DEFAULT_PROTOCOL;
        $display = public_link_label($lid, $link);
        $used = (int) ($link['used_bytes'] ?? 0);
        $limit = (int) ($link['limit_bytes'] ?? 0);
        $links_out[] = [
            'uuid' => $lid,
            'label' => $display,
            'active' => $allowed,
            'protocol' => $proto,
            'used_bytes' => $used,
            'limit_bytes' => $limit,
            'expiry_date' => $link['expires_at'] ?? null,
            'vless_link' => generate_share_link($lid, $host, $display, $proto),
        ];
        $total_used += $used;
        if ($limit > 0) {
            $total_limit += $limit;
        }
        if (!empty($link['expires_at'])) {
            $expiries[] = $link['expires_at'];
        }
    }
    $nearest = $expiries ? min($expiries) : null;
    json_response([
        'locked' => $locked,
        'subscription_id' => $uuid_key,
        'name' => $sub['name'],
        'desc' => public_announcement($sub['desc'] ?? ''),
        'sub_url' => 'https://' . $host . '/sub-group/' . $uuid_key,
        'total_used' => $total_used,
        'total_limit' => $total_limit,
        'expiry_date' => $nearest,
        'uploaded_bytes' => 0,
        'active_connections' => 0,
        'links' => $links_out,
    ]);
}

// ── /api/public/single/{uuid} — داده JSON صفحه عمومی تک‌لینک ─────────────────
function handle_public_single(string $uuid): never
{
    $st = &state();
    $link = $st['links'][$uuid] ?? null;
    if (!$link) {
        json_response(['detail' => 'not found'], 404);
    }
    $host = base_url_host();
    $proto = $link['protocol'] ?? DEFAULT_PROTOCOL;
    $display = public_link_label($uuid, $link);
    $used = (int) ($link['used_bytes'] ?? 0);
    $limit = (int) ($link['limit_bytes'] ?? 0);
    $vless_link = generate_share_link($uuid, $host, $display, $proto);
    $allowed = is_link_allowed($link);
    json_response([
        'locked' => false,
        'subscription_id' => $uuid,
        'name' => $display,
        'desc' => public_announcement($link['note'] ?? ''),
        'sub_url' => 'https://' . $host . '/sub/' . $uuid,
        'total_used' => $used,
        'total_limit' => $limit,
        'expiry_date' => $link['expires_at'] ?? null,
        'uploaded_bytes' => 0,
        'active_connections' => 0,
        'links' => [[
            'uuid' => $uuid,
            'label' => $display,
            'active' => $allowed,
            'protocol' => $proto,
            'used_bytes' => $used,
            'limit_bytes' => $limit,
            'expiry_date' => $link['expires_at'] ?? null,
            'vless_link' => $vless_link,
        ]],
    ]);
}

// ── /p/{uuid_key} — صفحه عمومی گروه ─────────────────────────────────────────
function handle_public_group_page(string $uuid_key): never
{
    $sub = find_sub_by_key($uuid_key);
    if (!$sub) {
        html_response('<h2 style="font-family:sans-serif;padding:40px">گروه پیدا نشد</h2>', 404);
    }
    track_connection($uuid_key, client_ip(), 'sub-page-view');
    html_response(render_public_group_page($uuid_key));
}
