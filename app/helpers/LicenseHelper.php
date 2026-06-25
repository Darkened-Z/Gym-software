<?php
/**
 * License Helper - System Activation Check
 * Prevents unauthorized distribution by requiring setup.php to be run
 */

class LicenseHelper {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Check if system is activated (setup.php has been run)
     */
    public function isSystemActivated() {
        try {
            $query = "SELECT COUNT(*) as count FROM system_license WHERE is_active = 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch();
            return intval($result['count'] ?? 0) > 0;
        } catch (Exception $e) {
            // If table doesn't exist, system is not activated
            return false;
        }
    }

    /**
     * Make sure the subscription column exists (self-heal on installs that
     * predate the monthly-billing feature). MySQL has no ADD COLUMN IF NOT
     * EXISTS, so check information_schema first.
     */
    public function ensureExpiryColumn() {
        try {
            $stmt = $this->conn->query("SHOW COLUMNS FROM system_license LIKE 'expires_at'");
            if ($stmt && $stmt->rowCount() === 0) {
                $this->conn->exec("ALTER TABLE system_license ADD COLUMN expires_at DATETIME NULL");
            }
        } catch (Exception $e) {
            error_log('LicenseHelper::ensureExpiryColumn: ' . $e->getMessage());
        }
    }

    /** Grace days after the expiry date before staff access is actually locked. */
    const GRACE_DAYS = 3;

    /**
     * Subscription status for the active license. Keys:
     *   activated   — setup.php has been run
     *   expires_at  — paid-through date (NULL = unlimited / active until cancelled)
     *   expired     — past the paid-through date (may still be inside grace)
     *   locked      — past expiry + GRACE_DAYS → STAFF access is enforced off
     *   in_grace    — expired but still within the grace window
     *   days_left   — whole days to the expiry date (negative once expired)
     *   grace_left  — days left in the grace window (only while in_grace)
     *   valid       — activated AND not locked
     * Fails OPEN (valid) on errors so a transient DB hiccup never locks a gym.
     */
    public function getStatus() {
        $this->ensureExpiryColumn();
        $base = ['activated' => false, 'expires_at' => null, 'expired' => false,
                 'locked' => false, 'in_grace' => false, 'days_left' => null,
                 'grace_left' => null, 'valid' => false];
        try {
            $stmt = $this->conn->query("SELECT is_active, expires_at FROM system_license WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
            $row = $stmt ? $stmt->fetch() : null;
            if (!$row) {
                return $base;
            }
            $exp = $row['expires_at'] ?? null;
            if ($exp === null || $exp === '') {
                // Unlimited — active until cancelled.
                return array_merge($base, ['activated' => true, 'valid' => true]);
            }
            $now = time();
            $expTs = strtotime($exp);
            $lockTs = $expTs + self::GRACE_DAYS * 86400;
            $expired = $now > $expTs;
            $locked = $now > $lockTs;
            return [
                'activated' => true,
                'expires_at' => $exp,
                'expired' => $expired,
                'locked' => $locked,
                'in_grace' => $expired && !$locked,
                'days_left' => (int) ceil(($expTs - $now) / 86400),
                'grace_left' => ($expired && !$locked) ? (int) ceil(($lockTs - $now) / 86400) : null,
                'valid' => !$locked,
            ];
        } catch (Exception $e) {
            error_log('LicenseHelper::getStatus: ' . $e->getMessage());
            // Fail open — do not lock a gym out on an unexpected error.
            return array_merge($base, ['activated' => true, 'valid' => true]);
        }
    }

    /** Activated AND not locked (past expiry + grace). */
    public function isLicenseValid() {
        $s = $this->getStatus();
        return $s['activated'] && $s['valid'];
    }

    /**
     * Get server fingerprint (unique identifier for this server)
     */
    public static function getServerFingerprint() {
        $components = [];
        
        // Server hostname
        if (function_exists('gethostname')) {
            $components[] = gethostname();
        }
        
        // Server IP
        $components[] = $_SERVER['SERVER_ADDR'] ?? $_SERVER['HTTP_HOST'] ?? 'unknown';
        
        // Document root
        $components[] = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;
        
        // PHP version
        $components[] = PHP_VERSION;
        
        // Create fingerprint hash
        $fingerprint = md5(implode('|', $components));
        
        return $fingerprint;
    }
    
    /**
     * Generate license key
     */
    public static function generateLicenseKey($serverFingerprint) {
        // Create a unique license key based on server fingerprint and timestamp
        $seed = $serverFingerprint . time() . uniqid();
        $licenseKey = hash('sha256', $seed);
        
        // Format as readable key (8-4-4-4-12 format)
        $formatted = substr($licenseKey, 0, 8) . '-' . 
                     substr($licenseKey, 8, 4) . '-' . 
                     substr($licenseKey, 12, 4) . '-' . 
                     substr($licenseKey, 16, 4) . '-' . 
                     substr($licenseKey, 20, 12);
        
        return strtoupper($formatted);
    }
    
    /**
     * Activate system with license key
     */
    public function activateSystem($licenseKey, $serverFingerprint) {
        try {
            // Check if already activated
            $checkQuery = "SELECT id FROM system_license WHERE is_active = 1 LIMIT 1";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() > 0) {
                // Update existing license
                $updateQuery = "UPDATE system_license SET 
                               license_key = :license_key, 
                               server_fingerprint = :fingerprint,
                               activated_at = NOW(),
                               is_active = 1 
                               WHERE is_active = 1";
                $updateStmt = $this->conn->prepare($updateQuery);
                $updateStmt->bindValue(':license_key', $licenseKey, PDO::PARAM_STR);
                $updateStmt->bindValue(':fingerprint', $serverFingerprint, PDO::PARAM_STR);
                return $updateStmt->execute();
            } else {
                // Insert new license
                $insertQuery = "INSERT INTO system_license (license_key, server_fingerprint, is_active) 
                               VALUES (:license_key, :fingerprint, 1)";
                $insertStmt = $this->conn->prepare($insertQuery);
                $insertStmt->bindValue(':license_key', $licenseKey, PDO::PARAM_STR);
                $insertStmt->bindValue(':fingerprint', $serverFingerprint, PDO::PARAM_STR);
                return $insertStmt->execute();
            }
        } catch (Exception $e) {
            error_log("License activation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verify license key matches server
     */
    public function verifyLicense($serverFingerprint) {
        try {
            $query = "SELECT license_key, server_fingerprint FROM system_license WHERE is_active = 1 LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $license = $stmt->fetch();
            
            if (!$license) {
                return false;
            }
            
            // Verify fingerprint matches (allow some flexibility for server changes)
            return $license['server_fingerprint'] === $serverFingerprint;
        } catch (Exception $e) {
            return false;
        }
    }
}

