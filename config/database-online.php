<?php
/**
 * Online Database Configuration
 * Always connects to the online database (used by sync.php on online server)
 */

class DatabaseOnline {
    // Online Server Configuration
    private $host = 'localhost';
    private $db_name = 'u124112239_gym';
    private $username = 'u124112239_gym';
    private $password = '@@AAbc123@@';
    
    private $conn;

    public function __construct() {
        // Always use online database credentials
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
            error_log("Connection error: " . $exception->getMessage());
            throw new Exception("Database connection failed: " . $exception->getMessage());
        }

        return $this->conn;
    }
}