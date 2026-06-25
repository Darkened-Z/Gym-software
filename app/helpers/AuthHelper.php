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
    }

    public static function ensureAdminAction(string $message = 'Only admin can perform this action'): void {
        if (self::currentRole() !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
    }
}
