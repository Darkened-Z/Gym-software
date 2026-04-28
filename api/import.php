<?php
/**
 * Import API Endpoint
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Load Composer autoloader for PHPSpreadsheet
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Composer dependencies not installed. Please run: composer install']);
    exit;
}

require_once __DIR__ . '/controllers/ImportController.php';

header('Content-Type: application/json');

// Check authentication
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

// Increase execution time and memory limit for large imports
set_time_limit(600); // 10 minutes
ini_set('memory_limit', '512M');

try {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }

    $file = $_FILES['file'];
    $gender = $_POST['gender'] ?? 'men';

    if (!in_array($gender, ['men', 'women'])) {
        throw new Exception('Invalid gender specified');
    }

    // Validate file type
    $fileType = $file['type'];
    $allowedTypes = [
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
        'application/csv'
    ];

    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['xls', 'xlsx', 'csv'];

    if (!in_array($fileType, $allowedTypes) && !in_array($fileExtension, $allowedExtensions)) {
        throw new Exception('Invalid file type. Please upload .xls, .xlsx, or .csv file');
    }

    // Validate file size
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        throw new Exception('File size exceeds maximum allowed size');
    }

    // Move uploaded file to temporary location
    $uploadDir = UPLOAD_DIR . 'imports/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $tempFile = $uploadDir . uniqid('import_', true) . '_' . basename($file['name']);
    
    if (!move_uploaded_file($file['tmp_name'], $tempFile)) {
        throw new Exception('Failed to save uploaded file');
    }

    // Process import
    $database = new Database();
    $db = $database->getConnection();
    
    $importController = new ImportController($db, $gender);
    $results = $importController->importFromFile($tempFile, $gender);

    // Clean up temporary file
    @unlink($tempFile);

    echo json_encode([
        'success' => true,
        'message' => "Import completed. Success: {$results['success']}, Failed: {$results['failed']}",
        'results' => $results
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

