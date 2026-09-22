<?php
/**
 * Lead CRUD / bulk action handler (AJAX + form POST).
 */
include_once __DIR__ . '/../connection.php';

if (empty($_SESSION['id'])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        json_response(['success' => false, 'message' => 'Unauthorized'], 401);
    }
    header('Location: ../index.php');
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || !empty($_POST['ajax']);

function redirect_back(string $fallback = '../leads.php'): void
{
    $ref = $_SERVER['HTTP_REFERER'] ?? $fallback;
    header('Location: ' . $ref);
    exit;
}

function parse_ids($raw): array
{
    if (is_array($raw)) {
        return array_values(array_unique(array_filter(array_map('intval', $raw))));
    }
    if (is_string($raw) && $raw !== '') {
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));
    }
    return [];
}

function log_status_history(mysqli $conn, int $leadId, string $old, string $new, int $userId, string $notes = ''): void
{
    if ($old === $new) {
        return;
    }
    $stmt = $conn->prepare(
        'INSERT INTO lead_status_history (lead_id, changed_by, old_status, new_status, notes)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iisss', $leadId, $userId, $old, $new, $notes);
    $stmt->execute();
}

$userId = (int) $_SESSION['id'];

/* ---------- Soft delete single ---------- */
if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        if ($isAjax) {
            json_response(['success' => false, 'message' => 'Invalid lead id.'], 400);
        }
        set_flash('Invalid lead id.', 'error');
        redirect_back();
    }
    $conn->query("UPDATE leads SET is_deleted = 1 WHERE id = {$id}");
    if ($isAjax) {
        json_response(['success' => true, 'message' => 'Lead deleted.']);
    }
    set_flash('Lead deleted successfully.');
    redirect_back();
}

/* ---------- Bulk delete ---------- */
if ($action === 'bulk_delete') {
    $ids = parse_ids($_POST['ids'] ?? []);
    if (!$ids) {
        json_response(['success' => false, 'message' => 'No leads selected.'], 400);
    }
    $idList = implode(',', $ids);
    $conn->query("UPDATE leads SET is_deleted = 1 WHERE id IN ({$idList}) AND is_deleted = 0");
    $affected = $conn->affected_rows;
    json_response(['success' => true, 'message' => "Deleted {$affected} lead(s).", 'count' => $affected]);
}

/* ---------- Bulk status ---------- */
if ($action === 'bulk_status') {
    $ids = parse_ids($_POST['ids'] ?? []);
    $status = trim((string) ($_POST['status'] ?? ''));
    if (!$ids) {
        json_response(['success' => false, 'message' => 'No leads selected.'], 400);
    }
    if (!array_key_exists($status, lead_statuses())) {
        json_response(['success' => false, 'message' => 'Invalid status.'], 400);
    }
    $idList = implode(',', $ids);
    $res = $conn->query("SELECT id, status FROM leads WHERE id IN ({$idList}) AND is_deleted = 0");
    while ($row = $res->fetch_assoc()) {
        log_status_history($conn, (int) $row['id'], $row['status'], $status, $userId, 'Bulk status update');
    }
    $st = $conn->real_escape_string($status);
    $conn->query("UPDATE leads SET status = '{$st}' WHERE id IN ({$idList}) AND is_deleted = 0");
    json_response(['success' => true, 'message' => 'Status updated for selected leads.', 'count' => $conn->affected_rows]);
}

