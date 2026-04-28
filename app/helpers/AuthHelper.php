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

    public static function generateCSRFToken(): string {
        self::ensureSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        } elseif (empty($_SESSION['csrf_token_time'])) {
            $_SESSION['csrf_token_time'] = time();
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCSRF(): void {
        self::ensureSession();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (empty($token) || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token.']);
            exit;
        }
    }
}
