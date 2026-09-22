<?php
/**
 * Confirm JSON column mapping and import leads.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Invalid request.'], 400);
}

$importId = (int) ($_POST['import_id'] ?? 0);
if ($importId <= 0) {
    json_out(['success' => false, 'message' => 'Invalid import id.'], 400);
}

$mappingRaw = $_POST['mapping'] ?? '';
if (is_array($mappingRaw)) {
    $mapping = $mappingRaw;
} else {
    $mapping = json_decode((string) $mappingRaw, true);
}
if (!is_array($mapping) || !$mapping) {
    json_out(['success' => false, 'message' => 'Column mapping is required.'], 400);
}

$importer = new JsonImporter($conn);
$result = $importer->processImport($importId, $mapping);

$ok = !empty($result['success']);
json_out([
    'success' => $ok,
    'message' => $result['message'] ?? ($ok ? 'Import completed.' : 'Import failed.'),
    'data' => [
        'id' => $importId,
        'total_records' => (int) ($result['total'] ?? 0),
        'success_count' => (int) ($result['success_count'] ?? 0),
        'failed_count' => (int) ($result['failed_count'] ?? 0),
        'duplicate_count' => (int) ($result['duplicate_count'] ?? 0),
        'status' => $result['status'] ?? ($ok ? 'completed' : 'failed'),
        'remarks' => $result['message'] ?? '',
    ],
], $ok ? 200 : 422);
