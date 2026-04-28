<?php
/**
 * Sync History API
 * Returns sync session history
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Check if sync_sessions table exists
    $tableCheck = $db->query("SHOW TABLES LIKE 'sync_sessions'");
    if ($tableCheck->rowCount() === 0) {
        echo json_encode([
            'success' => true,
            'data' => [],
            'message' => 'Sync tables not initialized. Please import sync_tables.sql'
        ]);
        exit;
    }
    
    // Get recent sync sessions
    $limit = $_GET['limit'] ?? 10;
    $query = "SELECT * FROM sync_sessions 
              ORDER BY started_at DESC 
              LIMIT :limit";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    $sessions = $stmt->fetchAll();
    
    // Format dates
    foreach ($sessions as &$session) {
        $session['started_at'] = $session['started_at'] ? date('Y-m-d H:i:s', strtotime($session['started_at'])) : null;
        $session['completed_at'] = $session['completed_at'] ? date('Y-m-d H:i:s', strtotime($session['completed_at'])) : null;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $sessions
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

