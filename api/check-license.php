<?php
/**
 * License Check API
 * Used by system to verify if setup.php has been run
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/LicenseHelper.php';

header('Content-Type: application/json');

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $licenseHelper = new LicenseHelper($db);
    $isActivated = $licenseHelper->isSystemActivated();
    
    if ($isActivated) {
        $serverFingerprint = LicenseHelper::getServerFingerprint();
        $verified = $licenseHelper->verifyLicense($serverFingerprint);
        
        echo json_encode([
            'activated' => true,
            'verified' => $verified
        ]);
    } else {
        http_response_code(403);
        echo json_encode([
            'activated' => false,
            'message' => 'System not activated. Please run setup.php first.',
            'setup_url' => 'setup.php'
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'activated' => false,
        'error' => $e->getMessage()
    ]);
}

