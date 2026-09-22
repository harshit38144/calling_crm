<?php
/**
 * AJAX Excel upload — saves file + lead_imports row only (no lead import yet).
 */
include_once __DIR__ . '/../connection.php';

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['upload_excel'])) {
    json_out(['success' => false, 'message' => 'Invalid request.'], 400);
}

if (empty($_FILES['excel_file']) || !is_array($_FILES['excel_file'])) {
    json_out(['success' => false, 'message' => 'No file selected. Please choose an Excel file.'], 400);
}

$file = $_FILES['excel_file'];
$error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
$tmp = (string) ($file['tmp_name'] ?? '');
$size = (int) ($file['size'] ?? 0);
$original = basename((string) ($file['name'] ?? ''));

if ($error === UPLOAD_ERR_NO_FILE || $original === '') {
    json_out(['success' => false, 'message' => 'No file selected. Please choose an Excel file.'], 400);
}

if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
    json_out(['success' => false, 'message' => 'File is too large for the server upload limit.'], 400);
}

if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
    json_out(['success' => false, 'message' => 'Upload failed. Please try again.'], 400);
}

$ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
if (!in_array($ext, excel_allowed_extensions(), true)) {
    json_out([
        'success' => false,
        'message' => 'Invalid file type. Only .xls and .xlsx files are allowed.',
    ], 400);
}

$maxBytes = excel_max_upload_bytes();
if ($size <= 0) {
    json_out(['success' => false, 'message' => 'The uploaded file is empty.'], 400);
}
if ($size > $maxBytes) {
    $maxMb = (int) ($maxBytes / (1024 * 1024));
    json_out([
        'success' => false,
        'message' => "File size exceeds the {$maxMb} MB limit.",
    ], 400);
}

$hash = hash_file('sha256', $tmp);
if ($hash === false) {
    json_out(['success' => false, 'message' => 'Could not read the uploaded file.'], 500);
}

// Duplicate by content hash
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

$uploadDir = __DIR__ . '/../uploads/excel/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true)) {
    json_out(['success' => false, 'message' => 'Upload directory is not writable.'], 500);
}

$stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest = $uploadDir . $stored;

if (!move_uploaded_file($tmp, $dest)) {
    json_out(['success' => false, 'message' => 'Failed to save the uploaded file.'], 500);
}

$userId = (int) $_SESSION['id'];
$source = 'excel';
$status = 'pending';
$totalRows = 0;
$remarks = 'File uploaded successfully. Lead import pending.';
$notes = 'Excel upload only — leads not imported yet.';

$stmt = $conn->prepare(
    'INSERT INTO lead_imports
      (imported_by, filename, original_filename, stored_filename, file_hash, file_size,
       source, total_rows, status, notes, remarks)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param(
    'issssisisss',
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
    $remarks
);

if (!$stmt->execute()) {
    @unlink($dest);
    // Unique hash race
    if ($conn->errno === 1062) {
        json_out([
            'success' => false,
            'message' => 'Duplicate import blocked. This file was already uploaded.',
        ], 409);
    }
    json_out(['success' => false, 'message' => 'Database error while saving upload history.'], 500);
}

$importId = (int) $conn->insert_id;

// Auto-import leads from the uploaded Excel
require_once __DIR__ . '/LeadImporter.php';
$importer = new LeadImporter($conn);
$importResult = $importer->processImport($importId);

$finalStatus = $importResult['status'] ?? 'pending';
$totalRows = (int) ($importResult['total'] ?? 0);
$remarks = $importResult['message'] ?? 'File uploaded.';

$ok = !empty($importResult['success']) || $finalStatus === 'completed';

json_out([
    'success' => $ok,
    'message' => $ok
        ? ('Excel uploaded and leads imported. ' . $remarks)
        : ('Excel uploaded but lead import had issues. ' . $remarks),
    'data' => [
        'id' => $importId,
        'file_name' => $original,
        'uploaded_by' => $_SESSION['name'] ?? $_SESSION['username'] ?? 'User',
        'upload_date' => date('Y-m-d H:i:s'),
        'total_records' => $totalRows,
        'success_count' => (int) ($importResult['success_count'] ?? 0),
        'failed_count' => (int) ($importResult['failed_count'] ?? 0),
        'duplicate_count' => (int) ($importResult['duplicate_count'] ?? 0),
        'status' => $finalStatus,
        'remarks' => $remarks,
        'file_size' => $size,
    ],
], $ok ? 200 : 422);
