<?php
require_once 'config.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = explode('/', trim($uri, '/'));

// Public endpoints (no auth required)
$publicEndpoints = ['health', 'info'];
if (!in_array($uri[0] ?? '', $publicEndpoints)) {
    $user = authenticate();
}

$db = getDB();

// Route handling
try {
    switch ($uri[0] ?? '') {
        case 'health':
            // Database health check
            try {
                $stmt = $db->query("SELECT 1");
                $dbStatus = 'connected';
            } catch (Exception $e) {
                $dbStatus = 'disconnected';
            }
            
            sendResponse([
                'status' => 'ok',
                'timestamp' => time(),
                'database' => $dbStatus,
                'version' => '1.0.0'
            ]);
            break;
            
        case 'info':
            // Get stats for version info
            try {
                $stmt = $db->query("SELECT COUNT(*) as count FROM sites");
                $sites = $stmt->fetch()['count'] ?? 0;
                
                $stmt = $db->query("SELECT COUNT(*) as count FROM modsec_events");
                $events = $stmt->fetch()['count'] ?? 0;
                
                $stmt = $db->query("SELECT COUNT(*) as count FROM banned_ips");
                $bans = $stmt->fetch()['count'] ?? 0;
            } catch (Exception $e) {
                $sites = $events = $bans = 0;
            }
            
            sendResponse([
                'name' => 'CatWAF Dashboard API',
                'version' => '1.0.0',
                'completion' => '89%',
                'tagline' => 'Purr-tecting your sites since 2025',
                'features' => [
                    'sites_management' => true,
                    'ssl_certificates' => true,
                    'modsecurity_waf' => true,
                    'bot_protection' => true,
                    'rate_limiting' => true,
                    'ban_management' => true,
                    'telemetry' => true,
                    'geoip_lookup' => true,
                    'access_logs' => true,
                    'security_events' => true,
                    'user_management' => false,
                ],
                'stats' => [
                    'sites' => $sites,
                    'security_events' => $events,
                    'banned_ips' => $bans
                ],
                'documentation' => 'https://github.com/smol-kitten/catwaf'
            ]);
            break;
            
        case 'sites':
            require_once 'endpoints/sites.php';
            handleSites($method, array_slice($uri, 1), $db);
            break;
            
        case 'bans':
            require_once 'endpoints/bans.php';
            handleBans($method, array_slice($uri, 1), $db);
            break;
            
        case 'logs':
            require_once 'endpoints/logs.php';
            handleLogs($method, array_slice($uri, 1), $db);
            break;
            
        case 'modsec':
            require_once 'endpoints/modsec.php';
            handleModSec($method, array_slice($uri, 1), $db);
            break;
            
        case 'security':
            require_once 'endpoints/modsec.php';
            handleModSec($method, array_slice($uri, 1), $db);
            break;
            
        case 'bots':
            require_once 'endpoints/bots.php';
            handleBots($method, array_slice($uri, 1), $db);
            break;
            
        case 'telemetry':
            require_once 'endpoints/telemetry.php';
            handleTelemetry($method, array_slice($uri, 1), $db);
            break;
            
        case 'stats':
            require_once 'endpoints/stats.php';
            handleStats($method, array_slice($uri, 1), $db);
            break;
            
        case 'settings':
            require_once 'endpoints/settings.php';
            handleSettings($method, array_slice($uri, 1), $db);
            break;
            
        case 'geoip':
            require_once 'endpoints/geoip.php';
            exit; // geoip.php handles its own routing
            
        case 'regenerate':
            require_once 'endpoints/regenerate.php';
            handleRegenerate($method, array_slice($uri, 1), $db);
            break;
            
        case 'certificates':
            // Check if it's upload endpoint
            if (isset($uri[1]) && $uri[1] === 'upload') {
                require_once 'endpoints/certificate-upload.php';
                handleCertificateUpload($method, array_slice($uri, 2), $db);
            } else {
                require_once 'endpoints/certificates.php';
            }
            break;
            
        case 'cache':
            require_once 'endpoints/cache.php';
            break;
            
        case 'challenge':
            require_once 'endpoints/challenge.php';
            handleChallenge($method, array_slice($uri, 1), $db);
            break;
            
        case 'custom-block-rules':
            require_once 'endpoints/custom-block-rules.php';
            handleCustomBlockRules($method, array_slice($uri, 1), $db);
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
    }
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'message' => $e->getMessage()]);
}
