<?php
/**
 * JSON lead import helpers — preview keys + map arbitrary fields into leads.
 */
require_once __DIR__ . '/LeadImporter.php';

class JsonImporter
{
    /** @var mysqli */
    private $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Parse JSON file into a list of associative row arrays.
     *
     * @return array{success:bool,message:string,records?:array<int,array<string,mixed>>,keys?:array<int,string>}
     */
    public static function loadRecordsFromFile(string $path): array
    {
        if (!is_file($path)) {
            return ['success' => false, 'message' => 'JSON file not found on disk.'];
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return ['success' => false, 'message' => 'JSON file is empty.'];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()];
        }

        $records = self::normalizeToRecords($decoded);
        if ($records === null) {
            return [
                'success' => false,
                'message' => 'JSON must be an array of objects, or an object containing an array of objects.',
            ];
        }
        if (!$records) {
            return ['success' => false, 'message' => 'JSON has no data rows.'];
        }

        $keys = self::collectKeys($records);
        return [
            'success' => true,
            'message' => 'OK',
            'records' => $records,
            'keys' => $keys,
        ];
    }

    /**
     * @param mixed $decoded
     * @return array<int, array<string, mixed>>|null
     */
    public static function normalizeToRecords($decoded): ?array
    {
        if (is_array($decoded) && self::isList($decoded)) {
            $out = [];
            foreach ($decoded as $row) {
                if (is_array($row) && self::isAssoc($row)) {
                    $out[] = $row;
                } elseif (is_scalar($row) || $row === null) {
                    $out[] = ['value' => $row];
                }
            }
            return $out;
        }

        if (is_array($decoded) && self::isAssoc($decoded)) {
            // Prefer common wrapper keys
            foreach (['data', 'leads', 'records', 'items', 'results', 'rows'] as $wrap) {
                if (isset($decoded[$wrap]) && is_array($decoded[$wrap]) && self::isList($decoded[$wrap])) {
                    return self::normalizeToRecords($decoded[$wrap]);
                }
            }
            // Single object → one row
            return [$decoded];
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array<int, string>
     */
    public static function collectKeys(array $records): array
    {
        $keys = [];
        $limit = min(count($records), 200);
        for ($i = 0; $i < $limit; $i++) {
            foreach ($records[$i] as $k => $v) {
                $k = (string) $k;
                if ($k === '' || isset($keys[$k])) {
                    continue;
                }
                $keys[$k] = true;
            }
        }
        return array_keys($keys);
    }

    /**
     * Build preview + suggested mapping for UI.
     *
     * @return array{success:bool,message:string,keys?:array<int,string>,suggested?:array<string,string>,samples?:array<string,array<int,string>>,preview_rows?:array<int,array<string,string>>,total_records?:int}
     */
    public static function buildPreview(string $path, int $previewLimit = 5): array
    {
        $loaded = self::loadRecordsFromFile($path);
        if (empty($loaded['success'])) {
            return $loaded;
        }

        /** @var array<int, array<string, mixed>> $records */
        $records = $loaded['records'];
        /** @var array<int, string> $keys */
        $keys = $loaded['keys'];

        $suggested = [];
        $usedCrm = [];
        foreach ($keys as $key) {
            $guess = LeadImporter::suggestFieldForHeader($key);
            if ($guess !== '_extra' && $guess !== '_skip' && isset($usedCrm[$guess])) {
                $guess = '_extra';
            }
            if ($guess !== '_extra' && $guess !== '_skip') {
                $usedCrm[$guess] = true;
            }
            $suggested[$key] = $guess;
        }

        $samples = [];
        foreach ($keys as $key) {
            $samples[$key] = [];
            foreach ($records as $row) {
                if (!array_key_exists($key, $row)) {
                    continue;
                }
                $val = self::stringifyValue($row[$key]);
                if ($val === '') {
                    continue;
                }
                $samples[$key][] = mb_substr($val, 0, 80);
                if (count($samples[$key]) >= 3) {
                    break;
                }
            }
        }

        $previewRows = [];
        $n = min(count($records), max(1, $previewLimit));
        for ($i = 0; $i < $n; $i++) {
            $flat = [];
            foreach ($keys as $key) {
                $flat[$key] = array_key_exists($key, $records[$i])
                    ? mb_substr(self::stringifyValue($records[$i][$key]), 0, 120)
                    : '';
            }
            $previewRows[] = $flat;
        }

        return [
            'success' => true,
            'message' => 'Preview ready.',
            'keys' => $keys,
            'suggested' => $suggested,
            'samples' => $samples,
            'preview_rows' => $previewRows,
            'total_records' => count($records),
            'field_options' => LeadImporter::crmFieldLabels(),
        ];
    }

    /**
     * Apply user mapping and import.
     *
     * @param array<string, string> $mapping jsonKey => crmField|_extra|_skip
     */
    public function processImport(int $importId, array $mapping): array
    {
        $stmt = $this->conn->prepare('SELECT * FROM lead_imports WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $importId);
        $stmt->execute();
        $import = $stmt->get_result()->fetch_assoc();
        if (!$import) {
            return [
                'success' => false,
                'message' => 'Import record not found.',
                'total' => 0,
                'success_count' => 0,
                'failed_count' => 0,
                'duplicate_count' => 0,
                'status' => 'failed',
            ];
        }

        if (($import['source'] ?? '') !== 'json') {
            return [
                'success' => false,
                'message' => 'This import is not a JSON file.',
                'total' => 0,
                'success_count' => 0,
                'failed_count' => 0,
                'duplicate_count' => 0,
                'status' => (string) ($import['status'] ?? 'failed'),
            ];
        }

        if (in_array($import['status'], ['processing', 'completed'], true)) {
            return [
                'success' => false,
                'message' => 'This file has already been processed (status: ' . $import['status'] . ').',
                'total' => (int) $import['total_rows'],
                'success_count' => (int) $import['success_count'],
                'failed_count' => (int) $import['failed_count'],
                'duplicate_count' => (int) $import['duplicate_count'],
                'status' => $import['status'],
            ];
        }

        $path = __DIR__ . '/../uploads/json/' . $import['stored_filename'];
        $loaded = self::loadRecordsFromFile($path);
        if (empty($loaded['success'])) {
            $this->failImport($importId, $loaded['message'] ?? 'Could not read JSON.');
            return [
                'success' => false,
                'message' => $loaded['message'] ?? 'Could not read JSON.',
                'total' => 0,
                'success_count' => 0,
                'failed_count' => 0,
                'duplicate_count' => 0,
                'status' => 'failed',
            ];
        }

        $allowed = array_keys(LeadImporter::crmFieldLabels());
        $cleanMap = [];
        foreach ($mapping as $jsonKey => $crmField) {
            $jsonKey = (string) $jsonKey;
            $crmField = (string) $crmField;
            if ($jsonKey === '') {
                continue;
            }
            if (!in_array($crmField, $allowed, true)) {
                $crmField = '_extra';
            }
            $cleanMap[$jsonKey] = $crmField;
        }

        $hasNameOrPhone = in_array('business_name', $cleanMap, true) || in_array('phone', $cleanMap, true);
        if (!$hasNameOrPhone) {
            $msg = 'Map at least Business Name or Phone before importing.';
            $this->failImport($importId, $msg);
            return [
                'success' => false,
                'message' => $msg,
                'total' => 0,
                'success_count' => 0,
                'failed_count' => 0,
                'duplicate_count' => 0,
                'status' => 'failed',
            ];
        }

        // Persist mapping
        $mapJson = json_encode($cleanMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $upd = $this->conn->prepare('UPDATE lead_imports SET column_mapping = ? WHERE id = ?');
        $upd->bind_param('si', $mapJson, $importId);
        $upd->execute();

        $parsedRows = [];
        foreach ($loaded['records'] as $row) {
            $parsedRows[] = self::mapRecord($row, $cleanMap);
        }

        $sourceLabel = 'json:' . ($import['original_filename'] ?: $import['filename']);
        $leadImporter = new LeadImporter($this->conn);
        return $leadImporter->importParsedRows($importId, $sourceLabel, $parsedRows);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $mapping
     * @return array<string, mixed>
     */
    public static function mapRecord(array $row, array $mapping): array
    {
        $parsed = [
            'business_name' => '',
            'contact_name' => '',
            'phone' => '',
            'alternate_phone' => '',
            'email' => '',
            'website' => '',
            'address' => '',
            'city' => '',
            'state' => '',
            'country' => '',
            'pincode' => '',
            'category' => '',
            'google_place_id' => '',
            'latitude' => null,
            'longitude' => null,
            'maps_url' => '',
            'rating' => null,
            'review_count' => 0,
            'extra_data' => [],
        ];

        foreach ($row as $key => $value) {
            $key = (string) $key;
            $target = $mapping[$key] ?? '_extra';
            $str = self::stringifyValue($value);

            if ($target === '_skip') {
                continue;
            }
            if ($target === '_extra') {
                if ($str !== '') {
                    $parsed['extra_data'][$key] = $str;
                }
                continue;
            }

            if (in_array($target, ['latitude', 'longitude', 'rating'], true)) {
                $parsed[$target] = ($str !== '' && is_numeric($str)) ? (float) $str : null;
                continue;
            }
            if ($target === 'review_count') {
                $parsed[$target] = $str === '' ? 0 : (int) preg_replace('/\D+/', '', $str);
                continue;
            }
            if ($target === 'phone' || $target === 'alternate_phone') {
                $parsed[$target] = mb_substr(preg_replace('/[^\d+]/', '', $str) ?: $str, 0, 30);
                continue;
            }
            if ($target === 'email' && $str !== '') {
                if (strpos($str, ';') !== false || strpos($str, ',') !== false) {
                    $parts = preg_split('/[;,]/', $str) ?: [];
                    $str = trim((string) ($parts[0] ?? ''));
                }
            }

            $maxLen = [
                'business_name' => 255,
                'contact_name' => 150,
                'email' => 150,
                'website' => 255,
                'address' => 500,
                'city' => 100,
                'state' => 100,
                'country' => 100,
                'pincode' => 20,
                'category' => 150,
                'google_place_id' => 191,
                'maps_url' => 500,
            ];
            $parsed[$target] = mb_substr($str, 0, $maxLen[$target] ?? 255);
        }

        return $parsed;
    }

    /** @param mixed $value */
    public static function stringifyValue($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function failImport(int $importId, string $msg): void
    {
        $esc = $this->conn->real_escape_string($msg);
        $this->conn->query(
            "UPDATE lead_imports SET status = 'failed', remarks = '{$esc}', completed_at = NOW()
             WHERE id = {$importId}"
        );
    }

    /** @param array<mixed> $arr */
    private static function isList(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    /** @param array<mixed> $arr */
    private static function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }
        return !self::isList($arr);
    }
}
