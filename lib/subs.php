<?php
// subs.php — گروه‌های اشتراک (معادل بخش SUBS در main.py)

declare(strict_types=1);
// PANEL_LOAD_GUARD — جلوگیری از اجرای مستقیم این فایل از وب (دفاع عمیق، علاوه بر .htaccess)
if (!isset($GLOBALS['WV_PANEL'])) { http_response_code(403); exit('Forbidden'); }


require_once __DIR__ . '/state.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/links.php';
require_once __DIR__ . '/stats.php';

function handle_create_sub(): never
{
    require_auth();
    $body = request_json();
    json_response(create_sub_core($body));
}

function create_sub_core(array $body): array
{
    $st = &state();
    $sub_id = generate_uuid();
    $uuid_key = bin2hex(random_bytes(16));
    $name = clean_public_label($body['name'] ?? null, $sub_id);
    $desc = mb_substr(trim((string) ($body['desc'] ?? '')), 0, 200);
    $password = trim((string) ($body['password'] ?? ''));
    $st['subs'][$sub_id] = [
        'name' => $name,
        'desc' => $desc,
        'password_hash' => $password !== '' ? hash_password($password) : null,
        'uuid_key' => $uuid_key,
        'created_at' => date('c'),
        'link_ids' => [],
    ];
    save_state();
    log_activity('sub', "گروه «$name» ساخته شد", 'ok');
    $host = base_url_host();
    return array_merge(
        ['sub_id' => $sub_id],
        $st['subs'][$sub_id],
        [
            'password_hash' => null,
            'public_url' => 'https://' . $host . '/p/' . $uuid_key,
            'sub_url' => 'https://' . $host . '/sub-group/' . $uuid_key,
        ]
    );
}

function handle_list_subs(): never
{
    require_auth();
    $host = base_url_host();
    $st = &state();
    $result = [];
    foreach ($st['subs'] as $sid => $s) {
        $link_ids = $s['link_ids'] ?? [];
        $active_count = 0;
        $total_used = 0;
        foreach ($link_ids as $lid) {
            $link = $st['links'][$lid] ?? null;
            if ($link) {
                if (is_link_allowed($link)) {
                    $active_count++;
                }
                $total_used += (int) ($link['used_bytes'] ?? 0);
            }
        }
        $result[] = array_merge(
            ['sub_id' => $sid],
            $s,
            [
                'password_hash' => null,
                'has_password' => ($s['password_hash'] ?? null) !== null,
                'links_count' => count($link_ids),
                'active_count' => $active_count,
                'total_used_bytes' => $total_used,
                'total_used_fmt' => fmt_bytes($total_used),
                'public_url' => 'https://' . $host . '/p/' . $s['uuid_key'],
                'sub_url' => 'https://' . $host . '/sub-group/' . $s['uuid_key'],
            ]
        );
    }
    usort($result, fn($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
    json_response(['subs' => $result]);
}

function handle_update_sub(string $sub_id): never
{
    require_auth();
    $body = request_json();
    $st = &state();
    if (!isset($st['subs'][$sub_id])) {
        json_response(['detail' => 'sub not found'], 404);
    }
    $s = &$st['subs'][$sub_id];
    if (array_key_exists('name', $body)) {
        $s['name'] = clean_public_label($body['name'], $sub_id);
    }
    if (array_key_exists('desc', $body)) {
        $s['desc'] = mb_substr((string) $body['desc'], 0, 200);
    }
    if (array_key_exists('password', $body)) {
        $pw = trim((string) $body['password']);
        $s['password_hash'] = $pw !== '' ? hash_password($pw) : null;
    }
    if (array_key_exists('link_ids', $body)) {
        $new_ids = [];
        foreach ((array) $body['link_ids'] as $lid) {
            $new_ids[] = (string) $lid;
        }
        $s['link_ids'] = $new_ids;
        // همگام‌سازی sub_id روی خود لینک‌ها
        foreach ($st['links'] as $lid => $l) {
            $in = in_array($lid, $new_ids, true);
            if ($in) {
                $st['links'][$lid]['sub_id'] = $sub_id;
            } elseif (($st['links'][$lid]['sub_id'] ?? null) === $sub_id) {
                $st['links'][$lid]['sub_id'] = null;
            }
        }
    }
    save_state();
    json_response(['ok' => true]);
}

function handle_delete_sub(string $sub_id): never
{
    require_auth();
    $st = &state();
    if (!isset($st['subs'][$sub_id])) {
        json_response(['detail' => 'sub not found'], 404);
    }
    $name = (string) ($st['subs'][$sub_id]['name'] ?? $sub_id);
    unset($st['subs'][$sub_id]);
    foreach ($st['links'] as $lid => $l) {
        if (($l['sub_id'] ?? null) === $sub_id) {
            $st['links'][$lid]['sub_id'] = null;
        }
    }
    save_state();
    log_activity('sub', "گروه «$name» حذف شد", 'warn');
    json_response(['ok' => true, 'deleted' => $sub_id]);
}

function handle_assign_link_to_sub(string $sub_id): never
{
    require_auth();
    $body = request_json();
    $link_id = (string) ($body['link_id'] ?? '');
    $action = (string) ($body['action'] ?? 'add');
    $st = &state();
    if (!isset($st['subs'][$sub_id])) {
        json_response(['detail' => 'sub not found'], 404);
    }
    $ids = $st['subs'][$sub_id]['link_ids'] ?? [];
    if ($action === 'add') {
        if (!in_array($link_id, $ids, true)) {
            $ids[] = $link_id;
        }
        if (isset($st['links'][$link_id])) {
            $st['links'][$link_id]['sub_id'] = $sub_id;
        }
    } else {
        $ids = array_values(array_diff($ids, [$link_id]));
        if (isset($st['links'][$link_id]) && ($st['links'][$link_id]['sub_id'] ?? null) === $sub_id) {
            $st['links'][$link_id]['sub_id'] = null;
        }
    }
    $st['subs'][$sub_id]['link_ids'] = $ids;
    save_state();
    json_response(['ok' => true]);
}

/** یافتن گروه بر اساس uuid_key عمومی */
function find_sub_by_key(string $uuid_key): ?array
{
    $st = &state();
    foreach ($st['subs'] as $sid => $s) {
        if (($s['uuid_key'] ?? '') === $uuid_key) {
            return array_merge(['sub_id' => $sid], $s);
        }
    }
    return null;
}
