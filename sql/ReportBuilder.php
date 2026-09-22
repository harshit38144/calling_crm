<?php
/**
 * Shared report query + column definitions for Calling CRM.
 */
class ReportBuilder
{
    /** @var mysqli */
    private $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public static function reportTypes(): array
    {
        return [
            'calls' => 'Calls Report',
            'leads' => 'Leads Report',
            'followups' => 'Follow-ups Report',
            'executive_performance' => 'Executive Performance',
            'conversion_rate' => 'Conversion Rate',
            'import_summary' => 'Import Summary',
        ];
    }

    /**
     * @return array{title:string,columns:array<string,string>,rows:list<array<string,mixed>>,summary?:array<string,mixed>}
     */
    public function build(string $type, array $filters): array
    {
        $from = $this->normalizeDate($filters['date_from'] ?? '', true);
        $to = $this->normalizeDate($filters['date_to'] ?? '', false);
        $executiveId = (int) ($filters['executive_id'] ?? 0);
        $status = trim((string) ($filters['status'] ?? ''));
        $importId = (int) ($filters['import_id'] ?? 0);

        switch ($type) {
            case 'calls':
                return $this->callsReport($from, $to, $executiveId);
            case 'leads':
                return $this->leadsReport($from, $to, $executiveId, $status, $importId);
            case 'followups':
                return $this->followupsReport($from, $to, $executiveId, $status);
            case 'executive_performance':
                return $this->executivePerformanceReport($from, $to);
            case 'conversion_rate':
                return $this->conversionRateReport($from, $to, $executiveId);
            case 'import_summary':
                return $this->importSummaryReport($from, $to);
            default:
                throw new InvalidArgumentException('Unknown report type.');
        }
    }

    private function normalizeDate(string $value, bool $startOfDay): string
    {
        $value = trim($value);
        if ($value === '') {
            return $startOfDay
                ? date('Y-m-01 00:00:00')
                : date('Y-m-d 23:59:59');
        }
        $ts = strtotime($value);
        if (!$ts) {
            return $startOfDay
                ? date('Y-m-01 00:00:00')
                : date('Y-m-d 23:59:59');
        }
        return $startOfDay
            ? date('Y-m-d 00:00:00', $ts)
            : date('Y-m-d 23:59:59', $ts);
    }

    private function callsReport(string $from, string $to, int $executiveId): array
    {
        $where = ["cl.called_at BETWEEN ? AND ?"];
        $types = 'ss';
        $params = [$from, $to];
        if ($executiveId > 0) {
            $where[] = 'cl.user_id = ?';
            $types .= 'i';
            $params[] = $executiveId;
        }
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT cl.id, cl.called_at, u.name AS executive, l.business_name, cl.phone_dialed,
                       cl.call_status, cl.duration_seconds, cl.lead_status_after, cl.notes, cl.remarks,
                       cl.next_followup_at
                FROM call_logs cl
                LEFT JOIN users u ON u.id = cl.user_id
                LEFT JOIN leads l ON l.id = cl.lead_id
                WHERE {$whereSql}
                ORDER BY cl.called_at DESC
                LIMIT 5000";

        $rows = $this->fetchAll($sql, $types, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'ID' => $r['id'],
                'Call Date' => $r['called_at'],
                'Executive' => $r['executive'] ?: '—',
                'Business' => $r['business_name'] ?: '—',
                'Phone' => $r['phone_dialed'] ?: '—',
                'Result' => $r['call_status'],
                'Duration (sec)' => (int) $r['duration_seconds'],
                'Lead Status' => $r['lead_status_after'] ?: '—',
                'Notes' => $r['notes'] ?: '',
                'Remarks' => $r['remarks'] ?: '',
                'Next Follow-up' => $r['next_followup_at'] ?: '',
            ];
        }

