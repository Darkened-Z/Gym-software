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
     * Whether front-desk staff access is open right now, per the gym-wide window
     * set in Settings. Times are evaluated in Pakistan time. Handles overnight
     * windows (e.g. 16:00–02:00). Returns enabled/open/start/end.
     */
    public static function staffHoursState(PDO $db): array {
        require_once __DIR__ . '/../models/Setting.php';
        $map = (new Setting($db))->getMap(['staff_hours_enabled', 'staff_hours_start', 'staff_hours_end']);
        $enabled = !empty($map['staff_hours_enabled']) && $map['staff_hours_enabled'] !== '0';
        $start = self::parseHm($map['staff_hours_start'] ?? '');
        $end = self::parseHm($map['staff_hours_end'] ?? '');
        if (!$enabled || $start === null || $end === null || $start === $end) {
            return ['enabled' => false, 'open' => true, 'start' => '', 'end' => ''];
        }
        $dt = new DateTime('now', new DateTimeZone('Asia/Karachi'));
        $now = (int)$dt->format('H') * 60 + (int)$dt->format('i');
        $open = ($start < $end) ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end);
        return ['enabled' => true, 'open' => $open, 'start' => self::fmtHm($start), 'end' => self::fmtHm($end)];
    }

    /** Hard-stop STAFF (only) APIs outside the configured hours. Admins exempt. */
    private static function enforceStaffHoursOrExit(): void {
        try {
            if (self::currentRole() !== 'staff') {
                return;
            }
            require_once __DIR__ . '/../../config/database.php';
            $db = (new Database())->getConnection();
            $st = self::staffHoursState($db);
            if ($st['enabled'] && !$st['open']) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Staff access is closed right now. Front-desk hours are ' . $st['start'] . ' to ' . $st['end'] . '.',
                    'error_code' => 'STAFF_HOURS_CLOSED'
                ]);
                exit;
            }
        } catch (Throwable $e) {
            // Fail open — never lock staff out over a settings/clock error.
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
                    'message' => 'This gym\'s subscription has expired. Front-desk access is locked — please contact your provider to renew.',
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
