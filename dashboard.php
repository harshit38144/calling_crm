<?php
include_once 'connection.php';
require_admin();

$msg = flash_msg();
$msgType = flash_type();

function dash_count(mysqli $conn, string $sql): int
{
    $res = $conn->query($sql);
    if (!$res) {
        return 0;
    }
    $row = $res->fetch_assoc();
    return (int) ($row['c'] ?? 0);
}

$totalLeads = dash_count($conn, 'SELECT COUNT(*) c FROM leads WHERE is_deleted = 0');
$todayCalls = dash_count($conn, 'SELECT COUNT(*) c FROM call_logs WHERE DATE(called_at) = CURDATE()');
$pendingFollowups = dash_count($conn, "SELECT COUNT(*) c FROM followups WHERE status = 'pending'");
$convertedLeads = dash_count($conn, "SELECT COUNT(*) c FROM leads WHERE is_deleted = 0 AND status = 'converted'");
$newLeads = dash_count($conn, "SELECT COUNT(*) c FROM leads WHERE is_deleted = 0 AND status = 'new'");
$importedFiles = dash_count($conn, 'SELECT COUNT(*) c FROM lead_imports');

// Leads by status
$statusLabels = [];
$statusData = [];
$statusBg = [];
$statusColors = [
    'new' => '#cbb0bc',
    'not_contacted' => '#e2b4c4',
    'attempted' => '#4f8fa8',
    'connected' => '#c45374',
    'interested' => '#2f9a78',
    'callback' => '#c9951a',
    'follow_up_required' => '#e0b445',
    'proposal_sent' => '#73b0c4',
    'converted' => '#2f9a78',
    'wrong_number' => '#d04d63',
    'duplicate' => '#8d7482',
    'not_interested' => '#6b5560',
    'closed' => '#2f2430',
];
$allStatuses = lead_statuses();
$statusMap = [];
$res = $conn->query(
    "SELECT status, COUNT(*) c FROM leads WHERE is_deleted = 0 GROUP BY status ORDER BY c DESC"
);
while ($row = $res->fetch_assoc()) {
    $statusMap[$row['status']] = (int) $row['c'];
}
foreach ($allStatuses as $key => $label) {
    $count = $statusMap[$key] ?? 0;
    if ($count <= 0) {
        continue;
    }
    $statusLabels[] = $label;
    $statusData[] = $count;
    $statusBg[] = $statusColors[$key] ?? '#94a3b8';
}
if (empty($statusLabels)) {
    $statusLabels = ['No data'];
    $statusData = [0];
    $statusBg = ['#e2e8f0'];
}

// Daily calls — last 14 days
$dailyLabels = [];
$dailyData = [];
$dailyMap = [];
$res = $conn->query(
    "SELECT DATE(called_at) d, COUNT(*) c
     FROM call_logs
     WHERE called_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
     GROUP BY DATE(called_at)"
);
while ($row = $res->fetch_assoc()) {
    $dailyMap[$row['d']] = (int) $row['c'];
}
for ($i = 13; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} day"));
    $dailyLabels[] = date('d M', strtotime($day));
    $dailyData[] = $dailyMap[$day] ?? 0;
}

// Monthly imports — last 12 months
$monthLabels = [];
$monthData = [];
$monthMap = [];
$res = $conn->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COUNT(*) c
     FROM lead_imports
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
     GROUP BY DATE_FORMAT(created_at, '%Y-%m')"
);
while ($row = $res->fetch_assoc()) {
    $monthMap[$row['ym']] = (int) $row['c'];
}
for ($i = 11; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("first day of -{$i} month"));
    $monthLabels[] = date('M Y', strtotime($ym . '-01'));
    $monthData[] = $monthMap[$ym] ?? 0;
}

// Executive performance — calls + conversions
$execLabels = [];
$execCalls = [];
$execConverted = [];
$res = $conn->query(
    "SELECT u.id, u.name, u.username,
            (SELECT COUNT(*) FROM call_logs cl WHERE cl.user_id = u.id) AS call_count,
            (SELECT COUNT(*) FROM leads l WHERE l.assigned_to = u.id AND l.is_deleted = 0 AND l.status = 'converted') AS converted_count
     FROM users u
     WHERE u.status = 'active'
     HAVING call_count > 0 OR converted_count > 0
     ORDER BY call_count DESC, converted_count DESC
     LIMIT 10"
);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $execLabels[] = $row['name'] ?: $row['username'];
        $execCalls[] = (int) $row['call_count'];
        $execConverted[] = (int) $row['converted_count'];
    }
}
if (empty($execLabels)) {
    // Fallback: show active users with zeros so chart still renders
    $res = $conn->query("SELECT name, username FROM users WHERE status = 'active' ORDER BY name LIMIT 5");
    while ($row = $res->fetch_assoc()) {
        $execLabels[] = $row['name'] ?: $row['username'];
        $execCalls[] = 0;
        $execConverted[] = 0;
    }
}

