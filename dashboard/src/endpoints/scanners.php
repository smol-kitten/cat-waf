<?php
/**
 * Scanner Detection API
 * 
 * Detects and tracks IPs that are scanning for vulnerabilities (hitting 404s)
 * 
 * Routes:
 * GET  /api/scanners                    - List detected scanner IPs
 * GET  /api/scanners/:id                - Get scanner details
 * GET  /api/scanners/endpoints          - List scanned endpoints
 * GET  /api/scanners/stats              - Get scanner detection stats
 * POST /api/scanners/:id/action         - Take action on scanner (block, whitelist, etc)
 * PUT  /api/scanners/settings           - Update scanner detection settings
 */

function handleScanners($method, $params, $db) {
    $action = $params[0] ?? '';
    
    switch ($method) {
        case 'GET':
            if ($action === 'endpoints') {
                getScannedEndpoints($db);
            } elseif ($action === 'stats') {
                getScannerStats($db);
            } elseif ($action === 'settings') {
                getScannerSettings($db);
            } elseif (is_numeric($action)) {
                getScannerDetails($db, (int)$action);
            } else {
                listScanners($db);
            }
            break;
            
        case 'POST':
            if (is_numeric($action) && ($params[1] ?? '') === 'action') {
                takeScannerAction($db, (int)$action);
            } else {
                sendResponse(['error' => 'Unknown action'], 400);
            }
            break;
            
        case 'PUT':
            if ($action === 'settings') {
                updateScannerSettings($db);
            } else {
                sendResponse(['error' => 'Unknown action'], 400);
            }
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * List all detected scanner IPs
 */
function listScanners($db) {
    try {
        $limit = min((int)($_GET['limit'] ?? 50), 200);
        $status = $_GET['status'] ?? null;
        $orderBy = $_GET['order_by'] ?? 'scan_count';
        $order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        $allowedOrderBy = ['scan_count', 'paths_scanned', 'last_seen', 'first_seen'];
        if (!in_array($orderBy, $allowedOrderBy)) {
            $orderBy = 'scan_count';
        }
        
        $sql = "SELECT * FROM scanner_ips";
        $params = [];
        
        if ($status) {
            $sql .= " WHERE status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY $orderBy $order LIMIT ?";
        $params[] = $limit;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $scanners = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add GeoIP data
        if (file_exists(__DIR__ . '/../lib/GeoIP.php')) {
            require_once __DIR__ . '/../lib/GeoIP.php';
            foreach ($scanners as &$scanner) {
                if (!empty($scanner['ip_address'])) {
                    $location = GeoIP::lookup($scanner['ip_address']);
                    if ($location) {
                        $scanner['country'] = $location['country'] ?? null;
                        $scanner['flag'] = GeoIP::getFlag($location['countryCode'] ?? '');
                    }
                }
            }
        }
        
        sendResponse([
            'scanners' => $scanners,
            'total' => count($scanners)
        ]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to list scanners: ' . $e->getMessage()], 500);
    }
}

/**
 * Get scanner details with request history
 */
function getScannerDetails($db, $id) {
    try {
        $stmt = $db->prepare("SELECT * FROM scanner_ips WHERE id = ?");
        $stmt->execute([$id]);
        $scanner = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$scanner) {
            sendResponse(['error' => 'Scanner not found'], 404);
        }
        
        // Get recent requests
        $stmt = $db->prepare("
            SELECT * FROM scanner_requests 
            WHERE scanner_ip_id = ? 
            ORDER BY timestamp DESC 
            LIMIT 100
        ");
        $stmt->execute([$id]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get path distribution
        $stmt = $db->prepare("
            SELECT path, COUNT(*) as count 
            FROM scanner_requests 
            WHERE scanner_ip_id = ? 
            GROUP BY path 
            ORDER BY count DESC 
            LIMIT 20
        ");
        $stmt->execute([$id]);
        $pathStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add GeoIP
        if (file_exists(__DIR__ . '/../lib/GeoIP.php')) {
            require_once __DIR__ . '/../lib/GeoIP.php';
            $location = GeoIP::lookup($scanner['ip_address']);
            if ($location) {
                $scanner['geoip'] = $location;
            }
        }
        
        sendResponse([
            'scanner' => $scanner,
            'requests' => $requests,
            'path_stats' => $pathStats
        ]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to get scanner details: ' . $e->getMessage()], 500);
    }
}

/**
 * Get list of scanned endpoints
 */
function getScannedEndpoints($db) {
    try {
        $limit = min((int)($_GET['limit'] ?? 50), 200);
        $category = $_GET['category'] ?? null;
        $orderBy = $_GET['order_by'] ?? 'hit_count';
        $order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        $sql = "SELECT * FROM scanned_endpoints";
        $params = [];
        
        if ($category) {
            $sql .= " WHERE category = ?";
            $params[] = $category;
        }
        
        $sql .= " ORDER BY $orderBy $order LIMIT ?";
        $params[] = $limit;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $endpoints = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse([
            'endpoints' => $endpoints,
            'total' => count($endpoints)
        ]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to list endpoints: ' . $e->getMessage()], 500);
    }
}

/**
 * Get scanner detection statistics
 */
function getScannerStats($db) {
    try {
        // Total scanners by status
        $stmt = $db->query("
            SELECT status, COUNT(*) as count 
            FROM scanner_ips 
            GROUP BY status
        ");
        $statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Total scanners detected today
        $stmt = $db->query("
            SELECT COUNT(*) as count 
            FROM scanner_ips 
            WHERE first_seen > DATE(NOW())
        ");
        $todayCount = $stmt->fetch()['count'] ?? 0;
        
        // Total scan attempts today
        $stmt = $db->query("
            SELECT SUM(scan_count) as total 
            FROM scanner_ips 
            WHERE last_seen > DATE(NOW())
        ");
        $scanAttemptsToday = $stmt->fetch()['total'] ?? 0;
        
        // Top scanned endpoints
        $stmt = $db->query("
            SELECT path, hit_count, category 
            FROM scanned_endpoints 
            WHERE hit_count > 0 
            ORDER BY hit_count DESC 
            LIMIT 10
        ");
        $topEndpoints = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Auto-blocked count
        $stmt = $db->query("SELECT COUNT(*) as count FROM scanner_ips WHERE auto_blocked = 1");
        $autoBlocked = $stmt->fetch()['count'] ?? 0;
        
        sendResponse([
            'stats' => [
                'by_status' => $statusCounts,
                'detected_today' => $todayCount,
                'scan_attempts_today' => $scanAttemptsToday,
                'auto_blocked' => $autoBlocked,
                'top_endpoints' => $topEndpoints
            ]
        ]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to get scanner stats: ' . $e->getMessage()], 500);
    }
}

/**
 * Take action on a scanner IP
 */
function takeScannerAction($db, $scannerId) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';
        
        if (!in_array($action, ['block', 'whitelist', 'challenge', 'rate_limit', 'reset'])) {
            sendResponse(['error' => 'Invalid action'], 400);
        }
        
        // Get scanner info
        $stmt = $db->prepare("SELECT * FROM scanner_ips WHERE id = ?");
        $stmt->execute([$scannerId]);
        $scanner = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$scanner) {
            sendResponse(['error' => 'Scanner not found'], 404);
        }
        
        $ip = $scanner['ip_address'];
        
        switch ($action) {
            case 'block':
                // Add to banned_ips
                $duration = $data['duration'] ?? 86400; // Default 24 hours
                $reason = "Scanner detected: {$scanner['scan_count']} scan attempts";
                
                $stmt = $db->prepare("
                    INSERT INTO banned_ips (ip_address, reason, banned_by, ban_duration, expires_at, created_at)
                    VALUES (?, ?, 'scanner-detection', ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())
                    ON DUPLICATE KEY UPDATE 
                        reason = VALUES(reason),
                        expires_at = VALUES(expires_at)
                ");
                $stmt->execute([$ip, $reason, $duration, $duration]);
                
                // Update scanner status
                $stmt = $db->prepare("UPDATE scanner_ips SET status = 'blocked', action_taken_at = NOW() WHERE id = ?");
                $stmt->execute([$scannerId]);
                
                // Regenerate banlist
                touch('/etc/nginx/sites-enabled/.reload_needed');
                break;
                
            case 'whitelist':
                $stmt = $db->prepare("UPDATE scanner_ips SET status = 'whitelisted', action_taken_at = NOW(), notes = ? WHERE id = ?");
                $stmt->execute([$data['notes'] ?? 'Manually whitelisted', $scannerId]);
                break;
                
            case 'challenge':
                $stmt = $db->prepare("UPDATE scanner_ips SET status = 'challenged', action_taken_at = NOW() WHERE id = ?");
                $stmt->execute([$scannerId]);
                break;
                
            case 'rate_limit':
                $stmt = $db->prepare("UPDATE scanner_ips SET status = 'rate_limited', action_taken_at = NOW() WHERE id = ?");
                $stmt->execute([$scannerId]);
                break;
                
            case 'reset':
                $stmt = $db->prepare("UPDATE scanner_ips SET status = 'monitoring', action_taken_at = NULL, notes = NULL WHERE id = ?");
                $stmt->execute([$scannerId]);
                break;
        }
        
        sendResponse([
            'success' => true,
            'message' => "Action '$action' applied to scanner",
            'ip' => $ip
        ]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to apply action: ' . $e->getMessage()], 500);
    }
}

/**
 * Get scanner detection settings
 */
function getScannerSettings($db) {
    try {
        $stmt = $db->query("
            SELECT setting_key, setting_value 
            FROM settings 
            WHERE setting_key LIKE 'scanner_%'
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $settings = [];
        foreach ($rows as $row) {
            $key = str_replace('scanner_', '', $row['setting_key']);
            $settings[$key] = $row['setting_value'];
        }
        
        // Add defaults
        $defaults = [
            'detection_enabled' => '1',
            '404_threshold' => '10',
            'timeframe' => '300',
            'success_ratio' => '0.1',
            'auto_block' => '0',
            'auto_block_duration' => '86400'
        ];
        
        foreach ($defaults as $key => $default) {
            if (!isset($settings[$key])) {
                $settings[$key] = $default;
            }
        }
        
        sendResponse(['settings' => $settings]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to get settings: ' . $e->getMessage()], 500);
    }
}

/**
 * Update scanner detection settings
 */
function updateScannerSettings($db) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $allowedKeys = [
            'scanner_detection_enabled',
            'scanner_404_threshold',
            'scanner_timeframe',
            'scanner_success_ratio',
            'scanner_auto_block',
            'scanner_auto_block_duration'
        ];
        
        $updated = 0;
        
        foreach ($data as $key => $value) {
            $fullKey = 'scanner_' . $key;
            if (!in_array($fullKey, $allowedKeys)) {
                continue;
            }
            
            $stmt = $db->prepare("
                INSERT INTO settings (setting_key, setting_value, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
            ");
            $stmt->execute([$fullKey, $value, $value]);
            $updated++;
        }
        
        sendResponse([
            'success' => true,
            'message' => "Updated $updated settings"
        ]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to update settings: ' . $e->getMessage()], 500);
    }
}
