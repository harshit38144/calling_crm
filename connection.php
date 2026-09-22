<?php
if (isset($_SERVER['HTTP_HOST']) && ($_SERVER['HTTP_HOST'] == 'localhost:8081' || $_SERVER['HTTP_HOST'] == 'localhost')) {
    $conn = new mysqli('localhost', 'root', '', 'calling_crm');
} else {
    // Update these credentials for production hosting
    $conn = new mysqli('localhost', 'root', '', 'calling_crm');
}

if ($conn->connect_errno) {
    die('Database connection failed. Please import database/schema.sql first.');
}

$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Kolkata');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_login()
{
    if (empty($_SESSION['id'])) {
        header('Location: index.php');
        exit;
    }
}

function require_admin()
{
    require_login();
    $role = $_SESSION['role'] ?? '';
    // Allow admin/manager/agent (and legacy numeric role "1")
    $allowed = ['admin', 'manager', 'agent', '1'];
    if (!in_array((string) $role, $allowed, true)) {
        header('Location: index.php');
        exit;
    }
}

function flash_msg()
{
    if (!empty($_SESSION['msg'])) {
        $msg = $_SESSION['msg'];
        unset($_SESSION['msg']);
        return $msg;
    }
    return '';
}

function flash_type()
{
    if (!empty($_SESSION['msg_type'])) {
        $type = $_SESSION['msg_type'];
        unset($_SESSION['msg_type']);
        return $type;
    }
    return 'success';
}

/** Max Excel upload size in bytes (10 MB). */
function excel_max_upload_bytes()
{
    return 10 * 1024 * 1024;
}

function excel_allowed_extensions()
{
    return ['xls', 'xlsx'];
}

function lead_statuses(): array
{
    return [
        'new' => 'New',
        'not_contacted' => 'Not Contacted',
        'attempted' => 'Attempted',
        'connected' => 'Connected',
        'interested' => 'Interested',
        'callback' => 'Callback',
        'follow_up_required' => 'Follow-up Required',
        'proposal_sent' => 'Proposal Sent',
        'converted' => 'Converted',
        'wrong_number' => 'Wrong Number',
        'duplicate' => 'Duplicate',
        'not_interested' => 'Not Interested',
        'closed' => 'Closed',
    ];
}

function lead_priorities(): array
{
    return [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];
}

function lead_status_badge(string $status): string
{
    $map = [
        'new' => 'new',
        'not_contacted' => 'new',
        'attempted' => 'contacted',
        'connected' => 'contacted',
        'interested' => 'contacted',
        'callback' => 'follow',
        'follow_up_required' => 'follow',
        'proposal_sent' => 'follow',
        'converted' => 'converted',
        'wrong_number' => 'lost',
        'duplicate' => 'lost',
        'not_interested' => 'lost',
        'closed' => 'lost',
    ];
    $labels = lead_statuses();
    $cls = $map[$status] ?? 'new';
    $label = $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    return '<span class="crm-status-pill crm-status-' . $cls . '">' . htmlspecialchars($label) . '</span>';
}

function call_result_statuses(): array
{
    return [
        'connected' => 'Connected',
        'no_answer' => 'No Answer',
        'busy' => 'Busy',
        'wrong_number' => 'Wrong Number',
        'switched_off' => 'Switched Off',
        'voicemail' => 'Voicemail',
        'failed' => 'Failed',
    ];
}

function set_flash(string $msg, string $type = 'success'): void
{
    $_SESSION['msg'] = $msg;
    $_SESSION['msg_type'] = $type;
}

