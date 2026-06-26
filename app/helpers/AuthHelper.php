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
