<?php
/**
 * AJAX JSON upload — saves file, creates lead_imports row, returns preview + suggested mapping.
 * Does NOT import leads until mapping is confirmed via json_import.php.
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
    json_out(['success' => false, 'message' => 'Please login to upload files.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['upload_json'])) {
    json_out(['success' => false, 'message' => 'Invalid request.'], 400);
}

if (empty($_FILES['json_file']) || !is_array($_FILES['json_file'])) {
    json_out(['success' => false, 'message' => 'No file selected. Please choose a JSON file.'], 400);
}

$file = $_FILES['json_file'];
$error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
$tmp = (string) ($file['tmp_name'] ?? '');
$size = (int) ($file['size'] ?? 0);
$original = basename((string) ($file['name'] ?? ''));

if ($error === UPLOAD_ERR_NO_FILE || $original === '') {
    json_out(['success' => false, 'message' => 'No file selected. Please choose a JSON file.'], 400);
}
if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
    json_out(['success' => false, 'message' => 'File is too large for the server upload limit.'], 400);
}
if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
    json_out(['success' => false, 'message' => 'Upload failed. Please try again.'], 400);
}

$ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
if ($ext !== 'json') {
    json_out(['success' => false, 'message' => 'Invalid file type. Only .json files are allowed.'], 400);
}

$maxBytes = excel_max_upload_bytes();
if ($size <= 0) {
    json_out(['success' => false, 'message' => 'The uploaded file is empty.'], 400);
}
if ($size > $maxBytes) {
    $maxMb = (int) ($maxBytes / (1024 * 1024));
    json_out(['success' => false, 'message' => "File size exceeds the {$maxMb} MB limit."], 400);
}

$hash = hash_file('sha256', $tmp);
if ($hash === false) {
    json_out(['success' => false, 'message' => 'Could not read the uploaded file.'], 500);
}

$dupStmt = $conn->prepare(
    'SELECT id, original_filename, created_at FROM lead_imports WHERE file_hash = ? LIMIT 1'
);
$dupStmt->bind_param('s', $hash);
$dupStmt->execute();
$dup = $dupStmt->get_result()->fetch_assoc();
if ($dup) {
    json_out([
        'success' => false,
        'message' => 'Duplicate import blocked. This file was already uploaded'
            . ' as "' . $dup['original_filename'] . '" on ' . $dup['created_at'] . '.',
    ], 409);
}

$uploadDir = __DIR__ . '/../uploads/json/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true)) {
    json_out(['success' => false, 'message' => 'Upload directory is not writable.'], 500);
}

$stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
$dest = $uploadDir . $stored;
if (!move_uploaded_file($tmp, $dest)) {
    json_out(['success' => false, 'message' => 'Failed to save the uploaded file.'], 500);
}

$preview = JsonImporter::buildPreview($dest, 5);
if (empty($preview['success'])) {
    @unlink($dest);
    json_out([
        'success' => false,
        'message' => $preview['message'] ?? 'Could not parse JSON file.',
    ], 422);
}

$userId = (int) $_SESSION['id'];
$source = 'json';
$status = 'pending';
$totalRows = (int) ($preview['total_records'] ?? 0);
$remarks = 'JSON uploaded. Map columns and confirm import.';
$notes = 'JSON upload — awaiting column mapping.';
$suggestedJson = json_encode($preview['suggested'] ?? [], JSON_UNESCAPED_UNICODE);

$stmt = $conn->prepare(
    'INSERT INTO lead_imports
      (imported_by, filename, original_filename, stored_filename, file_hash, file_size,
       source, total_rows, status, notes, remarks, column_mapping)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param(
    'issssisissss',
    $userId,
    $original,
    $original,
    $stored,
    $hash,
    $size,
    $source,
    $totalRows,
    $status,
    $notes,
    $remarks,
    $suggestedJson
);

if (!$stmt->execute()) {
    @unlink($dest);
    if ($conn->errno === 1062) {
        json_out([
            'success' => false,
            'message' => 'Duplicate import blocked. This file was already uploaded.',
        ], 409);
    }
    json_out(['success' => false, 'message' => 'Database error while saving upload history.'], 500);
}

$importId = (int) $conn->insert_id;

json_out([
    'success' => true,
    'message' => 'JSON uploaded. Map fields below, then import.',
    'data' => [
        'id' => $importId,
        'file_name' => $original,
        'uploaded_by' => $_SESSION['name'] ?? $_SESSION['username'] ?? 'User',
        'upload_date' => date('Y-m-d H:i:s'),
        'total_records' => $totalRows,
        'status' => $status,
        'source' => 'json',
        'keys' => $preview['keys'] ?? [],
        'suggested' => $preview['suggested'] ?? [],
        'samples' => $preview['samples'] ?? [],
        'preview_rows' => $preview['preview_rows'] ?? [],
        'field_options' => $preview['field_options'] ?? LeadImporter::crmFieldLabels(),
    ],
]);
