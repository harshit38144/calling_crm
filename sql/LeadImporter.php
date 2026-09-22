<?php
/**
 * Reads an uploaded Excel file and inserts leads into the database.
 */
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

class LeadImporter
{
    /** @var mysqli */
    private $conn;

    /** Normalized header alias => lead column */
    private static $fieldAliases = [
        'business_name' => [
            'business name', 'business_name', 'businessname', 'company', 'company name',
            'shop name', 'shop_name', 'name', 'title', 'store name',
        ],
        'contact_name' => [
            'owner name', 'owner_name', 'owner', 'contact name', 'contact_name',
            'contact person', 'contact', 'person name',
        ],
        'phone' => [
            'phone', 'phone number', 'phone_number', 'mobile', 'mobile number',
            'primary phone', 'contact number', 'contact_number', 'tel', 'telephone',
        ],
        'alternate_phone' => [
            'alternate phone', 'alternate_phone', 'alt phone', 'alt_phone',
            'additional phones', 'additional_phones', 'secondary phone', 'other phone',
        ],
        'email' => [
            'email', 'emails', 'e-mail', 'email address', 'email_address', 'mail',
        ],
        'website' => [
            'website', 'website url', 'website_url', 'web', 'url', 'web url',
        ],
        'category' => [
            'category', 'primary_category', 'primary category', 'type', 'business type',
            'business category',
        ],
        'address' => [
            'address', 'full address', 'street', 'street address', 'location address',
        ],
        'city' => ['city', 'town'],
        'state' => ['state', 'province', 'region'],
        'country' => ['country'],
        'pincode' => [
            'pincode', 'pin code', 'pin_code', 'zip', 'zipcode', 'zip code', 'postal code',
        ],
        'rating' => ['rating', 'ratings', 'stars', 'star rating'],
        'review_count' => [
            'reviews', 'review', 'review_count', 'review count', 'total_reviews',
            'total reviews', 'no of reviews', 'number of reviews',
        ],
        'latitude' => ['latitude', 'lat'],
        'longitude' => ['longitude', 'lng', 'lon', 'long'],
        'maps_url' => [
            'maps url', 'maps_url', 'map url', 'map_url', 'google_maps_url',
            'google maps url', 'maps link', 'map link', 'google map', 'gmaps',
        ],
        'google_place_id' => [
            'place_id', 'place id', 'google_place_id', 'google place id',
        ],
    ];

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Process a lead_imports row: read Excel, insert leads, update stats.
     *
     * @return array{success:bool,message:string,total:int,success_count:int,failed_count:int,duplicate_count:int,status:string}
     */
    public function processImport(int $importId): array
    {
        @set_time_limit(0);

        $stmt = $this->conn->prepare('SELECT * FROM lead_imports WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $importId);
        $stmt->execute();
        $import = $stmt->get_result()->fetch_assoc();

        if (!$import) {
            return $this->result(false, 'Import record not found.', 0, 0, 0, 0, 'failed');
        }

        if (in_array($import['status'], ['processing', 'completed'], true)) {
            return $this->result(
                false,
                'This file has already been processed (status: ' . $import['status'] . ').',
                (int) $import['total_rows'],
                (int) $import['success_count'],
                (int) $import['failed_count'],
                (int) $import['duplicate_count'],
                $import['status']
            );
        }

        $path = __DIR__ . '/../uploads/excel/' . $import['stored_filename'];
        if ($import['stored_filename'] === '' || !is_file($path)) {
            $this->markImport($importId, 'failed', 0, 0, 0, 0, 'Stored Excel file not found on disk.');
            return $this->result(false, 'Stored Excel file not found on disk.', 0, 0, 0, 0, 'failed');
        }

        $this->conn->query(
            "UPDATE lead_imports SET status = 'processing', started_at = NOW(),
             remarks = 'Importing leads…' WHERE id = " . (int) $importId
        );

        try {
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
        } catch (Throwable $e) {
            $msg = 'Failed to read Excel: ' . $e->getMessage();
            $this->markImport($importId, 'failed', 0, 0, 0, 0, $msg);
            return $this->result(false, $msg, 0, 0, 0, 0, 'failed');
        }

        if (count($rows) < 2) {
            $msg = 'Excel file has no data rows (header only or empty).';
            $this->markImport($importId, 'failed', 0, 0, 0, 0, $msg);
            return $this->result(false, $msg, 0, 0, 0, 0, 'failed');
        }

        $headerRow = array_shift($rows);
        $columnMap = $this->detectColumns($headerRow);

        if ($columnMap['mapped']['business_name'] === null
            && $columnMap['mapped']['phone'] === null
        ) {
            $msg = 'Could not detect Business Name or Phone columns. Check the header row.';
            $this->markImport($importId, 'failed', 0, 0, 0, 0, $msg);
            return $this->result(false, $msg, 0, 0, 0, 0, 'failed');
        }

        $userId = (int) ($import['imported_by'] ?? 0);
        $sourceLabel = 'excel:' . ($import['original_filename'] ?: $import['filename']);

        $total = 0;
        $success = 0;
        $failed = 0;
        $duplicates = 0;
        $errors = [];

        foreach ($rows as $rowIndex => $row) {
            if ($this->rowIsEmpty($row)) {
                continue;
            }
            $total++;

            $parsed = $this->mapRow($row, $columnMap);
            $business = $parsed['business_name'];
            $phone = $parsed['phone'];

            if ($business === '' && $phone === '') {
                $failed++;
                if (count($errors) < 20) {
                    $errors[] = 'Row ' . ($rowIndex + 2) . ': missing business name and phone.';
                }
                continue;
            }

            if ($this->insertLead($importId, $userId, $sourceLabel, $parsed)) {
                $success++;
            } else {
                $failed++;
                if (count($errors) < 20) {
                    $errors[] = 'Row ' . ($rowIndex + 2) . ': ' . ($this->conn->error ?: 'insert failed');
                }
            }
        }

        if ($total === 0) {
            $remarks = 'No data rows found in Excel file.';
            $this->markImport($importId, 'failed', 0, 0, 0, 0, $remarks);
            return $this->result(false, $remarks, 0, 0, 0, 0, 'failed');
        }

        $remarks = sprintf(
            'Imported %d of %d rows. Failed: %d.',
            $success,
            $total,
            $failed
        );
        if ($errors) {
            $remarks .= ' Issues: ' . implode(' | ', $errors);
        }

        $finalStatus = 'completed';
        $this->markImport($importId, $finalStatus, $total, $success, $failed, $duplicates, $remarks);

        return $this->result(true, $remarks, $total, $success, $failed, $duplicates, $finalStatus);
    }

    /**
     * Import already-mapped lead rows (used by JSON importer).
     *
     * @param array<int, array<string, mixed>> $parsedRows
     * @return array{success:bool,message:string,total:int,success_count:int,failed_count:int,duplicate_count:int,status:string}
     */
    public function importParsedRows(int $importId, string $sourceLabel, array $parsedRows): array
    {
        @set_time_limit(0);

        $stmt = $this->conn->prepare('SELECT * FROM lead_imports WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $importId);
        $stmt->execute();
        $import = $stmt->get_result()->fetch_assoc();
        if (!$import) {
            return $this->result(false, 'Import record not found.', 0, 0, 0, 0, 'failed');
        }

        $userId = (int) ($import['imported_by'] ?? 0);
        $this->conn->query(
            "UPDATE lead_imports SET status = 'processing', started_at = NOW(),
             remarks = 'Importing leads…' WHERE id = " . (int) $importId
        );

        $total = 0;
        $success = 0;
        $failed = 0;
        $duplicates = 0;
        $errors = [];

        foreach ($parsedRows as $rowIndex => $parsed) {
            if (!is_array($parsed)) {
                continue;
            }
            $business = trim((string) ($parsed['business_name'] ?? ''));
            $phone = trim((string) ($parsed['phone'] ?? ''));
            if ($business === '' && $phone === '') {
                // skip completely empty mapped rows
                $hasExtra = !empty($parsed['extra_data']) && is_array($parsed['extra_data']);
                if (!$hasExtra) {
                    continue;
                }
                $failed++;
                if (count($errors) < 20) {
                    $errors[] = 'Row ' . ($rowIndex + 1) . ': missing business name and phone.';
                }
                $total++;
                continue;
            }

            $total++;

            // Ensure all expected keys exist
            $parsed = array_merge([
                'business_name' => '', 'contact_name' => '', 'phone' => '', 'alternate_phone' => '',
                'email' => '', 'website' => '', 'address' => '', 'city' => '', 'state' => '',
                'country' => '', 'pincode' => '', 'category' => '', 'google_place_id' => '',
                'latitude' => null, 'longitude' => null, 'maps_url' => '', 'rating' => null,
                'review_count' => 0, 'extra_data' => [],
            ], $parsed);

            if ($this->insertLead($importId, $userId, $sourceLabel, $parsed)) {
                $success++;
            } else {
                $failed++;
                if (count($errors) < 20) {
                    $errors[] = 'Row ' . ($rowIndex + 1) . ': ' . ($this->conn->error ?: 'insert failed');
                }
            }
        }

        if ($total === 0) {
            $remarks = 'No valid data rows found after mapping.';
            $this->markImport($importId, 'failed', 0, 0, 0, 0, $remarks);
            return $this->result(false, $remarks, 0, 0, 0, 0, 'failed');
        }

        $remarks = sprintf(
            'Imported %d of %d rows. Failed: %d.',
            $success,
            $total,
            $failed
        );
        if ($errors) {
            $remarks .= ' Issues: ' . implode(' | ', $errors);
        }

        $this->markImport($importId, 'completed', $total, $success, $failed, $duplicates, $remarks);
        return $this->result(true, $remarks, $total, $success, $failed, $duplicates, 'completed');
    }

    /** @return array<string, string> field => label */
    public static function crmFieldLabels(): array
    {
        return [
            'business_name' => 'Business Name',
            'contact_name' => 'Owner / Contact',
            'phone' => 'Phone',
            'alternate_phone' => 'Alternate Phone',
            'email' => 'Email',
            'website' => 'Website',
            'category' => 'Category',
            'address' => 'Address',
            'city' => 'City',
            'state' => 'State',
            'country' => 'Country',
            'pincode' => 'Pincode',
            'rating' => 'Rating',
            'review_count' => 'Review Count',
            'latitude' => 'Latitude',
            'longitude' => 'Longitude',
            'maps_url' => 'Maps URL',
            'google_place_id' => 'Google Place ID',
            '_extra' => 'Save in Extra Data',
            '_skip' => 'Skip this field',
        ];
    }

    public static function suggestFieldForHeader(string $header): string
    {
        $norm = strtolower(trim(preg_replace('/\s+/', ' ', $header) ?? $header));
        if ($norm === '') {
            return '_extra';
        }
        foreach (self::$fieldAliases as $field => $aliases) {
            if (in_array($norm, $aliases, true)) {
                return $field;
            }
        }
        // loose contains match
        foreach (self::$fieldAliases as $field => $aliases) {
            foreach ($aliases as $alias) {
                if ($alias !== '' && (strpos($norm, $alias) !== false || strpos($alias, $norm) !== false)) {
                    return $field;
                }
            }
        }
        return '_extra';
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private function insertLead(int $importId, int $userId, string $sourceLabel, array $parsed): bool
    {
        $createdBy = $userId > 0 ? $userId : null;
        $extraJson = $parsed['extra_data'] !== []
            ? json_encode($parsed['extra_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $latSql = $parsed['latitude'] === null ? 'NULL' : (string) (float) $parsed['latitude'];
        $lngSql = $parsed['longitude'] === null ? 'NULL' : (string) (float) $parsed['longitude'];
        $ratingSql = $parsed['rating'] === null ? 'NULL' : (string) (float) $parsed['rating'];
        $createdBySql = $createdBy === null ? 'NULL' : (string) (int) $createdBy;
        $extraSql = $extraJson === null ? 'NULL' : ("'" . $this->conn->real_escape_string($extraJson) . "'");

        $sql = sprintf(
            "INSERT INTO leads (
                import_id, created_by, business_name, contact_name, phone, alternate_phone,
                email, website, address, city, state, country, pincode, category, source,
                google_place_id, latitude, longitude, maps_url, rating, review_count,
                status, notes, extra_data
            ) VALUES (
                %d, %s, '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', %s, %s, '%s', %s, %d,
                'new', NULL, %s
            )",
            $importId,
            $createdBySql,
            $this->conn->real_escape_string($parsed['business_name']),
            $this->conn->real_escape_string($parsed['contact_name']),
            $this->conn->real_escape_string($parsed['phone']),
            $this->conn->real_escape_string($parsed['alternate_phone']),
            $this->conn->real_escape_string($parsed['email']),
            $this->conn->real_escape_string($parsed['website']),
            $this->conn->real_escape_string($parsed['address']),
            $this->conn->real_escape_string($parsed['city']),
            $this->conn->real_escape_string($parsed['state']),
            $this->conn->real_escape_string($parsed['country']),
            $this->conn->real_escape_string($parsed['pincode']),
            $this->conn->real_escape_string($parsed['category']),
            $this->conn->real_escape_string($sourceLabel),
            $this->conn->real_escape_string($parsed['google_place_id']),
            $latSql,
            $lngSql,
            $this->conn->real_escape_string($parsed['maps_url']),
            $ratingSql,
            (int) $parsed['review_count'],
            $extraSql
        );

        return (bool) $this->conn->query($sql);
    }

    /**
     * @param array<int, mixed> $headerRow
     * @return array{mapped: array<string, int|null>, extras: array<int, string>}
     */
    private function detectColumns(array $headerRow): array
    {
        $mapped = [];
        foreach (array_keys(self::$fieldAliases) as $field) {
            $mapped[$field] = null;
        }
        $extras = [];

        foreach ($headerRow as $colIndex => $rawHeader) {
            $header = $this->normalizeHeader((string) $rawHeader);
            if ($header === '') {
                continue;
            }

            $matched = false;
            foreach (self::$fieldAliases as $field => $aliases) {
                if ($mapped[$field] !== null) {
                    continue;
                }
                if (in_array($header, $aliases, true)) {
                    $mapped[$field] = (int) $colIndex;
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $extras[(int) $colIndex] = trim((string) $rawHeader);
            }
        }

        return ['mapped' => $mapped, 'extras' => $extras];
    }

    /**
     * @param array<int, mixed> $row
     * @param array{mapped: array<string, int|null>, extras: array<int, string>} $columnMap
     * @return array<string, mixed>
     */
    private function mapRow(array $row, array $columnMap): array
    {
        $get = function (?int $idx) use ($row): string {
            if ($idx === null || !array_key_exists($idx, $row)) {
                return '';
            }
            $v = $row[$idx];
            if ($v === null) {
                return '';
            }
            return trim((string) $v);
        };

        $m = $columnMap['mapped'];

        $phone = $this->cleanPhone($get($m['phone']));
        $altRaw = $get($m['alternate_phone']);
        $altPhone = '';
        if ($altRaw !== '') {
            $parts = preg_split('/[;,|]/', $altRaw) ?: [];
            $altPhone = $this->cleanPhone(trim((string) ($parts[0] ?? '')));
        }

        $emailRaw = $get($m['email']);
        if ($emailRaw !== '' && (strpos($emailRaw, ';') !== false || strpos($emailRaw, ',') !== false)) {
            $parts = preg_split('/[;,]/', $emailRaw) ?: [];
            $emailRaw = trim((string) ($parts[0] ?? ''));
        }

        $ratingStr = $get($m['rating']);
        $rating = ($ratingStr !== '' && is_numeric($ratingStr)) ? round((float) $ratingStr, 2) : null;

        $reviewsStr = $get($m['review_count']);
        $reviewCount = $reviewsStr === '' ? 0 : (int) preg_replace('/\D+/', '', $reviewsStr);

        $latStr = $get($m['latitude']);
        $lngStr = $get($m['longitude']);
        $lat = ($latStr !== '' && is_numeric($latStr)) ? (float) $latStr : null;
        $lng = ($lngStr !== '' && is_numeric($lngStr)) ? (float) $lngStr : null;

        $extra = [];
        foreach ($columnMap['extras'] as $colIndex => $headerLabel) {
            if (!array_key_exists($colIndex, $row)) {
                continue;
            }
            $val = $row[$colIndex];
            if ($val === null || trim((string) $val) === '') {
                continue;
            }
            $extra[$headerLabel] = is_scalar($val) ? trim((string) $val) : json_encode($val);
        }

        return [
            'business_name' => mb_substr($get($m['business_name']), 0, 255),
            'contact_name' => mb_substr($get($m['contact_name']), 0, 150),
            'phone' => mb_substr($phone, 0, 30),
            'alternate_phone' => mb_substr($altPhone, 0, 30),
            'email' => mb_substr($emailRaw, 0, 150),
            'website' => mb_substr($get($m['website']), 0, 255),
            'address' => mb_substr($get($m['address']), 0, 500),
            'city' => mb_substr($get($m['city']), 0, 100),
            'state' => mb_substr($get($m['state']), 0, 100),
            'country' => mb_substr($get($m['country']), 0, 100),
            'pincode' => mb_substr($get($m['pincode']), 0, 20),
            'category' => mb_substr($get($m['category']), 0, 150),
            'google_place_id' => mb_substr($get($m['google_place_id']), 0, 191),
            'latitude' => $lat,
            'longitude' => $lng,
            'maps_url' => mb_substr($get($m['maps_url']), 0, 500),
            'rating' => $rating,
            'review_count' => max(0, $reviewCount),
            'extra_data' => $extra,
        ];
    }

    private function isDuplicateLead(string $phone, string $businessName): bool
    {
        $phoneKey = $this->normalizePhone($phone);
        if ($phoneKey !== '') {
            $esc = $this->conn->real_escape_string($phoneKey);
            $q = $this->conn->query(
                "SELECT id FROM leads
                 WHERE is_deleted = 0 AND phone <> ''
                   AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''),')','') = '{$esc}'
                 LIMIT 1"
            );
            if ($q && $q->fetch_assoc()) {
                return true;
            }
        }

        $nameKey = $this->normalizeName($businessName);
        if ($nameKey !== '') {
            $esc = $this->conn->real_escape_string($nameKey);
            $q = $this->conn->query(
                "SELECT id FROM leads
                 WHERE is_deleted = 0 AND business_name <> ''
                   AND LOWER(TRIM(business_name)) = '{$esc}'
                 LIMIT 1"
            );
            if ($q && $q->fetch_assoc()) {
                return true;
            }
        }

        return false;
    }

    private function markImport(
        int $id,
        string $status,
        int $total,
        int $success,
        int $failed,
        int $duplicates,
        string $remarks
    ): void {
        $stmt = $this->conn->prepare(
            'UPDATE lead_imports SET
                status = ?, total_rows = ?, success_count = ?, failed_count = ?,
                duplicate_count = ?, remarks = ?, notes = ?,
                completed_at = NOW(), updated_at = NOW()
             WHERE id = ?'
        );
        $notes = 'Lead import finished.';
        $stmt->bind_param(
            'siiiissi',
            $status,
            $total,
            $success,
            $failed,
            $duplicates,
            $remarks,
            $notes,
            $id
        );
        $stmt->execute();
    }

    private function result(
        bool $ok,
        string $message,
        int $total,
        int $success,
        int $failed,
        int $duplicates,
        string $status
    ): array {
        return [
            'success' => $ok,
            'message' => $message,
            'total' => $total,
            'success_count' => $success,
            'failed_count' => $failed,
            'duplicate_count' => $duplicates,
            'status' => $status,
        ];
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        return preg_replace('/\s+/', ' ', $header) ?? $header;
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function normalizeName(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
    }

    private function cleanPhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        if (preg_match('/^\+?[0-9\s\-\(\)]+$/', $phone)) {
            return preg_replace('/[^\d+]/', '', $phone) ?: $phone;
        }
        return mb_substr($phone, 0, 30);
    }

    /** @param array<int, mixed> $row */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }
}
