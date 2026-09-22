<?php
/**
 * Reload JSON preview + suggested mapping for an existing pending import.
 */
include_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/JsonImporter.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(array $payload, int $code = 200)
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if (empty($_SESSION['id'])) {
    json_out(['success' => false, 'message' => 'Unauthorized'], 401);
}

$importId = (int) ($_GET['import_id'] ?? $_POST['import_id'] ?? 0);
if ($importId <= 0) {
    json_out(['success' => false, 'message' => 'Invalid import id.'], 400);
}

$stmt = $conn->prepare('SELECT * FROM lead_imports WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $importId);
$stmt->execute();
$import = $stmt->get_result()->fetch_assoc();
if (!$import) {
    json_out(['success' => false, 'message' => 'Import not found.'], 404);
}
if (($import['source'] ?? '') !== 'json') {
    json_out(['success' => false, 'message' => 'Not a JSON import.'], 400);
}
if (!in_array($import['status'], ['pending', 'failed'], true)) {
    json_out(['success' => false, 'message' => 'This import can no longer be remapped.'], 400);
}

$path = __DIR__ . '/../uploads/json/' . $import['stored_filename'];
$preview = JsonImporter::buildPreview($path, 5);
if (empty($preview['success'])) {
    json_out([
        'success' => false,
        'message' => $preview['message'] ?? 'Could not parse JSON file.',
    ], 422);
}

$savedMap = [];
if (!empty($import['column_mapping'])) {
    $decoded = json_decode((string) $import['column_mapping'], true);
    if (is_array($decoded)) {
        $savedMap = $decoded;
    }
}

$suggested = $preview['suggested'] ?? [];
if ($savedMap) {
    foreach ($suggested as $key => $_) {
        if (isset($savedMap[$key])) {
            $suggested[$key] = $savedMap[$key];
        }
    }
}

json_out([
    'success' => true,
    'message' => 'Preview ready.',
    'data' => [
        'id' => $importId,
        'file_name' => $import['original_filename'] ?: $import['filename'],
        'total_records' => (int) ($preview['total_records'] ?? $import['total_rows']),
        'status' => $import['status'],
        'source' => 'json',
        'keys' => $preview['keys'] ?? [],
        'suggested' => $suggested,
        'samples' => $preview['samples'] ?? [],
        'preview_rows' => $preview['preview_rows'] ?? [],
        'field_options' => $preview['field_options'] ?? LeadImporter::crmFieldLabels(),
    ],
]);
