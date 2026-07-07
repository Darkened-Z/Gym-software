<?php
/**
 * Import device attendance (e.g. a ZKTeco F22 export) into the gym's own
 * attendance so it shows up in the existing Attendance + Reports — read-only,
 * no device changes. Each row in the export is one scan/visit; we map it to a
 * member (by member code, by the F22 PIN scheme, or by name) and insert an
 * attendance record. Re-importing the same file is safe (dedup by member+time).
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/models/Member.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;

class AttendanceImportController {
    private $db;

    // Column header aliases -> logical field (lowercased, trimmed).
    private static $aliases = [
        'user_id' => ['user id', 'userid', 'user_id', 'ac-no', 'ac no', 'acno', 'pin', 'id', 'badge', 'badgenumber', 'badge number', 'enroll number', 'enrollno', 'emp id', 'employee id', 'no.', 'no'],
        'name'    => ['name', 'user name', 'username', 'employee name', 'emp name', 'full name'],
        'datetime'=> ['date time', 'datetime', 'punch time', 'att time', 'timestamp', 'check time', 'clock time', 'time in', 'record time'],
        'date'    => ['date'],
        'time'    => ['time'],
    ];

    public function __construct($db) {
        $this->db = $db;
    }

    public function importFromFile(string $filePath): array {
        $result = ['imported' => 0, 'duplicates' => 0, 'unmatched' => 0, 'rows' => 0,
                   'unmatched_list' => [], 'errors' => []];

        $sheet = IOFactory::load($filePath)->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false);
        if (count($rows) < 2) {
            throw new Exception('File is empty or has no data rows.');
        }

        $headers = array_map(fn($h) => strtolower(trim((string)$h)), $rows[0]);
        $col = $this->mapColumns($headers);
        if (!isset($col['datetime']) && !isset($col['date'])) {
            throw new Exception('Could not find a date/time column. Expected a column like "Date Time", "Time" or "Date".');
        }
        if (!isset($col['user_id']) && !isset($col['name'])) {
            throw new Exception('Could not find a user column. Expected "User ID"/"AC-No"/"PIN" or "Name".');
        }

        $memberCache = [];
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) continue;
            $result['rows']++;

            $userId = isset($col['user_id']) ? trim((string)($row[$col['user_id']] ?? '')) : '';
            $name = isset($col['name']) ? trim((string)($row[$col['name']] ?? '')) : '';
            $when = $this->parseDateTime($row, $col);
            if ($when === null) { $result['errors'][] = "Row " . ($i + 1) . ": unreadable date/time"; continue; }

            $key = $userId . '|' . mb_strtolower($name);
            if (!array_key_exists($key, $memberCache)) {
                $memberCache[$key] = $this->resolveMember($userId, $name);
            }
            $match = $memberCache[$key];
            if (!$match) {
                $result['unmatched']++;
                $label = trim(($userId !== '' ? $userId : '') . ' ' . $name);
                if ($label !== '' && !in_array($label, $result['unmatched_list'], true) && count($result['unmatched_list']) < 100) {
                    $result['unmatched_list'][] = $label;
                }
                continue;
            }

            if ($this->insertAttendance($match['gender'], (int)$match['id'], $when)) {
                $result['imported']++;
            } else {
                $result['duplicates']++;
            }
        }
        return $result;
    }

    private function mapColumns(array $headers): array {
        $col = [];
        foreach ($headers as $idx => $h) {
            foreach (self::$aliases as $field => $names) {
                if (in_array($h, $names, true) && !isset($col[$field])) {
                    $col[$field] = $idx;
                }
            }
        }
        return $col;
    }

    private function parseDateTime(array $row, array $col): ?string {
        // Combined datetime column, else date + optional time.
        $raw = null;
        if (isset($col['datetime'])) {
            $raw = $row[$col['datetime']] ?? null;
        } elseif (isset($col['date'])) {
            $d = $row[$col['date']] ?? null;
            $t = isset($col['time']) ? ($row[$col['time']] ?? '') : '';
            if (is_numeric($d)) {
                try { $d = XlsDate::excelToDateTimeObject((float)$d)->format('Y-m-d'); } catch (Throwable $e) {}
            }
            $raw = trim((string)$d . ' ' . (string)$t);
        }
        if ($raw === null || trim((string)$raw) === '') return null;

        if (is_numeric($raw)) { // Excel serial datetime
            try { return XlsDate::excelToDateTimeObject((float)$raw)->format('Y-m-d H:i:s'); }
            catch (Throwable $e) { return null; }
        }
        $ts = strtotime((string)$raw);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    /** @return array{id:int,gender:string,name:string}|null */
    private function resolveMember(string $userId, string $name): ?array {
        foreach (['men', 'women'] as $g) {
            $t = 'members_' . $g;
            // 1) member_code == user id
            if ($userId !== '') {
                $r = $this->one("SELECT id, name FROM {$t} WHERE member_code = ? LIMIT 1", [$userId]);
                if ($r) return ['id' => $r['id'], 'gender' => $g, 'name' => $r['name']];
            }
            // 2) F22 PIN scheme: men 10000000+id, women 20000000+id
            if (ctype_digit($userId)) {
                $pin = (int)$userId;
                $off = $g === 'women' ? 20000000 : 10000000;
                if ($pin > $off && $pin < $off + 10000000) {
                    $r = $this->one("SELECT id, name FROM {$t} WHERE id = ? LIMIT 1", [$pin - $off]);
                    if ($r) return ['id' => $r['id'], 'gender' => $g, 'name' => $r['name']];
                }
            }
        }
        // 3) exact name match (either gender)
        if ($name !== '') {
            foreach (['men', 'women'] as $g) {
                $r = $this->one("SELECT id, name FROM members_{$g} WHERE LOWER(name) = LOWER(?) LIMIT 1", [$name]);
                if ($r) return ['id' => $r['id'], 'gender' => $g, 'name' => $r['name']];
            }
        }
        return null;
    }

    private function insertAttendance(string $gender, int $memberId, string $checkIn): bool {
        $t = 'attendance_' . $gender;
        $dup = $this->one("SELECT id FROM {$t} WHERE member_id = ? AND check_in = ? LIMIT 1", [$memberId, $checkIn]);
        if ($dup) return false;
        $stmt = $this->db->prepare(
            "INSERT INTO {$t} (member_id, check_in, is_first_entry_today, entry_gate_id, write_source)
             VALUES (?, ?, 1, 'f22-import', 'f22-import')"
        );
        $stmt->execute([$memberId, $checkIn]);
        return true;
    }

    private function one(string $sql, array $params) {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
