<?php
/**
 * Application Configuration
 * Production-Ready with Environment Variable Support
 */

// ============================================================================
// Load Environment Variables from .env file
// ============================================================================

/**
 * Load environment variables from .env file
 */
function loadEnv($path) {
    if (!file_exists($path)) {
        return;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            
            // Remove quotes if present
            $value = trim($value, '"\'');
            
            // Set environment variable
            if (!array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
                putenv("$name=$value");
            }
        }
    }
}

// Load .env from project root
loadEnv(__DIR__ . '/../.env');

// Helper function to get environment variable with default
function env($key, $default = null) {
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    
    // Handle boolean strings
    if (strtolower($value) === 'true') return true;
    if (strtolower($value) === 'false') return false;
    if (strtolower($value) === 'null') return null;
    
    return $value;
}

/**
 * Resolve environment values to booleans safely.
 */
function env_bool($key, $default = false) {
    $value = env($key, $default);

    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int)$value === 1;
    }

    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    return (bool)$value;
}

/**
 * Resolve member join/admission date column with legacy fallback support.
 */
function resolve_member_date_column(PDO $db, string $tableName): string {
    static $cache = [];

    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }

    $query = "SELECT COLUMN_NAME
              FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name
                AND COLUMN_NAME IN ('join_date', 'admission_date')";

    $stmt = $db->prepare($query);
    $stmt->bindValue(':table_name', $tableName, PDO::PARAM_STR);
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    if (in_array('join_date', $columns, true)) {
        return $cache[$tableName] = 'join_date';
    }

    if (in_array('admission_date', $columns, true)) {
        return $cache[$tableName] = 'admission_date';
    }

    return $cache[$tableName] = 'join_date';
}

// ============================================================================
// Application Configuration
// ============================================================================

define('APP_ENV', env('APP_ENV', 'development'));
define('DEBUG_MODE', env_bool('APP_DEBUG', false));

// ============================================================================
// Session Configuration
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    $cookieDomain = $_SERVER['HTTP_HOST'] ?? '';
    $cookieDomain = preg_replace('/:\\d+$/', '', $cookieDomain);
    if ($cookieDomain === 'localhost') {
        $cookieDomain = '';
    }

    // Set session cookie parameters from environment
    session_set_cookie_params([
        'lifetime' => (int)env('SESSION_LIFETIME', 3600),
        'path' => '/',
        'domain' => $cookieDomain,
        'secure' => env_bool('SESSION_SECURE_COOKIE', false),
        'httponly' => env_bool('SESSION_HTTPONLY', true),
        'samesite' => env('SESSION_SAMESITE', 'Strict')
    ]);
    session_start();
    
    // Session fixation protection - regenerate ID periodically
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
    
    // Session timeout validation
    if (isset($_SESSION['last_activity'])) {
        $timeout = (int)env('SESSION_LIFETIME', 3600);
        if (time() - $_SESSION['last_activity'] > $timeout) {
            session_unset();
            session_destroy();
            session_start();
        }
    }
    $_SESSION['last_activity'] = time();
}

// ============================================================================
// Error Reporting
// ============================================================================

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', 0);
}

// Error logging configuration
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../' . env('LOG_ERROR_FILE', 'logs/error.log'));

// Create logs directory if it doesn't exist
$logsDir = __DIR__ . '/../logs';
if (!file_exists($logsDir)) {
    mkdir($logsDir, 0755, true);
}

// ============================================================================
// Timezone
// ============================================================================

date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Karachi'));

// ============================================================================
// Gate Configuration
// ============================================================================

define('GATE_ENTRY_COOLDOWN', (int)env('GATE_ENTRY_COOLDOWN_SECONDS', 5));
define('GATE_EXIT_COOLDOWN', (int)env('GATE_EXIT_COOLDOWN_SECONDS', 5));
define('GATE_OPEN_DURATION', (int)env('GATE_OPEN_DURATION_MS', 3000));

// ============================================================================
// Rate Limiting Configuration
// ============================================================================

define('RATE_LIMIT_LOGIN_MAX', (int)env('RATE_LIMIT_LOGIN_MAX', 5));
define('RATE_LIMIT_LOGIN_WINDOW', (int)env('RATE_LIMIT_LOGIN_WINDOW', 900));
define('RATE_LIMIT_GATE_MAX', (int)env('RATE_LIMIT_GATE_MAX', 10));
define('RATE_LIMIT_GATE_WINDOW', (int)env('RATE_LIMIT_GATE_WINDOW', 60));

// ============================================================================
// Cache Configuration
// ============================================================================

define('CACHE_ENABLED', env_bool('CACHE_ENABLED', true));
define('CACHE_DEFAULT_TTL', (int)env('CACHE_DEFAULT_TTL', 300));

// ============================================================================
// Base URL
// ============================================================================

if (isset($_SERVER['HTTP_HOST'])) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || 
                 !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') 
                 ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    $basePath = str_replace('\\', '/', $scriptPath);
    $basePath = rtrim($basePath, '/');
    
    if ($basePath === '' || $basePath === '/' || $basePath === '\\') {
        define('BASE_URL', $protocol . $host . '/');
    } else {
        define('BASE_URL', $protocol . $host . $basePath . '/');
    }
} else {
    define('BASE_URL', '/');
}

// Upload directories
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('PROFILE_IMAGES_DIR', __DIR__ . '/../uploads/profiles/');

// Create upload directories if they don't exist
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}
if (!file_exists(PROFILE_IMAGES_DIR)) {
    mkdir(PROFILE_IMAGES_DIR, 0755, true);
}

// Maximum file upload size (10MB)
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);

// Allowed file types for profile images
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// Allowed file types for Excel import
define('ALLOWED_EXCEL_TYPES', [
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/csv'
]);

