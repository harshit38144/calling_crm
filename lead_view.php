<?php
include_once 'connection.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('Invalid lead.', 'error');
    header('Location: leads.php');
    exit;
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

if (!$lead) {
    set_flash('Lead not found.', 'error');
    header('Location: leads.php');
    exit;
}

$history = [];
$h = $conn->query(
    "SELECT h.*, u.name AS changed_by_name
     FROM lead_status_history h
     LEFT JOIN users u ON u.id = h.changed_by
     WHERE h.lead_id = {$id}
     ORDER BY h.created_at DESC
     LIMIT 30"
);
while ($row = $h->fetch_assoc()) {
    $history[] = $row;
}

$calls = [];
$cr = $conn->query(
    "SELECT cl.*, u.name AS executive_name
     FROM call_logs cl
     LEFT JOIN users u ON u.id = cl.user_id
     WHERE cl.lead_id = {$id}
     ORDER BY cl.called_at DESC, cl.id DESC
     LIMIT 50"
);
while ($row = $cr->fetch_assoc()) {
    $calls[] = $row;
}

$followups = [];
$fr = $conn->query(
    "SELECT f.*, u.name AS executive_name
     FROM followups f
     LEFT JOIN users u ON u.id = f.user_id
     WHERE f.lead_id = {$id}
     ORDER BY f.scheduled_at DESC
     LIMIT 20"
);
while ($row = $fr->fetch_assoc()) {
    $followups[] = $row;
}

$executives = [];
$er = $conn->query("SELECT id, name, username FROM users WHERE status = 'active' ORDER BY name");
while ($row = $er->fetch_assoc()) {
    $executives[] = $row;
}

$extra = [];
if (!empty($lead['extra_data'])) {
    $decoded = json_decode($lead['extra_data'], true);
    if (is_array($decoded)) {
        $extra = $decoded;
    }
}