/* ---------- Bulk assign ---------- */
if ($action === 'bulk_assign') {
    $ids = parse_ids($_POST['ids'] ?? []);
    $executive = (int) ($_POST['assigned_to'] ?? 0);
    if (!$ids) {
        json_response(['success' => false, 'message' => 'No leads selected.'], 400);
    }

    $idList = implode(',', $ids);
    if ($executive <= 0) {
        $conn->query("UPDATE leads SET assigned_to = NULL WHERE id IN ({$idList}) AND is_deleted = 0");
        // Deactivate active assignments
        $conn->query(
            "UPDATE lead_assignments SET is_active = 0, unassigned_at = NOW()
             WHERE lead_id IN ({$idList}) AND is_active = 1"
        );
        json_response(['success' => true, 'message' => 'Leads unassigned.', 'count' => count($ids)]);
    }

    $u = $conn->query("SELECT id FROM users WHERE id = {$executive} AND status = 'active' LIMIT 1");
    if (!$u || !$u->fetch_assoc()) {
        json_response(['success' => false, 'message' => 'Invalid executive.'], 400);
    }

    $conn->query(
        "UPDATE lead_assignments SET is_active = 0, unassigned_at = NOW()
         WHERE lead_id IN ({$idList}) AND is_active = 1"
    );

    foreach ($ids as $leadId) {
        $conn->query("UPDATE leads SET assigned_to = {$executive}, status = IF(status = 'new', 'not_contacted', status)
                      WHERE id = {$leadId} AND is_deleted = 0");
        $stmt = $conn->prepare(
            'INSERT INTO lead_assignments (lead_id, user_id, assigned_by, is_active)
             VALUES (?, ?, ?, 1)'
        );
        $stmt->bind_param('iii', $leadId, $executive, $userId);
        $stmt->execute();
    }

    json_response(['success' => true, 'message' => 'Leads assigned successfully.', 'count' => count($ids)]);
}

/* ---------- Get lead details (view modal) ---------- */
if ($action === 'get' || $action === 'view') {
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'Invalid lead id.'], 400);
    }

    $stmt = $conn->prepare(
        "SELECT l.*, u.name AS executive_name, u.username AS executive_username,
                c.name AS created_by_name, li.original_filename AS import_file
         FROM leads l
         LEFT JOIN users u ON u.id = l.assigned_to
         LEFT JOIN users c ON c.id = l.created_by
         LEFT JOIN lead_imports li ON li.id = l.import_id
         WHERE l.id = ? AND l.is_deleted = 0
         LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $lead = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$lead) {
        json_response(['success' => false, 'message' => 'Lead not found.'], 404);
    }

    $statuses = lead_statuses();
    $priorities = lead_priorities();
    $statusKey = (string) ($lead['status'] ?? '');
    $priorityKey = (string) ($lead['priority'] ?? '');

    $extra = [];
    if (!empty($lead['extra_data'])) {
        $decoded = json_decode($lead['extra_data'], true);
        if (is_array($decoded)) {
            $extra = $decoded;
        }
    }

    $callCount = 0;
    $calls = [];
    $callResults = call_result_statuses();
    $cr = $conn->prepare(
        "SELECT cl.*, u.name AS executive_name
         FROM call_logs cl
         LEFT JOIN users u ON u.id = cl.user_id
         WHERE cl.lead_id = ?
         ORDER BY cl.called_at DESC, cl.id DESC
         LIMIT 50"
    );
    $cr->bind_param('i', $id);
    $cr->execute();
    $callRows = $cr->get_result();
    while ($row = $callRows->fetch_assoc()) {
        $secs = (int) ($row['duration_seconds'] ?? 0);
        $statusKey = (string) ($row['call_status'] ?? '');
        $afterKey = (string) ($row['lead_status_after'] ?? '');
        $calls[] = [
            'executive_name' => (string) ($row['executive_name'] ?: 'Executive'),
            'called_at' => (string) ($row['called_at'] ?? ''),
            'phone_dialed' => (string) ($row['phone_dialed'] ?? ''),
            'call_status' => $statusKey,
            'call_status_label' => $callResults[$statusKey] ?? $statusKey,
            'duration_label' => sprintf('%d:%02d', intdiv($secs, 60), $secs % 60),
            'lead_status_after' => $afterKey,
            'lead_status_after_badge' => $afterKey !== '' ? lead_status_badge($afterKey) : '',
            'notes' => (string) ($row['notes'] ?? ''),
            'remarks' => (string) ($row['remarks'] ?? ''),
            'next_followup_at' => (string) ($row['next_followup_at'] ?? ''),
        ];
    }
    $cr->close();
    $callCount = count($calls);

    // If limited to 50, get true total
    $cc = $conn->prepare('SELECT COUNT(*) AS c FROM call_logs WHERE lead_id = ?');
    $cc->bind_param('i', $id);
    $cc->execute();
    $callCount = (int) ($cc->get_result()->fetch_assoc()['c'] ?? $callCount);
    $cc->close();

    $followups = [];
    $fr = $conn->prepare(
        "SELECT f.*, u.name AS executive_name
         FROM followups f
         LEFT JOIN users u ON u.id = f.user_id
         WHERE f.lead_id = ?
         ORDER BY f.scheduled_at DESC
         LIMIT 20"
    );
    $fr->bind_param('i', $id);
    $fr->execute();
    $fuRows = $fr->get_result();
    while ($row = $fuRows->fetch_assoc()) {
        $followups[] = [
            'scheduled_at' => (string) ($row['scheduled_at'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'status_label' => ucfirst((string) ($row['status'] ?? '')),
            'executive_name' => (string) ($row['executive_name'] ?: '—'),
            'notes' => (string) ($row['notes'] ?? ''),
        ];
    }
    $fr->close();

    json_response([
        'success' => true,
        'lead' => [
            'id' => (int) $lead['id'],
            'business_name' => (string) ($lead['business_name'] ?? ''),
            'contact_name' => (string) ($lead['contact_name'] ?? ''),
            'phone' => (string) ($lead['phone'] ?? ''),
            'alternate_phone' => (string) ($lead['alternate_phone'] ?? ''),
            'email' => (string) ($lead['email'] ?? ''),
            'website' => (string) ($lead['website'] ?? ''),
            'category' => (string) ($lead['category'] ?? ''),
            'address' => (string) ($lead['address'] ?? ''),
            'city' => (string) ($lead['city'] ?? ''),
            'state' => (string) ($lead['state'] ?? ''),
            'country' => (string) ($lead['country'] ?? ''),
            'pincode' => (string) ($lead['pincode'] ?? ''),
            'source' => (string) ($lead['source'] ?? ''),
            'import_file' => (string) ($lead['import_file'] ?? ''),
            'import_id' => (int) ($lead['import_id'] ?? 0),
            'status' => $statusKey,
            'status_label' => $statuses[$statusKey] ?? ucfirst(str_replace('_', ' ', $statusKey)),
            'status_badge' => lead_status_badge($statusKey),
            'priority' => $priorityKey,
            'priority_label' => $priorities[$priorityKey] ?? ucfirst($priorityKey),
            'notes' => (string) ($lead['notes'] ?? ''),
            'remarks' => (string) ($lead['remarks'] ?? ''),
            'assigned_to' => (int) ($lead['assigned_to'] ?? 0),
            'executive' => (string) ($lead['executive_name'] ?: ($lead['executive_username'] ?: 'Unassigned')),
            'created_by' => (string) ($lead['created_by_name'] ?? ''),
            'created_at' => (string) ($lead['created_at'] ?? ''),
            'last_called_at' => (string) ($lead['last_called_at'] ?? ''),
            'next_followup_at' => (string) ($lead['next_followup_at'] ?? ''),
            'call_count' => $callCount,
            'extra' => $extra,
            'calls' => $calls,
            'followups' => $followups,
        ],
    ]);
}

/* ---------- Save lead (create / update) ---------- */
if ($action === 'save') {
    $id = (int) ($_POST['id'] ?? 0);

    $fields = [
        'business_name' => trim((string) ($_POST['business_name'] ?? '')),
        'contact_name' => trim((string) ($_POST['contact_name'] ?? '')),
        'phone' => trim((string) ($_POST['phone'] ?? '')),
        'alternate_phone' => trim((string) ($_POST['alternate_phone'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'website' => trim((string) ($_POST['website'] ?? '')),
        'category' => trim((string) ($_POST['category'] ?? '')),
        'address' => trim((string) ($_POST['address'] ?? '')),
        'city' => trim((string) ($_POST['city'] ?? '')),
        'state' => trim((string) ($_POST['state'] ?? '')),
        'country' => trim((string) ($_POST['country'] ?? '')),
        'pincode' => trim((string) ($_POST['pincode'] ?? '')),
        'maps_url' => trim((string) ($_POST['maps_url'] ?? '')),
        'notes' => trim((string) ($_POST['notes'] ?? '')),
        'remarks' => trim((string) ($_POST['remarks'] ?? '')),
    ];

    $status = trim((string) ($_POST['status'] ?? 'new'));
    if (!array_key_exists($status, lead_statuses())) {
        $status = 'new';
    }
    $priority = trim((string) ($_POST['priority'] ?? 'medium'));
    if (!array_key_exists($priority, lead_priorities())) {
        $priority = 'medium';
    }

    $assignedTo = (int) ($_POST['assigned_to'] ?? 0);
    $assignedToVal = $assignedTo > 0 ? $assignedTo : null;

    $ratingRaw = trim((string) ($_POST['rating'] ?? ''));
    $rating = ($ratingRaw !== '' && is_numeric($ratingRaw)) ? round((float) $ratingRaw, 2) : null;
    $reviewCount = max(0, (int) ($_POST['review_count'] ?? 0));

    $latRaw = trim((string) ($_POST['latitude'] ?? ''));
    $lngRaw = trim((string) ($_POST['longitude'] ?? ''));
    $lat = ($latRaw !== '' && is_numeric($latRaw)) ? (float) $latRaw : null;
    $lng = ($lngRaw !== '' && is_numeric($lngRaw)) ? (float) $lngRaw : null;

    $nextFollowupRaw = trim((string) ($_POST['next_followup_at'] ?? ''));
    $nextFollowupSql = 'NULL';
    if ($nextFollowupRaw !== '') {
        $fts = strtotime($nextFollowupRaw);
        if ($fts) {
            $nextFollowupSql = "'" . $conn->real_escape_string(date('Y-m-d H:i:s', $fts)) . "'";
        }
    }

    if ($fields['business_name'] === '' && $fields['phone'] === '') {
        if ($isAjax) {
            json_response(['success' => false, 'message' => 'Business name or phone is required.'], 400);
        }
        set_flash('Business name or phone is required.', 'error');
        header('Location: ' . ($id > 0 ? "../lead_edit.php?id={$id}" : '../lead_edit.php'));
        exit;
    }

    $esc = function ($v) use ($conn) {
        return "'" . $conn->real_escape_string((string) $v) . "'";
    };
    $nullOrFloat = function ($v) {
        return $v === null ? 'NULL' : (string) (float) $v;
    };
    $nullOrInt = function ($v) {
        return $v === null ? 'NULL' : (string) (int) $v;
    };

    if ($id > 0) {
        $existing = $conn->query("SELECT * FROM leads WHERE id = {$id} AND is_deleted = 0")->fetch_assoc();
        if (!$existing) {
            if ($isAjax) {
                json_response(['success' => false, 'message' => 'Lead not found.'], 404);
            }
            set_flash('Lead not found.', 'error');
            header('Location: ../leads.php');
            exit;
        }

        $sql = "UPDATE leads SET
            business_name = {$esc($fields['business_name'])},
            contact_name = {$esc($fields['contact_name'])},
            phone = {$esc($fields['phone'])},
            alternate_phone = {$esc($fields['alternate_phone'])},
            email = {$esc($fields['email'])},
            website = {$esc($fields['website'])},
            category = {$esc($fields['category'])},
            address = {$esc($fields['address'])},
            city = {$esc($fields['city'])},
            state = {$esc($fields['state'])},
            country = {$esc($fields['country'])},
            pincode = {$esc($fields['pincode'])},
            maps_url = {$esc($fields['maps_url'])},
            notes = {$esc($fields['notes'])},
            remarks = {$esc($fields['remarks'])},
            next_followup_at = {$nextFollowupSql},
            status = {$esc($status)},
            priority = {$esc($priority)},
            assigned_to = {$nullOrInt($assignedToVal)},
            rating = {$nullOrFloat($rating)},
            review_count = {$reviewCount},
            latitude = {$nullOrFloat($lat)},
            longitude = {$nullOrFloat($lng)}
            WHERE id = {$id}";

        if ($conn->query($sql)) {
            log_status_history($conn, $id, $existing['status'], $status, $userId, 'Lead edited');

            // Assignment sync
            $oldAssigned = (int) ($existing['assigned_to'] ?? 0);
            if ($oldAssigned !== (int) ($assignedToVal ?? 0)) {
                $conn->query(
                    "UPDATE lead_assignments SET is_active = 0, unassigned_at = NOW()
                     WHERE lead_id = {$id} AND is_active = 1"
                );
                if ($assignedToVal) {
                    $stmt = $conn->prepare(
                        'INSERT INTO lead_assignments (lead_id, user_id, assigned_by, is_active) VALUES (?, ?, ?, 1)'
                    );
                    $stmt->bind_param('iii', $id, $assignedToVal, $userId);
                    $stmt->execute();
                }
            }

            if ($isAjax) {
                json_response(['success' => true, 'message' => 'Lead updated successfully.', 'id' => $id]);
            }
            set_flash('Lead updated successfully.');
            header("Location: ../lead_view.php?id={$id}");
            exit;
        }

        if ($isAjax) {
            json_response(['success' => false, 'message' => 'Update failed: ' . $conn->error], 500);
        }
        set_flash('Update failed: ' . $conn->error, 'error');
        header("Location: ../lead_edit.php?id={$id}");
        exit;
    }

    // Create
    $source = 'manual';
    $sql = "INSERT INTO leads (
        created_by, assigned_to, business_name, contact_name, phone, alternate_phone,
        email, website, category, address, city, state, country, pincode, maps_url,
        rating, review_count, latitude, longitude, status, priority, notes, source
    ) VALUES (
        {$userId}, {$nullOrInt($assignedToVal)}, {$esc($fields['business_name'])}, {$esc($fields['contact_name'])},
        {$esc($fields['phone'])}, {$esc($fields['alternate_phone'])}, {$esc($fields['email'])},
        {$esc($fields['website'])}, {$esc($fields['category'])}, {$esc($fields['address'])},
        {$esc($fields['city'])}, {$esc($fields['state'])}, {$esc($fields['country'])},
        {$esc($fields['pincode'])}, {$esc($fields['maps_url'])},
        {$nullOrFloat($rating)}, {$reviewCount}, {$nullOrFloat($lat)}, {$nullOrFloat($lng)},
        {$esc($status)}, {$esc($priority)}, {$esc($fields['notes'])}, {$esc($source)}
    )";

    if ($conn->query($sql)) {
        $newId = (int) $conn->insert_id;
        if ($assignedToVal) {
            $stmt = $conn->prepare(
                'INSERT INTO lead_assignments (lead_id, user_id, assigned_by, is_active) VALUES (?, ?, ?, 1)'
            );
            $stmt->bind_param('iii', $newId, $assignedToVal, $userId);
            $stmt->execute();
        }
        log_status_history($conn, $newId, '', $status, $userId, 'Lead created');
        if ($isAjax) {
            json_response(['success' => true, 'message' => 'Lead created successfully.', 'id' => $newId]);
        }
        set_flash('Lead created successfully.');
        header("Location: ../lead_view.php?id={$newId}");
        exit;
    }

    if ($isAjax) {
        json_response(['success' => false, 'message' => 'Create failed: ' . $conn->error], 500);
    }
    set_flash('Create failed: ' . $conn->error, 'error');
    header('Location: ../lead_edit.php');
    exit;
}

/* ---------- Save notes & remarks ---------- */
if ($action === 'save_notes') {
    $id = (int) ($_POST['id'] ?? $_POST['lead_id'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'Invalid lead id.'], 400);
    }
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));

    $stmt = $conn->prepare(
        'UPDATE leads SET notes = ?, remarks = ? WHERE id = ? AND is_deleted = 0'
    );
    $stmt->bind_param('ssi', $notes, $remarks, $id);
    if (!$stmt->execute()) {
        json_response(['success' => false, 'message' => 'Could not save notes.'], 500);
    }

    $check = $conn->query("SELECT id FROM leads WHERE id = {$id} AND is_deleted = 0 LIMIT 1");
    if (!$check || !$check->fetch_assoc()) {
        json_response(['success' => false, 'message' => 'Lead not found.'], 404);
    }

    json_response([
        'success' => true,
        'message' => 'Notes & remarks saved.',
        'notes' => $notes,
        'remarks' => $remarks,
    ]);
}