// Recent activities
$activities = [];
$res = $conn->query(
    "SELECT a.*, u.name AS user_name, l.business_name
     FROM activities a
     LEFT JOIN users u ON u.id = a.user_id
     LEFT JOIN leads l ON l.id = a.lead_id
     ORDER BY a.created_at DESC
     LIMIT 12"
);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $activities[] = $row;
    }
}

// Recent calls as activity fallback enrichment already in activities; also show latest calls if few activities
$recentCalls = [];
$res = $conn->query(
    "SELECT cl.*, u.name AS executive_name, l.business_name
     FROM call_logs cl
     LEFT JOIN users u ON u.id = cl.user_id
     LEFT JOIN leads l ON l.id = cl.lead_id
     ORDER BY cl.called_at DESC
     LIMIT 8"
);
while ($row = $res->fetch_assoc()) {
    $recentCalls[] = $row;
}

function activity_icon(string $type): string
{
    $map = [
        'call' => 'fa-phone text-success',
        'import' => 'fa-file-excel text-primary',
        'assignment' => 'fa-user-check text-info',
        'status_change' => 'fa-exchange-alt text-warning',
        'followup' => 'fa-clock text-warning',
        'note' => 'fa-sticky-note text-secondary',
        'login' => 'fa-sign-in-alt text-muted',
    ];
    return $map[$type] ?? 'fa-circle text-muted';
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Dashboard | Calling CRM</title>
  <?php include 'includes/header-links.php'; ?>
  <style>
    .chart-wrap { position: relative; height: 280px; }
    a.kpi-link { text-decoration: none; color: inherit; display: block; height: 100%; }
  </style>
</head>
<body class="hold-transition sidebar-mini sidebar-collapse layout-fixed">
<div class="wrapper">
  <?php include 'includes/top-header.php'; ?>
  <?php include 'includes/sidebar.php'; ?>
  <div class="content-wrapper">
    <?php include 'includes/page-header.php'; ?>
    <section class="content">
      <div class="container-fluid">
        <?php if ($msg): ?>
          <div class="alert alert-<?= htmlspecialchars($msgType === 'error' ? 'danger' : $msgType) ?>">
            <?= htmlspecialchars($msg) ?>
          </div>
        <?php endif; ?>

        <div class="dash-hero">
          <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
              <h3 class="mb-1">Welcome, <?= htmlspecialchars($_SESSION['name'] ?? 'Admin') ?></h3>
              <p class="mb-0">Your soft workspace for leads, calls &amp; conversions · <?= date('l, d M Y') ?></p>
            </div>
            <div class="mt-3 mt-md-0">
              <a href="leads.php" class="btn btn-light btn-sm mr-1"><i class="fas fa-address-book"></i> Leads</a>
              <a href="import_excel.php" class="btn btn-outline-light btn-sm"><i class="fas fa-file-excel"></i> Import</a>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-xl-2 col-md-4 col-6 mb-3">
            <a class="kpi-link" href="leads.php">
              <div class="card kpi-card">
                <div class="kpi-body d-flex justify-content-between align-items-start">
                  <div>
                    <div class="kpi-label">Total Leads</div>
                    <div class="kpi-value"><?= number_format($totalLeads) ?></div>
                  </div>
                  <div class="kpi-icon blue"><i class="fas fa-users"></i></div>
                </div>
                <div class="kpi-foot">All active leads</div>
              </div>
            </a>
          </div>
          <div class="col-xl-2 col-md-4 col-6 mb-3">
            <div class="card kpi-card">
              <div class="kpi-body d-flex justify-content-between align-items-start">
                <div>
                  <div class="kpi-label">Today's Calls</div>
                  <div class="kpi-value"><?= number_format($todayCalls) ?></div>
                </div>
                <div class="kpi-icon green"><i class="fas fa-phone"></i></div>
              </div>
              <div class="kpi-foot"><?= date('d M Y') ?></div>
            </div>
          </div>
          <div class="col-xl-2 col-md-4 col-6 mb-3">
            <div class="card kpi-card">
              <div class="kpi-body d-flex justify-content-between align-items-start">
                <div>
                  <div class="kpi-label">Pending Follow-ups</div>
                  <div class="kpi-value"><?= number_format($pendingFollowups) ?></div>
                </div>
                <div class="kpi-icon amber"><i class="fas fa-clock"></i></div>
              </div>
              <div class="kpi-foot">Awaiting action</div>
            </div>
          </div>
          <div class="col-xl-2 col-md-4 col-6 mb-3">
            <a class="kpi-link" href="leads.php">
              <div class="card kpi-card">
                <div class="kpi-body d-flex justify-content-between align-items-start">
                  <div>
                    <div class="kpi-label">Converted Leads</div>
                    <div class="kpi-value"><?= number_format($convertedLeads) ?></div>
                  </div>
                  <div class="kpi-icon teal"><i class="fas fa-trophy"></i></div>
                </div>
                <div class="kpi-foot">Won deals</div>
              </div>
            </a>
          </div>
          <div class="col-xl-2 col-md-4 col-6 mb-3">
            <div class="card kpi-card">
              <div class="kpi-body d-flex justify-content-between align-items-start">
                <div>
                  <div class="kpi-label">New Leads</div>
                  <div class="kpi-value"><?= number_format($newLeads) ?></div>
                </div>
                <div class="kpi-icon indigo"><i class="fas fa-user-plus"></i></div>
              </div>
              <div class="kpi-foot">Status: New</div>
            </div>
          </div>
          <div class="col-xl-2 col-md-4 col-6 mb-3">
            <a class="kpi-link" href="import_excel.php">
              <div class="card kpi-card">
                <div class="kpi-body d-flex justify-content-between align-items-start">
                  <div>
                    <div class="kpi-label">Imported Files</div>
                    <div class="kpi-value"><?= number_format($importedFiles) ?></div>
                  </div>
                  <div class="kpi-icon rose"><i class="fas fa-file-excel"></i></div>
                </div>
                <div class="kpi-foot">Excel uploads</div>
              </div>
            </a>
          </div>
        </div>

        <div class="row">
          <div class="col-lg-6 mb-3">
            <div class="card chart-card">
              <div class="card-header">Leads by Status</div>
              <div class="card-body"><div class="chart-wrap"><canvas id="chartStatus"></canvas></div></div>
            </div>
          </div>
          <div class="col-lg-6 mb-3">
            <div class="card chart-card">
              <div class="card-header">Daily Calls (Last 14 Days)</div>
              <div class="card-body"><div class="chart-wrap"><canvas id="chartDailyCalls"></canvas></div></div>
            </div>
          </div>
          <div class="col-lg-6 mb-3">
            <div class="card chart-card">
              <div class="card-header">Monthly Imports</div>
              <div class="card-body"><div class="chart-wrap"><canvas id="chartMonthlyImports"></canvas></div></div>
            </div>
          </div>
          <div class="col-lg-6 mb-3">
            <div class="card chart-card">
              <div class="card-header">Executive Performance</div>
              <div class="card-body"><div class="chart-wrap"><canvas id="chartExec"></canvas></div></div>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-lg-7 mb-3">
            <div class="card activity-card">
              <div class="card-header">Recent Activities</div>
              <div class="card-body">
                <?php if (!$activities && !$recentCalls): ?>
                  <p class="text-muted mb-0">No recent activity yet. Start calling leads or importing Excel files.</p>
                <?php else: ?>
                  <?php if ($activities): ?>
                    <?php foreach ($activities as $act): ?>
                      <div class="activity-item">
                        <div class="activity-icon"><i class="fas <?= activity_icon($act['activity_type']) ?>"></i></div>
                        <div class="flex-grow-1">
                          <div>
                            <strong><?= htmlspecialchars($act['title'] ?: ucfirst($act['activity_type'])) ?></strong>
                            <?php if (!empty($act['business_name'])): ?>
                              · <a href="lead_view.php?id=<?= (int) $act['lead_id'] ?>"><?= htmlspecialchars($act['business_name']) ?></a>
                            <?php endif; ?>
                          </div>
                          <?php if (!empty($act['description'])): ?>
                            <div class="text-muted small"><?= htmlspecialchars($act['description']) ?></div>
                          <?php endif; ?>
                          <div class="activity-meta">
                            <?= htmlspecialchars($act['user_name'] ?: 'System') ?>
                            · <?= htmlspecialchars($act['created_at']) ?>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <?php foreach ($recentCalls as $call): ?>
                      <div class="activity-item">
                        <div class="activity-icon"><i class="fas fa-phone text-success"></i></div>
                        <div class="flex-grow-1">
                          <div>
                            <strong>Call logged</strong>
                            <?php if (!empty($call['business_name'])): ?>
                              · <a href="lead_view.php?id=<?= (int) $call['lead_id'] ?>"><?= htmlspecialchars($call['business_name']) ?></a>
                            <?php endif; ?>
                          </div>
                          <div class="text-muted small">
                            <?= htmlspecialchars($call['phone_dialed'] ?: '') ?>
                            · <?= htmlspecialchars(call_result_statuses()[$call['call_status']] ?? $call['call_status']) ?>
                          </div>
                          <div class="activity-meta">
                            <?= htmlspecialchars($call['executive_name'] ?: 'Executive') ?>
                            · <?= htmlspecialchars($call['called_at']) ?>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="col-lg-5 mb-3">
            <div class="card activity-card">
              <div class="card-header">Quick Actions</div>
              <div class="card-body">
                <a href="import_excel.php" class="btn btn-outline-primary btn-block mb-2 text-left">
                  <i class="fas fa-file-excel mr-2"></i> Import Excel Leads
                </a>
                <a href="leads.php" class="btn btn-outline-secondary btn-block mb-2 text-left">
                  <i class="fas fa-address-book mr-2"></i> Manage Leads
                </a>
                <a href="lead_edit.php" class="btn btn-outline-success btn-block mb-2 text-left">
                  <i class="fas fa-plus mr-2"></i> Add Lead Manually
                </a>
                <hr>
                <div class="small text-muted mb-1">Pending follow-ups needing attention</div>
                <?php
                $fuList = $conn->query(
                    "SELECT f.id, f.scheduled_at, l.id AS lead_id, l.business_name, u.name AS exec_name
                     FROM followups f
                     LEFT JOIN leads l ON l.id = f.lead_id
                     LEFT JOIN users u ON u.id = f.user_id
                     WHERE f.status = 'pending'
                     ORDER BY f.scheduled_at ASC
                     LIMIT 5"
                );
                if ($fuList && $fuList->num_rows):
                    while ($fu = $fuList->fetch_assoc()):
                ?>
                  <div class="activity-item py-2">
                    <div class="activity-icon"><i class="fas fa-clock text-warning"></i></div>
                    <div>
                      <a href="lead_view.php?id=<?= (int) $fu['lead_id'] ?>">
                        <?= htmlspecialchars($fu['business_name'] ?: ('Lead #' . $fu['lead_id'])) ?>
                      </a>
                      <div class="activity-meta">
                        <?= htmlspecialchars($fu['scheduled_at']) ?>
                        · <?= htmlspecialchars($fu['exec_name'] ?: '—') ?>
                      </div>
                    </div>
                  </div>
                <?php
                    endwhile;
                else:
                ?>
                  <p class="text-muted small mb-0">No pending follow-ups.</p>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

      </div>
    </section>
  </div>
  <?php include 'includes/copyright.php'; ?>
</div>
<?php include 'includes/footer-links.php'; ?>
<script src="plugins/chart.js/Chart.umd.min.js"></script>
<script>
(function () {
  var statusLabels = <?= json_encode($statusLabels) ?>;
  var statusData = <?= json_encode($statusData) ?>;
  var statusBg = <?= json_encode($statusBg) ?>;
  var dailyLabels = <?= json_encode($dailyLabels) ?>;
  var dailyData = <?= json_encode($dailyData) ?>;
  var monthLabels = <?= json_encode($monthLabels) ?>;
  var monthData = <?= json_encode($monthData) ?>;
  var execLabels = <?= json_encode($execLabels) ?>;
  var execCalls = <?= json_encode($execCalls) ?>;
  var execConverted = <?= json_encode($execConverted) ?>;

  Chart.defaults.font.family = "'Source Sans Pro', sans-serif";
  Chart.defaults.color = '#64748b';

  new Chart(document.getElementById('chartStatus'), {
    type: 'doughnut',
    data: {
      labels: statusLabels,
      datasets: [{ data: statusData, backgroundColor: statusBg, borderWidth: 0 }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'right' } },
      cutout: '58%'
    }
  });

  new Chart(document.getElementById('chartDailyCalls'), {
    type: 'bar',
    data: {
      labels: dailyLabels,
      datasets: [{
        label: 'Calls',
        data: dailyData,
        backgroundColor: '#c45374',
        borderRadius: 8,
        maxBarThickness: 28
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: { grid: { display: false } },
        y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f3e4eb' } }
      },
      plugins: { legend: { display: false } }
    }
  });

  new Chart(document.getElementById('chartMonthlyImports'), {
    type: 'line',
    data: {
      labels: monthLabels,
      datasets: [{
        label: 'Imports',
        data: monthData,
        borderColor: '#d97793',
        backgroundColor: 'rgba(217, 119, 147, 0.15)',
        fill: true,
        tension: 0.35,
        pointRadius: 4,
        pointBackgroundColor: '#c45374'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: { grid: { display: false } },
        y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f3e4eb' } }
      }
    }
  });

  new Chart(document.getElementById('chartExec'), {
    type: 'bar',
    data: {
      labels: execLabels,
      datasets: [
        {
          label: 'Calls',
          data: execCalls,
          backgroundColor: '#c45374',
          borderRadius: 8,
          maxBarThickness: 22
        },
        {
          label: 'Converted',
          data: execConverted,
          backgroundColor: '#2f9a78',
          borderRadius: 8,
          maxBarThickness: 22
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: { grid: { display: false } },
        y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f3e4eb' } }
      }
    }
  });
})();
</script>
</body>
</html>