$statuses = lead_statuses();
$callResults = call_result_statuses();
$msg = flash_msg();
$msgType = flash_type();
$defaultPhone = $lead['phone'] ?: $lead['alternate_phone'];
$openCall = isset($_GET['call']);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Lead #<?= $id ?> | Calling CRM</title>
  <?php include 'includes/header-links.php'; ?>
  <style>
    .call-history-item { border-left: 3px solid #c45374; padding-left: 12px; margin-bottom: 14px; }
    .tel-link { font-size: 1.1rem; font-weight: 600; }
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
        <div id="callAlert" class="alert" style="display:none;"></div>

        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
          <h4 class="mb-2">
            <?= htmlspecialchars($lead['business_name'] ?: 'Lead #' . $id) ?>
            <span id="leadStatusBadge"><?= lead_status_badge($lead['status']) ?></span>
          </h4>
          <div class="mb-2">
            <a href="leads.php<?= !empty($lead['import_id']) ? ('?import_id=' . (int) $lead['import_id']) : '' ?>"
               class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
            <?php if ($defaultPhone): ?>
              <a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $defaultPhone)) ?>"
                 class="btn btn-success btn-sm" id="btnTelDial">
                <i class="fas fa-phone"></i> Dial
              </a>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-primary" data-toggle="modal" data-target="#callModal">
              <i class="fas fa-headset"></i> Log Call
            </button>
            <a href="lead_edit.php?id=<?= $id ?>" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Edit</a>
            <button type="button" class="btn btn-danger btn-sm" id="btnDeleteView" data-id="<?= $id ?>">
              <i class="fas fa-trash"></i> Delete
            </button>
          </div>
        </div>

        <div class="row">
          <div class="col-lg-7">
            <div class="card">
              <div class="card-header"><strong>Lead Details</strong></div>
              <div class="card-body">
                <div class="row">
                  <?php
                  $pairs = [
                    'Business Name' => $lead['business_name'],
                    'Owner / Contact' => $lead['contact_name'],
                    'Phone' => $lead['phone'],
                    'Alternate Phone' => $lead['alternate_phone'],
                    'Email' => $lead['email'],
                    'Website' => $lead['website'],
                    'Category' => $lead['category'],
                    'Address' => $lead['address'],
                    'City' => $lead['city'],
                    'State' => $lead['state'],
                    'Pincode' => $lead['pincode'],
                    'Source' => $lead['source'],
                    'Import File' => $lead['import_file'],
                  ];
                  foreach ($pairs as $label => $value):
                      if ($value === null || $value === '') {
                          $value = '—';
                      }
                  ?>
                    <div class="col-md-6 mb-3">
                      <div class="text-muted small"><?= htmlspecialchars($label) ?></div>
                      <div>
                        <?php if ($label === 'Phone' && $lead['phone']): ?>
                          <a class="tel-link" href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $lead['phone'])) ?>">
                            <?= htmlspecialchars($lead['phone']) ?>
                          </a>
                        <?php elseif ($label === 'Website' && !empty($lead['website']) && $lead['website'] !== '—'): ?>
                          <?php
                            $websiteUrl = trim($lead['website']);
                            if (!preg_match('#^https?://#i', $websiteUrl)) {
                                $websiteUrl = 'https://' . $websiteUrl;
                            }
                          ?>
                          <a href="<?= htmlspecialchars($websiteUrl) ?>" target="_blank" rel="noopener noreferrer">
                            <?= htmlspecialchars($lead['website']) ?>
                          </a>
                        <?php elseif ($label === 'Email' && !empty($lead['email']) && $lead['email'] !== '—'): ?>
                          <a href="mailto:<?= htmlspecialchars($lead['email']) ?>">
                            <?= htmlspecialchars($lead['email']) ?>
                          </a>
                        <?php else: ?>
                          <?= htmlspecialchars((string) $value) ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <div class="card">
              <div class="card-header d-flex">
                <strong>Notes &amp; Remarks</strong>
              </div>
              <div class="card-body">
                <div class="mb-3">
                  <div class="text-muted small">Notes</div>
                  <div id="leadNotesDisplay"><?= $lead['notes'] ? nl2br(htmlspecialchars($lead['notes'])) : '<span class="text-muted">—</span>' ?></div>
                </div>
                <div>
                  <div class="text-muted small">Remarks</div>
                  <div id="leadRemarksDisplay"><?= !empty($lead['remarks']) ? nl2br(htmlspecialchars($lead['remarks'])) : '<span class="text-muted">—</span>' ?></div>
                </div>
              </div>
            </div>

            <?php if ($extra): ?>
              <div class="card">
                <div class="card-header"><strong>Additional Fields</strong></div>
                <div class="card-body">
                  <div class="row">
                    <?php foreach ($extra as $k => $v): ?>
                      <div class="col-md-6 mb-3">
                        <div class="text-muted small"><?= htmlspecialchars((string) $k) ?></div>
                        <div><?= htmlspecialchars(is_scalar($v) ? (string) $v : json_encode($v)) ?></div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <div class="col-lg-5">
            <div class="card card-outline card-success">
              <div class="card-header">
                <strong><i class="fas fa-phone-alt mr-1"></i> Calling Summary</strong>
              </div>
              <div class="card-body">
                <p class="mb-1"><strong>Executive:</strong>
                  <?= htmlspecialchars($lead['executive_name'] ?: ($lead['executive_username'] ?: 'Unassigned')) ?>
                </p>
                <p class="mb-1"><strong>Last Called:</strong>
                  <span id="lastCalledAt"><?= htmlspecialchars($lead['last_called_at'] ?: '—') ?></span>
                </p>
                <p class="mb-1"><strong>Next Follow-up:</strong>
                  <span id="nextFollowupAt"><?= htmlspecialchars($lead['next_followup_at'] ?: '—') ?></span>
                </p>
                <p class="mb-0"><strong>Total Calls:</strong> <span id="totalCalls"><?= count($calls) ?></span></p>
                <button type="button" class="btn btn-block btn-primary mt-3"
                        data-toggle="modal" data-target="#callModal">
                  <i class="fas fa-headset"></i> Call / Log Outcome
                </button>
              </div>
            </div>

            <div class="card">
              <div class="card-header"><strong>Call History</strong></div>
              <div class="card-body" id="callHistoryBox" style="max-height:420px;overflow:auto;">
                <?php if (!$calls): ?>
                  <p class="text-muted mb-0" id="callHistoryEmpty">No calls logged yet.</p>
                <?php else: ?>
                  <?php foreach ($calls as $call): ?>
                    <div class="call-history-item">
                      <div class="d-flex justify-content-between">
                        <strong><?= htmlspecialchars($call['executive_name'] ?: 'Executive') ?></strong>
                        <small class="text-muted"><?= htmlspecialchars($call['called_at']) ?></small>
                      </div>
                      <div>
                        <?= htmlspecialchars($call['phone_dialed'] ?: '—') ?>
                        · <?= htmlspecialchars(call_result_statuses()[$call['call_status']] ?? $call['call_status']) ?>
                        · <?= sprintf('%d:%02d', intdiv((int) $call['duration_seconds'], 60), ((int) $call['duration_seconds']) % 60) ?>
                      </div>
                      <?php if (!empty($call['lead_status_after'])): ?>
                        <div>Status → <?= lead_status_badge($call['lead_status_after']) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($call['notes'])): ?>
                        <div class="small"><strong>Notes:</strong> <?= nl2br(htmlspecialchars($call['notes'])) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($call['remarks'])): ?>
                        <div class="small"><strong>Remarks:</strong> <?= nl2br(htmlspecialchars($call['remarks'])) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($call['next_followup_at'])): ?>
                        <div class="small text-warning"><i class="fas fa-clock"></i>
                          Follow-up: <?= htmlspecialchars($call['next_followup_at']) ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>

            <div class="card">
              <div class="card-header"><strong>Follow-ups</strong></div>
              <div class="card-body p-0">
                <?php if (!$followups): ?>
                  <p class="text-muted p-3 mb-0">No follow-ups scheduled.</p>
                <?php else: ?>
                  <ul class="list-group list-group-flush" id="followupList">
                    <?php foreach ($followups as $fu): ?>
                      <li class="list-group-item">
                        <div><strong><?= htmlspecialchars($fu['scheduled_at']) ?></strong>
                          <span class="badge badge-<?= $fu['status'] === 'pending' ? 'warning' : 'secondary' ?>">
                            <?= htmlspecialchars(ucfirst($fu['status'])) ?>
                          </span>
                        </div>
                        <small class="text-muted"><?= htmlspecialchars($fu['executive_name'] ?: '—') ?></small>
                        <?php if (!empty($fu['notes'])): ?>
                          <div class="small"><?= htmlspecialchars($fu['notes']) ?></div>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
            </div>

            <div class="card">
              <div class="card-header"><strong>Status History</strong></div>
              <div class="card-body p-0">
                <?php if (!$history): ?>
                  <p class="text-muted p-3 mb-0">No status changes yet.</p>
                <?php else: ?>
                  <ul class="list-group list-group-flush">
                    <?php foreach ($history as $hrow): ?>
                      <li class="list-group-item">
                        <div>
                          <?= htmlspecialchars($hrow['old_status'] ?: '—') ?>
                          → <strong><?= htmlspecialchars($hrow['new_status']) ?></strong>
                        </div>
                        <small class="text-muted">
                          <?= htmlspecialchars($hrow['changed_by_name'] ?: 'System') ?>
                          · <?= htmlspecialchars($hrow['created_at']) ?>
                        </small>
                      </li>
                    <?php endforeach; ?>
                  </ul>
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

