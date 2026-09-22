<?php
include_once 'connection.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$isEdit = $id > 0;
$lead = [
    'business_name' => '', 'contact_name' => '', 'phone' => '', 'alternate_phone' => '',
    'email' => '', 'website' => '', 'category' => '', 'address' => '', 'city' => '',
    'state' => '', 'country' => '', 'pincode' => '', 'maps_url' => '', 'notes' => '',
    'status' => 'new', 'priority' => 'medium', 'assigned_to' => '',
    'rating' => '', 'review_count' => 0, 'latitude' => '', 'longitude' => '',
];

if ($isEdit) {
    $stmt = $conn->prepare('SELECT * FROM leads WHERE id = ? AND is_deleted = 0 LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        set_flash('Lead not found.', 'error');
        header('Location: leads.php');
        exit;
    }
    $lead = $row;
}

$statuses = lead_statuses();
$priorities = lead_priorities();
$executives = [];
$res = $conn->query("SELECT id, name, username FROM users WHERE status = 'active' ORDER BY name");
while ($row = $res->fetch_assoc()) {
    $executives[] = $row;
}

$msg = flash_msg();
$msgType = flash_type();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title><?= $isEdit ? 'Edit Lead' : 'Add Lead' ?> | Calling CRM</title>
  <?php include 'includes/header-links.php'; ?>
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

        <div class="card card-outline card-primary">
          <div class="card-header">
            <h3 class="card-title mb-0">
              <i class="fas fa-<?= $isEdit ? 'edit' : 'plus' ?> mr-2"></i>
              <?= $isEdit ? 'Edit Lead #' . $id : 'Add Lead' ?>
            </h3>
          </div>
          <form method="post" action="sql/leads_actions.php">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $isEdit ? $id : 0 ?>">
            <div class="card-body">
              <div class="row">
                <div class="col-md-6 form-group">
                  <label>Business Name</label>
                  <input type="text" name="business_name" class="form-control"
                         value="<?= htmlspecialchars($lead['business_name'] ?? '') ?>">
                </div>
                <div class="col-md-6 form-group">
                  <label>Owner / Contact Name</label>
                  <input type="text" name="contact_name" class="form-control"
                         value="<?= htmlspecialchars($lead['contact_name'] ?? '') ?>">
                </div>
                <div class="col-md-4 form-group">
                  <label>Phone</label>
                  <input type="text" name="phone" class="form-control"
                         value="<?= htmlspecialchars($lead['phone'] ?? '') ?>">
                </div>
                <div class="col-md-4 form-group">
                  <label>Alternate Phone</label>
                  <input type="text" name="alternate_phone" class="form-control"
                         value="<?= htmlspecialchars($lead['alternate_phone'] ?? '') ?>">
                </div>
                <div class="col-md-4 form-group">
                  <label>Email</label>
                  <input type="email" name="email" class="form-control"
                         value="<?= htmlspecialchars($lead['email'] ?? '') ?>">
                </div>
                <div class="col-md-6 form-group">
                  <label>Website</label>
                  <input type="text" name="website" class="form-control"
                         value="<?= htmlspecialchars($lead['website'] ?? '') ?>">
                </div>
                <div class="col-md-6 form-group">
                  <label>Category</label>
                  <input type="text" name="category" class="form-control"
                         value="<?= htmlspecialchars($lead['category'] ?? '') ?>">
                </div>
                <div class="col-md-12 form-group">
                  <label>Address</label>
                  <input type="text" name="address" class="form-control"
                         value="<?= htmlspecialchars($lead['address'] ?? '') ?>">
                </div>
                <div class="col-md-3 form-group">
                  <label>City</label>
                  <input type="text" name="city" class="form-control"
                         value="<?= htmlspecialchars($lead['city'] ?? '') ?>">
                </div>
                <div class="col-md-3 form-group">
                  <label>State</label>
                  <input type="text" name="state" class="form-control"
                         value="<?= htmlspecialchars($lead['state'] ?? '') ?>">
                </div>
                <div class="col-md-3 form-group">
                  <label>Country</label>
                  <input type="text" name="country" class="form-control"
                         value="<?= htmlspecialchars($lead['country'] ?? '') ?>">
                </div>
                <div class="col-md-3 form-group">
                  <label>Pincode</label>
                  <input type="text" name="pincode" class="form-control"
                         value="<?= htmlspecialchars($lead['pincode'] ?? '') ?>">
                </div>
                <div class="col-md-4 form-group">
                  <label>Status</label>
                  <select name="status" class="custom-select">
                    <?php foreach ($statuses as $k => $label): ?>
                      <option value="<?= htmlspecialchars($k) ?>" <?= ($lead['status'] ?? '') === $k ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4 form-group">
                  <label>Priority</label>
                  <select name="priority" class="custom-select">
                    <?php foreach ($priorities as $k => $label): ?>
                      <option value="<?= htmlspecialchars($k) ?>" <?= ($lead['priority'] ?? '') === $k ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4 form-group">
                  <label>Executive</label>
                  <select name="assigned_to" class="custom-select">
                    <option value="0">Unassigned</option>
                    <?php foreach ($executives as $e): ?>
                      <option value="<?= (int) $e['id'] ?>"
                        <?= (int) ($lead['assigned_to'] ?? 0) === (int) $e['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($e['name'] ?: $e['username']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3 form-group">
                  <label>Rating</label>
                  <input type="number" step="0.01" min="0" max="5" name="rating" class="form-control"
                         value="<?= htmlspecialchars((string) ($lead['rating'] ?? '')) ?>">
                </div>
                <div class="col-md-3 form-group">
                  <label>Reviews</label>
                  <input type="number" min="0" name="review_count" class="form-control"
                         value="<?= (int) ($lead['review_count'] ?? 0) ?>">
                </div>
                <div class="col-md-3 form-group">
                  <label>Latitude</label>
                  <input type="text" name="latitude" class="form-control"
                         value="<?= htmlspecialchars((string) ($lead['latitude'] ?? '')) ?>">
                </div>
                <div class="col-md-3 form-group">
                  <label>Longitude</label>
                  <input type="text" name="longitude" class="form-control"
                         value="<?= htmlspecialchars((string) ($lead['longitude'] ?? '')) ?>">
                </div>
                <div class="col-md-12 form-group">
                  <label>Maps URL</label>
                  <input type="text" name="maps_url" class="form-control"
                         value="<?= htmlspecialchars($lead['maps_url'] ?? '') ?>">
                </div>
                <div class="col-md-12 form-group">
                  <label>Notes</label>
                  <textarea name="notes" class="form-control" rows="3"><?= htmlspecialchars($lead['notes'] ?? '') ?></textarea>
                </div>
                <div class="col-md-12 form-group">
                  <label>Remarks</label>
                  <textarea name="remarks" class="form-control" rows="2"><?= htmlspecialchars($lead['remarks'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6 form-group">
                  <label>Next Follow-up</label>
                  <input type="datetime-local" name="next_followup_at" class="form-control"
                         value="<?= !empty($lead['next_followup_at']) ? date('Y-m-d\TH:i', strtotime($lead['next_followup_at'])) : '' ?>">
                </div>
              </div>
            </div>
            <div class="card-footer">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Lead
              </button>
              <a href="<?= $isEdit ? 'lead_view.php?id=' . $id : 'leads.php' ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </section>
  </div>
  <?php include 'includes/copyright.php'; ?>
</div>
<?php include 'includes/footer-links.php'; ?>
</body>
</html>
