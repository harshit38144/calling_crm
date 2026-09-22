<?php
/**
 * Log a call against a lead and update lead status / follow-up.
 */
include_once __DIR__ . '/../connection.php';

if (empty($_SESSION['id'])) {
    json_response(['success' => false, 'message' => 'Unauthorized'], 401);
}

$action = $_POST['action'] ?? '';
$userId = (int) $_SESSION['id'];

if ($action !== 'log_call') {
    json_response(['success' => false, 'message' => 'Unknown action.'], 400);
}

$leadId = (int) ($_POST['lead_id'] ?? 0);
if ($leadId <= 0) {
    json_response(['success' => false, 'message' => 'Invalid lead.'], 400);
}

$lead = $conn->query("SELECT * FROM leads WHERE id = {$leadId} AND is_deleted = 0 LIMIT 1")->fetch_assoc();
if (!$lead) {
    json_response(['success' => false, 'message' => 'Lead not found.'], 404);
}

$phone = trim((string) ($_POST['phone_dialed'] ?? $lead['phone']));
$callStatus = trim((string) ($_POST['call_status'] ?? 'no_answer'));
$leadStatus = trim((string) ($_POST['lead_status'] ?? $lead['status']));
$notes = trim((string) ($_POST['notes'] ?? ''));
$remarks = trim((string) ($_POST['remarks'] ?? ''));
$calledAt = trim((string) ($_POST['called_at'] ?? ''));
$nextFollowup = trim((string) ($_POST['next_followup_at'] ?? ''));
$executiveId = (int) ($_POST['executive_id'] ?? $userId);

$durationMin = max(0, (int) ($_POST['duration_min'] ?? 0));
$durationSec = max(0, (int) ($_POST['duration_sec'] ?? 0));
if ($durationSec > 59) {
    $durationSec = 59;
}
$durationSeconds = ($durationMin * 60) + $durationSec;

// Also accept duration_seconds directly
if (isset($_POST['duration_seconds']) && $_POST['duration_seconds'] !== '') {
    $durationSeconds = max(0, (int) $_POST['duration_seconds']);
}

if (!array_key_exists($callStatus, call_result_statuses())) {
    $callStatus = 'no_answer';
}
if (!array_key_exists($leadStatus, lead_statuses())) {
    $leadStatus = $lead['status'];
}

if ($executiveId <= 0) {
    $executiveId = $userId;
}
$execOk = $conn->query("SELECT id FROM users WHERE id = {$executiveId} AND status = 'active' LIMIT 1");
if (!$execOk || !$execOk->fetch_assoc()) {
    $executiveId = $userId;
}

if ($calledAt === '') {
    $calledAt = date('Y-m-d H:i:s');
} else {
    $ts = strtotime($calledAt);
    $calledAt = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
}

$nextFollowupSql = 'NULL';
$nextFollowupVal = null;
if ($nextFollowup !== '') {
    $fts = strtotime($nextFollowup);
    if ($fts) {
        $nextFollowupVal = date('Y-m-d H:i:s', $fts);
        $nextFollowupSql = "'" . $conn->real_escape_string($nextFollowupVal) . "'";
    }
}

$phoneEsc = $conn->real_escape_string($phone);
$notesEsc = $conn->real_escape_string($notes);
$remarksEsc = $conn->real_escape_string($remarks);
$callStatusEsc = $conn->real_escape_string($callStatus);
$leadStatusEsc = $conn->real_escape_string($leadStatus);
$calledAtEsc = $conn->real_escape_string($calledAt);

$sql = "INSERT INTO call_logs
        (lead_id, user_id, phone_dialed, call_type, call_status, duration_seconds,
         notes, remarks, next_followup_at, lead_status_after, called_at)
        VALUES (
          {$leadId}, {$executiveId}, '{$phoneEsc}', 'outbound', '{$callStatusEsc}', {$durationSeconds},
          " . ($notes === '' ? 'NULL' : "'{$notesEsc}'") . ",
          " . ($remarks === '' ? 'NULL' : "'{$remarksEsc}'") . ",
          {$nextFollowupSql},
          '{$leadStatusEsc}',
          '{$calledAtEsc}'
        )";

