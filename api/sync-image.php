<?php
/**
 * Image Sync Endpoint (for online server)
 * Receives profile images from local server during sync
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database-online.php';

header('Content-Type: application/json');

// Sync API key authentication
$apiKey = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
$expectedApiKey = (string)env('SYNC_API_KEY', '');

if ($expectedApiKey === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'SYNC_API_KEY is not configured on the server']);
    exit;
}

if (!hash_equals($expectedApiKey, (string)$apiKey)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Invalid API key']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No image uploaded or upload error');
    }

    $file = $_FILES['image'];
    $imagePath = $_POST['image_path'] ?? null;

    if (!$imagePath) {
        throw new Exception('Image path not provided');
    }

    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $fileType = $file['type'];
    
    if (!in_array($fileType, $allowedTypes)) {
        throw new Exception('Invalid file type');
    }

    // Validate file size (5MB max)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('File size exceeds 5MB limit');
    }

    // Extract filename from path (e.g., "uploads/profiles/image.jpg" -> "image.jpg")
    $filename = basename($imagePath);
    $uploadPath = PROFILE_IMAGES_DIR . $filename;

    // Create directory if it doesn't exist
    if (!file_exists(PROFILE_IMAGES_DIR)) {
        mkdir(PROFILE_IMAGES_DIR, 0755, true);
    }

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        throw new Exception('Failed to save uploaded file');
    }

    echo json_encode([
        'success' => true,
        'path' => $imagePath,
        'message' => 'Image synced successfully'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

