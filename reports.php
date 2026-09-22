<?php
include_once 'connection.php';
require_once 'sql/ReportBuilder.php';
require_admin();

$reportTypes = ReportBuilder::reportTypes();
$statuses = lead_statuses();

$executives = [];
$res = $conn->query("SELECT id, name, username FROM users WHERE status = 'active' ORDER BY name");
while ($row = $res->fetch_assoc()) {
    $executives[] = $row;
}

$imports = [];
$res = $conn->query(
    "SELECT id, original_filename, created_at FROM lead_imports ORDER BY created_at DESC LIMIT 100"
);
while ($row = $res->fetch_assoc()) {
    $imports[] = $row;
}

$defaultFrom = date('Y-m-01');
$defaultTo = date('Y-m-d');
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Reports | Calling CRM</title>
  <?php include 'includes/header-links.php'; ?>
  <style>
    .summary-chip {
      display: inline-block;
      background: #fff5f8;
      border: 1px solid #efd7e1;
      border-radius: 20px;
      padding: 6px 12px;
      margin: 0 8px 8px 0;
      font-size: 13px;
    }
    .summary-chip strong { color: #9a3d5c; }
    #reportTable th { white-space: nowrap; }
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

        <div class="card report-card mb-3">
          <div class="card-header"><i class="fas fa-chart-bar mr-2"></i>Generate Report</div>
          <div class="card-body">
            <form id="reportForm">
              <div class="row">
                <div class="col-md-3 form-group">
                  <label>Report Type</label>
                  <select name="report_type" id="report_type" class="custom-select" required>
                    <?php foreach ($reportTypes as $k => $label): ?>
                      <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-2 form-group">
                  <label>From Date</label>
                  <input type="date" name="date_from" id="date_from" class="form-control" value="<?= $defaultFrom ?>">
                </div>
                <div class="col-md-2 form-group">
                  <label>To Date</label>
                  <input type="date" name="date_to" id="date_to" class="form-control" value="<?= $defaultTo ?>">
                </div>
                <div class="col-md-3 form-group" id="wrap_executive">
                  <label>Executive</label>
                  <select name="executive_id" id="executive_id" class="custom-select">
                    <option value="0">All Executives</option>
                    <?php foreach ($executives as $e): ?>
                      <option value="<?= (int) $e['id'] ?>"><?= htmlspecialchars($e['name'] ?: $e['username']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-2 form-group" id="wrap_status">
                  <label>Status</label>
                  <select name="status" id="status" class="custom-select">
                    <option value="">All</option>
                    <?php foreach ($statuses as $k => $label): ?>
                      <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3 form-group d-none" id="wrap_import">
                  <label>Excel Import</label>
                  <select name="import_id" id="import_id" class="custom-select">
                    <option value="0">All Files</option>
                    <?php foreach ($imports as $imp): ?>
                      <option value="<?= (int) $imp['id'] ?>">
                        <?= htmlspecialchars(($imp['original_filename'] ?: ('#' . $imp['id'])) . ' · ' . $imp['created_at']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3 form-group d-none" id="wrap_fu_status">
                  <label>Follow-up Status</label>
                  <select name="fu_status" id="fu_status" class="custom-select">
                    <option value="">All</option>
                    <option value="pending">Pending</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="missed">Missed</option>
                    <option value="rescheduled">Rescheduled</option>
                  </select>
                </div>
              </div>
              <div class="d-flex flex-wrap align-items-center">
                <button type="submit" class="btn btn-primary mr-2 mb-2" id="btnGenerate">
                  <i class="fas fa-sync-alt"></i> Generate
                </button>
                <div class="btn-group mb-2 mr-2">
                  <button type="button" class="btn btn-success btn-export" data-format="excel" disabled>
                    <i class="fas fa-file-excel"></i> Excel
                  </button>
                  <button type="button" class="btn btn-info btn-export" data-format="csv" disabled>
                    <i class="fas fa-file-csv"></i> CSV
                  </button>
                  <button type="button" class="btn btn-danger btn-export" data-format="pdf" disabled>
                    <i class="fas fa-file-pdf"></i> PDF
                  </button>
                </div>
                <span class="text-muted small mb-2" id="resultMeta"></span>
              </div>
            </form>
          </div>
        </div>

        <div id="reportAlert" class="alert" style="display:none;"></div>

        <div class="card report-card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span id="reportTitle">Report Preview</span>
          </div>
          <div class="card-body">
            <div id="summaryBox" class="mb-3"></div>
            <div class="table-responsive">
              <table class="table table-bordered table-striped table-sm mb-0" id="reportTable">
                <thead><tr><th class="text-muted">Generate a report to see results</th></tr></thead>
                <tbody></tbody>
              </table>
            </div>
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
  function showAlert(type, msg) {
    $('#reportAlert').removeClass('alert-success alert-danger alert-warning')
      .addClass('alert-' + type).text(msg).show();
  }

  function syncFilters() {
    var t = $('#report_type').val();
    $('#wrap_executive').toggleClass('d-none', t === 'import_summary' || t === 'executive_performance');
    $('#wrap_status').toggleClass('d-none', t !== 'leads' && t !== 'conversion_rate');
    $('#wrap_import').toggleClass('d-none', t !== 'leads');
    $('#wrap_fu_status').toggleClass('d-none', t !== 'followups');
  }

  function formParams() {
    var p = {
      report_type: $('#report_type').val(),
      date_from: $('#date_from').val(),
      date_to: $('#date_to').val(),
      executive_id: $('#executive_id').val() || 0,
      import_id: $('#import_id').val() || 0,
      status: ''
    };
    if (p.report_type === 'followups') {
      p.status = $('#fu_status').val() || '';
    } else if (p.report_type === 'leads' || p.report_type === 'conversion_rate') {
      p.status = $('#status').val() || '';
    }
    return p;
  }

  function renderTable(res) {
    $('#reportTitle').text(res.title || 'Report Preview');
    var summaryHtml = '';
    if (res.summary) {
      Object.keys(res.summary).forEach(function (k) {
        summaryHtml += '<span class="summary-chip">' + $('<div>').text(k).html() +
          ': <strong>' + $('<div>').text(String(res.summary[k])).html() + '</strong></span>';
      });
    }
    $('#summaryBox').html(summaryHtml);

    var cols = res.columns || [];
    var rows = res.rows || [];
    var thead = '<tr>' + cols.map(function (c) {
      return '<th>' + $('<div>').text(c).html() + '</th>';
    }).join('') + '</tr>';

    var tbody = '';
    if (!rows.length) {
      tbody = '<tr><td colspan="' + Math.max(cols.length, 1) + '" class="text-center text-muted">No records found for selected filters.</td></tr>';
    } else {
      rows.forEach(function (row) {
        tbody += '<tr>';
        cols.forEach(function (c) {
          var v = row[c] == null ? '' : String(row[c]);
          tbody += '<td>' + $('<div>').text(v).html() + '</td>';
        });
        tbody += '</tr>';
      });
    }
    $('#reportTable thead').html(thead);
    $('#reportTable tbody').html(tbody);

    var meta = (res.total_rows || 0) + ' record(s)';
    if (res.truncated) meta += ' · preview shows first 200';
    $('#resultMeta').text(meta);
    $('.btn-export').prop('disabled', false);
  }

  $('#report_type').on('change', syncFilters);
  syncFilters();

  $('#reportForm').on('submit', function (e) {
    e.preventDefault();
    $('#reportAlert').hide();
    $('#btnGenerate').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Generating…');
    $.ajax({
      url: 'sql/reports_export.php',
      method: 'POST',
      data: formParams(),
      dataType: 'json'
    }).done(function (res) {
      if (!res || !res.success) {
        showAlert('danger', (res && res.message) ? res.message : 'Failed to generate report.');
        return;
      }
      renderTable(res);
    }).fail(function (xhr) {
      var msg = 'Failed to generate report.';
      try { msg = JSON.parse(xhr.responseText).message || msg; } catch (err) {}
      showAlert('danger', msg);
    }).always(function () {
      $('#btnGenerate').prop('disabled', false).html('<i class="fas fa-sync-alt"></i> Generate');
    });
  });

  $('.btn-export').on('click', function () {
    var format = $(this).data('format');
    var p = formParams();
    p.export = format;
    var q = $.param(p);
    window.location.href = 'sql/reports_export.php?' + q;
  });
})(jQuery);
</script>
</body>
</html>
