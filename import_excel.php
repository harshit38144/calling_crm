<?php
include_once 'connection.php';
require_admin();

$msg = flash_msg();
$msgType = flash_type();
$maxMb = (int) (excel_max_upload_bytes() / (1024 * 1024));

$history = [];
$sql = "SELECT li.*, u.name AS uploaded_by_name, u.username AS uploaded_by_username
        FROM lead_imports li
        LEFT JOIN users u ON u.id = li.imported_by
        ORDER BY li.created_at DESC
        LIMIT 100";
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $history[] = $row;
    }
}

function import_status_badge(string $status): string
{
    $map = [
        'pending' => 'warning',
        'processing' => 'info',
        'completed' => 'success',
        'failed' => 'danger',
        'cancelled' => 'secondary',
    ];
    $cls = $map[$status] ?? 'secondary';
    return '<span class="badge badge-' . $cls . '">' . htmlspecialchars(ucfirst($status)) . '</span>';
}

function import_source_badge(string $source): string
{
    $source = strtolower(trim($source));
    if ($source === 'json') {
        return '<span class="badge badge-info">JSON</span>';
    }
    return '<span class="badge badge-secondary">Excel</span>';
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Import Leads | Calling CRM</title>
  <?php include 'includes/header-links.php'; ?>
  <style>
    .upload-drop { padding: 28px 20px; text-align: center; border: 2px dashed #ced4da; border-radius: 8px; background: #fafbfc; transition: border-color .15s, background .15s; }
    .upload-drop.dragover { border-color: #007bff; background: #f0f7ff; }
    #uploadProgressWrap, #jsonProgressWrap { display: none; }
    #uploadAlert { display: none; }
    .remarks-cell { max-width: 240px; white-space: normal; font-size: 13px; }
    #jsonMappingPanel { display: none; }
    .map-table th, .map-table td { vertical-align: middle !important; font-size: 13px; }
    .map-table .sample-cell { color: #6c757d; font-size: 12px; max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .map-key { font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 12px; word-break: break-all; }
    .import-tabs .nav-link { font-weight: 600; }
    .map-hint { font-size: 13px; }
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

        <div id="uploadAlert" class="alert" role="alert"></div>

        <div class="card card-outline card-primary">
          <div class="card-header p-0 border-bottom-0">
            <ul class="nav nav-tabs import-tabs" id="importTypeTabs" role="tablist">
              <li class="nav-item">
                <a class="nav-link active" id="tab-excel" data-toggle="tab" href="#pane-excel" role="tab">
                  <i class="fas fa-file-excel mr-1"></i> Excel
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link" id="tab-json" data-toggle="tab" href="#pane-json" role="tab">
                  <i class="fas fa-file-code mr-1"></i> JSON
                </a>
              </li>
            </ul>
          </div>
          <div class="card-body">
            <div class="tab-content">
              <div class="tab-pane fade show active" id="pane-excel" role="tabpanel">
                <p class="text-muted mb-3">
                  Upload <strong>.xls</strong> or <strong>.xlsx</strong> (max <?= $maxMb ?> MB).
                  Columns are detected automatically. Unknown columns go to extra data.
                  Duplicate phone or business name rows are imported as separate leads.
                </p>
                <form id="excelUploadForm" enctype="multipart/form-data" method="post" action="sql/excel_upload.php">
                  <input type="hidden" name="upload_excel" value="1">
                  <div class="upload-drop mb-3" id="uploadDrop">
                    <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                    <p class="mb-2">Drag &amp; drop your Excel file here, or choose a file</p>
                    <input type="file" name="excel_file" id="excel_file" class="form-control-file d-inline-block"
                           accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                           required>
                  </div>
                  <div id="uploadProgressWrap" class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                      <small class="text-muted">Upload / import progress</small>
                      <small id="uploadProgressPct">0%</small>
                    </div>
                    <div class="progress" style="height: 18px;">
                      <div id="uploadProgressBar" class="progress-bar bg-primary progress-bar-striped progress-bar-animated"
                           role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                    </div>
                  </div>
                  <button type="submit" id="uploadBtn" class="btn btn-primary">
                    <i class="fas fa-upload mr-1"></i> Upload &amp; Import
                  </button>
                </form>
              </div>

              <div class="tab-pane fade" id="pane-json" role="tabpanel">
                <p class="text-muted mb-3">
                  Upload a <strong>.json</strong> file (max <?= $maxMb ?> MB) — array of objects or
                  <code>{ "data": [ ... ] }</code>. Map each JSON field to a CRM column, then import.
                </p>
                <form id="jsonUploadForm" enctype="multipart/form-data" method="post" action="sql/json_upload.php">
                  <input type="hidden" name="upload_json" value="1">
                  <div class="upload-drop mb-3" id="jsonDrop">
                    <i class="fas fa-file-code fa-2x text-muted mb-2"></i>
                    <p class="mb-2">Drag &amp; drop your JSON file here, or choose a file</p>
                    <input type="file" name="json_file" id="json_file" class="form-control-file d-inline-block"
                           accept=".json,application/json" required>
                  </div>
                  <div id="jsonProgressWrap" class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                      <small class="text-muted">Upload progress</small>
                      <small id="jsonProgressPct">0%</small>
                    </div>
                    <div class="progress" style="height: 18px;">
                      <div id="jsonProgressBar" class="progress-bar bg-info progress-bar-striped progress-bar-animated"
                           role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                    </div>
                  </div>
                  <button type="submit" id="jsonUploadBtn" class="btn btn-info">
                    <i class="fas fa-upload mr-1"></i> Upload &amp; Map Fields
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>

        <div class="card card-outline card-info" id="jsonMappingPanel">
          <div class="card-header d-flex align-items-center justify-content-between flex-wrap">
            <h3 class="card-title mb-0">
              <i class="fas fa-exchange-alt mr-2"></i>Map JSON Fields
              <small class="text-muted ml-2" id="mapFileLabel"></small>
            </h3>
            <span class="badge badge-light" id="mapRecordCount"></span>
          </div>
          <div class="card-body">
            <p class="map-hint text-muted mb-3">
              Choose a CRM field for each JSON key. Unmapped fields can be saved in Extra Data or skipped.
              At least <strong>Business Name</strong> or <strong>Phone</strong> must be mapped.
            </p>
            <input type="hidden" id="mapImportId" value="">
            <div class="table-responsive mb-3">
              <table class="table table-bordered table-sm map-table mb-0" id="mapTable">
                <thead class="thead-light">
                  <tr>
                    <th style="width:22%">JSON Field</th>
                    <th style="width:28%">Sample Values</th>
                    <th style="width:50%">Map To CRM Field</th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
            <div class="mb-3">
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btnMapReset">
                <i class="fas fa-undo mr-1"></i> Reset Suggestions
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btnMapExtraAll">
                <i class="fas fa-archive mr-1"></i> Unmapped → Extra Data
              </button>
            </div>
            <div class="d-flex flex-wrap align-items-center">
              <button type="button" class="btn btn-success mr-2 mb-2" id="btnConfirmJsonImport">
                <i class="fas fa-database mr-1"></i> Confirm &amp; Import Leads
              </button>
              <button type="button" class="btn btn-outline-secondary mb-2" id="btnCancelMapping">
                Cancel
              </button>
              <span class="ml-2 mb-2 text-muted small" id="mapImportStatus"></span>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <h3 class="card-title mb-0"><i class="fas fa-history mr-2"></i>Upload History</h3>
          </div>
          <div class="card-body table-responsive p-0">
            <table class="table table-hover table-striped mb-0" id="uploadHistoryTable">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Type</th>
                  <th>File Name</th>
                  <th>Uploaded By</th>
                  <th>Upload Date</th>
                  <th>Total</th>
                  <th>Imported</th>
                  <th>Duplicates</th>
                  <th>Failed</th>
                  <th>Status</th>
                  <th>Remarks</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$history): ?>
                  <tr id="historyEmptyRow">
                    <td colspan="12" class="text-center text-muted py-4">No uploads yet.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($history as $row): ?>
                    <?php
                      $src = strtolower((string) ($row['source'] ?? 'excel'));
                      $isJson = ($src === 'json');
                      $canProcess = in_array($row['status'], ['pending', 'failed'], true)
                          && !empty($row['stored_filename']);
                    ?>
                    <tr data-import-id="<?= (int) $row['id'] ?>" data-source="<?= htmlspecialchars($src) ?>">
                      <td><?= (int) $row['id'] ?></td>
                      <td class="col-source"><?= import_source_badge($src) ?></td>
                      <td><?= htmlspecialchars($row['original_filename'] ?: $row['filename']) ?></td>
                      <td><?= htmlspecialchars($row['uploaded_by_name'] ?: ($row['uploaded_by_username'] ?: '—')) ?></td>
                      <td><?= htmlspecialchars($row['created_at']) ?></td>
                      <td class="col-total"><?= (int) $row['total_rows'] ?></td>
                      <td class="col-success"><?= (int) $row['success_count'] ?></td>
                      <td class="col-dup"><?= (int) $row['duplicate_count'] ?></td>
                      <td class="col-failed"><?= (int) $row['failed_count'] ?></td>
                      <td class="col-status"><?= import_status_badge($row['status']) ?></td>
                      <td class="remarks-cell col-remarks"><?= htmlspecialchars($row['remarks'] ?: ($row['notes'] ?: '—')) ?></td>
                      <td class="col-action">
                        <?php if ((int) $row['success_count'] > 0 || $row['status'] === 'completed'): ?>
                          <a href="leads.php?import_id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-success mb-1"
                             title="View leads from this import">
                            <i class="fas fa-address-book"></i> View Leads
                          </a>
                        <?php endif; ?>
                        <?php if ($canProcess && $isJson): ?>
                          <button type="button" class="btn btn-sm btn-outline-info btn-map-json"
                                  data-id="<?= (int) $row['id'] ?>">
                            <i class="fas fa-exchange-alt"></i> Map &amp; Import
                          </button>
                        <?php elseif ($canProcess): ?>
                          <button type="button" class="btn btn-sm btn-outline-primary btn-process-import"
                                  data-id="<?= (int) $row['id'] ?>">
                            <i class="fas fa-database"></i> Import Leads
                          </button>
                        <?php elseif ((int) $row['success_count'] <= 0 && $row['status'] !== 'completed'): ?>
                          <span class="text-muted">—</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </section>
  </div>
  <?php include 'includes/copyright.php'; ?>
</div>
<?php include 'includes/footer-links.php'; ?>
<script>
(function ($) {
  var maxBytes = <?= (int) excel_max_upload_bytes() ?>;
  var $alert = $('#uploadAlert');
  var mapState = { importId: 0, keys: [], suggested: {}, samples: {}, fieldOptions: {} };

  function showAlert(type, message) {
    $alert
      .removeClass('alert-success alert-danger alert-warning alert-info')
      .addClass('alert-' + type)
      .html($('<div>').text(message).html())
      .show();
    $('html, body').animate({ scrollTop: 0 }, 200);
  }

  function setBar($bar, $pct, p) {
    p = Math.max(0, Math.min(100, Math.round(p)));
    $bar.css('width', p + '%').attr('aria-valuenow', p).text(p + '%');
    $pct.text(p + '%');
  }

  function statusBadge(status) {
    var map = { pending: 'warning', processing: 'info', completed: 'success', failed: 'danger', cancelled: 'secondary' };
    var cls = map[status] || 'secondary';
    var label = status ? status.charAt(0).toUpperCase() + status.slice(1) : '';
    return '<span class="badge badge-' + cls + '">' + label + '</span>';
  }

  function sourceBadge(source) {
    if ((source || '').toLowerCase() === 'json') {
      return '<span class="badge badge-info">JSON</span>';
    }
    return '<span class="badge badge-secondary">Excel</span>';
  }

  function esc(s) {
    return $('<div>').text(s == null ? '' : String(s)).html();
  }

  function buildActionHtml(id, status, successCount, source) {
    var action = '';
    var canProcess = (status === 'pending' || status === 'failed');
    var isJson = (source || '').toLowerCase() === 'json';
    if (successCount > 0 || status === 'completed') {
      action += '<a href="leads.php?import_id=' + id + '" class="btn btn-sm btn-success mb-1"><i class="fas fa-address-book"></i> View Leads</a> ';
    }
    if (canProcess && isJson) {
      action += '<button type="button" class="btn btn-sm btn-outline-info btn-map-json" data-id="' + id + '"><i class="fas fa-exchange-alt"></i> Map &amp; Import</button>';
    } else if (canProcess) {
      action += '<button type="button" class="btn btn-sm btn-outline-primary btn-process-import" data-id="' + id + '"><i class="fas fa-database"></i> Import Leads</button>';
    }
    if (!action) action = '<span class="text-muted">—</span>';
    return action;
  }

  function prependHistoryRow(data) {
    $('#historyEmptyRow').remove();
    var status = data.status || 'pending';
    var successCount = data.success_count != null ? data.success_count : 0;
    var source = data.source || 'excel';
    var row = '<tr data-import-id="' + (data.id || '') + '" data-source="' + esc(source) + '">' +
      '<td>' + (data.id || '') + '</td>' +
      '<td class="col-source">' + sourceBadge(source) + '</td>' +
      '<td>' + esc(data.file_name) + '</td>' +
      '<td>' + esc(data.uploaded_by) + '</td>' +
      '<td>' + esc(data.upload_date) + '</td>' +
      '<td class="col-total">' + (data.total_records != null ? data.total_records : 0) + '</td>' +
      '<td class="col-success">' + successCount + '</td>' +
      '<td class="col-dup">' + (data.duplicate_count != null ? data.duplicate_count : 0) + '</td>' +
      '<td class="col-failed">' + (data.failed_count != null ? data.failed_count : 0) + '</td>' +
      '<td class="col-status">' + statusBadge(status) + '</td>' +
      '<td class="remarks-cell col-remarks">' + esc(data.remarks || '') + '</td>' +
      '<td class="col-action">' + buildActionHtml(data.id, status, successCount, source) + '</td>' +
      '</tr>';
    $('#uploadHistoryTable tbody').prepend(row);
  }

  function updateHistoryRow(id, data, sourceHint) {
    var $tr = $('tr[data-import-id="' + id + '"]');
    if (!$tr.length) return;
    var source = sourceHint || $tr.attr('data-source') || data.source || 'excel';
    $tr.attr('data-source', source);
    $tr.find('.col-source').html(sourceBadge(source));
    if (data.total != null || data.total_records != null) {
      $tr.find('.col-total').text(data.total != null ? data.total : data.total_records);
    }
    $tr.find('.col-success').text(data.success_count != null ? data.success_count : 0);
    $tr.find('.col-dup').text(data.duplicate_count != null ? data.duplicate_count : 0);
    $tr.find('.col-failed').text(data.failed_count != null ? data.failed_count : 0);
    $tr.find('.col-status').html(statusBadge(data.status || 'pending'));
    $tr.find('.col-remarks').text(data.message || data.remarks || '');
    var status = data.status || 'pending';
    var successCount = data.success_count != null ? data.success_count : 0;
    $tr.find('.col-action').html(buildActionHtml(id, status, successCount, source));
  }

  function bindDrop($drop, $file) {
    $drop.on('dragover dragenter', function (e) {
      e.preventDefault();
      e.stopPropagation();
      $drop.addClass('dragover');
    }).on('dragleave drop', function (e) {
      e.preventDefault();
      e.stopPropagation();
      $drop.removeClass('dragover');
      if (e.type === 'drop' && e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files.length) {
        try { $file[0].files = e.originalEvent.dataTransfer.files; } catch (err) {}
      }
    });
  }

  /* ---------- Excel upload ---------- */
  var $form = $('#excelUploadForm');
  var $file = $('#excel_file');
  var $btn = $('#uploadBtn');
  var $wrap = $('#uploadProgressWrap');
  var $bar = $('#uploadProgressBar');
  var $pct = $('#uploadProgressPct');
  bindDrop($('#uploadDrop'), $file);

  function validateExcel() {
    var input = $file[0];
    if (!input.files || !input.files.length) {
      showAlert('danger', 'Please choose an Excel file (.xls or .xlsx).');
      return false;
    }
    var f = input.files[0];
    var ext = (f.name.split('.').pop() || '').toLowerCase();
    if (['xls', 'xlsx'].indexOf(ext) === -1) {
      showAlert('danger', 'Invalid file type. Only .xls and .xlsx files are allowed.');
      return false;
    }
    if (f.size <= 0) {
      showAlert('danger', 'The selected file is empty.');
      return false;
    }
    if (f.size > maxBytes) {
      showAlert('danger', 'File size exceeds the <?= $maxMb ?> MB limit.');
      return false;
    }
    return true;
  }

  $form.on('submit', function (e) {
    e.preventDefault();
    $alert.hide();
    if (!validateExcel()) return;
    var fd = new FormData($form[0]);
    $btn.prop('disabled', true);
    $wrap.show();
    setBar($bar, $pct, 0);
    $.ajax({
      url: 'sql/excel_upload.php',
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      dataType: 'json',
      xhr: function () {
        var xhr = $.ajaxSettings.xhr();
        if (xhr.upload) {
          xhr.upload.addEventListener('progress', function (ev) {
            if (ev.lengthComputable) setBar($bar, $pct, (ev.loaded / ev.total) * 90);
          });
        }
        return xhr;
      },
      success: function (res) {
        setBar($bar, $pct, 100);
        if (res && res.data) {
          res.data.source = res.data.source || 'excel';
          prependHistoryRow(res.data);
        }
        if (res && res.success) {
          showAlert('success', res.message || 'Upload & import successful.');
          $form[0].reset();
        } else {
          showAlert('warning', (res && res.message) ? res.message : 'Import finished with issues.');
        }
      },
      error: function (xhr) {
        var msg = 'Upload failed. Please try again.';
        var res = null;
        try { res = JSON.parse(xhr.responseText); } catch (err) {}
        if (res && res.message) msg = res.message;
        if (res && res.data) {
          res.data.source = res.data.source || 'excel';
          prependHistoryRow(res.data);
        }
        showAlert('danger', msg);
      },
      complete: function () {
        $btn.prop('disabled', false);
        setTimeout(function () { $wrap.fadeOut(200); }, 800);
      }
    });
  });

  $(document).on('click', '.btn-process-import', function () {
    var id = $(this).data('id');
    var $button = $(this);
    if (!id) return;
    $button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
    $.ajax({
      url: 'sql/process_excel_import.php',
      method: 'POST',
      data: { import_id: id },
      dataType: 'json',
      success: function (res) {
        if (res) updateHistoryRow(id, res, 'excel');
        showAlert(res && res.success ? 'success' : 'warning', (res && res.message) ? res.message : 'Done.');
      },
      error: function (xhr) {
        var msg = 'Import failed.';
        try {
          var res = JSON.parse(xhr.responseText);
          if (res && res.message) msg = res.message;
          if (res) updateHistoryRow(id, res, 'excel');
        } catch (err) {}
        showAlert('danger', msg);
        $button.prop('disabled', false).html('<i class="fas fa-database"></i> Import Leads');
      }
    });
  });

  /* ---------- JSON upload + mapping ---------- */
  var $jForm = $('#jsonUploadForm');
  var $jFile = $('#json_file');
  var $jBtn = $('#jsonUploadBtn');
  var $jWrap = $('#jsonProgressWrap');
  var $jBar = $('#jsonProgressBar');
  var $jPct = $('#jsonProgressPct');
  bindDrop($('#jsonDrop'), $jFile);

  function validateJson() {
    var input = $jFile[0];
    if (!input.files || !input.files.length) {
      showAlert('danger', 'Please choose a JSON file.');
      return false;
    }
    var f = input.files[0];
    var ext = (f.name.split('.').pop() || '').toLowerCase();
    if (ext !== 'json') {
      showAlert('danger', 'Invalid file type. Only .json files are allowed.');
      return false;
    }
    if (f.size <= 0) {
      showAlert('danger', 'The selected file is empty.');
      return false;
    }
    if (f.size > maxBytes) {
      showAlert('danger', 'File size exceeds the <?= $maxMb ?> MB limit.');
      return false;
    }
    return true;
  }

  function fieldSelectHtml(selected) {
    var opts = mapState.fieldOptions || {};
    var html = '<select class="form-control form-control-sm map-select">';
    Object.keys(opts).forEach(function (key) {
      html += '<option value="' + esc(key) + '"' + (key === selected ? ' selected' : '') + '>' + esc(opts[key]) + '</option>';
    });
    html += '</select>';
    return html;
  }

  function renderMappingPanel(data) {
    mapState.importId = data.id;
    mapState.keys = data.keys || [];
    mapState.suggested = data.suggested || {};
    mapState.samples = data.samples || {};
    mapState.fieldOptions = data.field_options || {};

    $('#mapImportId').val(data.id);
    $('#mapFileLabel').text(data.file_name ? '— ' + data.file_name : '');
    $('#mapRecordCount').text((data.total_records || 0) + ' records');
    $('#mapImportStatus').text('');

    var $tb = $('#mapTable tbody').empty();
    mapState.keys.forEach(function (key) {
      var samples = (mapState.samples[key] || []).join(' · ');
      var suggested = mapState.suggested[key] || '_extra';
      var tr = '<tr data-json-key="' + esc(key) + '">' +
        '<td class="map-key">' + esc(key) + '</td>' +
        '<td class="sample-cell" title="' + esc(samples) + '">' + esc(samples || '—') + '</td>' +
        '<td>' + fieldSelectHtml(suggested) + '</td>' +
        '</tr>';
      $tb.append(tr);
    });

    $('#jsonMappingPanel').slideDown(200);
    $('html, body').animate({ scrollTop: $('#jsonMappingPanel').offset().top - 80 }, 300);
  }

  function collectMapping() {
    var mapping = {};
    $('#mapTable tbody tr').each(function () {
      var key = $(this).attr('data-json-key');
      var val = $(this).find('.map-select').val() || '_extra';
      if (key) mapping[key] = val;
    });
    return mapping;
  }

  function mappingValid(mapping) {
    var vals = Object.keys(mapping).map(function (k) { return mapping[k]; });
    return vals.indexOf('business_name') !== -1 || vals.indexOf('phone') !== -1;
  }

  $jForm.on('submit', function (e) {
    e.preventDefault();
    $alert.hide();
    if (!validateJson()) return;
    var fd = new FormData($jForm[0]);
    $jBtn.prop('disabled', true);
    $jWrap.show();
    setBar($jBar, $jPct, 0);
    $.ajax({
      url: 'sql/json_upload.php',
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      dataType: 'json',
      xhr: function () {
        var xhr = $.ajaxSettings.xhr();
        if (xhr.upload) {
          xhr.upload.addEventListener('progress', function (ev) {
            if (ev.lengthComputable) setBar($jBar, $jPct, (ev.loaded / ev.total) * 95);
          });
        }
        return xhr;
      },
      success: function (res) {
        setBar($jBar, $jPct, 100);
        if (res && res.data) {
          res.data.source = 'json';
          res.data.remarks = res.data.remarks || 'Awaiting column mapping';
          prependHistoryRow(res.data);
          renderMappingPanel(res.data);
        }
        if (res && res.success) {
          showAlert('success', res.message || 'JSON uploaded. Map fields below.');
          $jForm[0].reset();
        } else {
          showAlert('warning', (res && res.message) ? res.message : 'Upload finished with issues.');
        }
      },
      error: function (xhr) {
        var msg = 'JSON upload failed.';
        try {
          var res = JSON.parse(xhr.responseText);
          if (res && res.message) msg = res.message;
        } catch (err) {}
        showAlert('danger', msg);
      },
      complete: function () {
        $jBtn.prop('disabled', false);
        setTimeout(function () { $jWrap.fadeOut(200); }, 800);
      }
    });
  });

  $(document).on('click', '.btn-map-json', function () {
    var id = $(this).data('id');
    var $button = $(this);
    if (!id) return;
    $button.prop('disabled', true);
    $.ajax({
      url: 'sql/json_preview.php',
      method: 'GET',
      data: { import_id: id },
      dataType: 'json',
      success: function (res) {
        if (res && res.success && res.data) {
          renderMappingPanel(res.data);
          $('#tab-json').tab('show');
          showAlert('info', 'Review the mapping, then confirm import.');
        } else {
          showAlert('danger', (res && res.message) ? res.message : 'Could not load preview.');
        }
      },
      error: function (xhr) {
        var msg = 'Could not load JSON preview.';
        try {
          var res = JSON.parse(xhr.responseText);
          if (res && res.message) msg = res.message;
        } catch (err) {}
        showAlert('danger', msg);
      },
      complete: function () {
        $button.prop('disabled', false);
      }
    });
  });

  $('#btnMapReset').on('click', function () {
    $('#mapTable tbody tr').each(function () {
      var key = $(this).attr('data-json-key');
      var suggested = (mapState.suggested && mapState.suggested[key]) || '_extra';
      $(this).find('.map-select').val(suggested);
    });
  });

  $('#btnMapExtraAll').on('click', function () {
    $('#mapTable tbody tr').each(function () {
      var $sel = $(this).find('.map-select');
      var v = $sel.val();
      if (v === '_skip' || !v) $sel.val('_extra');
    });
  });

  $('#btnCancelMapping').on('click', function () {
    $('#jsonMappingPanel').slideUp(150);
    mapState.importId = 0;
  });

  $('#btnConfirmJsonImport').on('click', function () {
    var id = mapState.importId || parseInt($('#mapImportId').val(), 10);
    if (!id) {
      showAlert('danger', 'No JSON import selected.');
      return;
    }
    var mapping = collectMapping();
    if (!mappingValid(mapping)) {
      showAlert('warning', 'Map at least Business Name or Phone before importing.');
      return;
    }
    var $button = $(this);
    $button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Importing…');
    $('#mapImportStatus').text('Importing leads…');
    $.ajax({
      url: 'sql/json_import.php',
      method: 'POST',
      data: {
        import_id: id,
        mapping: JSON.stringify(mapping)
      },
      dataType: 'json',
      success: function (res) {
        var payload = (res && res.data) ? res.data : res;
        if (payload) updateHistoryRow(id, payload, 'json');
        showAlert(res && res.success ? 'success' : 'warning', (res && res.message) ? res.message : 'Done.');
        if (res && res.success) {
          $('#jsonMappingPanel').slideUp(150);
          mapState.importId = 0;
        }
      },
      error: function (xhr) {
        var msg = 'JSON import failed.';
        try {
          var res = JSON.parse(xhr.responseText);
          if (res && res.message) msg = res.message;
          if (res && res.data) updateHistoryRow(id, res.data, 'json');
        } catch (err) {}
        showAlert('danger', msg);
      },
      complete: function () {
        $button.prop('disabled', false).html('<i class="fas fa-database mr-1"></i> Confirm &amp; Import Leads');
        $('#mapImportStatus').text('');
      }
    });
  });
})(jQuery);
</script>
</body>
</html>
