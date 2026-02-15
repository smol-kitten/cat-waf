<?php

/**
 * Security Check Center API endpoint
 * Centralized security monitoring and health checks
 */

function handleSecurityChecks($method, $params, $db) {
    error_log("handleSecurityChecks called: method=$method, params=" . json_encode($params));
    
    // Special endpoint for running checks
    if (isset($params[0]) && $params[0] === 'run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        runSecurityChecks($db);
        sendResponse(['success' => true, 'message' => 'Security checks initiated']);
    }
    
    // Special endpoint for running a specific check
    if (isset($params[0]) && $params[0] === 'run' && isset($params[1]) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $checkId = $params[1];
        runSingleSecurityCheck($db, $checkId);
        sendResponse(['success' => true, 'message' => 'Security check run']);
    }
    
    // Get history for a specific check
    if (isset($params[0]) && isset($params[1]) && $params[1] === 'history' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $checkId = $params[0];
        $stmt = $db->prepare("
            SELECT * FROM security_check_history 
            WHERE check_id = ? 
            ORDER BY checked_at DESC 
            LIMIT 50
        ");
        $stmt->execute([$checkId]);
        sendResponse(['history' => $stmt->fetchAll()]);
    }
    
    switch ($method) {
        case 'GET':
            if (!empty($params[0]) && is_numeric($params[0])) {
                // Get specific security check
                $stmt = $db->prepare("SELECT * FROM security_checks WHERE id = ?");
                $stmt->execute([$params[0]]);
                $check = $stmt->fetch();
                
                if ($check) {
                    sendResponse(['check' => $check]);
                } else {
                    sendResponse(['error' => 'Security check not found'], 404);
                }
            } else {
                // List all security checks with summary (deduplicated by check_type)
                $stmt = $db->query("
                    SELECT sc.* FROM security_checks sc
                    INNER JOIN (
                        SELECT check_type, MIN(id) AS min_id
                        FROM security_checks
                        GROUP BY check_type
                    ) dedup ON sc.id = dedup.min_id
                    ORDER BY 
                        CASE sc.status
                            WHEN 'critical' THEN 1
                            WHEN 'warning' THEN 2
                            WHEN 'healthy' THEN 3
                            ELSE 4
                        END,
                        sc.check_name ASC
                ");
                $checks = $stmt->fetchAll();
                
                // Calculate summary statistics
                $summary = [
                    'total' => count($checks),
                    'healthy' => 0,
                    'warning' => 0,
                    'critical' => 0,
                    'unknown' => 0
                ];
                
                foreach ($checks as $check) {
                    $status = $check['status'];
                    if (isset($summary[$status])) {
                        $summary[$status]++;
                    }
                }
                
                sendResponse([
                    'checks' => $checks,
                    'summary' => $summary
                ]);
            }
            break;
            
        case 'POST':
            // Create custom security check
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['check_type']) || empty($data['check_name'])) {
                sendResponse(['error' => 'Missing required fields: check_type, check_name'], 400);
            }
            
            $stmt = $db->prepare("
                INSERT INTO security_checks (
                    check_type, check_name, status, severity, message,
                    check_interval, enabled, details
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['check_type'],
                $data['check_name'],
                $data['status'] ?? 'unknown',
                $data['severity'] ?? 'info',
                $data['message'] ?? null,
                $data['check_interval'] ?? 3600,
                $data['enabled'] ?? 1,
                $data['details'] ?? null
            ]);
            
            $checkId = $db->lastInsertId();
            
            sendResponse(['success' => true, 'id' => $checkId, 'message' => 'Security check created'], 201);
            break;
            
        case 'PUT':
        case 'PATCH':
            // Update security check
            if (empty($params[0])) {
                sendResponse(['error' => 'Check ID required'], 400);
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data)) {
                sendResponse(['error' => 'No data provided'], 400);
            }
            
            $fields = [];
            $values = [];
            
            $updatableFields = [
                'check_name', 'status', 'severity', 'message', 'details',
                'check_interval', 'enabled', 'last_checked', 'next_check'
            ];
            
            foreach ($updatableFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = ?";
                    $values[] = $data[$field];
                }
            }
            
            if (empty($fields)) {
                sendResponse(['error' => 'No valid fields to update'], 400);
            }
            
            $values[] = $params[0];
            $stmt = $db->prepare("UPDATE security_checks SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($values);
            
            sendResponse(['success' => true, 'message' => 'Security check updated']);
            break;
            
        case 'DELETE':
            // Delete security check
            if (empty($params[0])) {
                sendResponse(['error' => 'Check ID required'], 400);
            }
            
            $stmt = $db->prepare("DELETE FROM security_checks WHERE id = ?");
            $stmt->execute([$params[0]]);
            
            sendResponse(['success' => true, 'message' => 'Security check deleted']);
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Run all security checks
 */
function runSecurityChecks($db) {
    $stmt = $db->query("SELECT * FROM security_checks WHERE enabled = 1");
    $checks = $stmt->fetchAll();
    
    foreach ($checks as $check) {
        runSingleSecurityCheck($db, $check['id']);
    }
}

/**
 * Run a single security check
 */
function runSingleSecurityCheck($db, $checkId) {
    $stmt = $db->prepare("SELECT * FROM security_checks WHERE id = ?");
    $stmt->execute([$checkId]);
    $check = $stmt->fetch();
    
    if (!$check) {
        return;
    }
    
    $status = 'unknown';
    $message = '';
    $details = [];
    
    try {
        switch ($check['check_type']) {
            case 'ssl_expiry':
                list($status, $message, $details) = checkSSLExpiry($db);
                break;
                
            case 'modsec_status':
                list($status, $message, $details) = checkModSecurityStatus();
                break;
                
            case 'fail2ban_status':
                list($status, $message, $details) = checkFail2banStatus();
                break;
                
            case 'disk_space':
                list($status, $message, $details) = checkDiskSpace();
                break;
                
            case 'nginx_status':
                list($status, $message, $details) = checkNginxStatus();
                break;
                
            case 'database_status':
                list($status, $message, $details) = checkDatabaseStatus($db);
                break;
                
            case 'security_rules':
                list($status, $message, $details) = checkSecurityRules($db);
                break;
                
            case 'blocked_attacks':
                list($status, $message, $details) = checkBlockedAttacks($db);
                break;
                
            default:
                $status = 'unknown';
                $message = 'Unknown check type';
        }
    } catch (Exception $e) {
        $status = 'critical';
        $message = 'Check failed: ' . $e->getMessage();
        error_log("Security check failed: " . $e->getMessage());
    }
    
    // Update the check
    $stmt = $db->prepare("
        UPDATE security_checks 
        SET status = ?, message = ?, details = ?, last_checked = NOW(), next_check = DATE_ADD(NOW(), INTERVAL check_interval SECOND)
        WHERE id = ?
    ");
    $stmt->execute([$status, $message, json_encode($details), $checkId]);
    
    // Add to history
    $stmt = $db->prepare("
        INSERT INTO security_check_history (check_id, status, message, details)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$checkId, $status, $message, json_encode($details)]);
}

/**
 * Individual check functions
 */

function checkSSLExpiry($db) {
    $stmt = $db->query("SELECT domain, ssl_enabled FROM sites WHERE ssl_enabled = 1");
    $sites = $stmt->fetchAll();
    
    $expiringSoon = [];
    $expired = [];
    
    foreach ($sites as $site) {
        $domain = $site['domain'];
        $certPath = "/etc/nginx/certs/{$domain}/fullchain.pem";
        
        if (file_exists($certPath)) {
            $certData = openssl_x509_parse(file_get_contents($certPath));
            $expiryTime = $certData['validTo_time_t'];
            $daysUntilExpiry = floor(($expiryTime - time()) / 86400);
            
            if ($daysUntilExpiry < 0) {
                $expired[] = $domain;
            } elseif ($daysUntilExpiry < 30) {
                $expiringSoon[] = ['domain' => $domain, 'days' => $daysUntilExpiry];
            }
        }
    }
    
    if (!empty($expired)) {
        return ['critical', count($expired) . ' certificate(s) expired', ['expired' => $expired, 'expiring_soon' => $expiringSoon]];
    } elseif (!empty($expiringSoon)) {
        return ['warning', count($expiringSoon) . ' certificate(s) expiring soon', ['expired' => $expired, 'expiring_soon' => $expiringSoon]];
    } else {
        return ['healthy', 'All certificates valid', ['total_checked' => count($sites)]];
    }
}

function checkModSecurityStatus() {
    // Check if ModSecurity is loaded in NGINX
    exec('nginx -V 2>&1 | grep -i modsecurity', $output, $returnVar);
    
    if ($returnVar === 0 && !empty($output)) {
        return ['healthy', 'ModSecurity module loaded', []];
    } else {
        return ['critical', 'ModSecurity module not found', []];
    }
}

function checkFail2banStatus() {
    // Try to detect the NGINX container name dynamically
    $nginxContainer = getenv('NGINX_CONTAINER_NAME') ?: 'cat-waf-nginx-1';
    
    exec("docker exec {$nginxContainer} sh -c \"command -v fail2ban-client\" 2>&1", $output, $returnVar);
    
    if ($returnVar === 0) {
        return ['healthy', 'Fail2ban available', []];
    } else {
        return ['warning', 'Fail2ban not available', []];
    }
}

function checkDiskSpace() {
    $diskFree = disk_free_space('/');
    $diskTotal = disk_total_space('/');
    $percentUsed = 100 - (($diskFree / $diskTotal) * 100);
    
    $details = [
        'free' => round($diskFree / 1024 / 1024 / 1024, 2) . ' GB',
        'total' => round($diskTotal / 1024 / 1024 / 1024, 2) . ' GB',
        'percent_used' => round($percentUsed, 2)
    ];
    
    if ($percentUsed > 90) {
        return ['critical', 'Disk space critical: ' . round($percentUsed, 2) . '% used', $details];
    } elseif ($percentUsed > 80) {
        return ['warning', 'Disk space warning: ' . round($percentUsed, 2) . '% used', $details];
    } else {
        return ['healthy', 'Disk space healthy: ' . round($percentUsed, 2) . '% used', $details];
    }
}

function checkNginxStatus() {
    exec('docker exec cat-waf-nginx-1 nginx -t 2>&1', $output, $returnVar);
    
    if ($returnVar === 0) {
        return ['healthy', 'NGINX configuration valid', []];
    } else {
        return ['critical', 'NGINX configuration invalid', ['output' => implode("\n", $output)]];
    }
}

function checkDatabaseStatus($db) {
    try {
        $stmt = $db->query("SELECT 1");
        $stmt->fetch();
        return ['healthy', 'Database connection active', []];
    } catch (Exception $e) {
        return ['critical', 'Database connection failed', ['error' => $e->getMessage()]];
    }
}

function checkSecurityRules($db) {
    $stmt = $db->query("SELECT COUNT(*) as count FROM custom_block_rules WHERE enabled = 1");
    $result = $stmt->fetch();
    $count = $result['count'];
    
    return ['healthy', $count . ' custom security rules active', ['count' => $count]];
}

function checkBlockedAttacks($db) {
    // Check for blocked attacks in the last hour
    $stmt = $db->query("
        SELECT COUNT(*) as count 
        FROM modsec_events 
        WHERE timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $result = $stmt->fetch();
    $count = $result['count'];
    
    $details = ['blocked_last_hour' => $count];
    
    if ($count > 1000) {
        return ['warning', 'High attack volume: ' . $count . ' attacks blocked in last hour', $details];
    } elseif ($count > 100) {
        return ['warning', 'Moderate attack volume: ' . $count . ' attacks blocked in last hour', $details];
    } else {
        return ['healthy', $count . ' attacks blocked in last hour', $details];
    }
}
