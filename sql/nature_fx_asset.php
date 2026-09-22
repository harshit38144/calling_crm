<?php
/**
 * Upload / update / delete custom nature FX GIF & image sprites.
 */
include_once __DIR__ . '/../connection.php';
require_admin();

$wantsJson = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
    || (strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false)
);

function nature_fx_asset_out(array $payload, int $code = 200): void
{
    global $wantsJson;
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode($payload);
        exit;
    }
    set_flash($payload['message'] ?? 'Done.', !empty($payload['success']) ? 'success' : 'error');
    header('Location: ../settings.php#custom-sprites');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nature_fx_asset_out(['success' => false, 'message' => 'Invalid request.'], 400);
}

$action = (string) ($_POST['action'] ?? '');
$uploadDir = __DIR__ . '/../insects/custom/';
$allowedExt = ['gif', 'png', 'jpg', 'jpeg', 'webp'];
$maxBytes = 8 * 1024 * 1024; // 8 MB

function nature_fx_load_raw_list(): array
{
    $raw = (string) get_setting('nature_fx_custom_assets', '[]');
    $list = json_decode($raw, true);
    return is_array($list) ? $list : [];
}

function nature_fx_slug_id(string $name): string
{
    $base = strtolower(pathinfo($name, PATHINFO_FILENAME));
    $base = preg_replace('/[^a-z0-9_\-]+/', '-', $base) ?? 'sprite';
    $base = trim($base, '-_');
    if ($base === '') {
        $base = 'sprite';
    }
    return substr($base, 0, 40);
}

/* ---------- Upload new sprite ---------- */
if ($action === 'upload') {
    if (empty($_FILES['sprite_file']) || !is_array($_FILES['sprite_file'])) {
        nature_fx_asset_out(['success' => false, 'message' => 'Please choose an image or GIF file.'], 400);
    }

    $file = $_FILES['sprite_file'];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    $original = basename((string) ($file['name'] ?? ''));

    if ($error === UPLOAD_ERR_NO_FILE || $original === '') {
        nature_fx_asset_out(['success' => false, 'message' => 'Please choose an image or GIF file.'], 400);
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || $size > $maxBytes) {
        nature_fx_asset_out(['success' => false, 'message' => 'File is too large (max 8 MB).'], 400);
    }
    if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        nature_fx_asset_out(['success' => false, 'message' => 'Upload failed. Please try again.'], 400);
    }

    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        nature_fx_asset_out(['success' => false, 'message' => 'Allowed types: GIF, PNG, JPG, WEBP.'], 400);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $okMime = [
        'image/gif', 'image/png', 'image/jpeg', 'image/webp', 'image/jpg',
    ];
    if (!in_array($mime, $okMime, true)) {
        nature_fx_asset_out(['success' => false, 'message' => 'File must be a valid image or GIF.'], 400);
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true)) {
        nature_fx_asset_out(['success' => false, 'message' => 'Could not create insects/custom folder.'], 500);
    }

    $label = trim((string) ($_POST['sprite_label'] ?? ''));
    if ($label === '') {
        $label = pathinfo($original, PATHINFO_FILENAME);
    }
    $label = mb_substr($label, 0, 80);

    $idBase = nature_fx_slug_id($label !== '' ? $label : $original);
    $id = $idBase;
    $list = nature_fx_load_raw_list();
    $existingIds = [];
    foreach ($list as $row) {
        if (!empty($row['id'])) {
            $existingIds[(string) $row['id']] = true;
        }
    }
    $n = 2;
    while (isset($existingIds[$id])) {
        $id = $idBase . '_' . $n;
        $n++;
    }

    $motion = (string) ($_POST['sprite_motion'] ?? 'fly');
    if (!in_array($motion, ['fly', 'crawl', 'hop'], true)) {
        $motion = 'fly';
    }

    $direction = (string) ($_POST['sprite_direction'] ?? 'random');
    if (!isset(nature_fx_direction_options()[$direction])) {
        $direction = 'random';
    }

    $blend = (string) ($_POST['sprite_blend'] ?? 'normal');
    if (!in_array($blend, ['multiply', 'screen', 'normal'], true)) {
        $blend = 'normal';
    }

    $w = max(32, min(160, (int) ($_POST['sprite_w'] ?? 72)));
    $h = max(32, min(160, (int) ($_POST['sprite_h'] ?? 72)));

    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $dest = $uploadDir . $stored;
    if (!move_uploaded_file($tmp, $dest)) {
        nature_fx_asset_out(['success' => false, 'message' => 'Failed to save the uploaded file.'], 500);
    }

    // Prefer real pixel size when available
    $info = @getimagesize($dest);
    if (is_array($info) && !empty($info[0]) && !empty($info[1])) {
        $iw = (int) $info[0];
        $ih = (int) $info[1];
        // Cap display size while keeping aspect
        $maxSide = 96;
        if ($iw > $maxSide || $ih > $maxSide) {
            $scale = $maxSide / max($iw, $ih);
            $w = max(32, (int) round($iw * $scale));
            $h = max(32, (int) round($ih * $scale));
        } else {
            $w = max(32, $iw);
            $h = max(32, $ih);
        }
    }

    $list[] = [
        'id' => $id,
        'file' => $stored,
        'label' => $label,
        'motion' => $motion,
        'direction' => $direction,
        'blend' => $blend,
        'w' => $w,
        'h' => $h,
        'opacity' => 0.78,
        'weight' => 4,
        'zone' => $motion === 'hop' ? 'bottom' : '',
        'path' => '',
    ];

    if (!nature_fx_save_custom_assets($list)) {
        @unlink($dest);
        nature_fx_asset_out(['success' => false, 'message' => 'Saved file but could not update settings.'], 500);
    }

    nature_fx_asset_out([
        'success' => true,
        'message' => 'GIF/image added. It will animate across the CRM.',
        'asset' => [
            'id' => $id,
            'label' => $label,
            'src' => 'insects/custom/' . $stored,
            'motion' => $motion,
            'direction' => $direction,
        ],
    ]);
}

