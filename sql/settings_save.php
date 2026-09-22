<?php
/**
 * Persist ambient nature FX settings.
 */
include_once __DIR__ . '/../connection.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['save_nature_fx'])) {
    header('Location: ../settings.php');
    exit;
}

$enabled = !empty($_POST['nature_fx_enabled']) ? '1' : '0';

$freq = (string) ($_POST['nature_fx_frequency'] ?? 'medium');
if (!in_array($freq, ['low', 'medium', 'high'], true)) {
    $freq = 'medium';
}

$allowed = array_keys(nature_fx_type_options());
$posted = $_POST['nature_fx_types'] ?? [];
if (!is_array($posted)) {
    $posted = [];
}
$picked = array_values(array_intersect(array_map('strval', $posted), $allowed));

if (count($picked) === 0) {
    $typesValue = 'all';
} elseif (count($picked) === count($allowed)) {
    $typesValue = 'all';
} else {
    $typesValue = implode(',', $picked);
}

$ok = set_setting('nature_fx_enabled', $enabled, 'nature_fx')
    && set_setting('nature_fx_frequency', $freq, 'nature_fx')
    && set_setting('nature_fx_types', $typesValue, 'nature_fx');

if ($ok) {
    set_flash('Nature animation settings saved.', 'success');
} else {
    set_flash('Could not save settings. Please try again.', 'error');
}

header('Location: ../settings.php');
exit;
