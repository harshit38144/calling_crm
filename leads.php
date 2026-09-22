<?php
include_once 'connection.php';
require_admin();

$msg = flash_msg();
$msgType = flash_type();
$statuses = lead_statuses();
$priorities = lead_priorities();
$callResults = call_result_statuses();

$categories = [];
$cities = [];
$executives = [];
$imports = [];

$preselectedImportId = (int) ($_GET['import_id'] ?? 0);

$res = $conn->query("SELECT DISTINCT category FROM leads WHERE is_deleted = 0 AND category <> '' ORDER BY category");
while ($row = $res->fetch_assoc()) {
    $categories[] = $row['category'];
}
$res = $conn->query("SELECT DISTINCT city FROM leads WHERE is_deleted = 0 AND city <> '' ORDER BY city");
while ($row = $res->fetch_assoc()) {
    $cities[] = $row['city'];
}
$res = $conn->query("SELECT id, name, username FROM users WHERE status = 'active' ORDER BY name");
while ($row = $res->fetch_assoc()) {
    $executives[] = $row;
}
$res = $conn->query(
    "SELECT li.id, li.original_filename, li.filename, li.created_at, li.success_count, li.status,
            (SELECT COUNT(*) FROM leads l WHERE l.import_id = li.id AND l.is_deleted = 0) AS lead_count
     FROM lead_imports li
     ORDER BY li.created_at DESC"
);
while ($row = $res->fetch_assoc()) {
    $imports[] = $row;
}

$activeImportLabel = '';
if ($preselectedImportId > 0) {
    foreach ($imports as $imp) {
        if ((int) $imp['id'] === $preselectedImportId) {
            $activeImportLabel = $imp['original_filename'] ?: $imp['filename'];
            break;
        }
    }
}

/* Summary stats for top cards */
$leadStats = [
    'total' => 0,
    'contacted' => 0,
    'follow_up' => 0,
    'converted' => 0,
    'lost' => 0,
];
$contactedSet = ['attempted', 'connected', 'interested'];
$followSet = ['callback', 'follow_up_required', 'proposal_sent'];
$lostSet = ['wrong_number', 'duplicate', 'not_interested', 'closed'];

