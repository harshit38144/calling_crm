<?php
/**
 * DataTables server-side endpoint for leads list.
 * Returns only one page of rows (LIMIT/OFFSET) for large datasets.
 */
include_once __DIR__ . '/../connection.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$draw = (int) ($_POST['draw'] ?? 1);
$start = max(0, (int) ($_POST['start'] ?? 0));
$length = (int) ($_POST['length'] ?? 25);
if ($length < 1) {
    $length = 25;
}
if ($length > 100) {
    $length = 100;
}

$search = trim((string) ($_POST['search']['value'] ?? $_POST['global_search'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$filterStatus = trim((string) ($_POST['filter_status'] ?? ''));
$filterCategory = trim((string) ($_POST['filter_category'] ?? ''));
$filterCity = trim((string) ($_POST['filter_city'] ?? ''));
$filterExecutive = trim((string) ($_POST['filter_executive'] ?? ''));
$filterImport = trim((string) ($_POST['filter_import'] ?? ''));

$columns = [
    0 => 'l.id',
    1 => 'l.id',
    2 => 'l.business_name',
    3 => 'l.phone',
    4 => 'l.email',
    5 => 'l.city',
    6 => 'l.category',
    7 => 'l.status',
    8 => 'l.created_at',
    9 => 'l.id',
];

$orderCol = (int) ($_POST['order'][0]['column'] ?? 1);
$orderDir = strtolower((string) ($_POST['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$orderBy = $columns[$orderCol] ?? 'l.id';

$where = ['l.is_deleted = 0'];
$types = '';
$params = [];

if ($filterImport === 'manual') {
    $where[] = 'l.import_id IS NULL';
} elseif ($filterImport !== '' && ctype_digit($filterImport)) {
    $where[] = 'l.import_id = ?';
    $types .= 'i';
    $params[] = (int) $filterImport;
}

if ($filterStatus !== '' && array_key_exists($filterStatus, lead_statuses())) {
    $where[] = 'l.status = ?';
    $types .= 's';
    $params[] = $filterStatus;
}
if ($filterCategory !== '') {
    $where[] = 'l.category = ?';
    $types .= 's';
    $params[] = $filterCategory;
}
if ($filterCity !== '') {
    $where[] = 'l.city = ?';
    $types .= 's';
    $params[] = $filterCity;
}
if ($filterExecutive === '0' || $filterExecutive === 'unassigned') {
    $where[] = 'l.assigned_to IS NULL';
} elseif ($filterExecutive !== '' && ctype_digit($filterExecutive)) {
    $where[] = 'l.assigned_to = ?';
    $types .= 'i';
    $params[] = (int) $filterExecutive;
}

$needsImportJoin = false;
if ($search !== '') {
    $needsImportJoin = true;
    $where[] = '(l.business_name LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR l.address LIKE ?
                OR l.contact_name LIKE ? OR l.alternate_phone LIKE ?
                OR li.original_filename LIKE ? OR li.filename LIKE ?)';
    $like = '%' . $search . '%';
    $types .= 'ssssssss';
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);

/* Total (unfiltered) — uses idx_leads_deleted_status / is_deleted */
$totalRow = $conn->query('SELECT COUNT(*) AS c FROM leads WHERE is_deleted = 0')->fetch_assoc();
$recordsTotal = (int) ($totalRow['c'] ?? 0);

/* Filtered count — skip JOINs unless search needs import filenames */
$countFrom = 'leads l';
if ($needsImportJoin) {
    $countFrom .= ' LEFT JOIN lead_imports li ON li.id = l.import_id';
}
$countSql = "SELECT COUNT(*) AS c FROM {$countFrom} WHERE {$whereSql}";
$countStmt = $conn->prepare($countSql);
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$recordsFiltered = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
$countStmt->close();

/* Keep OFFSET inside range when filters shrink the result set */
if ($recordsFiltered > 0 && $start >= $recordsFiltered) {
    $start = (int) (floor(($recordsFiltered - 1) / $length) * $length);
}

$data = [];
if ($recordsFiltered > 0) {
    $dataSql = "SELECT l.id, l.business_name, l.contact_name, l.phone, l.email, l.city, l.category,
                       l.status, l.priority, l.address, l.created_at, l.assigned_to, l.import_id,
                       u.name AS executive_name,
                       li.original_filename, li.filename AS import_filename
                FROM leads l
                LEFT JOIN users u ON u.id = l.assigned_to
                LEFT JOIN lead_imports li ON li.id = l.import_id
                WHERE {$whereSql}
                ORDER BY {$orderBy} {$orderDir}, l.id {$orderDir}
                LIMIT ?, ?";

    $dataTypes = $types . 'ii';
    $dataParams = $params;
    $dataParams[] = $start;
    $dataParams[] = $length;

    $dataStmt = $conn->prepare($dataSql);
    $dataStmt->bind_param($dataTypes, ...$dataParams);
    $dataStmt->execute();
    $result = $dataStmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $id = (int) $row['id'];
        $actions = '<div class="crm-action-btns">'
            . '<button type="button" class="crm-act crm-act-call btn-call-lead" data-id="' . $id . '" title="Call"><i class="fas fa-phone"></i></button>'
            . '<button type="button" class="crm-act crm-act-view btn-view-lead" data-id="' . $id . '" title="View"><i class="fas fa-eye"></i></button>'
            . '<div class="crm-act-more">'
            . '<button type="button" class="crm-act crm-act-more-btn" title="More actions" aria-haspopup="true" aria-expanded="false"><i class="fas fa-ellipsis-h"></i></button>'
            . '<div class="crm-act-menu" role="menu">'
            . '<button type="button" class="crm-act-menu-item btn-edit-lead" data-id="' . $id . '" role="menuitem"><i class="fas fa-pen crm-act-edit"></i><span>Edit</span></button>'
            . '<button type="button" class="crm-act-menu-item btn-delete-lead" data-id="' . $id . '" role="menuitem"><i class="fas fa-trash crm-act-del"></i><span>Delete</span></button>'
            . '</div>'
            . '</div>'
            . '</div>';

        $fileName = $row['original_filename'] ?: ($row['import_filename'] ?: '');
        if ($fileName !== '' && !empty($row['import_id'])) {
            $excelFile = '<a class="import-file-chip" href="leads.php?import_id=' . (int) $row['import_id'] . '" title="Show leads from this file">'
                . htmlspecialchars($fileName) . '</a>';
        } else {
            $excelFile = '<span class="text-muted">Manual</span>';
        }

        $phoneRaw = trim((string) ($row['phone'] ?? ''));
        $emailRaw = trim((string) ($row['email'] ?? ''));
        $phoneHtml = $phoneRaw !== ''
            ? '<span class="crm-cell-icon"><i class="fas fa-phone"></i><span class="crm-cell-text">' . htmlspecialchars($phoneRaw) . '</span></span>'
            : '—';
        $emailHtml = $emailRaw !== ''
            ? '<span class="crm-cell-icon"><i class="fas fa-envelope"></i><span class="crm-cell-text">' . htmlspecialchars($emailRaw) . '</span></span>'
            : '—';

        $businessName = trim((string) ($row['business_name'] ?? ''));
        $businessHtml = $businessName !== ''
            ? '<span class="crm-cell-text" title="' . htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($businessName) . '</span>'
            : '—';

        $cityName = trim((string) ($row['city'] ?? ''));
        $cityHtml = $cityName !== ''
            ? '<span class="crm-cell-text" title="' . htmlspecialchars($cityName, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($cityName) . '</span>'
            : '—';

        $categoryName = trim((string) ($row['category'] ?? ''));
        $categoryHtml = $categoryName !== ''
            ? '<span class="crm-cell-text" title="' . htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($categoryName) . '</span>'
            : '—';

        $createdTs = strtotime((string) $row['created_at']);
        if ($createdTs) {
            $createdHtml = '<span class="crm-cell-date">'
                . htmlspecialchars(date('Y-m-d', $createdTs))
                . '<small>' . htmlspecialchars(date('H:i:s', $createdTs)) . '</small></span>';
        } else {
            $createdHtml = htmlspecialchars((string) $row['created_at']);
        }

        $data[] = [
            'id' => $id,
            'checkbox' => '<input type="checkbox" class="lead-check" value="' . $id . '">',
            'business_name' => $businessHtml,
            'phone' => $phoneHtml,
            'email' => $emailHtml,
            'city' => $cityHtml,
            'category' => $categoryHtml,
            'status' => lead_status_badge($row['status']),
            'excel_file' => $excelFile,
            'executive' => htmlspecialchars($row['executive_name'] ?: 'Unassigned'),
            'created_at' => $createdHtml,
            'actions' => $actions,
        ];
    }
    $dataStmt->close();
}

echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $recordsTotal,
    'recordsFiltered' => $recordsFiltered,
    'data' => $data,
]);