if (!$conn->query($sql)) {
    json_response(['success' => false, 'message' => 'Failed to save call: ' . $conn->error], 500);
}
$callId = (int) $conn->insert_id;

// Update lead
$oldStatus = $lead['status'];
$leadNotes = $notes !== '' ? $notes : ($lead['notes'] ?? '');
$leadRemarks = $remarks !== '' ? $remarks : ($lead['remarks'] ?? '');

$updateNext = $nextFollowupVal
    ? ("next_followup_at = '" . $conn->real_escape_string($nextFollowupVal) . "'")
    : 'next_followup_at = next_followup_at';

$conn->query(
    "UPDATE leads SET
        status = '{$leadStatusEsc}',
        notes = " . ($leadNotes === '' || $leadNotes === null ? 'NULL' : ("'" . $conn->real_escape_string((string) $leadNotes) . "'")) . ",
        remarks = " . ($leadRemarks === '' || $leadRemarks === null ? 'NULL' : ("'" . $conn->real_escape_string((string) $leadRemarks) . "'")) . ",
        last_called_at = '{$calledAtEsc}',
        assigned_to = COALESCE(assigned_to, {$executiveId}),
        {$updateNext}
     WHERE id = {$leadId}"
);

// Status history
if ($oldStatus !== $leadStatus) {
    $stmt = $conn->prepare(
        'INSERT INTO lead_status_history (lead_id, changed_by, old_status, new_status, notes)
         VALUES (?, ?, ?, ?, ?)'
    );
    $histNote = 'Updated after call #' . $callId;
    $stmt->bind_param('iisss', $leadId, $userId, $oldStatus, $leadStatus, $histNote);
    $stmt->execute();
}

// Create follow-up task if scheduled
if ($nextFollowupVal) {
    $conn->query(
        "UPDATE followups SET status = 'rescheduled'
         WHERE lead_id = {$leadId} AND status = 'pending'"
    );
    $fstmt = $conn->prepare(
        'INSERT INTO followups (lead_id, user_id, created_by, scheduled_at, status, notes)
         VALUES (?, ?, ?, ?, \'pending\', ?)'
    );
    $fnote = $remarks !== '' ? $remarks : ($notes !== '' ? $notes : 'Follow-up from call');
    $fstmt->bind_param('iiiss', $leadId, $executiveId, $userId, $nextFollowupVal, $fnote);
    $fstmt->execute();
}

// Activity
$title = 'Call logged';
$desc = "Call to {$phone} · {$callStatus} · status → {$leadStatus}";
$conn->query(
    "INSERT INTO activities (user_id, lead_id, activity_type, title, description)
     VALUES ({$userId}, {$leadId}, 'call', '" . $conn->real_escape_string($title) . "',
             '" . $conn->real_escape_string($desc) . "')"
);

// Fresh call row for UI
$callRow = $conn->query(
    "SELECT cl.*, u.name AS executive_name
     FROM call_logs cl
     LEFT JOIN users u ON u.id = cl.user_id
     WHERE cl.id = {$callId}
     LIMIT 1"
)->fetch_assoc();

json_response([
    'success' => true,
    'message' => 'Call saved successfully.',
    'call' => [
        'id' => $callId,
        'executive' => $callRow['executive_name'] ?? ($_SESSION['name'] ?? 'Executive'),
        'phone_dialed' => $phone,
        'call_status' => $callStatus,
        'call_status_label' => call_result_statuses()[$callStatus] ?? $callStatus,
        'lead_status' => $leadStatus,
        'lead_status_badge' => lead_status_badge($leadStatus),
        'duration_seconds' => $durationSeconds,
        'duration_label' => sprintf('%d:%02d', intdiv($durationSeconds, 60), $durationSeconds % 60),
        'notes' => $notes,
        'remarks' => $remarks,
        'called_at' => $calledAt,
        'next_followup_at' => $nextFollowupVal,
    ],
    'lead' => [
        'status' => $leadStatus,
        'status_badge' => lead_status_badge($leadStatus),
        'notes' => $leadNotes,
        'remarks' => $leadRemarks,
        'next_followup_at' => $nextFollowupVal ?: ($lead['next_followup_at'] ?? null),
        'last_called_at' => $calledAt,
    ],
]);