function json_response(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

/**
 * Read a single setting value (with optional default).
 *
 * @param mixed $default
 * @param bool $refresh Force a DB re-read and refresh the static cache.
 */
function get_setting(string $key, $default = null, bool $refresh = false)
{
    global $conn;
    static $cache = [];

    if (!$refresh && array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $conn->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    $cache[$key] = $row ? $row['setting_value'] : $default;
    return $cache[$key];
}

/**
 * Upsert a setting value.
 */
function set_setting(string $key, string $value, string $group = 'general'): bool
{
    global $conn;
    $stmt = $conn->prepare(
        'INSERT INTO settings (setting_key, setting_value, setting_group)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = VALUES(setting_group)'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('sss', $key, $value, $group);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        get_setting($key, $value, true);
        nature_fx_config(true);
    }
    return $ok;
}

/** Allowed nature animation type keys. */
function nature_fx_type_options(): array
{
    return [
        'butterflies' => 'Butterflies',
        'fireflies' => 'Fireflies',
        'leaves' => 'Leaves',
        'feathers' => 'Feathers',
        'birds' => 'Birds',
        'insects' => 'Insects (GIFs & images from /insects)',
    ];
}

/**
 * Direction options for custom GIF/image animations.
 * from = enter from left (LTR), to = enter from right (RTL), random = either side.
 *
 * @return array<string, string>
 */
function nature_fx_direction_options(): array
{
    return [
        'from' => 'From left → right',
        'to' => 'From right → left',
        'random' => 'Random direction',
    ];
}

/** Normalize legacy ltr/rtl and UI from/to/random into from|to|random. */
function nature_fx_normalize_direction(string $direction): string
{
    $d = strtolower(trim($direction));
    if ($d === 'ltr' || $d === 'from') {
        return 'from';
    }
    if ($d === 'rtl' || $d === 'to') {
        return 'to';
    }
    return 'random';
}

/**
 * Built-in insect catalog (files under insects/sprites/).
 *
 * @return array<int, array<string, mixed>>
 */
function nature_fx_builtin_catalog(): array
{
    return [
        ['file' => 'mosquito.gif',        'id' => 'mosquito',     'motion' => 'fly',   'blend' => 'multiply', 'w' => 72,  'h' => 56,  'opacity' => 0.72, 'weight' => 3],
        ['file' => 'mosquito-cute.gif',   'id' => 'mosquito2',    'motion' => 'fly',   'blend' => 'multiply', 'w' => 64,  'h' => 64,  'opacity' => 0.65, 'weight' => 2, 'direction' => 'rtl'],
        ['file' => 'dragonfly-cute.gif',  'id' => 'bug-fly',      'motion' => 'fly',   'blend' => 'multiply', 'w' => 70,  'h' => 70,  'opacity' => 0.7,  'weight' => 2, 'direction' => 'rtl'],
        ['file' => 'grasshopper.gif',     'id' => 'grasshopper',  'motion' => 'hop',   'blend' => 'multiply', 'w' => 80,  'h' => 56,  'opacity' => 0.75, 'weight' => 3],
        ['file' => 'grasshopper-jump.gif','id' => 'hopper-jump',  'motion' => 'hop',   'blend' => 'multiply', 'w' => 90,  'h' => 60,  'opacity' => 0.72, 'weight' => 2, 'direction' => 'rtl', 'zone' => 'bottom'],
        ['file' => 'cricket.gif',         'id' => 'cricket',      'motion' => 'hop',   'blend' => 'multiply', 'w' => 110, 'h' => 88,  'opacity' => 0.78, 'weight' => 2, 'direction' => 'rtl', 'zone' => 'bottom'],
        ['file' => 'ladybug.png',         'id' => 'ladybug',      'motion' => 'crawl', 'blend' => 'screen',   'w' => 52,  'h' => 52,  'opacity' => 0.85, 'weight' => 3, 'direction' => 'ltr', 'path' => 'diag-tl-br'],
        ['file' => 'beetle-walk.gif',     'id' => 'beetle',       'motion' => 'crawl', 'blend' => 'multiply', 'w' => 58,  'h' => 48,  'opacity' => 0.7,  'weight' => 2, 'direction' => 'rtl'],
    ];
}

/** @return array<string, true> */
function nature_fx_disabled_ids(): array
{
    $raw = (string) get_setting('nature_fx_disabled_sprites', '[]');
    $list = json_decode($raw, true);
    if (!is_array($list)) {
        return [];
    }
    $out = [];
    foreach ($list as $id) {
        $id = preg_replace('/[^a-z0-9_\-]/i', '', (string) $id);
        if ($id !== '') {
            $out[$id] = true;
        }
    }
    return $out;
}

/** @param array<int, string> $ids */
function nature_fx_save_disabled_ids(array $ids): bool
{
    $clean = [];
    foreach ($ids as $id) {
        $id = preg_replace('/[^a-z0-9_\-]/i', '', (string) $id);
        if ($id !== '') {
            $clean[] = $id;
        }
    }
    $clean = array_values(array_unique($clean));
    return set_setting(
        'nature_fx_disabled_sprites',
        json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'nature_fx'
    );
}

/**
 * Per-sprite overrides for built-in insects (direction, motion, blend, label…).
 *
 * @return array<string, array<string, mixed>>
 */
function nature_fx_sprite_overrides(): array
{
    $raw = (string) get_setting('nature_fx_sprite_overrides', '{}');
    $map = json_decode($raw, true);
    return is_array($map) ? $map : [];
}

/** @param array<string, array<string, mixed>> $map */
function nature_fx_save_sprite_overrides(array $map): bool
{
    $clean = [];
    foreach ($map as $id => $row) {
        $id = preg_replace('/[^a-z0-9_\-]/i', '', (string) $id);
        if ($id === '' || !is_array($row)) {
            continue;
        }
        $entry = [];
        if (isset($row['label'])) {
            $entry['label'] = mb_substr(trim((string) $row['label']), 0, 80);
        }
        if (isset($row['direction'])) {
            $entry['direction'] = nature_fx_normalize_direction((string) $row['direction']);
        }
        if (isset($row['motion']) && in_array($row['motion'], ['fly', 'crawl', 'hop'], true)) {
            $entry['motion'] = $row['motion'];
        }
        if (isset($row['blend']) && in_array($row['blend'], ['multiply', 'screen', 'normal'], true)) {
            $entry['blend'] = $row['blend'];
        }
        if (isset($row['weight'])) {
            $entry['weight'] = max(1, min(10, (int) $row['weight']));
        }
        if (isset($row['zone'])) {
            $entry['zone'] = (string) $row['zone'];
        }
        if ($entry) {
            $clean[$id] = $entry;
        }
    }
    return set_setting(
        'nature_fx_sprite_overrides',
        json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'nature_fx'
    );
}

/**
 * Curated sprite list from insects/sprites, with overrides + disabled filter.
 * blend: multiply removes white BG; screen removes black BG on light UI.
 * motion: fly | crawl | hop
 *
 * @param bool $includeDisabled When true, also return hidden built-ins (for Settings UI).
 * @return array<int, array<string, mixed>>
 */
function nature_fx_sprite_assets(bool $includeDisabled = false): array
{
    $base = 'insects/sprites/';
    $catalog = nature_fx_builtin_catalog();
    $disabled = nature_fx_disabled_ids();
    $overrides = nature_fx_sprite_overrides();

    $root = __DIR__ . DIRECTORY_SEPARATOR;
    $out = [];
    foreach ($catalog as $item) {
        $id = (string) $item['id'];
        $isDisabled = isset($disabled[$id]);
        if ($isDisabled && !$includeDisabled) {
            continue;
        }

        $path = $root . str_replace('/', DIRECTORY_SEPARATOR, $base . $item['file']);
        if (!is_file($path)) {
            continue;
        }

        $ov = isset($overrides[$id]) && is_array($overrides[$id]) ? $overrides[$id] : [];
        $motion = (string) ($ov['motion'] ?? $item['motion']);
        if (!in_array($motion, ['fly', 'crawl', 'hop'], true)) {
            $motion = 'fly';
        }
        $blend = (string) ($ov['blend'] ?? $item['blend']);
        if (!in_array($blend, ['multiply', 'screen', 'normal'], true)) {
            $blend = 'multiply';
        }
        $direction = nature_fx_normalize_direction((string) ($ov['direction'] ?? ($item['direction'] ?? 'random')));
        $label = trim((string) ($ov['label'] ?? ''));
        if ($label === '') {
            $label = $id;
        }

        $out[] = [
            'src' => $base . $item['file'] . '?v=' . (int) (@filemtime($path) ?: time()),
            'id' => $id,
            'label' => $label,
            'motion' => $motion,
            'blend' => $blend,
            'w' => $item['w'],
            'h' => $item['h'],
            'opacity' => $item['opacity'],
            'weight' => max(1, min(10, (int) ($ov['weight'] ?? $item['weight']))),
            'direction' => $direction,
            'zone' => (string) ($ov['zone'] ?? ($item['zone'] ?? '')),
            'path' => (string) ($item['path'] ?? ''),
            'custom' => false,
            'builtin' => true,
            'disabled' => $isDisabled,
            'file' => $item['file'],
        ];
    }
    return $out;
}

/**
 * User-uploaded GIF/image sprites stored in settings + insects/custom/.
 *
 * @return array<int, array<string, mixed>>
 */
function nature_fx_custom_assets(): array
{
    $raw = (string) get_setting('nature_fx_custom_assets', '[]');
    $list = json_decode($raw, true);
    if (!is_array($list)) {
        return [];
    }

    $root = __DIR__ . DIRECTORY_SEPARATOR;
    $out = [];
    foreach ($list as $item) {
        if (!is_array($item) || empty($item['file']) || empty($item['id'])) {
            continue;
        }
        $file = basename((string) $item['file']);
        $rel = 'insects/custom/' . $file;
        $path = $root . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($path)) {
            continue;
        }

        $direction = (string) ($item['direction'] ?? 'random');
        if (!isset(nature_fx_direction_options()[$direction])) {
            $direction = 'random';
        }

        $motion = (string) ($item['motion'] ?? 'fly');
        if (!in_array($motion, ['fly', 'crawl', 'hop'], true)) {
            $motion = 'fly';
        }

        $blend = (string) ($item['blend'] ?? 'normal');
        if (!in_array($blend, ['multiply', 'screen', 'normal'], true)) {
            $blend = 'normal';
        }

        $out[] = [
            'src' => $rel . '?v=' . (int) (@filemtime($path) ?: time()),
            'id' => preg_replace('/[^a-z0-9_\-]/i', '', (string) $item['id']) ?: ('custom_' . substr(md5($file), 0, 8)),
            'label' => (string) ($item['label'] ?? $item['id']),
            'motion' => $motion,
            'blend' => $blend,
            'w' => max(24, min(200, (int) ($item['w'] ?? 72))),
            'h' => max(24, min(200, (int) ($item['h'] ?? 72))),
            'opacity' => max(0.3, min(1, (float) ($item['opacity'] ?? 0.75))),
            'weight' => max(1, min(10, (int) ($item['weight'] ?? 3))),
            'direction' => $direction,
            'zone' => (string) ($item['zone'] ?? ''),
            'path' => (string) ($item['path'] ?? ''),
            'custom' => true,
            'file' => $file,
        ];
    }
    return $out;
}

/**
 * Persist custom asset list JSON.
 *
 * @param array<int, array<string, mixed>> $list
 */
function nature_fx_save_custom_assets(array $list): bool
{
    $clean = [];
    foreach ($list as $item) {
        if (!is_array($item) || empty($item['file']) || empty($item['id'])) {
            continue;
        }
        $clean[] = [
            'id' => (string) $item['id'],
            'file' => basename((string) $item['file']),
            'label' => (string) ($item['label'] ?? $item['id']),
            'motion' => (string) ($item['motion'] ?? 'fly'),
            'direction' => (string) ($item['direction'] ?? 'random'),
            'blend' => (string) ($item['blend'] ?? 'normal'),
            'w' => (int) ($item['w'] ?? 72),
            'h' => (int) ($item['h'] ?? 72),
            'opacity' => (float) ($item['opacity'] ?? 0.75),
            'weight' => (int) ($item['weight'] ?? 3),
            'zone' => (string) ($item['zone'] ?? ''),
            'path' => (string) ($item['path'] ?? ''),
        ];
    }
    return set_setting(
        'nature_fx_custom_assets',
        json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'nature_fx'
    );
}

/**
 * Resolved config for the ambient nature FX engine (safe defaults).
 *
 * @param bool $reset Pass true to clear the static cache (e.g. after saving settings).
 */
function nature_fx_config(bool $reset = false): array
{
    static $cfg = null;
    if ($reset) {
        $cfg = null;
        return [];
    }
    if ($cfg !== null) {
        return $cfg;
    }

    $enabled = get_setting('nature_fx_enabled', '1') === '1';
    $freq = get_setting('nature_fx_frequency', 'medium');
    if (!in_array($freq, ['low', 'medium', 'high'], true)) {
        $freq = 'medium';
    }

    $typesRaw = (string) get_setting('nature_fx_types', 'all');
    $allowed = array_keys(nature_fx_type_options());
    if ($typesRaw === 'all' || $typesRaw === '') {
        $types = $allowed;
    } else {
        $picked = array_values(array_filter(array_map('trim', explode(',', $typesRaw))));
        $types = array_values(array_intersect($picked, $allowed));
        if (empty($types)) {
            $types = $allowed;
        }
    }

    $cfg = [
        'enabled' => $enabled,
        'frequency' => $freq,
        'types' => $types,
        'assets' => array_merge(nature_fx_sprite_assets(false), nature_fx_custom_assets()),
        'custom_assets' => nature_fx_custom_assets(),
        'disabled_assets' => array_values(array_filter(
            nature_fx_sprite_assets(true),
            static function ($a) {
                return !empty($a['disabled']);
            }
        )),
    ];
    return $cfg;
}
