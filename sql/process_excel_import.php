<?php
/**
 * AJAX: process a pending Excel import into leads.
 */
include_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/LeadImporter.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(array $payload, int $code = 200)
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if (empty($_SESSION['id'])) {
    json_out(['success' => false, 'message' => 'Please login.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Invalid request.'], 400);
}

$importId = (int) ($_POST['import_id'] ?? 0);
if ($importId <= 0) {
    json_out(['success' => false, 'message' => 'Invalid import id.'], 400);
}

$importer = new LeadImporter($conn);
$result = $importer->processImport($importId);

json_out($result, $result['success'] ? 200 : 400);
