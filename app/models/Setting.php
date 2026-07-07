<?php
/**
 * Setting Model — simple key/value gym settings (contact + social links).
 *
 * Self-creates its table (like Package) so existing installs need no manual
 * migration. Only a whitelist of public-safe keys is ever exposed without
 * auth (used to populate the public login footer).
 */

class Setting {
    private $conn;
    private $table = 'gym_settings';

    /** Keys that are safe to expose publicly (contact + socials). */
    public static $publicKeys = [
        'gym_name',
        'location',
        'logo_url',
        'theme_accent',
        'font_family',
        'phone',
        'email',
        'address_url',
        'social_whatsapp',
        'social_youtube',
        'social_facebook',
        'social_instagram',
        'social_snapchat',
        'social_tiktok',
    ];

    /** Admin-only settings (editable in the dashboard, never exposed publicly). */
    public static $adminKeys = [];

    /** All keys the admin Details screen may read and write. */
    public static function editableKeys(): array {
        return array_merge(self::$publicKeys, self::$adminKeys);
    }

    public function __construct($db) {
        $this->conn = $db;
        $this->ensureSchema();
    }

    private function ensureSchema(): void {
        try {
            $this->conn->exec(
                "CREATE TABLE IF NOT EXISTS {$this->table} (
                    setting_key VARCHAR(64) PRIMARY KEY,
                    setting_value TEXT NULL,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Exception $e) {
            error_log('Setting::ensureSchema: ' . $e->getMessage());
        }
    }

    /** Return a key=>value map for the given keys (or all if null). */
    public function getMap(array $keys = null): array {
        $sql = "SELECT setting_key, setting_value FROM {$this->table}";
        $params = [];
        if ($keys !== null) {
            if (count($keys) === 0) return [];
            $in = implode(',', array_fill(0, count($keys), '?'));
            $sql .= " WHERE setting_key IN ($in)";
            $params = array_values($keys);
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }
        return $out;
    }

    /** Public keys only, with every key present (defaulting to ''). */
    public function getPublicMap(): array {
        $map = $this->getMap(self::$publicKeys);
        $out = [];
        foreach (self::$publicKeys as $k) {
            $out[$k] = $map[$k] ?? '';
        }
        return $out;
    }

    /** Upsert a key=>value map. Values are length-capped; empty => NULL. */
    public function setMany(array $assoc): bool {
        $sql = "INSERT INTO {$this->table} (setting_key, setting_value) VALUES (:k, :v)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
        $stmt = $this->conn->prepare($sql);
        foreach ($assoc as $k => $v) {
            $key = substr((string)$k, 0, 64);
            $val = ($v === null || $v === '') ? null : substr((string)$v, 0, 2000);
            $stmt->bindValue(':k', $key, PDO::PARAM_STR);
            $stmt->bindValue(':v', $val, $val === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->execute();
        }
        return true;
    }
}
