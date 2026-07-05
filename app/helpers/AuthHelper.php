<?php
/**
 * Shared auth/permission helper
 */

class AuthHelper {
    private static function ensureSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function currentRole(): ?string {
        self::ensureSession();
        return $_SESSION['role'] ?? null;
    }

    public static function isAuthenticated(): bool {
        return self::currentRole() !== null;
    }

    public static function requireRoles(array $roles): void {
        $currentRole = self::currentRole();
        if (!$currentRole || !in_array($currentRole, $roles, true)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        self::enforceSubscriptionOrExit();
        self::enforceStaffHoursOrExit();
    }

    private static function parseHm($v): ?int {
        $v = trim((string)$v);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m)) {
            return null;
        }
        $h = (int)$m[1];
        $min = (int)$m[2];
        if ($h > 23 || $min > 59) {
            return null;
        }
        return $h * 60 + $min;
    }

    private static function fmtHm(int $mins): string {
        return sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
    }

    /**
     * Whether a specific staff member's access is open right now, per THEIR own
     * allowed weekdays + hours (set by admin on the staff account). Times in
     * Pakistan time; handles overnight windows. Returns restricted/open/reason.
     */
    public static function staffAccessState(PDO $db, int $userId): array {
        $stmt = $db->prepare("SELECT access_enabled, access_days, access_start, access_end FROM users WHERE id = :id LIMIT 1");
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u || empty($u['access_enabled'])) {
            return ['restricted' => false, 'open' => true, 'reason' => ''];
        }
        $now = new DateTime('now', new DateTimeZone('Asia/Karachi'));
        // Day check (ISO: Mon=1..Sun=7). Empty list = every day.
        $days = array_values(array_filter(array_map('intval', explode(',', (string)($u['access_days'] ?? '')))));
        if ($days && !in_array((int)$now->format('N'), $days, true)) {
            return ['restricted' => true, 'open' => false, 'reason' => 'day'];
        }
        // Hour window. Missing/equal = no hour limit (days-only).
        $start = self::parseHm($u['access_start'] ?? '');
        $end = self::parseHm($u['access_end'] ?? '');
        if ($start === null || $end === null || $start === $end) {
            return ['restricted' => true, 'open' => true, 'reason' => ''];
        }
        $mins = (int)$now->format('H') * 60 + (int)$now->format('i');
        $open = ($start < $end) ? ($mins >= $start && $mins < $end) : ($mins >= $start || $mins < $end);
        return ['restricted' => true, 'open' => $open, 'reason' => $open ? '' : 'hours'];
    }

    /** Hard-stop a STAFF member outside THEIR own allowed days/hours. Admins exempt. */
    private static function enforceStaffHoursOrExit(): void {
        try {
            if (self::currentRole() !== 'staff') {
                return;
            }
            $uid = (int)($_SESSION['user_id'] ?? 0);
            if ($uid <= 0) {
                return;
            }
            require_once __DIR__ . '/../../config/database.php';
            $db = (new Database())->getConnection();
            $st = self::staffAccessState($db, $uid);
            if ($st['restricted'] && !$st['open']) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Your front-desk access is closed right now — you are outside your allowed days or hours. Please contact the admin.',
                    'error_code' => 'STAFF_HOURS_CLOSED'
                ]);
                exit;
            }
        } catch (Throwable $e) {
            // Fail open — never lock staff out over a clock/db error.
        }
    }

    /**
     * Hard-stop STAFF/ADMIN APIs when the gym is locked (past expiry + grace), so
     * an already-open front-desk session can't keep working. Partial lock —
     * members (and unauthenticated) are never gated here. Fails OPEN on errors.
     */
    private static function enforceSubscriptionOrExit(): void {
        try {
            $role = self::currentRole();
            if ($role !== 'admin' && $role !== 'staff') {
                return; // members keep working when the gym is locked
            }
            require_once __DIR__ . '/../../config/database.php';
            require_once __DIR__ . '/LicenseHelper.php';
            $db = (new Database())->getConnection();
            $status = (new LicenseHelper($db))->getStatus();
            if (!empty($status['activated']) && !empty($status['locked'])) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Your license has expired — please reactivate your license to continue.',
                    'error_code' => 'SUBSCRIPTION_EXPIRED'
                ]);
                exit;
            }
        } catch (Throwable $e) {
            // Fail open — never lock a paying gym out over a license-check error.
        }
    }

    public static function requireAdmin(): void {
        self::requireRoles(['admin']);
    }

    public static function requireAdminOrStaff(): void {
        self::requireRoles(['admin', 'staff']);
        // Central men/women section gate: a staff member assigned to one section
        // cannot touch the other side's data (members, attendance, payments…).
        self::requireGenderAccess($_GET['gender'] ?? $_POST['gender'] ?? null);
    }

    /** The gender section the current user may access: admin -> 'both'. */
    public static function allowedSection(): string {
        self::ensureSession();
        if (($_SESSION['role'] ?? null) === 'admin') {
            return 'both';
        }
        $s = strtolower((string)($_SESSION['staff_section'] ?? 'both'));
        return in_array($s, ['men', 'women', 'both'], true) ? $s : 'both';
    }

    /** Block a staff member from a gender section they aren't assigned to. */
    public static function requireGenderAccess(?string $gender): void {
        if ($gender === null) {
            return;
        }
        $gender = strtolower(trim($gender));
        if ($gender !== 'men' && $gender !== 'women') {
            return;
        }
        $allowed = self::allowedSection();
        if ($allowed === 'both' || $allowed === $gender) {
            return;
        }
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'You do not have access to this section.',
            'error_code' => 'SECTION_FORBIDDEN'
        ]);
        exit;
    }

    public static function ensureAdminAction(string $message = 'Only admin can perform this action'): void {
        if (self::currentRole() !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
    }
}
