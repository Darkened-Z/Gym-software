<?php
/**
 * Unified Database Configuration
 * Uses .env configuration with fallbacks
 */

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $conn;

    public function __construct() {
        // Load configuration from .env
        $this->host = env('DB_HOST', 'localhost');
        $this->db_name = env('DB_NAME', 'gym_management');
        $this->username = env('DB_USERNAME', 'root');
        $this->password = env('DB_PASSWORD', '');
    }

    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch(PDOException $exception) {
            // Try fallback to u124112239_gym if gym_management doesn't exist (legacy support)
            if ($this->db_name === 'gym_management' && strpos($exception->getMessage(), 'Unknown database') !== false) {
                try {
                    $this->conn = new PDO(
                        "mysql:host=" . $this->host . ";dbname=u124112239_gym;charset=utf8mb4",
                        $this->username,
                        $this->password,
                        [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES => false
                        ]
                    );
                    return $this->conn;
                } catch(PDOException $e) {
                    // Fallback failed
                }
            }
            
            error_log("Connection error: " . $exception->getMessage());
            die("Database connection error. Please check your configuration.");
        }

        return $this->conn;
    }

    public function isLocal() {
        $host = strtolower((string)$this->host);
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