$statRes = $conn->query("SELECT status, COUNT(*) AS c FROM leads WHERE is_deleted = 0 GROUP BY status");
if ($statRes) {
    while ($row = $statRes->fetch_assoc()) {
        $c = (int) $row['c'];
        $st = (string) $row['status'];
        $leadStats['total'] += $c;
        if (in_array($st, $contactedSet, true)) {
            $leadStats['contacted'] += $c;
        } elseif (in_array($st, $followSet, true)) {
            $leadStats['follow_up'] += $c;
        } elseif ($st === 'converted') {
            $leadStats['converted'] += $c;
        } elseif (in_array($st, $lostSet, true)) {
            $leadStats['lost'] += $c;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Leads | Calling CRM</title>
  <?php include 'includes/header-links.php'; ?>
  <link rel="stylesheet" href="plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" href="plugins/datatables-responsive/css/responsive.bootstrap4.min.css">
  <link rel="stylesheet" href="custom/leads-page.css?v=20">
</head>
<body class="hold-transition sidebar-mini sidebar-collapse layout-fixed">
<div class="wrapper">
  <?php include 'includes/top-header.php'; ?>
  <?php include 'includes/sidebar.php'; ?>
  <div class="content-wrapper">
    <?php include 'includes/page-header.php'; ?>
    <section class="content">
      <div class="container-fluid leads-page">

        <?php if ($msg): ?>
          <div class="alert alert-<?= htmlspecialchars($msgType === 'error' ? 'danger' : $msgType) ?>">
            <?= htmlspecialchars($msg) ?>
          </div>
        <?php endif; ?>
        <div id="leadsAlert" class="alert" style="display:none;"></div>

        <?php if ($activeImportLabel !== ''): ?>
          <div class="alert alert-info py-2">
            Showing leads from Excel file:
            <strong><?= htmlspecialchars($activeImportLabel) ?></strong>
            <a href="leads.php" class="float-right">Clear file filter</a>
          </div>
        <?php endif; ?>

        <div class="leads-hero">
          <div>
            <h1>Leads</h1>
            <p>Manage and track all your potential customers in one place.</p>
          </div>
          <div class="leads-hero-actions">
            <button type="button" class="btn leads-btn-settings" id="btnLeadsSettings" data-toggle="modal" data-target="#leadsColumnsModal" title="Table settings" aria-label="Table settings">
              <i class="fas fa-cog"></i>
            </button>
            <a href="import_excel.php" class="btn leads-btn-import"><i class="fas fa-file-upload"></i> Import</a>
            <button type="button" class="btn leads-btn-add" id="btnAddLead">
              <i class="fas fa-plus"></i> Add Lead
            </button>
          </div>
        </div>

        <div class="leads-stats">
          <div class="leads-stat-card">
            <div class="leads-stat-icon total"><i class="fas fa-users"></i></div>
            <div class="leads-stat-meta">
              <strong><?= number_format($leadStats['total']) ?></strong>
              <span>Total Leads</span>
            </div>
          </div>
          <div class="leads-stat-card">
            <div class="leads-stat-icon contacted"><i class="fas fa-phone-alt"></i></div>
            <div class="leads-stat-meta">
              <strong><?= number_format($leadStats['contacted']) ?></strong>
              <span>Contacted</span>
            </div>
          </div>
          <div class="leads-stat-card">
            <div class="leads-stat-icon follow"><i class="far fa-clock"></i></div>
            <div class="leads-stat-meta">
              <strong><?= number_format($leadStats['follow_up']) ?></strong>
              <span>Follow Up</span>
            </div>
          </div>
          <div class="leads-stat-card">
            <div class="leads-stat-icon converted"><i class="fas fa-handshake"></i></div>
            <div class="leads-stat-meta">
              <strong><?= number_format($leadStats['converted']) ?></strong>
              <span>Converted</span>
            </div>
          </div>
          <div class="leads-stat-card">
            <div class="leads-stat-icon lost"><i class="fas fa-times"></i></div>
            <div class="leads-stat-meta">
              <strong><?= number_format($leadStats['lost']) ?></strong>
              <span>Lost</span>
            </div>
          </div>
        </div>

        <div class="leads-panel">
          <div class="leads-filters">
            <select id="filter_import" class="custom-select">
              <option value="">All Excel Files</option>
              <option value="manual" <?= $preselectedImportId === 0 && isset($_GET['import_id']) && $_GET['import_id'] === 'manual' ? 'selected' : '' ?>>Manual / No File</option>
              <?php foreach ($imports as $imp): ?>
                <?php
                  $label = ($imp['original_filename'] ?: $imp['filename']) ?: ('Import #' . $imp['id']);
                  $label .= ' (' . (int) $imp['lead_count'] . ' leads · ' . $imp['created_at'] . ')';
                ?>
                <option value="<?= (int) $imp['id'] ?>" <?= $preselectedImportId === (int) $imp['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <select id="filter_status" class="custom-select">
              <option value="">All Status</option>
              <?php foreach ($statuses as $k => $label): ?>
                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
            <select id="filter_category" class="custom-select">
              <option value="">All Categories</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
              <?php endforeach; ?>
            </select>
            <select id="filter_city" class="custom-select">
              <option value="">All Cities</option>
              <?php foreach ($cities as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
              <?php endforeach; ?>
            </select>
            <select id="filter_executive" class="custom-select">
              <option value="">All Executives</option>
              <option value="unassigned">Unassigned</option>
              <?php foreach ($executives as $e): ?>
                <option value="<?= (int) $e['id'] ?>"><?= htmlspecialchars($e['name'] ?: $e['username']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="bulk-bar alert alert-light border py-2">
            <div class="form-inline flex-wrap">
              <span class="mr-3"><strong id="selectedCount">0</strong> selected</span>
              <select id="bulk_status" class="custom-select custom-select-sm mr-2 mb-1" style="width:auto;">
                <option value="">Set Status…</option>
                <?php foreach ($statuses as $k => $label): ?>
                  <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" id="btnBulkStatus" class="btn btn-sm btn-primary mr-2 mb-1">Apply Status</button>
              <select id="bulk_assign" class="custom-select custom-select-sm mr-2 mb-1" style="width:auto;">
                <option value="">Assign To…</option>
                <option value="0">Unassign</option>
                <?php foreach ($executives as $e): ?>
                  <option value="<?= (int) $e['id'] ?>"><?= htmlspecialchars($e['name'] ?: $e['username']) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" id="btnBulkAssign" class="btn btn-sm btn-info mr-2 mb-1">Assign</button>
              <button type="button" id="btnBulkDelete" class="btn btn-sm btn-danger mb-1">Delete Selected</button>
            </div>
          </div>

          <table id="leadsTable" class="table table-striped table-hover" style="width:100%">
            <thead>
              <tr>
                <th width="30"><input type="checkbox" id="checkAll"></th>
                <th>ID</th>
                <th>Business</th>
                <th>Phone</th>
                <th>Email</th>
                <th>City</th>
                <th>Category</th>
                <th>Status</th>
                <th>Created</th>
                <th width="140">Actions</th>
              </tr>
            </thead>
          </table>
        </div>

      </div>
    </section>
  </div>
  <?php include 'includes/copyright.php'; ?>
</div>

<!-- Column settings modal -->
<div class="modal fade" id="leadsColumnsModal" tabindex="-1" role="dialog" aria-labelledby="leadsColumnsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content leads-settings-modal">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="leadsColumnsModalLabel">Table View Settings</h5>
          <p class="leads-settings-sub">Choose which columns to show in the leads table.</p>
        </div>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div class="leads-settings-actions-top">
          <button type="button" class="btn btn-sm leads-settings-chip" id="btnColsSelectAll">Select All</button>
          <button type="button" class="btn btn-sm leads-settings-chip" id="btnColsClearAll">Clear All</button>
          <button type="button" class="btn btn-sm leads-settings-chip" id="btnColsReset">Reset to Default</button>
        </div>
        <div class="leads-settings-grid" id="leadsColumnsCheckboxes">
          <label class="leads-settings-item"><input type="checkbox" class="col-toggle" data-col="1" checked> <span>ID</span></label>
          <label class="leads-settings-item"><input type="checkbox" class="col-toggle" data-col="2" checked> <span>Business</span></label>
          <label class="leads-settings-item"><input type="checkbox" class="col-toggle" data-col="3" checked> <span>Phone</span></label>
          <label class="leads-settings-item"><input type="checkbox" class="col-toggle" data-col="4" checked> <span>Email</span></label>
          <label class="leads-settings-item"><input type="checkbox" class="col-toggle" data-col="5" checked> <span>City</span></label>
          <label class="leads-settings-item"><input type="checkbox" class="col-toggle" data-col="6" checked> <span>Category</span></label>
          <label class="leads-settings-item"><input type="checkbox" class="col-toggle" data-col="7" checked> <span>Status</span></label>
          <label class="leads-settings-item"><input type="checkbox" class="col-toggle" data-col="8" checked> <span>Created</span></label>
          <label class="leads-settings-item"><input type="checkbox" class="col-toggle" data-col="9" checked> <span>Actions</span></label>
        </div>
        <p class="leads-settings-hint">Selection &amp; checkbox column stays visible for bulk actions.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn leads-settings-cancel" data-dismiss="modal">Close</button>
        <button type="button" class="btn leads-settings-apply" id="btnColsApply">Apply</button>
      </div>
    </div>
  </div>
</div>

<!-- Add Lead modal -->
<div class="modal fade" id="addLeadModal" tabindex="-1" role="dialog" aria-labelledby="addLeadModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable" role="document">
    <div class="modal-content leads-add-modal">
      <form id="addLeadForm" autocomplete="off">
        <div class="modal-header">
          <div>
            <h5 class="modal-title" id="addLeadModalLabel">Add Lead</h5>
            <p class="leads-settings-sub" id="addLeadModalSub">Create a new lead without leaving this page.</p>
          </div>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <div id="addLeadFormAlert" class="alert" style="display:none;"></div>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="0">
          <input type="hidden" name="ajax" value="1">
          <div class="row">
            <div class="col-md-6 form-group">
              <label>Business Name</label>
              <input type="text" name="business_name" class="form-control" placeholder="Business name">
            </div>
            <div class="col-md-6 form-group">
              <label>Owner / Contact Name</label>
              <input type="text" name="contact_name" class="form-control" placeholder="Contact person">
            </div>
            <div class="col-md-4 form-group">
              <label>Phone</label>
              <input type="text" name="phone" class="form-control" placeholder="Phone number">
            </div>
            <div class="col-md-4 form-group">
              <label>Alternate Phone</label>
              <input type="text" name="alternate_phone" class="form-control" placeholder="Optional">
            </div>
            <div class="col-md-4 form-group">
              <label>Email</label>
              <input type="email" name="email" class="form-control" placeholder="email@example.com">
            </div>
            <div class="col-md-6 form-group">
              <label>Website</label>
              <input type="text" name="website" class="form-control" placeholder="https://">
            </div>
            <div class="col-md-6 form-group">
              <label>Category</label>
              <input type="text" name="category" class="form-control" list="addLeadCategories" placeholder="Category">
              <datalist id="addLeadCategories">
                <?php foreach ($categories as $c): ?>
                  <option value="<?= htmlspecialchars($c) ?>">
                <?php endforeach; ?>
              </datalist>
            </div>
            <div class="col-md-12 form-group">
              <label>Address</label>
              <input type="text" name="address" class="form-control" placeholder="Full address">
            </div>
            <div class="col-md-3 form-group">
              <label>City</label>
              <input type="text" name="city" class="form-control" list="addLeadCities">
              <datalist id="addLeadCities">
                <?php foreach ($cities as $c): ?>
                  <option value="<?= htmlspecialchars($c) ?>">
                <?php endforeach; ?>
              </datalist>
            </div>
            <div class="col-md-3 form-group">
              <label>State</label>
              <input type="text" name="state" class="form-control">
            </div>
            <div class="col-md-3 form-group">
              <label>Country</label>
              <input type="text" name="country" class="form-control">
            </div>
            <div class="col-md-3 form-group">
              <label>Pincode</label>
              <input type="text" name="pincode" class="form-control">
            </div>
            <div class="col-md-4 form-group">
              <label>Status</label>
              <select name="status" class="custom-select">
                <?php foreach ($statuses as $k => $label): ?>
                  <option value="<?= htmlspecialchars($k) ?>" <?= $k === 'new' ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 form-group">
              <label>Priority</label>
              <select name="priority" class="custom-select">
                <?php foreach ($priorities as $k => $label): ?>
                  <option value="<?= htmlspecialchars($k) ?>" <?= $k === 'medium' ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 form-group">
              <label>Executive</label>
              <select name="assigned_to" class="custom-select">
                <option value="0">Unassigned</option>
                <?php foreach ($executives as $e): ?>
                  <option value="<?= (int) $e['id'] ?>"><?= htmlspecialchars($e['name'] ?: $e['username']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-12 form-group">
              <label>Notes</label>
              <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn leads-settings-cancel" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn leads-settings-apply" id="btnSaveLead">
            <i class="fas fa-save"></i> Save Lead
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- View Lead modal -->
<div class="modal fade" id="viewLeadModal" tabindex="-1" role="dialog" aria-labelledby="viewLeadModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable" role="document">
    <div class="modal-content leads-add-modal leads-view-modal">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="viewLeadModalLabel">Lead Details</h5>
          <p class="leads-settings-sub" id="viewLeadSub">Loading…</p>
        </div>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div id="viewLeadLoading" class="leads-view-loading">
          <i class="fas fa-spinner fa-spin"></i> Loading lead…
        </div>
        <div id="viewLeadError" class="alert alert-danger" style="display:none;"></div>
        <div id="viewLeadContent" style="display:none;">
          <div class="leads-view-hero">
            <div id="viewLeadStatus" class="d-inline-block"></div>
            <div class="leads-view-meta" id="viewLeadMeta"></div>
          </div>

          <ul class="nav nav-pills leads-view-tabs" id="viewLeadTabs" role="tablist">
            <li class="nav-item">
              <a class="nav-link active" id="viewTabDetails-tab" data-toggle="pill" href="#viewTabDetails" role="tab">Details</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" id="viewTabSummary-tab" data-toggle="pill" href="#viewTabSummary" role="tab">Calling Summary</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" id="viewTabHistory-tab" data-toggle="pill" href="#viewTabHistory" role="tab">Call History</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" id="viewTabFollowups-tab" data-toggle="pill" href="#viewTabFollowups" role="tab">Follow-ups</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" id="viewTabNotes-tab" data-toggle="pill" href="#viewTabNotes" role="tab">Notes &amp; Remarks</a>
            </li>
          </ul>

          <div class="tab-content leads-view-tab-content" id="viewLeadTabContent">
            <div class="tab-pane fade show active" id="viewTabDetails" role="tabpanel">
              <div class="row leads-view-grid" id="viewLeadFields"></div>
            </div>

            <div class="tab-pane fade" id="viewTabSummary" role="tabpanel">
              <div class="leads-view-section-card">
                <div class="leads-view-section-title"><i class="fas fa-headset"></i> Log Call Details</div>
                <form id="viewCallForm" autocomplete="off">
                  <input type="hidden" name="action" value="log_call">
                  <input type="hidden" name="lead_id" id="viewCallLeadId" value="0">
                  <div class="row">
                    <div class="col-md-3 form-group">
                      <label>Phone</label>
                      <input type="text" name="phone_dialed" id="viewCallPhone" class="form-control" required>
                    </div>
                    <div class="col-md-3 form-group">
                      <label>Call Date / Time</label>
                      <input type="datetime-local" name="called_at" id="viewCallAt" class="form-control" required>
                    </div>
                    <div class="col-md-3 form-group">
                      <label>Executive</label>
                      <select name="executive_id" id="viewCallExec" class="custom-select">
                        <?php foreach ($executives as $e): ?>
                          <option value="<?= (int) $e['id'] ?>"><?= htmlspecialchars($e['name'] ?: $e['username']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-3 form-group">
                      <label>Call Result</label>
                      <select name="call_status" class="custom-select" required>
                        <?php foreach ($callResults as $k => $label): ?>
                          <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-3 form-group">
                      <label>Lead Status</label>
                      <select name="lead_status" id="viewCallLeadStatus" class="custom-select" required>
                        <?php foreach ($statuses as $k => $label): ?>
                          <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-3 form-group">
                      <label>Duration (min)</label>
                      <input type="number" name="duration_min" class="form-control" min="0" value="0">
                    </div>
                    <div class="col-md-3 form-group">
                      <label>Duration (sec)</label>
                      <input type="number" name="duration_sec" class="form-control" min="0" max="59" value="0">
                    </div>
                    <div class="col-md-3 form-group">
                      <label>Next Follow-up</label>
                      <input type="datetime-local" name="next_followup_at" class="form-control">
                    </div>
                    <div class="col-md-6 form-group">
                      <label>Call Notes</label>
                      <textarea name="notes" class="form-control" rows="2" placeholder="Conversation notes…"></textarea>
                    </div>
                    <div class="col-md-6 form-group">
                      <label>Call Remarks</label>
                      <textarea name="remarks" class="form-control" rows="2" placeholder="Internal remarks…"></textarea>
                    </div>
                  </div>
                  <div id="viewCallFormAlert" class="alert" style="display:none;"></div>
                </form>
              </div>
            </div>

            <div class="tab-pane fade" id="viewTabHistory" role="tabpanel">
              <div class="leads-view-section-card">
                <div class="leads-view-section-title"><i class="fas fa-history"></i> Call History</div>
                <div id="viewLeadCallHistory" class="leads-view-timeline"></div>
              </div>
            </div>

            <div class="tab-pane fade" id="viewTabFollowups" role="tabpanel">
              <div class="leads-view-section-card mb-3">
                <div class="leads-view-section-title"><i class="fas fa-plus-circle"></i> Add Follow-up</div>
                <form id="viewFollowupForm" autocomplete="off">
                  <input type="hidden" name="action" value="add_followup">
                  <input type="hidden" name="lead_id" id="viewFollowupLeadId" value="0">
                  <input type="hidden" name="ajax" value="1">
                  <div class="row">
                    <div class="col-md-4 form-group">
                      <label>Scheduled At</label>
                      <input type="datetime-local" name="scheduled_at" id="viewFollowupAt" class="form-control" required>
                    </div>
                    <div class="col-md-4 form-group">
                      <label>Executive</label>
                      <select name="executive_id" id="viewFollowupExec" class="custom-select">
                        <?php foreach ($executives as $e): ?>
                          <option value="<?= (int) $e['id'] ?>"><?= htmlspecialchars($e['name'] ?: $e['username']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-4 form-group">
                      <label>Priority</label>
                      <select name="priority" class="custom-select">
                        <?php foreach ($priorities as $k => $label): ?>
                          <option value="<?= htmlspecialchars($k) ?>" <?= $k === 'medium' ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-12 form-group mb-0">
                      <label>Notes</label>
                      <textarea name="notes" class="form-control" rows="2" placeholder="Follow-up notes…"></textarea>
                    </div>
                  </div>
                  <div id="viewFollowupFormAlert" class="alert mt-2" style="display:none;"></div>
                </form>
              </div>
              <div class="leads-view-section-card">
                <div class="leads-view-section-title"><i class="fas fa-clock"></i> Scheduled Follow-ups</div>
                <div id="viewLeadFollowups" class="leads-view-timeline"></div>
              </div>
            </div>

            <div class="tab-pane fade" id="viewTabNotes" role="tabpanel">
              <form id="viewNotesForm" autocomplete="off">
                <input type="hidden" name="action" value="save_notes">
                <input type="hidden" name="lead_id" id="viewNotesLeadId" value="0">
                <input type="hidden" name="ajax" value="1">
                <div class="leads-view-notes" id="viewLeadNotesWrap">
                  <div class="leads-view-field-card">
                    <div class="leads-view-label">Notes</div>
                    <textarea name="notes" id="viewLeadNotesInput" class="form-control" rows="6" placeholder="Lead notes…"></textarea>
                  </div>
                  <div class="leads-view-field-card">
                    <div class="leads-view-label">Remarks</div>
                    <textarea name="remarks" id="viewLeadRemarksInput" class="form-control" rows="6" placeholder="Internal remarks…"></textarea>
                  </div>
                </div>
                <div id="viewNotesFormAlert" class="alert mt-2" style="display:none;"></div>
              </form>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer leads-view-footer">
        <button type="button" class="btn leads-settings-cancel" data-dismiss="modal">Close</button>

        <span class="leads-view-footer-actions" data-tab="viewTabDetails">
          <a href="#" class="btn leads-btn-import" id="viewLeadFullPage" target="_blank" rel="noopener">
            <i class="fas fa-external-link-alt"></i> Full Page
          </a>
        <a href="#" class="btn leads-settings-apply" id="viewLeadEdit">
          <i class="fas fa-pen"></i> Edit
        </a>
          <button type="button" class="btn leads-btn-add" id="viewLeadGotoCall">
            <i class="fas fa-phone"></i> Log Call
          </button>
        </span>

        <span class="leads-view-footer-actions" data-tab="viewTabSummary" style="display:none;">
          <button type="button" class="btn leads-btn-import" id="viewLeadGotoHistory">
            <i class="fas fa-history"></i> View History
          </button>
          <button type="button" class="btn leads-btn-add" id="viewLeadSaveCall">
            <i class="fas fa-save"></i> Save Call
          </button>
        </span>

        <span class="leads-view-footer-actions" data-tab="viewTabHistory" style="display:none;">
          <button type="button" class="btn leads-btn-add" id="viewLeadGotoCallFromHistory">
            <i class="fas fa-plus"></i> Add Call
          </button>
        </span>

        <span class="leads-view-footer-actions" data-tab="viewTabFollowups" style="display:none;">
          <button type="button" class="btn leads-btn-add" id="viewLeadSaveFollowup">
            <i class="fas fa-save"></i> Save Follow-up
          </button>
        </span>

        <span class="leads-view-footer-actions" data-tab="viewTabNotes" style="display:none;">
          <button type="button" class="btn leads-btn-add" id="viewLeadSaveNotes">
            <i class="fas fa-save"></i> Save Notes
          </button>
        </span>
      </div>
    </div>
  </div>
</div>

<!-- Confirm delete modal -->
<div class="modal fade" id="confirmDeleteModal" tabindex="-1" role="dialog" aria-labelledby="confirmDeleteModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content leads-confirm-modal">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="confirmDeleteModalLabel">Delete Lead</h5>
          <p class="leads-settings-sub" id="confirmDeleteSub">This action cannot be undone.</p>
        </div>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div class="leads-confirm-icon"><i class="fas fa-exclamation-triangle"></i></div>
        <p class="leads-confirm-msg" id="confirmDeleteMsg">Are you sure you want to delete this lead?</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn leads-settings-cancel" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn leads-confirm-delete" id="btnConfirmDelete">
          <i class="fas fa-trash"></i> Delete
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Center success / status popup -->
<div id="leadsCenterPopup" class="leads-center-popup" aria-live="polite" aria-hidden="true">
  <div class="leads-center-popup-card">
    <div class="leads-center-popup-icon" id="leadsCenterPopupIcon"><i class="fas fa-check"></i></div>
    <h4 id="leadsCenterPopupTitle">Deleted</h4>
    <p id="leadsCenterPopupMsg">Lead deleted successfully.</p>
  </div>
</div>

<?php include 'includes/footer-links.php'; ?>
<script src="plugins/datatables/jquery.dataTables.min.js"></script>
<script src="plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
<script src="plugins/datatables-responsive/js/dataTables.responsive.min.js"></script>
<script src="plugins/datatables-responsive/js/responsive.bootstrap4.min.js"></script>
<script>
(function ($) {
  function showAlert(type, msg) {
    $('#leadsAlert').removeClass('alert-success alert-danger alert-warning')
      .addClass('alert-' + type).text(msg).show();
  }

  function selectedIds() {
    return $('.lead-check:checked').map(function () { return $(this).val(); }).get();
  }

  function updateBulkBar() {
    var n = selectedIds().length;
    $('#selectedCount').text(n);
    $('.bulk-bar').toggle(n > 0);
    $('#checkAll').prop('checked', n > 0 && n === $('.lead-check').length);
  }

  function syncImportUrl() {
    var importId = $('#filter_import').val();
    var url = new URL(window.location.href);
    if (importId) {
      url.searchParams.set('import_id', importId);
    } else {
      url.searchParams.delete('import_id');
    }
    window.history.replaceState({}, '', url.pathname + url.search);
  }

  var FILTERS_KEY = 'crm-leads-filters';

  function readFilters() {
    return {
      import: $('#filter_import').val() || '',
      status: $('#filter_status').val() || '',
      category: $('#filter_category').val() || '',
      city: $('#filter_city').val() || '',
      executive: $('#filter_executive').val() || ''
    };
  }

  function saveFilters() {
    try {
      localStorage.setItem(FILTERS_KEY, JSON.stringify(readFilters()));
    } catch (e) {}
  }

  function restoreFilters() {
    try {
      var raw = localStorage.getItem(FILTERS_KEY);
      if (!raw) return;
      var f = JSON.parse(raw);
      if (!f || typeof f !== 'object') return;
      // URL import_id wins over saved filter
      var urlImport = new URL(window.location.href).searchParams.get('import_id');
      if (urlImport === null && f.import != null && $('#filter_import option[value="' + f.import + '"]').length) {
        $('#filter_import').val(f.import);
      }
      if (f.status && $('#filter_status option[value="' + f.status + '"]').length) {
        $('#filter_status').val(f.status);
      }
      if (f.category && $('#filter_category option[value="' + f.category + '"]').length) {
        $('#filter_category').val(f.category);
      }
      if (f.city && $('#filter_city option[value="' + f.city + '"]').length) {
        $('#filter_city').val(f.city);
      }
      if (f.executive != null && f.executive !== '' && $('#filter_executive option[value="' + f.executive + '"]').length) {
        $('#filter_executive').val(f.executive);
      }
    } catch (e) {}
  }

  restoreFilters();

  // Footer: First | Previous | up to 3 page numbers | Next | Last
  $.fn.dataTable.ext.pager.simple_current = function (page, pages) {
    var buttons = ['first', 'previous'];
    var start = Math.max(0, Math.min(page - 1, pages - 3));
    var end = Math.min(pages, start + 3);
    for (var i = start; i < end; i++) {
      buttons.push(i);
    }
    buttons.push('next', 'last');
    return buttons;
  };

  var table = $('#leadsTable').DataTable({
    processing: true,
    serverSide: true,
    responsive: true,
    paging: true,
    pagingType: 'simple_current',
    pageLength: 25,
    lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
    searchDelay: 400,
    stateSave: true,
    stateDuration: 60 * 60 * 8,
    order: [[1, 'desc']],
    dom: '<"leads-toolbar"<"leads-length"l><"leads-search"f>>rt<"leads-footer"<"leads-info"i><"leads-pager"p>>',
    language: {
      search: '',
      searchPlaceholder: 'Search leads...',
      lengthMenu: 'Show _MENU_ entries',
      info: 'Showing _START_–_END_ of _TOTAL_ entries',
      infoEmpty: 'Showing 0–0 of 0 entries',
      infoFiltered: '(filtered from _MAX_ total)',
      thousands: ',',
      processing: '<div class="leads-processing"><i class="fas fa-spinner fa-spin"></i> Loading leads…</div>',
      paginate: {
        first: 'First',
        previous: 'Previous',
        next: 'Next',
        last: 'Last'
      },
      zeroRecords: 'No matching leads found'
    },
    ajax: {
      url: 'sql/leads_datatable.php',
      type: 'POST',
      data: function (d) {
        var f = readFilters();
        d.filter_import = f.import;
        d.filter_status = f.status;
        d.filter_category = f.category;
        d.filter_city = f.city;
        d.filter_executive = f.executive;
      }
    },
    columns: [
      { data: 'checkbox', orderable: false, searchable: false },
      { data: 'id' },
      { data: 'business_name', className: 'crm-col-business' },
      { data: 'phone' },
      { data: 'email', className: 'crm-col-email' },
      { data: 'city', className: 'crm-col-city' },
      { data: 'category', className: 'crm-col-category' },
      { data: 'status', orderable: true },
      { data: 'created_at' },
      { data: 'actions', orderable: false, searchable: false }
    ],
    columnDefs: [
      {
        targets: [2, 4, 5, 6],
        createdCell: function (td, cellData) {
          var text = $('<div>').html(cellData).text();
          if (text && text !== '—') {
            $(td).attr('title', text);
          }
        }
      }
    ],
    drawCallback: function () {
      updateBulkBar();
    }
  });

  /* ===== Column visibility settings (localStorage) ===== */
  var COLS_KEY = 'crm-leads-visible-cols';
  var DEFAULT_COLS = [1, 2, 3, 4, 5, 6, 7, 8, 9];
  var TOGGLE_COLS = [1, 2, 3, 4, 5, 6, 7, 8, 9];

  function getSavedCols() {
    try {
      var raw = localStorage.getItem(COLS_KEY);
      if (!raw) return DEFAULT_COLS.slice();
      var parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) return DEFAULT_COLS.slice();
      return parsed.map(Number).filter(function (n) {
        return TOGGLE_COLS.indexOf(n) !== -1;
      });
    } catch (e) {
      return DEFAULT_COLS.slice();
    }
  }

  function saveCols(cols) {
    try {
      localStorage.setItem(COLS_KEY, JSON.stringify(cols));
    } catch (e) {}
  }

  function syncCheckboxes(cols) {
    $('.col-toggle').each(function () {
      var col = Number($(this).data('col'));
      $(this).prop('checked', cols.indexOf(col) !== -1);
    });
  }

  function applyColumnVisibility(cols, persist) {
    if (!cols.length) {
      cols = [2];
      syncCheckboxes(cols);
    }
    table.columns().every(function () {
      var idx = this.index();
      if (idx === 0) {
        this.visible(true, false);
        return;
      }
      this.visible(cols.indexOf(idx) !== -1, false);
    });
    table.columns.adjust().draw(false);
    if (persist !== false) {
      saveCols(cols);
    }
  }

  function readCheckedCols() {
    return $('.col-toggle:checked').map(function () {
      return Number($(this).data('col'));
    }).get();
  }

  // Restore saved prefs on load
  (function restoreCols() {
    var saved = getSavedCols();
    syncCheckboxes(saved);
    applyColumnVisibility(saved, false);
  })();

  $('#leadsColumnsModal').on('show.bs.modal', function () {
    syncCheckboxes(getSavedCols());
  });

  $(document).on('change', '.col-toggle', function () {
    applyColumnVisibility(readCheckedCols(), true);
  });

  $('#btnColsSelectAll').on('click', function () {
    $('.col-toggle').prop('checked', true);
    applyColumnVisibility(TOGGLE_COLS.slice(), true);
  });

  $('#btnColsClearAll').on('click', function () {
    $('.col-toggle').prop('checked', false);
    // Keep Business so the table is never empty
    $('.col-toggle[data-col="2"]').prop('checked', true);
    applyColumnVisibility([2], true);
  });

  $('#btnColsReset').on('click', function () {
    syncCheckboxes(DEFAULT_COLS);
    applyColumnVisibility(DEFAULT_COLS.slice(), true);
  });

  $('#btnColsApply').on('click', function () {
    applyColumnVisibility(readCheckedCols(), true);
    $('#leadsColumnsModal').modal('hide');
  });

  /* ===== Add / Edit Lead modal ===== */
  var leadFormMode = 'add'; // 'add' | 'edit'

  function showAddLeadAlert(type, msg) {
    $('#addLeadFormAlert')
      .removeClass('alert-success alert-danger alert-warning')
      .addClass('alert-' + type)
      .text(msg)
      .show();
  }

  function resetLeadFormForAdd() {
    leadFormMode = 'add';
    $('#addLeadForm')[0].reset();
    $('#addLeadForm [name="id"]').val('0');
    $('#addLeadForm [name="status"]').val('new');
    $('#addLeadForm [name="priority"]').val('medium');
    $('#addLeadForm [name="assigned_to"]').val('0');
    $('#addLeadModalLabel').text('Add Lead');
    $('#addLeadModalSub').text('Create a new lead without leaving this page.');
    $('#addLeadFormAlert').hide().text('');
    $('#btnSaveLead').prop('disabled', false).html('<i class="fas fa-save"></i> Save Lead');
  }

  function fillLeadForm(lead) {
    var $form = $('#addLeadForm');
    $form.find('[name="id"]').val(lead.id || 0);
    $form.find('[name="business_name"]').val(lead.business_name || '');
    $form.find('[name="contact_name"]').val(lead.contact_name || '');
    $form.find('[name="phone"]').val(lead.phone || '');
    $form.find('[name="alternate_phone"]').val(lead.alternate_phone || '');
    $form.find('[name="email"]').val(lead.email || '');
    $form.find('[name="website"]').val(lead.website || '');
    $form.find('[name="category"]').val(lead.category || '');
    $form.find('[name="address"]').val(lead.address || '');
    $form.find('[name="city"]').val(lead.city || '');
    $form.find('[name="state"]').val(lead.state || '');
    $form.find('[name="country"]').val(lead.country || '');
    $form.find('[name="pincode"]').val(lead.pincode || '');
    $form.find('[name="status"]').val(lead.status || 'new');
    $form.find('[name="priority"]').val(lead.priority || 'medium');
    $form.find('[name="assigned_to"]').val(String(lead.assigned_to || 0));
    $form.find('[name="notes"]').val(lead.notes || '');
  }

  function openAddLeadModal() {
    resetLeadFormForAdd();
    $('#addLeadModal').modal('show');
  }

  function openEditLeadModal(id) {
    leadFormMode = 'edit';
    $('#addLeadFormAlert').hide().text('');
    $('#addLeadModalLabel').text('Edit Lead');
    $('#addLeadModalSub').text('Lead #' + id);
    $('#btnSaveLead').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Loading…');
    $('#addLeadModal').modal('show');

    $.ajax({
      url: 'sql/leads_actions.php',
      method: 'POST',
      data: { action: 'get', id: id, ajax: 1 },
      dataType: 'json',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).done(function (res) {
      if (res && res.success && res.lead) {
        fillLeadForm(res.lead);
        $('#addLeadModalLabel').text('Edit Lead');
        $('#addLeadModalSub').text((res.lead.business_name || 'Lead') + ' · #' + res.lead.id);
        $('#btnSaveLead').prop('disabled', false).html('<i class="fas fa-save"></i> Update Lead');
      } else {
        showAddLeadAlert('danger', (res && res.message) || 'Could not load lead.');
        $('#btnSaveLead').prop('disabled', false).html('<i class="fas fa-save"></i> Update Lead');
      }
    }).fail(function (xhr) {
      var msg = 'Could not load lead.';
      try { msg = JSON.parse(xhr.responseText).message || msg; } catch (e) {}
      showAddLeadAlert('danger', msg);
      $('#btnSaveLead').prop('disabled', false).html('<i class="fas fa-save"></i> Update Lead');
    });
  }

  $('#btnAddLead').on('click', function () {
    openAddLeadModal();
  });

  $(document).on('click', '.btn-edit-lead', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $('.crm-act-more').removeClass('open').find('.crm-act-more-btn').attr('aria-expanded', 'false');
    var id = $(this).data('id');
    if (id) openEditLeadModal(id);
  });

  $('#addLeadForm').on('submit', function (e) {
    e.preventDefault();
    var $form = $(this);
    var business = $.trim($form.find('[name="business_name"]').val());
    var phone = $.trim($form.find('[name="phone"]').val());
    if (!business && !phone) {
      showAddLeadAlert('warning', 'Business name or phone is required.');
      return;
    }

    var $btn = $('#btnSaveLead');
    var savingLabel = leadFormMode === 'edit' ? 'Update Lead' : 'Save Lead';
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

    $.ajax({
      url: 'sql/leads_actions.php',
      method: 'POST',
      data: $form.serialize(),
      dataType: 'json',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).done(function (res) {
      if (res && res.success) {
        $('#addLeadModal').modal('hide');
        showAlert('success', res.message || (leadFormMode === 'edit' ? 'Lead updated successfully.' : 'Lead created successfully.'));
        table.ajax.reload(null, false);
        if (leadFormMode === 'edit' && currentViewLead && Number(currentViewLead.id) === Number($form.find('[name="id"]').val())) {
          reloadViewLead();
        }
      } else {
        showAddLeadAlert('danger', (res && res.message) || 'Could not save lead.');
        $btn.prop('disabled', false).html('<i class="fas fa-save"></i> ' + savingLabel);
      }
    }).fail(function (xhr) {
      var msg = 'Could not save lead.';
      try { msg = JSON.parse(xhr.responseText).message || msg; } catch (err) {}
      showAddLeadAlert('danger', msg);
      $btn.prop('disabled', false).html('<i class="fas fa-save"></i> ' + savingLabel);
    });
  });

  /* ===== View Lead modal ===== */
  function escHtml(str) {
    return $('<div>').text(str == null ? '' : String(str)).html();
  }

  function dash(val) {
    var v = $.trim(val == null ? '' : String(val));
    return v ? escHtml(v) : '—';
  }

  function telHref(phone) {
    return 'tel:' + String(phone || '').replace(/\s+/g, '');
  }

  function websiteHref(url) {
    var u = $.trim(url || '');
    if (!u) return '';
    if (!/^https?:\/\//i.test(u)) u = 'https://' + u;
    return u;
  }

  function viewField(label, valueHtml, colClass) {
    colClass = colClass || 'col-md-4';
    return '<div class="' + colClass + '">'
      + '<div class="leads-view-field-card">'
      + '<div class="leads-view-label">' + escHtml(label) + '</div>'
      + '<div class="leads-view-value">' + valueHtml + '</div>'
      + '</div>'
      + '</div>';
  }

  var currentViewLead = null;

  function toLocalInputValue(dateObj) {
    var d = dateObj || new Date();
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
      + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function setViewFooterTab(tabId) {
    $('.leads-view-footer-actions').hide();
    $('.leads-view-footer-actions[data-tab="' + tabId + '"]').css('display', 'inline-flex');
  }

  function showViewFormAlert(sel, type, msg) {
    $(sel).removeClass('alert-success alert-danger alert-warning')
      .addClass('alert-' + type).text(msg).show();
  }

  function renderCallHistory(calls) {
    calls = Array.isArray(calls) ? calls : [];
    if (!calls.length) {
      $('#viewLeadCallHistory').html('<p class="leads-view-empty">No calls logged yet.</p>');
      return;
    }
    var header = '<div class="leads-view-history-item leads-view-history-row leads-view-history-head">'
      + '<span class="lvh-cell lvh-exec">Executive</span>'
      + '<span class="lvh-cell lvh-date">Date / Time</span>'
      + '<span class="lvh-cell lvh-phone">Phone</span>'
      + '<span class="lvh-cell lvh-result">Call Result</span>'
      + '<span class="lvh-cell lvh-dur">Duration</span>'
      + '<span class="lvh-cell lvh-status">Lead Status</span>'
      + '<span class="lvh-cell lvh-notes">Notes</span>'
      + '<span class="lvh-cell lvh-remarks">Remarks</span>'
      + '<span class="lvh-cell lvh-follow">Follow-up</span>'
      + '</div>';

    var rows = calls.map(function (call) {
      var notes = call.notes || '';
      var remarks = call.remarks || '';
      var follow = call.next_followup_at || '';
      var statusBadge = call.lead_status_after_badge || call.lead_status_badge || '';
      return '<div class="leads-view-history-item leads-view-history-row">'
        + '<span class="lvh-cell lvh-exec" title="' + escHtml(call.executive_name || call.executive || 'Executive') + '">'
        + '<strong>' + escHtml(call.executive_name || call.executive || 'Executive') + '</strong>'
        + '</span>'
        + '<span class="lvh-cell lvh-date">' + escHtml(call.called_at || '—') + '</span>'
        + '<span class="lvh-cell lvh-phone">' + escHtml(call.phone_dialed || '—') + '</span>'
        + '<span class="lvh-cell lvh-result">' + escHtml(call.call_status_label || call.call_status || '—') + '</span>'
        + '<span class="lvh-cell lvh-dur">' + escHtml(call.duration_label || '0:00') + '</span>'
        + '<span class="lvh-cell lvh-status">' + (statusBadge || '—') + '</span>'
        + '<span class="lvh-cell lvh-notes" title="' + escHtml(notes) + '">' + (notes ? escHtml(notes) : '—') + '</span>'
        + '<span class="lvh-cell lvh-remarks" title="' + escHtml(remarks) + '">' + (remarks ? escHtml(remarks) : '—') + '</span>'
        + '<span class="lvh-cell lvh-follow" title="' + escHtml(follow) + '">'
        + (follow ? '<i class="fas fa-clock"></i> ' + escHtml(follow) : '—')
        + '</span>'
        + '</div>';
    }).join('');

    $('#viewLeadCallHistory').html(header + rows);
  }

  function renderFollowups(followups) {
    followups = Array.isArray(followups) ? followups : [];
    if (!followups.length) {
      $('#viewLeadFollowups').html('<p class="leads-view-empty">No follow-ups scheduled.</p>');
      return;
    }
    $('#viewLeadFollowups').html(followups.map(function (fu) {
      var statusCls = (fu.status === 'pending') ? 'is-pending' : 'is-done';
      return '<div class="leads-view-history-item">'
        + '<div class="leads-view-history-top">'
        + '<strong>' + escHtml(fu.scheduled_at || '—') + '</strong>'
        + '<span class="leads-view-fu-badge ' + statusCls + '">' + escHtml(fu.status_label || fu.status || '') + '</span>'
        + '</div>'
        + '<div class="leads-view-history-meta">' + escHtml(fu.executive_name || '—') + '</div>'
        + (fu.notes ? '<div class="leads-view-history-note">' + escHtml(fu.notes) + '</div>' : '')
        + '</div>';
    }).join(''));
  }

  function fillViewLeadModal(lead, options) {
    options = options || {};
    currentViewLead = lead;
    var title = lead.business_name || ('Lead #' + lead.id);
    $('#viewLeadModalLabel').text(title);
    $('#viewLeadSub').text('Lead #' + lead.id);
    $('#viewLeadStatus').html(lead.status_badge || '');

    var metaBits = [];
    if (lead.priority_label) metaBits.push('<span>Priority: <strong>' + escHtml(lead.priority_label) + '</strong></span>');
    if (lead.executive) metaBits.push('<span>Executive: <strong>' + escHtml(lead.executive) + '</strong></span>');
    $('#viewLeadMeta').html(metaBits.join(''));

    var phoneHtml = lead.phone
      ? '<a class="leads-view-link" href="' + escHtml(telHref(lead.phone)) + '">' + escHtml(lead.phone) + '</a>'
      : '—';
    var emailHtml = lead.email
      ? '<a class="leads-view-link" href="mailto:' + escHtml(lead.email) + '">' + escHtml(lead.email) + '</a>'
      : '—';
    var webHref = websiteHref(lead.website);
    var webHtml = webHref
      ? '<a class="leads-view-link" href="' + escHtml(webHref) + '" target="_blank" rel="noopener noreferrer">' + escHtml(lead.website) + '</a>'
      : '—';

    var fields = [
      viewField('Owner / Contact', dash(lead.contact_name)),
      viewField('Phone', phoneHtml),
      viewField('Alternate Phone', dash(lead.alternate_phone)),
      viewField('Email', emailHtml),
      viewField('Website', webHtml),
      viewField('Category', dash(lead.category)),
      viewField('Address', dash(lead.address), 'col-md-12'),
      viewField('City', dash(lead.city)),
      viewField('State', dash(lead.state)),
      viewField('Country', dash(lead.country)),
      viewField('Pincode', dash(lead.pincode)),
      viewField('Source', dash(lead.source)),
      viewField('Import File', dash(lead.import_file)),
      viewField('Created', dash(lead.created_at))
    ];

    if (lead.extra && typeof lead.extra === 'object') {
      $.each(lead.extra, function (k, v) {
        var val = (v !== null && typeof v === 'object') ? JSON.stringify(v) : v;
        fields.push(viewField(String(k), dash(val)));
      });
    }

    $('#viewLeadFields').html(fields.join(''));

    renderCallHistory(lead.calls);
    renderFollowups(lead.followups);

    // Prefill call form
    $('#viewCallLeadId').val(lead.id);
    $('#viewCallPhone').val(lead.phone || lead.alternate_phone || '');
    $('#viewCallAt').val(toLocalInputValue());
    $('#viewCallLeadStatus').val(lead.status || 'new');
    $('#viewCallForm [name="duration_min"]').val(0);
    $('#viewCallForm [name="duration_sec"]').val(0);
    $('#viewCallForm [name="next_followup_at"]').val('');
    $('#viewCallForm [name="notes"]').val('');
    $('#viewCallForm [name="remarks"]').val('');
    $('#viewCallForm [name="call_status"]').val('no_answer');
    $('#viewCallFormAlert').hide().text('');

    // Prefill follow-up form
    $('#viewFollowupLeadId').val(lead.id);
    if (!options.keepFollowupForm) {
      $('#viewFollowupAt').val('');
      $('#viewFollowupForm [name="notes"]').val('');
      $('#viewFollowupForm [name="priority"]').val('medium');
    }
    $('#viewFollowupFormAlert').hide().text('');

    // Prefill notes
    $('#viewNotesLeadId').val(lead.id);
    $('#viewLeadNotesInput').val(lead.notes || '');
    $('#viewLeadRemarksInput').val(lead.remarks || '');
    $('#viewNotesFormAlert').hide().text('');

    $('#viewLeadFullPage').attr('href', 'lead_view.php?id=' + lead.id);
    $('#viewLeadEdit').data('id', lead.id).attr('href', '#');

    if (options.tab) {
      $('#viewLeadTabs a[href="#' + options.tab + '"]').tab('show');
      setViewFooterTab(options.tab);
    } else if (!options.keepTab) {
      $('#viewLeadTabs a[href="#viewTabDetails"]').tab('show');
      setViewFooterTab('viewTabDetails');
    }

    $('#viewLeadLoading').hide();
    $('#viewLeadError').hide();
    $('#viewLeadContent').show();
  }

  function reloadViewLead(keepTab) {
    if (!currentViewLead || !currentViewLead.id) return;
    $.ajax({
      url: 'sql/leads_actions.php',
      method: 'POST',
      data: { action: 'get', id: currentViewLead.id, ajax: 1 },
      dataType: 'json',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).done(function (res) {
      if (res && res.success && res.lead) {
        fillViewLeadModal(res.lead, { keepTab: true, keepFollowupForm: true });
        if (keepTab) {
          $('#viewLeadTabs a[href="#' + keepTab + '"]').tab('show');
          setViewFooterTab(keepTab);
        }
        table.ajax.reload(null, false);
      }
    });
  }

  function openViewLeadModal(id, tab) {
    currentViewLead = null;
    $('#viewLeadContent').hide();
    $('#viewLeadError').hide().text('');
    $('#viewLeadLoading').show();
    $('#viewLeadModalLabel').text('Lead Details');
    $('#viewLeadSub').text('Loading…');
    setViewFooterTab(tab || 'viewTabDetails');
    $('#viewLeadModal').modal('show');

    $.ajax({
      url: 'sql/leads_actions.php',
      method: 'POST',
      data: { action: 'get', id: id, ajax: 1 },
      dataType: 'json',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).done(function (res) {
      if (res && res.success && res.lead) {
        fillViewLeadModal(res.lead, { tab: tab || 'viewTabDetails' });
      } else {
        $('#viewLeadLoading').hide();
        $('#viewLeadError').text((res && res.message) || 'Could not load lead.').show();
      }
    }).fail(function (xhr) {
      var msg = 'Could not load lead.';
      try { msg = JSON.parse(xhr.responseText).message || msg; } catch (e) {}
      $('#viewLeadLoading').hide();
      $('#viewLeadError').text(msg).show();
    });
  }

  $(document).on('click', '.btn-view-lead', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var id = $(this).data('id');
    if (id) openViewLeadModal(id);
  });

  $(document).on('click', '.btn-call-lead', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var id = $(this).data('id');
    if (id) openViewLeadModal(id, 'viewTabSummary');
  });

  $('#viewLeadEdit').on('click', function (e) {
    e.preventDefault();
    var id = $(this).data('id') || (currentViewLead && currentViewLead.id);
    if (!id) return;
    $('#viewLeadModal').modal('hide');
    openEditLeadModal(id);
  });

  $('#viewLeadTabs a[data-toggle="pill"]').on('shown.bs.tab', function (e) {
    var tabId = ($(e.target).attr('href') || '').replace('#', '');
    setViewFooterTab(tabId);
  });

  $('#viewLeadGotoCall, #viewLeadGotoCallFromHistory').on('click', function () {
    $('#viewLeadTabs a[href="#viewTabSummary"]').tab('show');
  });

  $('#viewLeadGotoHistory').on('click', function () {
    $('#viewLeadTabs a[href="#viewTabHistory"]').tab('show');
  });

  $('#viewLeadSaveCall').on('click', function () {
    $('#viewCallForm').trigger('submit');
  });

  $('#viewCallForm').on('submit', function (e) {
    e.preventDefault();
    var $btn = $('#viewLeadSaveCall');
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving…');
    $('#viewCallFormAlert').hide();

    $.ajax({
      url: 'sql/call_actions.php',
      method: 'POST',
      data: $(this).serialize(),
      dataType: 'json',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).done(function (res) {
      if (res && res.success) {
        showViewFormAlert('#viewCallFormAlert', 'success', res.message || 'Call saved.');
        reloadViewLead('viewTabHistory');
      } else {
        showViewFormAlert('#viewCallFormAlert', 'danger', (res && res.message) || 'Could not save call.');
      }
    }).fail(function (xhr) {
      var msg = 'Could not save call.';
      try { msg = JSON.parse(xhr.responseText).message || msg; } catch (err) {}
      showViewFormAlert('#viewCallFormAlert', 'danger', msg);
    }).always(function () {
      $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Save Call');
    });
  });

  $('#viewLeadSaveFollowup').on('click', function () {
    $('#viewFollowupForm').trigger('submit');
  });

  $('#viewFollowupForm').on('submit', function (e) {
    e.preventDefault();
    var scheduled = $.trim($('#viewFollowupAt').val());
    if (!scheduled) {
      showViewFormAlert('#viewFollowupFormAlert', 'warning', 'Follow-up date/time is required.');
      return;
    }
    var $btn = $('#viewLeadSaveFollowup');
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving…');
    $('#viewFollowupFormAlert').hide();

    $.ajax({
      url: 'sql/leads_actions.php',
      method: 'POST',
      data: $(this).serialize(),
      dataType: 'json',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).done(function (res) {
      if (res && res.success) {
        showViewFormAlert('#viewFollowupFormAlert', 'success', res.message || 'Follow-up saved.');
        $('#viewFollowupForm')[0].reset();
        $('#viewFollowupLeadId').val(currentViewLead ? currentViewLead.id : 0);
        $('#viewFollowupForm [name="priority"]').val('medium');
        reloadViewLead('viewTabFollowups');
      } else {
        showViewFormAlert('#viewFollowupFormAlert', 'danger', (res && res.message) || 'Could not save follow-up.');
      }
    }).fail(function (xhr) {
      var msg = 'Could not save follow-up.';
      try { msg = JSON.parse(xhr.responseText).message || msg; } catch (err) {}
      showViewFormAlert('#viewFollowupFormAlert', 'danger', msg);
    }).always(function () {
      $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Save Follow-up');
    });
  });

  $('#viewLeadSaveNotes').on('click', function () {
    $('#viewNotesForm').trigger('submit');
  });

  $('#viewNotesForm').on('submit', function (e) {
    e.preventDefault();
    var $btn = $('#viewLeadSaveNotes');
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving…');
    $('#viewNotesFormAlert').hide();

    $.ajax({
      url: 'sql/leads_actions.php',
      method: 'POST',
      data: $(this).serialize(),
      dataType: 'json',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).done(function (res) {
      if (res && res.success) {
        showViewFormAlert('#viewNotesFormAlert', 'success', res.message || 'Notes saved.');
        if (currentViewLead) {
          currentViewLead.notes = res.notes || '';
          currentViewLead.remarks = res.remarks || '';
        }
      } else {
        showViewFormAlert('#viewNotesFormAlert', 'danger', (res && res.message) || 'Could not save notes.');
      }
    }).fail(function (xhr) {
      var msg = 'Could not save notes.';
      try { msg = JSON.parse(xhr.responseText).message || msg; } catch (err) {}
      showViewFormAlert('#viewNotesFormAlert', 'danger', msg);
    }).always(function () {
      $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Save Notes');
    });
  });

  $('#filter_import, #filter_status, #filter_category, #filter_city, #filter_executive').on('change', function () {
    if (this.id === 'filter_import') {
      syncImportUrl();
    }
    saveFilters();
    // Reset to page 1 when filters change; keep search/sort via DataTables state
    table.ajax.reload(null, true);
  });

  $('#checkAll').on('change', function () {
    $('.lead-check').prop('checked', this.checked);
    updateBulkBar();
  });
  $(document).on('change', '.lead-check', updateBulkBar);

  function showCenterPopup(opts) {
    opts = opts || {};
    var type = opts.type === 'error' ? 'error' : 'success';
    var $popup = $('#leadsCenterPopup');
    $popup.removeClass('is-success is-error is-visible')
      .addClass('is-' + type);
    $('#leadsCenterPopupIcon').html(type === 'success'
      ? '<i class="fas fa-check"></i>'
      : '<i class="fas fa-times"></i>');
    $('#leadsCenterPopupTitle').text(opts.title || (type === 'success' ? 'Success' : 'Error'));
    $('#leadsCenterPopupMsg').text(opts.message || '');
    $popup.attr('aria-hidden', 'false').addClass('is-visible');

    clearTimeout(showCenterPopup._timer);
    showCenterPopup._timer = setTimeout(function () {
      $popup.removeClass('is-visible').attr('aria-hidden', 'true');
    }, opts.duration || 2200);
  }

  function postAction(data, okMsg, opts) {
    opts = opts || {};
    return $.ajax({
      url: 'sql/leads_actions.php',
      method: 'POST',
      data: $.extend({ ajax: 1 }, data),
      dataType: 'json',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).done(function (res) {
      if (!opts.silent) {
        showAlert(res.success ? 'success' : 'danger', res.message || okMsg);
      }
      if (res.success) {
        table.ajax.reload(null, false);
        $('#checkAll').prop('checked', false);
        updateBulkBar();
        if (typeof opts.onSuccess === 'function') opts.onSuccess(res);
      } else if (typeof opts.onError === 'function') {
        opts.onError(res);
      } else if (opts.silent) {
        showCenterPopup({ type: 'error', title: 'Failed', message: (res && res.message) || okMsg || 'Action failed.' });
      }
    }).fail(function (xhr) {
      var msg = 'Action failed.';
      try { msg = JSON.parse(xhr.responseText).message || msg; } catch (e) {}
      if (!opts.silent) {
        showAlert('danger', msg);
      } else if (typeof opts.onError === 'function') {
        opts.onError({ message: msg });
      } else {
        showCenterPopup({ type: 'error', title: 'Failed', message: msg });
      }
    });
  }

  var pendingDelete = null; // { type: 'single'|'bulk', id?, ids? }

  function openConfirmDelete(opts) {
    pendingDelete = opts || null;
    if (!pendingDelete) return;

    if (pendingDelete.type === 'bulk') {
      var n = (pendingDelete.ids || []).length;
      $('#confirmDeleteModalLabel').text('Delete Selected Leads');
      $('#confirmDeleteSub').text('This action cannot be undone.');
      $('#confirmDeleteMsg').text('Are you sure you want to delete ' + n + ' selected lead(s)?');
      $('#btnConfirmDelete').html('<i class="fas fa-trash"></i> Delete ' + n);
    } else {
      $('#confirmDeleteModalLabel').text('Delete Lead');
      $('#confirmDeleteSub').text('This action cannot be undone.');
      $('#confirmDeleteMsg').text('Are you sure you want to delete this lead?');
      $('#btnConfirmDelete').html('<i class="fas fa-trash"></i> Delete');
    }

    $('.crm-act-more').removeClass('open').find('.crm-act-more-btn').attr('aria-expanded', 'false');
    $('#confirmDeleteModal').modal('show');
  }

  $(document).on('click', '.btn-delete-lead', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var id = $(this).data('id');
    if (!id) return;
    openConfirmDelete({ type: 'single', id: id });
  });

  $('#btnBulkDelete').on('click', function () {
    var ids = selectedIds();
    if (!ids.length) return;
    openConfirmDelete({ type: 'bulk', ids: ids });
  });

  $('#btnConfirmDelete').on('click', function () {
    if (!pendingDelete) return;
    var $btn = $(this);
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Deleting…');

    var payload = pendingDelete.type === 'bulk'
      ? { action: 'bulk_delete', ids: pendingDelete.ids }
      : { action: 'delete', id: pendingDelete.id };

    var okMsg = pendingDelete.type === 'bulk' ? 'Selected leads deleted.' : 'Lead deleted successfully.';

    postAction(payload, okMsg, {
      silent: true,
      onSuccess: function (res) {
        pendingDelete = null;
        $('#confirmDeleteModal').modal('hide');
        showCenterPopup({
          type: 'success',
          title: 'Deleted',
          message: (res && res.message) || okMsg
        });
      },
      onError: function (res) {
        showCenterPopup({
          type: 'error',
          title: 'Delete Failed',
          message: (res && res.message) || 'Could not delete.'
        });
      }
    }).always(function () {
      $btn.prop('disabled', false).html('<i class="fas fa-trash"></i> Delete');
    });
  });

  $('#confirmDeleteModal').on('hidden.bs.modal', function () {
    pendingDelete = null;
    $('#btnConfirmDelete').prop('disabled', false).html('<i class="fas fa-trash"></i> Delete');
  });

  /* Actions overflow menu (...) */
  $(document).on('click', '.crm-act-more-btn', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $wrap = $(this).closest('.crm-act-more');
    var wasOpen = $wrap.hasClass('open');
    $('.crm-act-more').removeClass('open').find('.crm-act-more-btn').attr('aria-expanded', 'false');
    if (!wasOpen) {
      $wrap.addClass('open');
      $(this).attr('aria-expanded', 'true');
    }
  });

  $(document).on('click', function () {
    $('.crm-act-more').removeClass('open').find('.crm-act-more-btn').attr('aria-expanded', 'false');
  });

  $(document).on('click', '.crm-act-menu', function (e) {
    e.stopPropagation();
  });

  $('#btnBulkStatus').on('click', function () {
    var ids = selectedIds();
    var status = $('#bulk_status').val();
    if (!ids.length) return;
    if (!status) { showAlert('warning', 'Choose a status.'); return; }
    postAction({ action: 'bulk_status', ids: ids, status: status });
  });

  $('#btnBulkAssign').on('click', function () {
    var ids = selectedIds();
    var assigned = $('#bulk_assign').val();
    if (!ids.length) return;
    if (assigned === '') { showAlert('warning', 'Choose an executive.'); return; }
    postAction({ action: 'bulk_assign', ids: ids, assigned_to: assigned });
  });
})(jQuery);
</script>
</body>
</html>
