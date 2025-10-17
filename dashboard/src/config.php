<?php
// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: 'mariadb');
define('DB_NAME', getenv('DB_NAME') ?: 'waf_db');
define('DB_USER', getenv('DB_USER') ?: 'waf_user');
define('DB_PASS', getenv('DB_PASSWORD') ?: 'changeme');

// API configuration
define('API_KEY', getenv('DASHBOARD_API_KEY') ?: 'change-this-default-token');

// Paths
define('NGINX_LOG_PATH', '/var/log/nginx');
define('MODSEC_LOG_PATH', '/var/log/modsec');
define('BANLIST_PATH', '/etc/fail2ban/state/banlist.conf');
define('FAIL2BAN_SOCKET', '/var/run/fail2ban/fail2ban.sock');

// Timezone
date_default_timezone_set('UTC');

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Database connection
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database connection failed']);
            exit;
        }
    }
    return $pdo;
}

// Authentication
function authenticate() {
    $headers = getallheaders();
    $token = $headers['Authorization'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    
    if (empty($token)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    // First check if token matches the API_KEY from environment
    if ($token === API_KEY) {
        return [
            'id' => 0,
            'name' => 'Master API Key',
            'token' => $token,
            'enabled' => 1
        ];
    }
    
    // Otherwise check database tokens
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM api_tokens WHERE token = ? AND enabled = 1");
    $stmt->execute([$token]);
    $apiToken = $stmt->fetch();
    
    if (!$apiToken) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }
    
    // Update last used
    $stmt = $db->prepare("UPDATE api_tokens SET last_used = NOW() WHERE id = ?");
    $stmt->execute([$apiToken['id']]);
    
    return $apiToken;
}

// Helper function to send JSON response
function sendResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
