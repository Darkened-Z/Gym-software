<?php
/**
 * Profile Image Upload API
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
    $gender = $_POST['gender'] ?? 'men';
    $memberCode = $_POST['member_code'] ?? '';

    // Require member code to name the file
    if (empty($memberCode)) {
        throw new Exception('Member code is required for profile image upload');
    }

    // Sanitize member code to safe filename (letters, numbers, dash, underscore)
    $safeMemberCode = preg_replace('/[^A-Za-z0-9_\-]/', '', $memberCode);
    if (empty($safeMemberCode)) {
        throw new Exception('Invalid member code provided');
    }

    // Validate file type - check both MIME type and extension
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $fileType = $file['type'];
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Validate MIME type
    if (!in_array($fileType, $allowedTypes)) {
        throw new Exception('Invalid file type. Please upload JPG, PNG, GIF, or WebP image');
    }
    
    // Validate extension
    if (!in_array($fileExtension, $allowedExtensions)) {
        throw new Exception('Invalid file extension');
    }
    
    // Additional security: verify it's actually an image
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        throw new Exception('File is not a valid image');
    }

    // Validate file size (5MB max)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('File size exceeds 5MB limit');
    }

    // Build deterministic filename using member code
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = $safeMemberCode . '.' . $extension;
    $uploadPath = PROFILE_IMAGES_DIR . $filename;

    // Create directory if it doesn't exist
    if (!file_exists(PROFILE_IMAGES_DIR)) {
        mkdir(PROFILE_IMAGES_DIR, 0755, true);
    }

    // Move uploaded file (overwrite existing for same member code)
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        throw new Exception('Failed to save uploaded file');
    }

    // Return relative path
    $relativePath = 'uploads/profiles/' . $filename;

    echo json_encode([
        'success' => true,
        'path' => $relativePath,
        'message' => 'Image uploaded successfully'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