/* ---------- Update direction / motion / label / blend ---------- */
if ($action === 'update') {
    $id = preg_replace('/[^a-z0-9_\-]/i', '', (string) ($_POST['sprite_id'] ?? ''));
    if ($id === '') {
        nature_fx_asset_out(['success' => false, 'message' => 'Invalid sprite id.'], 400);
    }

    $list = nature_fx_load_raw_list();
    $foundCustom = false;
    foreach ($list as &$row) {
        if (($row['id'] ?? '') !== $id) {
            continue;
        }
        $foundCustom = true;
        if (isset($_POST['sprite_direction'])) {
            $d = nature_fx_normalize_direction((string) $_POST['sprite_direction']);
            $row['direction'] = $d;
        }
        if (isset($_POST['sprite_motion'])) {
            $m = (string) $_POST['sprite_motion'];
            if (in_array($m, ['fly', 'crawl', 'hop'], true)) {
                $row['motion'] = $m;
                if ($m === 'hop' && empty($row['zone'])) {
                    $row['zone'] = 'bottom';
                }
            }
        }
        if (isset($_POST['sprite_blend'])) {
            $b = (string) $_POST['sprite_blend'];
            if (in_array($b, ['multiply', 'screen', 'normal'], true)) {
                $row['blend'] = $b;
            }
        }
        if (isset($_POST['sprite_label'])) {
            $lbl = trim((string) $_POST['sprite_label']);
            if ($lbl !== '') {
                $row['label'] = mb_substr($lbl, 0, 80);
            }
        }
        break;
    }
    unset($row);

    if ($foundCustom) {
        if (!nature_fx_save_custom_assets($list)) {
            nature_fx_asset_out(['success' => false, 'message' => 'Could not save changes.'], 500);
        }
        nature_fx_asset_out(['success' => true, 'message' => 'Sprite updated.']);
    }

    // Built-in insect → store overrides
    $builtinIds = [];
    foreach (nature_fx_builtin_catalog() as $item) {
        $builtinIds[(string) $item['id']] = true;
    }
    if (!isset($builtinIds[$id])) {
        nature_fx_asset_out(['success' => false, 'message' => 'Sprite not found.'], 404);
    }

    $overrides = nature_fx_sprite_overrides();
    $entry = isset($overrides[$id]) && is_array($overrides[$id]) ? $overrides[$id] : [];
    if (isset($_POST['sprite_direction'])) {
        $entry['direction'] = nature_fx_normalize_direction((string) $_POST['sprite_direction']);
    }
    if (isset($_POST['sprite_motion'])) {
        $m = (string) $_POST['sprite_motion'];
        if (in_array($m, ['fly', 'crawl', 'hop'], true)) {
            $entry['motion'] = $m;
            if ($m === 'hop') {
                $entry['zone'] = 'bottom';
            }
        }
    }
    if (isset($_POST['sprite_blend'])) {
        $b = (string) $_POST['sprite_blend'];
        if (in_array($b, ['multiply', 'screen', 'normal'], true)) {
            $entry['blend'] = $b;
        }
    }
    if (isset($_POST['sprite_label'])) {
        $lbl = trim((string) $_POST['sprite_label']);
        if ($lbl !== '') {
            $entry['label'] = mb_substr($lbl, 0, 80);
        }
    }
    $overrides[$id] = $entry;
    if (!nature_fx_save_sprite_overrides($overrides)) {
        nature_fx_asset_out(['success' => false, 'message' => 'Could not save changes.'], 500);
    }
    nature_fx_asset_out(['success' => true, 'message' => 'Insect updated.']);
}

