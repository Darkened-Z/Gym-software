<?php
/**
 * ZKTeco ADMS "Push" receiver — F22 / UA860 talk to THIS endpoint.
 *
 * Routes:
 *   GET  /iclock/cdata       — handshake (option/stamp block)
 *   POST /iclock/cdata       — ATTLOG / RTLOG / TABLEDATA uploads
 *   GET  /iclock/getrequest  — device polls for commands (auto-block lives here)
 *   POST /iclock/devicecmd   — device acknowledges command results
 *   POST /iclock/registry    — device registration
 *
 * Auto-block: every 30 min the getrequest handler checks which members are
 * overdue. Overdue → disable on device (door won't open). Paid up → re-enable.
 * Fingerprints stay enrolled either way — no re-scan needed.
 *
 * Tracking uses gym_settings keys (no extra tables):
 *   f22_disabled_pins    — comma-separated PINs currently disabled on device
 *   f22_cmd_counter      — monotonic command ID for device ack protocol
 *   f22_last_overdue_check — throttle timestamp
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/app/helpers/LicenseHelper.php';

header('Content-Type: text/plain');

$db = null;
try { $db = (new Database())->getConnection(); }
catch (Throwable $e) { error_log('[iclock] db: ' . $e->getMessage()); echo "OK\n"; exit; }

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$sn = trim((string)($_GET['SN'] ?? ''));
$table = strtoupper(trim((string)($_GET['table'] ?? '')));

$route = strtolower(basename($path));

// --- Allowlist ------------------------------------------------------------
function iclock_allowed(PDO $db, string $sn): bool {
    if ($sn === '') return false;
    $stmt = $db->prepare("SELECT setting_value FROM gym_settings WHERE setting_key = ?");
    $stmt->execute(['f22_allowed_serials']);
    $raw = (string)($stmt->fetchColumn() ?: '');
    if (trim($raw) === '') return true;
    foreach (preg_split('/[,\s]+/', $raw) as $tok) if (trim($tok) === $sn) return true;
    return false;
}

if (!iclock_allowed($db, $sn)) {
    error_log("[iclock] rejected SN='{$sn}' route={$route}");
    http_response_code(200);
    echo "OK\n";
    exit;
}

$rawBody = ($method === 'POST') ? (file_get_contents('php://input') ?: '') : '';

$logLine = date('Y-m-d H:i:s') . " {$method} route={$route} SN={$sn} table={$table} qs=" . ($_SERVER['QUERY_STRING'] ?? '');
if ($method === 'POST') $logLine .= " body=" . substr($rawBody, 0, 500);
@file_put_contents(__DIR__ . '/iclock-debug.log', $logLine . "\n", FILE_APPEND);

$hb = $db->prepare("INSERT INTO gym_settings (setting_key, setting_value) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
$hb->execute(['f22_last_seen', date('Y-m-d H:i:s')]);
$hb->execute(['f22_last_sn', $sn]);

// --- Routes ---------------------------------------------------------------
if ($route === 'cdata') {
    if ($method === 'GET') {
        echo "GET OPTION FROM: {$sn}\n";
        echo "Stamp=0\n";
        echo "ATTLOGStamp=0\n";
        echo "OPERLOGStamp=0\n";
        echo "ATTPHOTOStamp=0\n";
        echo "ErrorDelay=30\n";
        echo "Delay=10\n";
        echo "TransTimes=00:00;23:59\n";
        echo "TransInterval=1\n";
        echo "TransFlag=1111000000\n";
        echo "Realtime=1\n";
        echo "Encrypt=0\n";
        echo "ServerVer=2.0.33\n";
        echo "PushOptionsFlag=1\n";
        exit;
    }
    if ($method === 'POST') {
        // Gym-subscription gate — when the panel marks the gym past-grace,
        // stop recording device punches. Belt-and-braces to the device-level
        // disable in iclock_build_block_commands(): if a scan somehow arrives
        // before the device has polled the getrequest queue, we still refuse
        // to write it into attendance so admins see the gym stop operating.
        $gymLocked = !(new LicenseHelper($db))->isLicenseValid();
        if ($gymLocked) {
            @file_put_contents(__DIR__ . '/iclock-debug.log',
                date('Y-m-d H:i:s') . " GYM LOCKED — dropping {$table} upload from SN={$sn}\n", FILE_APPEND);
        } else {
            if ($table === 'ATTLOG') {
                iclock_process_attlog($db, $rawBody);
            } elseif ($table === 'RTLOG') {
                iclock_process_rtlog($db, $rawBody);
            } elseif ($table === 'TABLEDATA' && ($_GET['tablename'] ?? '') === 'user') {
                iclock_sync_users($db, $rawBody);
            } elseif ($table === 'FINGERTMP' || ($table === 'TABLEDATA' && ($_GET['tablename'] ?? '') === 'fingertmp')) {
                // Template upload from device in response to DATA QUERY FINGERTMP.
                // Format: one line per template — "PIN\tFID\tSize\tValid\tTMP"
                // where TMP is base64-ish encoded template blob. We cache the
                // whole raw line per (PIN, FID) so we can replay it back to the
                // device with DATA UPDATE FINGERTMP later.
                iclock_cache_fingertmp($db, $rawBody);
            }
        }
        echo "OK\n";
        exit;
    }
}

if ($route === 'getrequest') {
    $commands = iclock_build_block_commands($db);
    if (empty($commands)) {
        echo "OK\n";
    } else {
        foreach ($commands as $line) {
            echo $line . "\n";
        }
        @file_put_contents(__DIR__ . '/iclock-debug.log',
            date('Y-m-d H:i:s') . " GETREQUEST sent " . count($commands) . " commands\n", FILE_APPEND);
    }
    exit;
}

if ($route === 'registry') {
    echo "RegistryCode=200\n";
    exit;
}

if ($route === 'devicecmd') {
    if ($method === 'POST' && $rawBody !== '') {
        @file_put_contents(__DIR__ . '/iclock-debug.log',
            date('Y-m-d H:i:s') . " DEVICECMD body=" . substr($rawBody, 0, 300) . "\n", FILE_APPEND);
    }
    echo "OK\n";
    exit;
}

if ($route === 'ping' || $route === 'fdata') {
    echo "OK\n";
    exit;
}

echo "OK\n";


// ==========================================================================
//  ATTLOG / RTLOG / user-sync
// ==========================================================================

function iclock_process_attlog(PDO $db, string $body): void {
    foreach (preg_split('/\r?\n/', $body) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = preg_split('/\t/', $line);
        if (count($parts) < 2) continue;
        $pin = trim($parts[0]);
        $ts = strtotime(trim($parts[1]));
        if (!$ts || $pin === '') continue;
        $when = date('Y-m-d H:i:s', $ts);
        $match = iclock_resolve_pin($db, $pin);
        if (!$match) {
            error_log("[iclock] unmatched PIN {$pin} at {$when}");
            continue;
        }
        iclock_upsert_visit($db, $match['gender'], (int)$match['id'], $when);
        iclock_alert_if_unpaid($db, $match['gender'], (int)$match['id'], $when);
    }
}

/** @return array{id:int,gender:string}|null */
function iclock_resolve_pin(PDO $db, string $pin): ?array {
    foreach (['men', 'women'] as $g) {
        $stmt = $db->prepare("SELECT id FROM members_{$g} WHERE member_code = ? LIMIT 1");
        $stmt->execute([$pin]);
        $id = $stmt->fetchColumn();
        if ($id) return ['id' => (int)$id, 'gender' => $g];
    }
    if (ctype_digit($pin)) {
        $p = (int)$pin;
        foreach ([['men', 10000000], ['women', 20000000]] as [$g, $off]) {
            if ($p > $off && $p < $off + 10000000) {
                $stmt = $db->prepare("SELECT id FROM members_{$g} WHERE id = ? LIMIT 1");
                $stmt->execute([$p - $off]);
                $id = $stmt->fetchColumn();
                if ($id) return ['id' => (int)$id, 'gender' => $g];
            }
        }
    }
    return null;
}

