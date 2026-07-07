<?php
/**
 * Branding logo upload (admin only). Saves a per-gym logo under
 * uploads/branding/ and returns its relative path. The path is stored in
 * gym_settings.logo_url by the Details form, and branding.js applies it across
 * the login page, dashboard, splash screen and tab icon.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

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

try {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No image uploaded or upload error');
    }

    $file = $_FILES['image'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($file['type'], $allowedTypes, true) || !in_array($ext, $allowedExt, true)) {
        throw new Exception('Please upload a PNG, JPG, WebP or GIF image');
    }
    // Confirm it is really a raster image (blocks scripts disguised as images).
    if (@getimagesize($file['tmp_name']) === false) {
        throw new Exception('File is not a valid image');
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        throw new Exception('Logo must be under 3MB');
    }

    // uploads/branding/ sits next to uploads/profiles/ (PROFILE_IMAGES_DIR).
    $dir = rtrim(dirname(PROFILE_IMAGES_DIR), '/\\') . '/branding/';
    if (!file_exists($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new Exception('Could not create the branding folder');
    }

    // Unique name so a re-upload is never served stale from cache.
    $name = 'logo-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
        throw new Exception('Failed to save uploaded file');
    }

    echo json_encode([
        'success' => true,
        'path' => 'uploads/branding/' . $name,
        'message' => 'Logo uploaded'
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
