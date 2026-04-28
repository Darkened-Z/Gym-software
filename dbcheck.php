<?php
// Temporary diagnostic — DELETE AFTER USE
require_once __DIR__ . '/config/config.php';

echo "<pre>";
echo "DB_HOST: " . env('DB_HOST', 'NOT SET') . "\n";
echo "DB_NAME: " . env('DB_NAME', 'NOT SET') . "\n";
echo "DB_USERNAME: " . env('DB_USERNAME', 'NOT SET') . "\n";
echo "DB_PASSWORD: " . (env('DB_PASSWORD', '') ? '(set)' : '(empty)') . "\n\n";

try {
    $pdo = new PDO(
        "mysql:host=" . env('DB_HOST', 'localhost') . ";charset=utf8mb4",
        env('DB_USERNAME', 'root'),
        env('DB_PASSWORD', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "✅ MySQL server connection OK\n";

    // Check if DB exists
    $stmt = $pdo->query("SHOW DATABASES LIKE '" . env('DB_NAME', '') . "'");
    if ($stmt->rowCount() > 0) {
        echo "✅ Database '" . env('DB_NAME', '') . "' exists\n";
        $pdo->exec("USE `" . env('DB_NAME', '') . "`");
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "Tables: " . (count($tables) ? implode(', ', $tables) : 'NONE') . "\n";
    } else {
        echo "❌ Database '" . env('DB_NAME', '') . "' does NOT exist\n";
        $all = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        echo "Available databases: " . implode(', ', $all) . "\n";
    }
} catch (PDOException $e) {
    echo "❌ Connection failed: " . $e->getMessage() . "\n";
}
echo "</pre>";
