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
 * Build BLOCK/UNBLOCK commands for overdue/paid members.
 * Throttled: runs the scan at most once per 30 minutes UNLESS the gym-lock
 * state just flipped, in which case we run immediately so the device gets
 * the block/unblock batch on its next poll.
 *
 * Gym-subscription lock (from the operator panel):
 *   locked  → every active member PIN is treated as "overdue" → the whole
 *             gym is disabled at the device (door refuses everyone).
 *   valid   → per-member overdue logic (individual fee due dates), same as
 *             before. Any PINs disabled during the lock get re-enabled
 *             automatically because they'll no longer be in the effective
 *             overdue set (unless they were also individually overdue).
 *
 * Blocking strategy: set the user's ADMS `EndDatetime` in the past (block)
 * or the far future (unblock). Fingerprints stay enrolled either way — this
 * is fully reversible.
 *
 * History: earlier iterations sent `Enable=0`, which the F22 firmware
 * silently rejected with Return=-629 (unknown field). Cross-check of
 * attendance vs. f22_disabled_pins on 2026-08-27 confirmed disabled
 * members were still checking in. `EndDatetime` is a standard ADMS
 * USERINFO field that ZKTeco designed for exactly this use case.
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

    // Names for unblock commands — DATA UPDATE USERINFO needs the Name field.
    $nameByPin = [];
    foreach (['men', 'women'] as $g) {
        $stmt = $db->prepare("SELECT member_code, name FROM members_{$g}
                              WHERE member_code IN (" . (empty($disabled) ? "''" : implode(',', array_fill(0, count($disabled), '?'))) . ")");
        if (!empty($disabled)) {
            $stmt->execute($disabled);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $nameByPin[$r['member_code']] = $r['name'];
            }
        }
    }

    $counter = (int)iclock_setting_get($db, 'f22_cmd_counter');
    $commands = [];

    // Past validity date (any pre-epoch-ish date the F22 will accept as expired).
    $blockDate   = '2000-01-01 00:00:00';
    $unblockDate = '2099-12-31 23:59:59';

    // New overdue → BLOCK (set EndDatetime in the past)
    foreach ($overduePins as $pin => $name) {
        if (isset($disabledSet[$pin])) continue; // already disabled
        $counter++;
        $safeName = str_replace(["\t", "\r", "\n"], ' ', (string)$name);
        $commands[] = "C:{$counter}:DATA UPDATE USERINFO PIN={$pin}\tName={$safeName}\tEndDatetime={$blockDate}";
        $disabledSet[$pin] = true;
        @file_put_contents(__DIR__ . '/iclock-debug.log',
            date('Y-m-d H:i:s') . " BLOCK pin={$pin} name={$safeName} (overdue) — EndDatetime={$blockDate}\n", FILE_APPEND);
    }

    // Previously disabled but now paid → UNBLOCK (set EndDatetime in the future)
    foreach ($disabled as $pin) {
        if (isset($overduePins[$pin])) continue; // still overdue
        $counter++;
        $name = $nameByPin[$pin] ?? ('PIN-' . $pin);
        $safeName = str_replace(["\t", "\r", "\n"], ' ', $name);
        $commands[] = "C:{$counter}:DATA UPDATE USERINFO PIN={$pin}\tName={$safeName}\tEndDatetime={$unblockDate}";
        unset($disabledSet[$pin]);
        @file_put_contents(__DIR__ . '/iclock-debug.log',
            date('Y-m-d H:i:s') . " UNBLOCK pin={$pin} name={$safeName} (paid up) — EndDatetime={$unblockDate}\n", FILE_APPEND);
    }

    // Persist state
    iclock_setting_set($db, 'f22_cmd_counter', (string)$counter);
    iclock_setting_set($db, 'f22_disabled_pins', implode(',', array_keys($disabledSet)));

    return $commands;
}