/* ---------- Delete (custom) or hide (built-in) ---------- */
if ($action === 'delete') {
    $id = preg_replace('/[^a-z0-9_\-]/i', '', (string) ($_POST['sprite_id'] ?? ''));
    if ($id === '') {
        nature_fx_asset_out(['success' => false, 'message' => 'Invalid sprite id.'], 400);
    }

    $list = nature_fx_load_raw_list();
    $kept = [];
    $removedFile = '';
    foreach ($list as $row) {
        if (($row['id'] ?? '') === $id) {
            $removedFile = basename((string) ($row['file'] ?? ''));
            continue;
        }
        $kept[] = $row;
    }

    if ($removedFile !== '' || count($kept) < count($list)) {
        if (!nature_fx_save_custom_assets($kept)) {
            nature_fx_asset_out(['success' => false, 'message' => 'Could not delete sprite.'], 500);
        }
        if ($removedFile !== '') {
            $path = $uploadDir . $removedFile;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        nature_fx_asset_out(['success' => true, 'message' => 'Custom sprite removed.']);
    }

    // Built-in → disable (hide) instead of deleting the file
    $builtinIds = [];
    foreach (nature_fx_builtin_catalog() as $item) {
        $builtinIds[(string) $item['id']] = true;
    }
    if (!isset($builtinIds[$id])) {
        nature_fx_asset_out(['success' => false, 'message' => 'Sprite not found.'], 404);
    }

    $disabled = array_keys(nature_fx_disabled_ids());
    if (!in_array($id, $disabled, true)) {
        $disabled[] = $id;
    }
    if (!nature_fx_save_disabled_ids($disabled)) {
        nature_fx_asset_out(['success' => false, 'message' => 'Could not hide insect.'], 500);
    }
    nature_fx_asset_out([
        'success' => true,
        'message' => 'Insect hidden. You can restore it from Hidden insects.',
        'hidden' => true,
    ]);
}

/* ---------- Restore a hidden built-in ---------- */
if ($action === 'restore') {
    $id = preg_replace('/[^a-z0-9_\-]/i', '', (string) ($_POST['sprite_id'] ?? ''));
    if ($id === '') {
        nature_fx_asset_out(['success' => false, 'message' => 'Invalid sprite id.'], 400);
    }

    $disabled = array_keys(nature_fx_disabled_ids());
    $disabled = array_values(array_filter($disabled, static function ($x) use ($id) {
        return $x !== $id;
    }));
    if (!nature_fx_save_disabled_ids($disabled)) {
        nature_fx_asset_out(['success' => false, 'message' => 'Could not restore insect.'], 500);
    }
    nature_fx_asset_out(['success' => true, 'message' => 'Insect restored.']);
}

nature_fx_asset_out(['success' => false, 'message' => 'Unknown action.'], 400);