        return [
            'title' => 'Calls Report',
            'columns' => $this->columnsFromRows($out, [
                'ID', 'Call Date', 'Executive', 'Business', 'Phone', 'Result',
                'Duration (sec)', 'Lead Status', 'Notes', 'Remarks', 'Next Follow-up',
            ]),
            'rows' => $out,
            'summary' => [
                'Total Calls' => count($out),
                'Total Duration (sec)' => array_sum(array_column($out, 'Duration (sec)')),
            ],
        ];
    }

    private function leadsReport(string $from, string $to, int $executiveId, string $status, int $importId): array
    {
        $where = ["l.is_deleted = 0", "l.created_at BETWEEN ? AND ?"];
        $types = 'ss';
        $params = [$from, $to];
        if ($executiveId > 0) {
            $where[] = 'l.assigned_to = ?';
            $types .= 'i';
            $params[] = $executiveId;
        }
        if ($status !== '' && array_key_exists($status, lead_statuses())) {
            $where[] = 'l.status = ?';
            $types .= 's';
            $params[] = $status;
        }
        if ($importId > 0) {
            $where[] = 'l.import_id = ?';
            $types .= 'i';
            $params[] = $importId;
        }
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT l.id, l.created_at, l.business_name, l.contact_name, l.phone, l.email,
                       l.city, l.category, l.status, u.name AS executive,
                       li.original_filename AS excel_file, l.last_called_at, l.next_followup_at
                FROM leads l
                LEFT JOIN users u ON u.id = l.assigned_to
                LEFT JOIN lead_imports li ON li.id = l.import_id
                WHERE {$whereSql}
                ORDER BY l.created_at DESC
                LIMIT 5000";

        $rows = $this->fetchAll($sql, $types, $params);
        $labels = lead_statuses();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'ID' => $r['id'],
                'Created' => $r['created_at'],
                'Business' => $r['business_name'] ?: '—',
                'Contact' => $r['contact_name'] ?: '—',
                'Phone' => $r['phone'] ?: '—',
                'Email' => $r['email'] ?: '—',
                'City' => $r['city'] ?: '—',
                'Category' => $r['category'] ?: '—',
                'Status' => $labels[$r['status']] ?? $r['status'],
                'Executive' => $r['executive'] ?: 'Unassigned',
                'Excel File' => $r['excel_file'] ?: 'Manual',
                'Last Called' => $r['last_called_at'] ?: '',
                'Next Follow-up' => $r['next_followup_at'] ?: '',
            ];
        }

        return [
            'title' => 'Leads Report',
            'columns' => $this->columnsFromRows($out, [
                'ID', 'Created', 'Business', 'Contact', 'Phone', 'Email', 'City',
                'Category', 'Status', 'Executive', 'Excel File', 'Last Called', 'Next Follow-up',
            ]),
            'rows' => $out,
            'summary' => ['Total Leads' => count($out)],
        ];
    }

    private function followupsReport(string $from, string $to, int $executiveId, string $status): array
    {
        $where = ["f.scheduled_at BETWEEN ? AND ?"];
        $types = 'ss';
        $params = [$from, $to];
        if ($executiveId > 0) {
            $where[] = 'f.user_id = ?';
            $types .= 'i';
            $params[] = $executiveId;
        }
        if (in_array($status, ['pending', 'completed', 'cancelled', 'missed', 'rescheduled'], true)) {
            $where[] = 'f.status = ?';
            $types .= 's';
            $params[] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT f.id, f.scheduled_at, f.status, f.priority, f.completed_at, f.notes,
                       u.name AS executive, l.business_name, l.phone
                FROM followups f
                LEFT JOIN users u ON u.id = f.user_id
                LEFT JOIN leads l ON l.id = f.lead_id
                WHERE {$whereSql}
                ORDER BY f.scheduled_at DESC
                LIMIT 5000";

        $rows = $this->fetchAll($sql, $types, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'ID' => $r['id'],
                'Scheduled At' => $r['scheduled_at'],
                'Status' => ucfirst($r['status']),
                'Priority' => ucfirst($r['priority']),
                'Executive' => $r['executive'] ?: '—',
                'Business' => $r['business_name'] ?: '—',
                'Phone' => $r['phone'] ?: '—',
                'Completed At' => $r['completed_at'] ?: '',
                'Notes' => $r['notes'] ?: '',
            ];
        }

        $pending = 0;
        foreach ($out as $row) {
            if (strtolower($row['Status']) === 'pending') {
                $pending++;
            }
        }

        return [
            'title' => 'Follow-ups Report',
            'columns' => $this->columnsFromRows($out, [
                'ID', 'Scheduled At', 'Status', 'Priority', 'Executive',
                'Business', 'Phone', 'Completed At', 'Notes',
            ]),
            'rows' => $out,
            'summary' => [
                'Total Follow-ups' => count($out),
                'Pending' => $pending,
            ],
        ];
    }

    private function executivePerformanceReport(string $from, string $to): array
    {
        $sql = "SELECT u.id, u.name, u.username,
                  (SELECT COUNT(*) FROM call_logs cl
                    WHERE cl.user_id = u.id AND cl.called_at BETWEEN ? AND ?) AS total_calls,
                  (SELECT COALESCE(SUM(cl2.duration_seconds),0) FROM call_logs cl2
                    WHERE cl2.user_id = u.id AND cl2.called_at BETWEEN ? AND ?) AS total_duration,
                  (SELECT COUNT(*) FROM call_logs cl3
                    WHERE cl3.user_id = u.id AND cl3.called_at BETWEEN ? AND ?
                      AND cl3.call_status = 'connected') AS connected_calls,
                  (SELECT COUNT(*) FROM leads l
                    WHERE l.assigned_to = u.id AND l.is_deleted = 0) AS assigned_leads,
                  (SELECT COUNT(*) FROM leads l2
                    WHERE l2.assigned_to = u.id AND l2.is_deleted = 0 AND l2.status = 'converted') AS converted_leads,
                  (SELECT COUNT(*) FROM followups f
                    WHERE f.user_id = u.id AND f.status = 'pending') AS pending_followups
                FROM users u
                WHERE u.status = 'active'
                ORDER BY total_calls DESC, converted_leads DESC";

        $rows = $this->fetchAll($sql, 'ssssss', [$from, $to, $from, $to, $from, $to]);
        $out = [];
        foreach ($rows as $r) {
            $calls = (int) $r['total_calls'];
            $converted = (int) $r['converted_leads'];
            $assigned = (int) $r['assigned_leads'];
            $rate = $assigned > 0 ? round(($converted / $assigned) * 100, 2) : 0.0;
            $out[] = [
                'Executive' => $r['name'] ?: $r['username'],
                'Total Calls' => $calls,
                'Connected Calls' => (int) $r['connected_calls'],
                'Talk Time (sec)' => (int) $r['total_duration'],
                'Assigned Leads' => $assigned,
                'Converted Leads' => $converted,
                'Conversion %' => $rate,
                'Pending Follow-ups' => (int) $r['pending_followups'],
            ];
        }

        return [
            'title' => 'Executive Performance',
            'columns' => $this->columnsFromRows($out, [
                'Executive', 'Total Calls', 'Connected Calls', 'Talk Time (sec)',
                'Assigned Leads', 'Converted Leads', 'Conversion %', 'Pending Follow-ups',
            ]),
            'rows' => $out,
            'summary' => [
                'Executives' => count($out),
                'Total Calls' => array_sum(array_column($out, 'Total Calls')),
                'Total Converted' => array_sum(array_column($out, 'Converted Leads')),
            ],
        ];
    }

    private function conversionRateReport(string $from, string $to, int $executiveId): array
    {
        $where = ["l.is_deleted = 0", "l.created_at BETWEEN ? AND ?"];
        $types = 'ss';
        $params = [$from, $to];
        if ($executiveId > 0) {
            $where[] = 'l.assigned_to = ?';
            $types .= 'i';
            $params[] = $executiveId;
        }
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT l.status, COUNT(*) AS total
                FROM leads l
                WHERE {$whereSql}
                GROUP BY l.status
                ORDER BY total DESC";

        $rows = $this->fetchAll($sql, $types, $params);
        $labels = lead_statuses();
        $grand = 0;
        foreach ($rows as $r) {
            $grand += (int) $r['total'];
        }

        $out = [];
        $converted = 0;
        $interested = 0;
        foreach ($rows as $r) {
            $count = (int) $r['total'];
            if ($r['status'] === 'converted') {
                $converted = $count;
            }
            if (in_array($r['status'], ['interested', 'proposal_sent', 'converted'], true)) {
                $interested += $count;
            }
            $pct = $grand > 0 ? round(($count / $grand) * 100, 2) : 0.0;
            $out[] = [
                'Status' => $labels[$r['status']] ?? $r['status'],
                'Leads' => $count,
                'Share %' => $pct,
            ];
        }

        $conversionRate = $grand > 0 ? round(($converted / $grand) * 100, 2) : 0.0;
        $interestRate = $grand > 0 ? round(($interested / $grand) * 100, 2) : 0.0;

        return [
            'title' => 'Conversion Rate',
            'columns' => $this->columnsFromRows($out, ['Status', 'Leads', 'Share %']),
            'rows' => $out,
            'summary' => [
                'Total Leads' => $grand,
                'Converted' => $converted,
                'Conversion Rate %' => $conversionRate,
                'Interest Pipeline %' => $interestRate,
            ],
        ];
    }

    private function importSummaryReport(string $from, string $to): array
    {
        $sql = "SELECT li.id, li.created_at, li.original_filename, u.name AS uploaded_by,
                       li.total_rows, li.success_count, li.duplicate_count, li.failed_count,
                       li.status, li.remarks,
                       (SELECT COUNT(*) FROM leads l WHERE l.import_id = li.id AND l.is_deleted = 0) AS active_leads
                FROM lead_imports li
                LEFT JOIN users u ON u.id = li.imported_by
                WHERE li.created_at BETWEEN ? AND ?
                ORDER BY li.created_at DESC
                LIMIT 5000";

        $rows = $this->fetchAll($sql, 'ss', [$from, $to]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'ID' => $r['id'],
                'Upload Date' => $r['created_at'],
                'File Name' => $r['original_filename'] ?: '—',
                'Uploaded By' => $r['uploaded_by'] ?: '—',
                'Total Rows' => (int) $r['total_rows'],
                'Imported' => (int) $r['success_count'],
                'Duplicates' => (int) $r['duplicate_count'],
                'Failed' => (int) $r['failed_count'],
                'Active Leads' => (int) $r['active_leads'],
                'Status' => ucfirst($r['status']),
                'Remarks' => $r['remarks'] ?: '',
            ];
        }

        return [
            'title' => 'Import Summary',
            'columns' => $this->columnsFromRows($out, [
                'ID', 'Upload Date', 'File Name', 'Uploaded By', 'Total Rows',
                'Imported', 'Duplicates', 'Failed', 'Active Leads', 'Status', 'Remarks',
            ]),
            'rows' => $out,
            'summary' => [
                'Files' => count($out),
                'Rows Imported' => array_sum(array_column($out, 'Imported')),
                'Active Leads' => array_sum(array_column($out, 'Active Leads')),
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $fallback
     * @return array<string,string>
     */
    private function columnsFromRows(array $rows, array $fallback): array
    {
        $keys = $rows ? array_keys($rows[0]) : $fallback;
        $cols = [];
        foreach ($keys as $k) {
            $cols[$k] = $k;
        }
        return $cols;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchAll(string $sql, string $types, array $params): array
    {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Query prepare failed: ' . $this->conn->error);
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }
}