/* ---------- Add follow-up ---------- */
if ($action === 'add_followup') {
    $id = (int) ($_POST['id'] ?? $_POST['lead_id'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'Invalid lead id.'], 400);
    }

    $lead = $conn->query("SELECT id, assigned_to FROM leads WHERE id = {$id} AND is_deleted = 0 LIMIT 1")->fetch_assoc();
    if (!$lead) {
        json_response(['success' => false, 'message' => 'Lead not found.'], 404);
    }

    $scheduled = trim((string) ($_POST['scheduled_at'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $priority = trim((string) ($_POST['priority'] ?? 'medium'));
    $executiveId = (int) ($_POST['executive_id'] ?? ($lead['assigned_to'] ?: $userId));

    if ($scheduled === '') {
        json_response(['success' => false, 'message' => 'Follow-up date/time is required.'], 400);
    }
    $ts = strtotime($scheduled);
    if (!$ts) {
        json_response(['success' => false, 'message' => 'Invalid follow-up date/time.'], 400);
    }
    $scheduledAt = date('Y-m-d H:i:s', $ts);

    if (!array_key_exists($priority, lead_priorities())) {
        $priority = 'medium';
    }
    if ($executiveId <= 0) {
        $executiveId = $userId;
    }

    $stmt = $conn->prepare(
        "INSERT INTO followups (lead_id, user_id, created_by, scheduled_at, status, priority, notes)
         VALUES (?, ?, ?, ?, 'pending', ?, ?)"
    );
    $stmt->bind_param('iiisss', $id, $executiveId, $userId, $scheduledAt, $priority, $notes);
    if (!$stmt->execute()) {
        json_response(['success' => false, 'message' => 'Could not add follow-up: ' . $conn->error], 500);
    }

    $conn->query(
        "UPDATE leads SET next_followup_at = '" . $conn->real_escape_string($scheduledAt) . "'
         WHERE id = {$id}"
    );

    $fuId = (int) $conn->insert_id;
    $ename = '';
    $er = $conn->query("SELECT name, username FROM users WHERE id = {$executiveId} LIMIT 1");
    if ($er && ($eu = $er->fetch_assoc())) {
        $ename = $eu['name'] ?: $eu['username'];
    }

    json_response([
        'success' => true,
        'message' => 'Follow-up scheduled.',
        'followup' => [
            'id' => $fuId,
            'scheduled_at' => $scheduledAt,
            'status' => 'pending',
            'status_label' => 'Pending',
            'executive_name' => $ename ?: '—',
            'notes' => $notes,
            'priority' => $priority,
        ],
        'next_followup_at' => $scheduledAt,
    ]);
}

if ($isAjax) {
    json_response(['success' => false, 'message' => 'Unknown action.'], 400);
}
set_flash('Unknown action.', 'error');
redirect_back();
