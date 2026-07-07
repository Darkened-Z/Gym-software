<?php
/**
 * Import device attendance (ZKTeco F22 export) — admin only.
 * Upload an .xls/.xlsx/.csv exported from the ZKTeco software/device; each scan
 * is matched to a member and stored as attendance (read-only visualisation, no
 * device changes).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Composer dependencies not installed. Run: composer install']);
    exit;
}
require_once __DIR__ . '/controllers/AttendanceImportController.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

set_time_limit(600);
ini_set('memory_limit', '512M');

try {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }
    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xls', 'xlsx', 'csv'], true)) {
        throw new Exception('Please upload a .xls, .xlsx or .csv file');
    }
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        throw new Exception('File is too large');
    }

    $dir = UPLOAD_DIR . 'imports/';
    if (!file_exists($dir)) mkdir($dir, 0755, true);
    $tmp = $dir . uniqid('att_', true) . '_' . basename($file['name']);
    if (!move_uploaded_file($file['tmp_name'], $tmp)) {
        throw new Exception('Failed to save uploaded file');
    }

    $db = (new Database())->getConnection();
    $r = (new AttendanceImportController($db))->importFromFile($tmp);
    @unlink($tmp);

    $msg = "Imported {$r['imported']} scan(s). Duplicates skipped: {$r['duplicates']}. Unmatched: {$r['unmatched']}.";
    echo json_encode(['success' => true, 'message' => $msg, 'results' => $r]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
