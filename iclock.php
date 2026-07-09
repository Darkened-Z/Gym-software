<?php
/**
 * ZKTeco ADMS "Push" receiver — the F22 talks to THIS endpoint directly.
 *
 * Handles the four /iclock/* routes the device calls:
 *   GET  /iclock/cdata     — handshake, we reply with the option/stamp block
 *   POST /iclock/cdata     — device uploads ATTLOG (attendance) / OPERLOG
 *   GET  /iclock/getrequest— device polls for queued commands (none yet;
 *                            control layer is parked)
 *   POST /iclock/devicecmd — device acknowledges command results
 *
 * Every scan lands as a "visit" (one row per member per day, first punch =
 * check-in, last punch = check-out) — identical semantics to the manual
 * import. Rows carry write_source='f22-live' so they never collide with any
 * other source.
 *
 * Security: the device's Serial Number (SN) must be in the allowlist
 * (gym_settings.f22_allowed_serials, comma-separated). Anything else is
 * ignored. No admin session — this is device→server.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

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
    // Empty allowlist = accept ANY SN (bootstrap mode — replace with the real
    // SN as soon as you see it in the log). Non-empty = strict match.
    if (trim($raw) === '') return true;
    foreach (preg_split('/[,\s]+/', $raw) as $tok) if (trim($tok) === $sn) return true;
    return false;
}

if (!iclock_allowed($db, $sn)) {
    error_log("[iclock] rejected SN='{$sn}' route={$route}");
    http_response_code(200); // reply 200 so the device doesn't retry forever
    echo "OK\n";
    exit;
}

// Log heartbeat: last-seen per SN into gym_settings.
$hb = $db->prepare("INSERT INTO gym_settings (setting_key, setting_value) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
$hb->execute(['f22_last_seen', date('Y-m-d H:i:s')]);
$hb->execute(['f22_last_sn', $sn]);

// --- Routes ---------------------------------------------------------------
if ($route === 'cdata') {
    if ($method === 'GET') {
        // Handshake — MUST start with "GET OPTION FROM: <SN>\n" or the device
        // refuses. Realtime=1 makes it push scans immediately.
        echo "GET OPTION FROM: {$sn}\n";
        echo "ATTLOGStamp=9999\n";
        echo "OPERLOGStamp=9999\n";
        echo "ATTPHOTOStamp=9999\n";
        echo "ErrorDelay=30\n";
        echo "Delay=15\n";
        echo "TransTimes=00:00;14:05\n";
        echo "TransInterval=1\n";
        echo "TransFlag=1111000000\n";
        echo "Realtime=1\n";
        echo "Encrypt=0\n";
        exit;
    }
    if ($method === 'POST') {
        $body = file_get_contents('php://input') ?: '';
        if ($table === 'ATTLOG') {
            iclock_process_attlog($db, $body);
        }
        // OPERLOG / other tables: acknowledged but not processed (user
        // enrollment sync belongs in the parked control layer).
        echo "OK\n";
        exit;
    }
}

if ($route === 'getrequest') {
    // No queued commands — control (auto-block unpaid) is parked.
    echo "OK\n";
    exit;
}

if ($route === 'devicecmd' || $route === 'ping' || $route === 'fdata' || $route === 'registry') {
    echo "OK\n";
    exit;
}

// Unknown /iclock/* — reply OK so the device doesn't panic.
echo "OK\n";


// --- ATTLOG processing ----------------------------------------------------
function iclock_process_attlog(PDO $db, string $body): void {
    // Each line = one punch: PIN \t YYYY-MM-DD HH:MM:SS \t Status \t VerifyMode \t WorkCode [...]
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
    // (1) member_code exact match
    foreach (['men', 'women'] as $g) {
        $stmt = $db->prepare("SELECT id FROM members_{$g} WHERE member_code = ? LIMIT 1");
        $stmt->execute([$pin]);
        $id = $stmt->fetchColumn();
        if ($id) return ['id' => (int)$id, 'gender' => $g];
    }
    // (2) F22 PIN scheme: men = 10_000_000 + id, women = 20_000_000 + id
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

    // Existing f22-live visit for today?
    $stmt = $db->prepare("SELECT id, check_in, check_out FROM {$t}
                          WHERE member_id = ? AND DATE(check_in) = ? AND write_source = 'f22-live' LIMIT 1");
    $stmt->execute([$memberId, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Honest first_entry flag: 0 if another source already logged today.
        $stmt = $db->prepare("SELECT 1 FROM {$t} WHERE member_id = ? AND DATE(check_in) = ? LIMIT 1");
        $stmt->execute([$memberId, $date]);
        $isFirst = $stmt->fetchColumn() ? 0 : 1;

        $ins = $db->prepare("INSERT INTO {$t}
            (member_id, check_in, check_out, duration_minutes, is_first_entry_today, entry_gate_id, write_source)
            VALUES (?, ?, NULL, NULL, ?, 'f22-live', 'f22-live')");
        $ins->execute([$memberId, $when, $isFirst]);
        return;
    }

    // Extend the same visit: first scan = check_in, last scan = check_out.
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
