<?php
/**
 * Authentication Check Script
 * This can be included in pages that need authentication checks
 */

require_once __DIR__ . '/config/config.php';

function checkAdminAuth() {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        header('Location: index.html');
        exit;
    }
}

function checkMemberAuth($requiredGender = null) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'member') {
        // Allow lookup even if not authenticated
        return false;
    }
    
    if ($requiredGender && isset($_SESSION['member_gender']) && $_SESSION['member_gender'] !== $requiredGender) {
        // Wrong gender portal, but allow lookup
        return false;
    }
    
    return true;
}

function redirectIfAuthenticated() {
    if (isset($_SESSION['role'])) {
        if ($_SESSION['role'] === 'admin') {
            header('Location: admin-dashboard.html');
            exit;
        } elseif ($_SESSION['role'] === 'member') {
            if ($_SESSION['member_gender'] === 'men') {
                header('Location: member-profile-men.html');
            } else {
                header('Location: member-profile-women.html');
            }
            exit;
        }
    }
}