<!-- Call Modal -->
<div class="modal fade" id="callModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <form id="callForm" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-headset mr-2"></i>Log Call —
          <?= htmlspecialchars($lead['business_name'] ?: ('#' . $id)) ?></h5>
        <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="log_call">
        <input type="hidden" name="lead_id" value="<?= $id ?>">
        <div class="row">
          <div class="col-md-6 form-group">
            <label>Phone</label>
            <div class="input-group">
              <input type="text" name="phone_dialed" id="call_phone" class="form-control"
                     value="<?= htmlspecialchars($defaultPhone) ?>" required>
              <div class="input-group-append">
                <a class="btn btn-success" id="modalDialBtn"
                   href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', (string) $defaultPhone)) ?>">
                  <i class="fas fa-phone"></i>
                </a>
              </div>
            </div>
          </div>
          <div class="col-md-6 form-group">
            <label>Call Date / Time</label>
            <input type="datetime-local" name="called_at" class="form-control"
                   value="<?= date('Y-m-d\TH:i') ?>" required>
          </div>
          <div class="col-md-4 form-group">
            <label>Executive</label>
            <select name="executive_id" class="custom-select">
              <?php foreach ($executives as $e): ?>
                <option value="<?= (int) $e['id'] ?>"
                  <?= (int) ($lead['assigned_to'] ?: $_SESSION['id']) === (int) $e['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($e['name'] ?: $e['username']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4 form-group">
            <label>Call Result</label>
            <select name="call_status" class="custom-select" required>
              <?php foreach ($callResults as $k => $label): ?>
                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4 form-group">
            <label>Lead Status</label>
            <select name="lead_status" class="custom-select" required>
              <?php foreach ($statuses as $k => $label): ?>
                <option value="<?= htmlspecialchars($k) ?>" <?= $lead['status'] === $k ? 'selected' : '' ?>>
                  <?= htmlspecialchars($label) ?>
                </option>
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
          <div class="col-md-6 form-group">
            <label>Next Follow-up</label>
            <input type="datetime-local" name="next_followup_at" class="form-control">
          </div>
          <div class="col-md-6 form-group">
            <label>Notes</label>
            <textarea name="notes" class="form-control" rows="3" placeholder="Conversation notes…"></textarea>
          </div>
          <div class="col-md-6 form-group">
            <label>Remarks</label>
            <textarea name="remarks" class="form-control" rows="3" placeholder="Internal remarks…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="btnSaveCall">
          <i class="fas fa-save"></i> Save Call
        </button>
      </div>
    </form>
  </div>
</div>

<?php include 'includes/footer-links.php'; ?>
<script>
(function ($) {
  <?php if ($openCall): ?>
  $(function () { $('#callModal').modal('show'); });
  <?php endif; ?>

  $('#call_phone').on('input', function () {
    var p = ($(this).val() || '').replace(/\s+/g, '');
    $('#modalDialBtn').attr('href', 'tel:' + p);
  });

  function showAlert(type, msg) {
    $('#callAlert').removeClass('alert-success alert-danger').addClass('alert-' + type).text(msg).show();
  }

  function esc(s) {
    return $('<div>').text(s == null ? '' : String(s)).html();
  }

  $('#callForm').on('submit', function (e) {
    e.preventDefault();
    var $btn = $('#btnSaveCall').prop('disabled', true);
    $.ajax({
      url: 'sql/call_actions.php',
      method: 'POST',
      data: $(this).serialize(),
      dataType: 'json',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).done(function (res) {
      if (!res || !res.success) {
        showAlert('danger', (res && res.message) ? res.message : 'Save failed');
        return;
      }
      showAlert('success', res.message || 'Call saved');
      $('#callModal').modal('hide');
      $('#callForm')[0].reset();
      $('#callForm [name=lead_id]').val(<?= $id ?>);
      $('#callForm [name=action]').val('log_call');
      $('#callForm [name=phone_dialed]').val(<?= json_encode($defaultPhone) ?>);
      $('#callForm [name=called_at]').val(new Date().toISOString().slice(0,16));

      if (res.lead) {
        $('#leadStatusBadge').html(res.lead.status_badge || '');
        $('#lastCalledAt').text(res.lead.last_called_at || '—');
        $('#nextFollowupAt').text(res.lead.next_followup_at || '—');
        if (res.lead.notes) {
          $('#leadNotesDisplay').html(esc(res.lead.notes).replace(/\n/g, '<br>'));
        }
        if (res.lead.remarks) {
          $('#leadRemarksDisplay').html(esc(res.lead.remarks).replace(/\n/g, '<br>'));
        }
      }

      if (res.call) {
        $('#callHistoryEmpty').remove();
        var c = res.call;
        var html = '<div class="call-history-item">' +
          '<div class="d-flex justify-content-between"><strong>' + esc(c.executive) + '</strong>' +
          '<small class="text-muted">' + esc(c.called_at) + '</small></div>' +
          '<div>' + esc(c.phone_dialed) + ' · ' + esc(c.call_status_label) + ' · ' + esc(c.duration_label) + '</div>' +
          (c.lead_status_badge ? '<div>Status → ' + c.lead_status_badge + '</div>' : '') +
          (c.notes ? '<div class="small"><strong>Notes:</strong> ' + esc(c.notes).replace(/\n/g,'<br>') + '</div>' : '') +
          (c.remarks ? '<div class="small"><strong>Remarks:</strong> ' + esc(c.remarks).replace(/\n/g,'<br>') + '</div>' : '') +
          (c.next_followup_at ? '<div class="small text-warning"><i class="fas fa-clock"></i> Follow-up: ' + esc(c.next_followup_at) + '</div>' : '') +
          '</div>';
        $('#callHistoryBox').prepend(html);
        var n = parseInt($('#totalCalls').text(), 10) || 0;
        $('#totalCalls').text(n + 1);
      }
    }).fail(function (xhr) {
      var msg = 'Failed to save call.';
      try { msg = JSON.parse(xhr.responseText).message || msg; } catch (err) {}
      showAlert('danger', msg);
    }).always(function () {
      $btn.prop('disabled', false);
    });
  });

  $('#btnDeleteView').on('click', function () {
    if (!confirm('Delete this lead?')) return;
    $.ajax({
      url: 'sql/leads_actions.php',
      method: 'POST',
      data: { action: 'delete', id: $(this).data('id'), ajax: 1 },
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      dataType: 'json'
    }).done(function (res) {
      if (res.success) window.location.href = 'leads.php';
      else alert(res.message || 'Delete failed');
    });
  });
})(jQuery);
</script>
</body>
</html>
