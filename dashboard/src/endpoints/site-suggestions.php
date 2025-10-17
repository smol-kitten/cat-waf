<?php
/**
 * Site Suggestions Endpoint
 * Analyzes access logs to suggest new sites to configure
 * Filters out catch-all "_" site traffic
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

// Verify API token
$headers = getallheaders();
$token = $headers['X-API-Key'] ?? '';

if (!verifyToken($token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $db = getDbConnection();
    
    // Get suggested sites from access logs
    // Excludes the catch-all "_" domain and localhost
    // Groups by domain and shows request counts
    $query = "
        SELECT 
            domain,
            COUNT(*) as request_count,
            COUNT(DISTINCT ip_address) as unique_visitors,
            MIN(timestamp) as first_seen,
            MAX(timestamp) as last_seen,
            AVG(status_code) as avg_status_code,
            SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as error_count
        FROM access_logs
        WHERE 
            domain IS NOT NULL 
            AND domain != ''
            AND domain != '_'
            AND domain != 'localhost'
            AND domain NOT LIKE '%.local'
            AND domain NOT LIKE '%:%'  -- Exclude IP:port format
            AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAYS)
        GROUP BY domain
        HAVING request_count > 5  -- Minimum threshold
        ORDER BY request_count DESC
        LIMIT 50
    ";
    
    $stmt = $db->query($query);
    $suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check which domains are already configured
    $configuredQuery = "SELECT domain FROM sites WHERE enabled = 1";
    $configuredStmt = $db->query($configuredQuery);
    $configuredDomains = $configuredStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Mark already configured domains
    foreach ($suggestions as &$suggestion) {
        $suggestion['is_configured'] = in_array($suggestion['domain'], $configuredDomains);
        $suggestion['error_rate'] = $suggestion['request_count'] > 0 
            ? round(($suggestion['error_count'] / $suggestion['request_count']) * 100, 2) 
            : 0;
        
        // Add recommendation priority
        if (!$suggestion['is_configured']) {
            if ($suggestion['request_count'] > 100 && $suggestion['error_rate'] < 50) {
                $suggestion['priority'] = 'high';
            } elseif ($suggestion['request_count'] > 50) {
                $suggestion['priority'] = 'medium';
            } else {
                $suggestion['priority'] = 'low';
            }
        } else {
            $suggestion['priority'] = 'configured';
        }
    }
    
    // Get catch-all stats
    $catchAllQuery = "
        SELECT 
            COUNT(*) as total_requests,
            COUNT(DISTINCT ip_address) as unique_visitors,
            COUNT(DISTINCT domain) as unique_domains
        FROM access_logs
        WHERE 
            (domain = '_' OR domain IS NULL OR domain = '')
            AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAYS)
    ";
    
    $catchAllStmt = $db->query($catchAllQuery);
    $catchAllStats = $catchAllStmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'suggestions' => $suggestions,
        'catch_all_stats' => $catchAllStats,
        'total_suggestions' => count($suggestions),
        'unconfigured_count' => count(array_filter($suggestions, fn($s) => !$s['is_configured']))
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to fetch site suggestions',
        'message' => $e->getMessage()
    ]);
}

function verifyToken($token) {
    $validToken = getenv('DASHBOARD_API_KEY') ?: 'change-this-default-token';
    return $token === $validToken;
}

function getDbConnection() {
    $host = getenv('DB_HOST') ?: 'mariadb';
    $db = getenv('DB_NAME') ?: 'waf_db';
    $user = getenv('DB_USER') ?: 'waf_user';
    $pass = getenv('DB_PASSWORD') ?: 'changeme';
    
    return new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
