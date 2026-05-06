<?php
/**
 * Authentication API
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/User.php';
require_once __DIR__ . '/../app/models/Member.php';
require_once __DIR__ . '/../app/helpers/LicenseHelper.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';
require_once __DIR__ . '/../app/services/AttendanceWriteService.php';

header('Content-Type: application/json');

// Check system activation for admin operations
function checkSystemActivation($db) {
    $licenseHelper = new LicenseHelper($db);
    if (!$licenseHelper->isSystemActivated()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'System not activated. Please run setup.php to activate the system.',
            'error_code' => 'SYSTEM_NOT_ACTIVATED'
        ]);
        exit;
    }
}

// File-based rate limiting (not bypassable by clearing cookies/session)
function checkRateLimit() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = md5($ip);
    $rateFile = __DIR__ . '/../logs/rl_' . $key . '.json';

    $maxAttempts = defined('RATE_LIMIT_LOGIN_MAX') ? RATE_LIMIT_LOGIN_MAX : 5;
    $window = defined('RATE_LIMIT_LOGIN_WINDOW') ? RATE_LIMIT_LOGIN_WINDOW : 900;

    $handle = @fopen($rateFile, 'c+');
    if ($handle === false) {
        return;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $data = ['count' => 0, 'time' => time()];
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        // Reset counter after window expires
        if (time() - $data['time'] > $window) {
            $data = ['count' => 0, 'time' => time()];
        }

        // Block if too many attempts
        if ($data['count'] >= $maxAttempts) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => 'Too many login attempts. Please try again later.',
                'error_code' => 'RATE_LIMIT_EXCEEDED'
            ]);
            exit;
        }

        $data['count']++;
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($data));
        fflush($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// Sanitize input
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $database = new Database();
    $db = $database->getConnection();

    switch ($action) {
        case 'login':
            if ($method === 'POST') {
                // Check rate limiting
                checkRateLimit();
                
                $data = json_decode(file_get_contents('php://input'), true);
                
                // Sanitize inputs
                $username = sanitizeInput($data['username'] ?? '');
                $password = $data['password'] ?? ''; // Don't sanitize password
                $memberCode = sanitizeInput($data['member_code'] ?? '');

                if (!empty($username) && !empty($password)) {
                    // Check system activation before allowing admin login
                    checkSystemActivation($db);
                    
                    // Admin login
                    $user = new User($db);
                    $result = $user->authenticate($username, $password);
                    
                    if ($result) {
                        $_SESSION['user_id'] = $result['id'];
                        $_SESSION['username'] = $result['username'];
                        $_SESSION['role'] = $result['role'];
                        $_SESSION['name'] = $result['name'];
                        
                        echo json_encode([
                            'success' => true,
                            'role' => $result['role'],
                            'message' => 'Login successful'
                        ]);
                    } else {
                        http_response_code(401);
                        echo json_encode([
                            'success' => false,
                            'message' => 'Invalid credentials' // Don't reveal which field is wrong
                        ]);
                    }
                } elseif (!empty($memberCode)) {
                    // Member login
                    $memberMen = new Member($db, 'men');
                    $memberWomen = new Member($db, 'women');
                    
                    $member = $memberMen->getByCode($memberCode);
                    $gender = 'men';
                    
                    if (!$member) {
                        $member = $memberWomen->getByCode($memberCode);
                        $gender = 'women';
                    }
                    
                    if ($member) {
                        $_SESSION['member_id'] = $member['id'];
                        $_SESSION['member_code'] = $member['member_code'];
                        $_SESSION['member_gender'] = $gender;
                        $_SESSION['role'] = 'member';

                        // Automatically record attendance on login
                        $attendanceService = new AttendanceWriteService($db, $gender);
                        $attendanceResult = $attendanceService->recordCheckIn((int)$member['id'], [
                            'source' => 'member-login'
                        ]);

                        if (empty($attendanceResult['success'])) {
                            error_log('[AUTH] Member attendance write failed: ' . ($attendanceResult['message'] ?? 'Unknown error'));
                        }

                        $payload = [
                            'success' => true,
                            'role' => 'member',
                            'gender' => $gender,
                            'message' => 'Login successful'
                        ];
                        if (empty($attendanceResult['success'])) {
                            $payload['attendance_warning'] = $attendanceResult['message'] ?? 'Attendance write unavailable';
                        }

                        echo json_encode($payload);
                    } else {
                        http_response_code(401);
                        echo json_encode([
                            'success' => false,
                            'message' => 'Invalid member code'
                        ]);
                    }
                } else {
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Missing credentials'
                    ]);
                }
            }
            break;

        case 'logout':
            session_destroy();
            echo json_encode([
                'success' => true,
                'message' => 'Logged out successfully'
            ]);
            break;

        case 'change_password':
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); break; }
            AuthHelper::requireAdmin();

            $data = json_decode(file_get_contents('php://input'), true);
            $currentPassword = $data['current_password'] ?? '';
            $newPassword = $data['new_password'] ?? '';
            $confirmPassword = $data['confirm_password'] ?? '';

            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'All password fields are required.']);
                break;
            }
            if ($newPassword !== $confirmPassword) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'New passwords do not match.']);
                break;
            }
            if (strlen($newPassword) < 8) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters.']);
                break;
            }

            $user = new User($db);
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $verified = $user->verifyPassword($userId, $currentPassword);
            if (!$verified) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
                break;
            }

            $updated = $user->updatePassword($userId, $newPassword);
            if ($updated) {
                echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to update password.']);
            }
            break;

        case 'check':
            if (isset($_SESSION['role'])) {
                // For admin/staff dashboard access, verify system is activated
                if (in_array($_SESSION['role'], ['admin', 'staff'], true)) {
                    checkSystemActivation($db);
                }
                
                $response = [
                    'authenticated' => true,
                    'role' => $_SESSION['role']
                ];
                
                if (in_array($_SESSION['role'], ['admin', 'staff'], true)) {
                    $response['user_id'] = $_SESSION['user_id'] ?? null;
                    $response['username'] = $_SESSION['username'] ?? null;
                    $response['name'] = $_SESSION['name'] ?? null;
                } elseif ($_SESSION['role'] === 'member') {
                    $response['member_id'] = $_SESSION['member_id'];
                    $response['member_code'] = $_SESSION['member_code'];
                    $response['gender'] = $_SESSION['member_gender'];
                }
                
                echo json_encode($response);
            } else {
                echo json_encode([
                    'authenticated' => false
                ]);
            }
            break;

        default:
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action'
            ]);
    }
} catch (Exception $e) {
    error_log('[auth.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => DEBUG_MODE ? $e->getMessage() : 'An unexpected server error occurred.'
    ]);
}

