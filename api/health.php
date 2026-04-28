<?php
/**
 * System Health Check Endpoint
 * Provides system status for monitoring and ESP32 pre-flight checks
 */

header('Content-Type: application/json');

// Skip if health check is disabled
if (defined('HEALTH_CHECK_ENABLED') && !HEALTH_CHECK_ENABLED) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$health = [
    'status' => 'ok',
    'timestamp' => date('Y-m-d H:i:s'),
    'system' => [
        'version' => '2.0.0',
        'environment' => APP_ENV,
        'debug_mode' => DEBUG_MODE,
        'timezone' => date_default_timezone_get()
    ],
    'database' => [
        'connected' => false,
        'latency_ms' => 0
    ],
    'services' => [
        'cache_dir' => file_exists(__DIR__ . '/../cache') && is_writable(__DIR__ . '/../cache'),
        'logs_dir' => file_exists(__DIR__ . '/../logs') && is_writable(__DIR__ . '/../logs'),
        'uploads_dir' => file_exists(__DIR__ . '/../uploads') && is_writable(__DIR__ . '/../uploads')
    ],
    'configuration' => [
        'gate_entry_cooldown' => defined('GATE_ENTRY_COOLDOWN') ? GATE_ENTRY_COOLDOWN : null,
        'gate_exit_cooldown' => defined('GATE_EXIT_COOLDOWN') ? GATE_EXIT_COOLDOWN : null,
        'rate_limit_enabled' => defined('RATE_LIMIT_GATE_MAX'),
        'cache_enabled' => defined('CACHE_ENABLED') ? CACHE_ENABLED : false
    ]
];

// Check database connectivity
try {
    $start = microtime(true);
    $db = (new Database())->getConnection();
    $stmt = $db->query('SELECT 1 as test');
    $result = $stmt->fetch();
    $latency = (microtime(true) - $start) * 1000;
    
    if ($result && $result['test'] == 1) {
        $health['database']['connected'] = true;
        $health['database']['latency_ms'] = round($latency, 2);
        
        // Check critical tables
        $tables = ['members_men', 'members_women', 'attendance_men', 'attendance_women', 'gate_activity_log', 'gate_cooldown'];
        $health['database']['tables'] = [];
        
        foreach ($tables as $table) {
            try {
                $stmt = $db->query("SELECT COUNT(*) as count FROM {$table}");
                $count = $stmt->fetch()['count'];
                $health['database']['tables'][$table] = 'ok';
            } catch (Exception $e) {
                $health['database']['tables'][$table] = 'error';
                $health['status'] = 'degraded';
            }
        }
    }
} catch (Exception $e) {
    $health['status'] = 'error';
    $health['database']['error'] = APP_ENV === 'production' ? 'Database connection failed' : $e->getMessage();
}

// Check if any critical service is down
if ($health['status'] === 'error') {
    http_response_code(503);
} elseif ($health['status'] === 'degraded') {
    http_response_code(200); // Still operational but with warnings
} else {
    http_response_code(200);
}

echo json_encode($health, JSON_PRETTY_PRINT);
