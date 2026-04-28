<?php
/**
 * CSRF Token Helper
 * Provides CSRF token generation and validation for form security
 */

class CSRFToken {
    /**
     * Generate a new CSRF token
     * @return string The generated token
     */
    public static function generate() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();
        
        return $token;
    }
    
    /**
     * Get the current CSRF token, generate if not exists
     * @return string The CSRF token
     */
    public static function get() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Generate new token if doesn't exist or expired (1 hour)
        if (!isset($_SESSION['csrf_token']) || 
            !isset($_SESSION['csrf_token_time']) ||
            (time() - $_SESSION['csrf_token_time']) > 3600) {
            return self::generate();
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Validate CSRF token
     * @param string $token The token to validate
     * @return bool True if valid, false otherwise
     */
    public static function validate($token) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
            return false;
        }
        
        // Check if token expired (1 hour)
        if ((time() - $_SESSION['csrf_token_time']) > 3600) {
            return false;
        }
        
        // Use hash_equals to prevent timing attacks
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Generate HTML hidden input field with CSRF token
     * @return string HTML input field
     */
    public static function field() {
        $token = self::get();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
    
    /**
     * Get CSRF token for JavaScript/AJAX requests
     * @return array Array with token
     */
    public static function getForAjax() {
        return [
            'csrf_token' => self::get()
        ];
    }
}