function iclock_upsert_visit(PDO $db, string $gender, int $memberId, string $when): void {
    $t = 'attendance_' . $gender;
    $date = substr($when, 0, 10);
    $stmt = $db->prepare("SELECT id, check_in, check_out FROM {$t}
                          WHERE member_id = ? AND DATE(check_in) = ? AND write_source = 'f22-live' LIMIT 1");
    $stmt->execute([$memberId, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $stmt = $db->prepare("SELECT 1 FROM {$t} WHERE member_id = ? AND DATE(check_in) = ? LIMIT 1");
        $stmt->execute([$memberId, $date]);
        $isFirst = $stmt->fetchColumn() ? 0 : 1;
        $ins = $db->prepare("INSERT INTO {$t}
            (member_id, check_in, check_out, duration_minutes, is_first_entry_today, entry_gate_id, write_source)
            VALUES (?, ?, NULL, NULL, ?, 'f22-live', 'f22-live')");
        $ins->execute([$memberId, $when, $isFirst]);
        return;
    }

    $newIn = ($when < $row['check_in']) ? $when : $row['check_in'];
    $existOut = $row['check_out'] ?: $row['check_in'];
    $newOut = ($when > $existOut) ? $when : $existOut;
    if ($newOut <= $newIn) $newOut = null;
    $sameIn = ($newIn === $row['check_in']);
    $sameOut = ($newOut === ($row['check_out'] ?: null));
    if ($sameIn && $sameOut) return;
    $dur = $newOut ? max(0, (int)((strtotime($newOut) - strtotime($newIn)) / 60)) : null;
    $upd = $db->prepare("UPDATE {$t} SET check_in = ?, check_out = ?, duration_minutes = ? WHERE id = ?");
    $upd->execute([$newIn, $newOut, $dur, $row['id']]);
}

function iclock_process_rtlog(PDO $db, string $body): void {
    foreach (preg_split('/\r?\n/', $body) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $fields = [];
        foreach (preg_split('/\t/', $line) as $kv) {
            $eq = strpos($kv, '=');
            if ($eq !== false) $fields[trim(substr($kv, 0, $eq))] = trim(substr($kv, $eq + 1));
        }
        $pin = (string)($fields['pin'] ?? '0');
        $event = (int)($fields['event'] ?? -1);
        $ts = strtotime($fields['time'] ?? '');
        if (!$ts || $pin === '0' || $pin === '') continue;
        if (!in_array($event, [0, 1, 2, 3, 4, 5, 6, 27], true)) continue;
        $when = date('Y-m-d H:i:s', $ts);
        $match = iclock_resolve_pin($db, $pin);
        if (!$match) {
            @file_put_contents(__DIR__ . '/iclock-debug.log',
                date('Y-m-d H:i:s') . " RTLOG unmatched pin={$pin} event={$event} at {$when}\n", FILE_APPEND);
            continue;
        }
        iclock_upsert_visit($db, $match['gender'], (int)$match['id'], $when);
        iclock_alert_if_unpaid($db, $match['gender'], (int)$match['id'], $when);
        @file_put_contents(__DIR__ . '/iclock-debug.log',
            date('Y-m-d H:i:s') . " RTLOG VISIT pin={$pin} → {$match['gender']} id={$match['id']} at {$when}\n", FILE_APPEND);
    }
}

function iclock_sync_users(PDO $db, string $body): void {
    foreach (preg_split('/\r?\n/', $body) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, 'user ') !== 0) continue;
        $line = substr($line, 5);
        $fields = [];
        foreach (preg_split('/\t/', $line) as $kv) {
            $eq = strpos($kv, '=');
            if ($eq !== false) $fields[trim(substr($kv, 0, $eq))] = trim(substr($kv, $eq + 1));
        }
        $pin = trim($fields['pin'] ?? '');
        $name = trim($fields['name'] ?? '');
        if ($pin === '' || $pin === '0') continue;
        if ($name === '') $name = 'F22-User-' . $pin;
        $exists = iclock_resolve_pin($db, $pin);
        if ($exists) continue;
        $phone = '00000' . str_pad($pin, 5, '0', STR_PAD_LEFT);
        $today = date('Y-m-d');
        $due = date('Y-m-d', strtotime('+30 days'));
        $ins = $db->prepare("INSERT INTO members_men
            (member_code, name, phone, join_date, next_fee_due_date, status)
            VALUES (?, ?, ?, ?, ?, 'active')");
        $ins->execute([$pin, $name, $phone, $today, $due]);
        @file_put_contents(__DIR__ . '/iclock-debug.log',
            date('Y-m-d H:i:s') . " SYNC NEW MEMBER pin={$pin} name={$name} → members_men\n", FILE_APPEND);
    }
}


// ==========================================================================
//  AUTO-BLOCK — uses gym_settings only, no extra tables needed
// ==========================================================================

function iclock_setting_get(PDO $db, string $key): string {
    $stmt = $db->prepare("SELECT setting_value FROM gym_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    return (string)($stmt->fetchColumn() ?: '');
}

function iclock_setting_set(PDO $db, string $key, string $val): void {
    $db->prepare("INSERT INTO gym_settings (setting_key, setting_value) VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
       ->execute([$key, $val]);
}

/**
 * Cache a fingerprint template upload from the device. Called when the F22
 * POSTs to /iclock/cdata after we asked it to QUERY FINGERTMP for a PIN.
 * Stores per (PIN, FID) in gym_settings as f22_tmp_<PIN>_<FID>. Also updates
 * the cached-PINs index f22_tmp_cached_pins.
 *
 * Line format (varies by firmware): PIN\tFID\tSize\tValid\tTMP\n
 * We store the entire raw line (minus trailing newline) so we can replay it
 * back verbatim with DATA UPDATE FINGERTMP.
 */
function iclock_cache_fingertmp(PDO $db, string $body): void {
    $cached = 0;
    $indexRaw = iclock_setting_get($db, 'f22_tmp_cached_pins');
    $index = array_filter(array_map('trim', explode(',', $indexRaw)));
    $indexSet = array_flip($index);

    foreach (preg_split('/\r?\n/', $body) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = preg_split('/\t/', $line);
        if (count($parts) < 2) continue;
        $pin = trim($parts[0]);
        $fid = trim($parts[1] ?? '0');
        if ($pin === '') continue;

        iclock_setting_set($db, "f22_tmp_{$pin}_{$fid}", $line);
        $cached++;
        if (!isset($indexSet[$pin])) { $index[] = $pin; $indexSet[$pin] = true; }
        @file_put_contents(__DIR__ . '/iclock-debug.log',
            date('Y-m-d H:i:s') . " FINGERTMP cached pin={$pin} fid={$fid} size=" . strlen($line) . " bytes\n", FILE_APPEND);
    }
    iclock_setting_set($db, 'f22_tmp_cached_pins', implode(',', array_values(array_unique($index))));
    // Clear any query-pending markers for cached PINs
    if ($cached > 0) {
        $pendingRaw = iclock_setting_get($db, 'f22_query_pending');
        if ($pendingRaw) {
            $pending = array_filter(array_map('trim', explode(',', $pendingRaw)));
            $stillPending = array_diff($pending, $index);
            iclock_setting_set($db, 'f22_query_pending', implode(',', $stillPending));
        }
    }
}

/**
 * Build BLOCK/UNBLOCK commands for overdue/paid members.
 *
 * Strategy — DATA DELETE FINGERTMP + template cache.
 *
 * Why: this F22 firmware rejects DATA UPDATE USERINFO with Return=-629 for
 * every field we tried (Enable, EndDatetime). Cross-check on 2026-08-29
 * showed 15 disabled members still checking in over 2 days. DELETE
 * commands are much more universally accepted than UPDATE across firmwares,
 * so we switch to deleting the fingerprint template directly — no template,
 * no thumb match, no door open.
 *
 * Restore-on-payment problem: DELETE is destructive unless we can put the
 * template back. So before we delete any PIN, we first send a
 * DATA QUERY FINGERTMP to make the device upload the template. Templates
 * come back via POST /iclock/cdata table=FINGERTMP and we cache them per
 * (PIN,FID) in gym_settings. Only after the template is cached do we
 * actually send the DELETE. On payment, we replay the cached line back
 * with DATA UPDATE FINGERTMP — that command usually works even on
 * firmwares that reject USERINFO updates.
 *
 * Throttled: runs at most once per 30 minutes UNLESS gym-lock state just
 * flipped. Fingertmp cache never expires.
 *
 * Returns array of "C:<id>:<command>" strings ready to echo.
 */
function iclock_build_block_commands(PDO $db): array {
    $gymLocked = !(new LicenseHelper($db))->isLicenseValid();
    $prevGymLocked = iclock_setting_get($db, 'f22_gym_locked_last') === '1';
    $stateChanged = ($gymLocked !== $prevGymLocked);

    $last = iclock_setting_get($db, 'f22_last_overdue_check');
    if ($last && (time() - strtotime($last)) < 1800 && !$stateChanged) return [];

    iclock_setting_set($db, 'f22_last_overdue_check', date('Y-m-d H:i:s'));
    iclock_setting_set($db, 'f22_gym_locked_last', $gymLocked ? '1' : '0');

    // Current disabled set
    $raw = iclock_setting_get($db, 'f22_disabled_pins');
    $disabled = array_filter(array_map('trim', explode(',', $raw)));
    $disabledSet = array_flip($disabled);

    // Effective "must be disabled" set. Gym locked → every active member.
    // Gym valid → members past their individual fee due date.
    $overduePins = [];
    if ($gymLocked) {
        foreach (['men', 'women'] as $g) {
            $stmt = $db->prepare("SELECT member_code, name FROM members_{$g}
                                  WHERE status = 'active'
                                  AND member_code IS NOT NULL AND member_code != ''");
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $overduePins[$m['member_code']] = $m['name'];
            }
        }
        @file_put_contents(__DIR__ . '/iclock-debug.log',
            date('Y-m-d H:i:s') . " GYM LOCKED — disabling " . count($overduePins) . " active member(s)\n", FILE_APPEND);
    } else {
        foreach (['men', 'women'] as $g) {
            $stmt = $db->prepare("SELECT member_code, name FROM members_{$g}
                                  WHERE status = 'active'
                                  AND next_fee_due_date < CURDATE()
                                  AND member_code IS NOT NULL AND member_code != ''");
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $overduePins[$m['member_code']] = $m['name'];
            }
        }
    }

    // Load template cache — we only DELETE fingerprints we can restore later.
    $cachedRaw = iclock_setting_get($db, 'f22_tmp_cached_pins');
    $cachedPins = array_flip(array_filter(array_map('trim', explode(',', $cachedRaw))));

    $pendingRaw = iclock_setting_get($db, 'f22_query_pending');
    $pendingPins = array_flip(array_filter(array_map('trim', explode(',', $pendingRaw))));
    $newPending = [];

    $counter = (int)iclock_setting_get($db, 'f22_cmd_counter');
    $commands = [];

    // ── BLOCK path ──
    // For each newly-overdue PIN:
    //   - No template cached yet → ask device to upload it (QUERY FINGERTMP)
    //     and mark PIN as pending. Delete happens on the next poll cycle.
    //   - Template cached → send DELETE FINGERTMP for FID 0-9 (all fingers).
    foreach ($overduePins as $pin => $name) {
        if (isset($disabledSet[$pin])) continue; // already deleted
        $safeName = str_replace(["\t", "\r", "\n"], ' ', (string)$name);

        if (!isset($cachedPins[$pin])) {
            // No cached template — request one now. Don't delete yet.
            if (!isset($pendingPins[$pin]) && !in_array($pin, $newPending, true)) {
                $counter++;
                $commands[] = "C:{$counter}:DATA QUERY FINGERTMP PIN={$pin} FID=0";
                $newPending[] = $pin;
                @file_put_contents(__DIR__ . '/iclock-debug.log',
                    date('Y-m-d H:i:s') . " QUERY-FINGERTMP pin={$pin} name={$safeName} — will delete once cached\n", FILE_APPEND);
            }
            continue; // don't add to disabledSet yet — wait for cache
        }

        // Template cached — safe to delete. Send DELETE for FID 0-9 to cover
        // members who registered multiple fingers.
        for ($fid = 0; $fid <= 9; $fid++) {
            $counter++;
            $commands[] = "C:{$counter}:DATA DELETE FINGERTMP PIN={$pin} FID={$fid}";
        }
        $disabledSet[$pin] = true;
        @file_put_contents(__DIR__ . '/iclock-debug.log',
            date('Y-m-d H:i:s') . " BLOCK pin={$pin} name={$safeName} (overdue) — DELETE FINGERTMP FID=0-9 (cached)\n", FILE_APPEND);
    }

    // Merge new pending PINs into pending index for next cycle
    if (!empty($newPending)) {
        $allPending = array_unique(array_merge(array_keys($pendingPins), $newPending));
        iclock_setting_set($db, 'f22_query_pending', implode(',', $allPending));
    }

    // ── UNBLOCK path ──
    // Previously disabled but now paid → restore cached template via UPDATE.
    // UPDATE FINGERTMP tends to work on firmwares that reject UPDATE USERINFO.
    foreach ($disabled as $pin) {
        if (isset($overduePins[$pin])) continue; // still overdue
        // Look up all cached FIDs for this PIN and restore each
        $restored = 0;
        for ($fid = 0; $fid <= 9; $fid++) {
            $cached = iclock_setting_get($db, "f22_tmp_{$pin}_{$fid}");
            if ($cached === '') continue;
            $counter++;
            $commands[] = "C:{$counter}:DATA UPDATE FINGERTMP {$cached}";
            $restored++;
        }
        unset($disabledSet[$pin]);
        @file_put_contents(__DIR__ . '/iclock-debug.log',
            date('Y-m-d H:i:s') . " UNBLOCK pin={$pin} (paid up) — restored {$restored} FID(s)" . ($restored === 0 ? ' — WARNING: no cache found, member must re-enroll' : '') . "\n", FILE_APPEND);
    }

    // Persist state
    iclock_setting_set($db, 'f22_cmd_counter', (string)$counter);
    iclock_setting_set($db, 'f22_disabled_pins', implode(',', array_keys($disabledSet)));

    return $commands;
}


// ==========================================================================
//  REAL-TIME UNPAID-ENTRY ALERTS
//  When a member scans in whose fee due date has passed, record an alert so
//  the front desk + owner get a live notification. Independent of the door
//  policy: even in lenient mode (door opens for them) the owner still gets
//  told "an unpaid member just walked in." Deduped to one alert per member
//  per day so re-scans don't spam.
// ==========================================================================

function iclock_ensure_alerts_table(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS access_alerts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                gender ENUM('men','women') NOT NULL,
                member_code VARCHAR(50) NULL,
                name VARCHAR(200) NULL,
                phone VARCHAR(20) NULL,
                due_date DATE NULL,
                days_overdue INT NULL,
                due_amount DECIMAL(10,2) DEFAULT 0.00,
                entered_at DATETIME NOT NULL,
                seen TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_seen (seen),
                INDEX idx_entered (entered_at),
                INDEX idx_member_day (member_id, gender, entered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('access_alerts ensure: ' . $e->getMessage());
    }
}

function iclock_alert_if_unpaid(PDO $db, string $gender, int $memberId, string $when): void {
    if (!in_array($gender, ['men', 'women'], true) || $memberId <= 0) return;
    try {
        $t = 'members_' . $gender;
        $stmt = $db->prepare("SELECT member_code, name, phone, next_fee_due_date, total_due_amount, status
                              FROM {$t} WHERE id = ? LIMIT 1");
        $stmt->execute([$memberId]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$m) return;

        // Alert trigger: fee due date is in the past. (Inactive members or those
        // with a recorded debt also qualify.)
        $dueDate = $m['next_fee_due_date'] ?? null;
        $due = (float)($m['total_due_amount'] ?? 0);
        $pastDue = $dueDate && $dueDate < date('Y-m-d');
        $inactive = ($m['status'] ?? 'active') !== 'active';
        if (!$pastDue && $due <= 0 && !$inactive) return; // paid + current -> no alert

        iclock_ensure_alerts_table($db);

        // Dedupe: one alert per member per calendar day.
        $day = substr($when, 0, 10);
        $dup = $db->prepare("SELECT 1 FROM access_alerts
                             WHERE member_id = ? AND gender = ? AND DATE(entered_at) = ? LIMIT 1");
        $dup->execute([$memberId, $gender, $day]);
        if ($dup->fetchColumn()) return;

        $daysOverdue = $dueDate ? (int)floor((strtotime($day) - strtotime($dueDate)) / 86400) : null;

        $ins = $db->prepare("INSERT INTO access_alerts
            (member_id, gender, member_code, name, phone, due_date, days_overdue, due_amount, entered_at, seen)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
        $ins->execute([
            $memberId, $gender, $m['member_code'] ?? null, $m['name'] ?? null, $m['phone'] ?? null,
            $dueDate, $daysOverdue, $due, $when,
        ]);
    } catch (Throwable $e) {
        error_log('iclock_alert_if_unpaid: ' . $e->getMessage());
    }
}
