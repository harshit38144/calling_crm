<?php
/**
 * Report preview (JSON) and export (excel/csv/pdf).
 */
include_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/ReportBuilder.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv as CsvWriter;
use Dompdf\Dompdf;
use Dompdf\Options;

if (empty($_SESSION['id'])) {
    if (isset($_GET['export'])) {
        http_response_code(401);
        echo 'Unauthorized';
        exit;
    }
    json_response(['success' => false, 'message' => 'Unauthorized'], 401);
}

$type = trim((string) ($_REQUEST['report_type'] ?? 'calls'));
$filters = [
    'date_from' => trim((string) ($_REQUEST['date_from'] ?? '')),
    'date_to' => trim((string) ($_REQUEST['date_to'] ?? '')),
    'executive_id' => (int) ($_REQUEST['executive_id'] ?? 0),
    'status' => trim((string) ($_REQUEST['status'] ?? '')),
    'import_id' => (int) ($_REQUEST['import_id'] ?? 0),
];

$export = strtolower(trim((string) ($_REQUEST['export'] ?? '')));

try {
    $builder = new ReportBuilder($conn);
    if (!array_key_exists($type, ReportBuilder::reportTypes())) {
        throw new InvalidArgumentException('Invalid report type.');
    }
    $report = $builder->build($type, $filters);
} catch (Throwable $e) {
    if ($export !== '') {
        http_response_code(400);
        echo $e->getMessage();
        exit;
    }
    json_response(['success' => false, 'message' => $e->getMessage()], 400);
}

if ($export === '') {
    // Preview JSON (limit rows for UI)
    $previewRows = array_slice($report['rows'], 0, 200);
    json_response([
        'success' => true,
        'title' => $report['title'],
        'columns' => array_values($report['columns']),
        'rows' => $previewRows,
        'total_rows' => count($report['rows']),
        'summary' => $report['summary'] ?? [],
        'truncated' => count($report['rows']) > 200,
    ]);
}

$safeName = preg_replace('/[^a-z0-9_\-]+/i', '_', $type) ?: 'report';
$stamp = date('Ymd_His');

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeName . '_' . $stamp . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
    if ($report['rows']) {
        fputcsv($out, array_keys($report['rows'][0]));
        foreach ($report['rows'] as $row) {
            fputcsv($out, array_values($row));
        }
    } else {
        fputcsv($out, array_values($report['columns']));
    }
    if (!empty($report['summary'])) {
        fputcsv($out, []);
        fputcsv($out, ['Summary']);
        foreach ($report['summary'] as $k => $v) {
            fputcsv($out, [$k, $v]);
        }
    }
    fclose($out);
    exit;
}

if ($export === 'excel' || $export === 'xlsx') {
    $sheet = new Spreadsheet();
    $active = $sheet->getActiveSheet();
    $active->setTitle(mb_substr($report['title'], 0, 31));

    $headers = $report['rows'] ? array_keys($report['rows'][0]) : array_values($report['columns']);
    $col = 1;
    foreach ($headers as $h) {
        $active->setCellValue([$col, 1], $h);
        $col++;
    }
    $active->getStyle('1:1')->getFont()->setBold(true);

    $r = 2;
    foreach ($report['rows'] as $row) {
        $c = 1;
        foreach ($headers as $h) {
            $val = $row[$h] ?? '';
            $active->setCellValue([$c, $r], is_scalar($val) ? $val : json_encode($val));
            $c++;
        }
        $r++;
    }

    if (!empty($report['summary'])) {
        $r += 2;
        $active->setCellValue([1, $r], 'Summary');
        $active->getStyle("A{$r}")->getFont()->setBold(true);
        $r++;
        foreach ($report['summary'] as $k => $v) {
            $active->setCellValue([1, $r], $k);
            $active->setCellValue([2, $r], $v);
            $r++;
        }
    }

    foreach (range(1, max(1, count($headers))) as $i) {
        $active->getColumnDimensionByColumn($i)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $safeName . '_' . $stamp . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($sheet);
    $writer->save('php://output');
    exit;
}

if ($export === 'pdf') {
    $title = htmlspecialchars($report['title']);
    $from = htmlspecialchars($filters['date_from'] ?: date('Y-m-01'));
    $to = htmlspecialchars($filters['date_to'] ?: date('Y-m-d'));
    $generated = htmlspecialchars(date('Y-m-d H:i:s'));

    $summaryHtml = '';
    if (!empty($report['summary'])) {
        $summaryHtml .= '<div style="margin:12px 0;"><strong>Summary</strong><ul>';
        foreach ($report['summary'] as $k => $v) {
            $summaryHtml .= '<li>' . htmlspecialchars((string) $k) . ': <strong>' . htmlspecialchars((string) $v) . '</strong></li>';
        }
        $summaryHtml .= '</ul></div>';
    }

    $headers = $report['rows'] ? array_keys($report['rows'][0]) : array_values($report['columns']);
    $thead = '';
    foreach ($headers as $h) {
        $thead .= '<th>' . htmlspecialchars((string) $h) . '</th>';
    }

    $tbody = '';
    if (!$report['rows']) {
        $tbody = '<tr><td colspan="' . max(1, count($headers)) . '" style="text-align:center;">No records found</td></tr>';
    } else {
        foreach ($report['rows'] as $row) {
            $tbody .= '<tr>';
            foreach ($headers as $h) {
                $val = $row[$h] ?? '';
                $tbody .= '<td>' . htmlspecialchars(is_scalar($val) ? (string) $val : json_encode($val)) . '</td>';
            }
            $tbody .= '</tr>';
        }
    }

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
      body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
      h1 { font-size: 18px; margin: 0 0 6px; color: #9a3d5c; }
      .meta { color: #666; margin-bottom: 14px; }
      table { width: 100%; border-collapse: collapse; }
      th { background: #c45374; color: #fff; padding: 6px 5px; text-align: left; font-size: 10px; }
      td { border-bottom: 1px solid #ddd; padding: 5px; font-size: 9px; vertical-align: top; }
      tr:nth-child(even) td { background: #fff8fb; }
    </style></head><body>
      <h1>' . $title . '</h1>
      <div class="meta">Period: ' . $from . ' to ' . $to . ' · Generated: ' . $generated . '</div>
      ' . $summaryHtml . '
      <table><thead><tr>' . $thead . '</tr></thead><tbody>' . $tbody . '</tbody></table>
    </body></html>';

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream($safeName . '_' . $stamp . '.pdf', ['Attachment' => true]);
    exit;
}

http_response_code(400);
echo 'Unsupported export format.';
