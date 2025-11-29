<?php
/**
 * Security Rules Management Endpoint
 * Manage scanner detection, learning mode, WordPress blocking, etc.
 */

function handleSecurityRules($method, $params, $db) {
    $action = $params[0] ?? null;
    
    switch ($method) {
        case 'GET':
            if ($action === 'list') {
                // Get all security rules
                $siteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : null;
                
                $sql = "SELECT * FROM security_rules WHERE 1=1";
                $params = [];
                
                if ($siteId !== null) {
                    $sql .= " AND (site_id = ? OR site_id IS NULL)";
                    $params[] = $siteId;
                }
                
                $sql .= " ORDER BY site_id ASC, rule_type ASC";
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Decode JSON config
                foreach ($rules as &$rule) {
                    $rule['config'] = json_decode($rule['config'], true);
                }
                
                sendResponse($rules);
                
            } elseif ($action === 'scanner-stats') {
                // Get scanner detection statistics
                $stmt = $db->query("
                    SELECT 
                        scan_type,
                        COUNT(*) as detection_count,
                        COUNT(DISTINCT ip_address) as unique_ips,
                        SUM(CASE WHEN auto_blocked = 1 THEN 1 ELSE 0 END) as auto_blocked_count,
                        MAX(last_seen) as last_activity
                    FROM scanner_detections
                    WHERE last_seen > DATE_SUB(NOW(), INTERVAL 7 DAY)
                    GROUP BY scan_type
                    ORDER BY detection_count DESC
                ");
                
                $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
                sendResponse($stats);
                
            } else {
                sendResponse(['error' => 'Invalid action'], 400);
            }
            break;
            
        case 'POST':
            if ($action === 'update') {
                // Update a security rule
                $data = json_decode(file_get_contents('php://input'), true);
                
                $id = $data['id'] ?? null;
                $enabled = isset($data['enabled']) ? (int)$data['enabled'] : null;
                $config = $data['config'] ?? null;
                
                if (!$id) {
                    sendResponse(['error' => 'Rule ID required'], 400);
                    return;
                }
                
                $updates = [];
                $params = [];
                
                if ($enabled !== null) {
                    $updates[] = "enabled = ?";
                    $params[] = $enabled;
                }
                
                if ($config !== null) {
                    $updates[] = "config = ?";
                    $params[] = json_encode($config);
                }
                
                if (empty($updates)) {
                    sendResponse(['error' => 'No updates provided'], 400);
                    return;
                }
                
                $params[] = $id;
                
                $sql = "UPDATE security_rules SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                
                sendResponse(['success' => true, 'message' => 'Security rule updated']);
                
            } elseif ($action === 'create') {
                // Create new security rule
                $data = json_decode(file_get_contents('php://input'), true);
                
                $siteId = $data['site_id'] ?? null;
                $ruleType = $data['rule_type'] ?? null;
                $enabled = isset($data['enabled']) ? (int)$data['enabled'] : 1;
                $config = $data['config'] ?? [];
                
                if (!$ruleType) {
                    sendResponse(['error' => 'Rule type required'], 400);
                    return;
                }
                
                $stmt = $db->prepare("
                    INSERT INTO security_rules (site_id, rule_type, enabled, config)
                    VALUES (?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $siteId,
                    $ruleType,
                    $enabled,
                    json_encode($config)
                ]);
                
                sendResponse([
                    'success' => true,
                    'id' => $db->lastInsertId(),
                    'message' => 'Security rule created'
                ]);
                
            } elseif ($action === 'block-scanner') {
                // Manually block a detected scanner IP
                $data = json_decode(file_get_contents('php://input'), true);
                $ip = $data['ip_address'] ?? null;
                $duration = (int)($data['duration'] ?? 3600);
                $reason = $data['reason'] ?? 'Manual scanner block';
                
                if (!$ip) {
                    sendResponse(['error' => 'IP address required'], 400);
                    return;
                }
                
                // Add to banned IPs
                $stmt = $db->prepare("
                    INSERT INTO banned_ips (ip_address, reason, duration, banned_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        reason = VALUES(reason),
                        duration = VALUES(duration),
                        banned_at = NOW()
                ");
                $stmt->execute([$ip, $reason, $duration]);
                
                // Update scanner detection
                $stmt = $db->prepare("
                    UPDATE scanner_detections 
                    SET auto_blocked = 1, block_reason = ?
                    WHERE ip_address = ?
                ");
                $stmt->execute([$reason, $ip]);
                
                // Regenerate nginx banlist
                exec("docker exec waf-dashboard php /var/www/html/regen.php");
                
                sendResponse(['success' => true, 'message' => 'Scanner IP blocked']);
                
            } else {
                sendResponse(['error' => 'Invalid action'], 400);
            }
            break;
            
        case 'DELETE':
            if ($action === 'clear-scanners') {
                // Clear old scanner detections
                $days = (int)($_GET['days'] ?? 7);
                
                $stmt = $db->prepare("
                    DELETE FROM scanner_detections 
                    WHERE last_seen < DATE_SUB(NOW(), INTERVAL ? DAY)
                ");
                $stmt->execute([$days]);
                
                $deleted = $stmt->rowCount();
                sendResponse(['success' => true, 'deleted' => $deleted]);
                
            } else {
                sendResponse(['error' => 'Invalid action'], 400);
            }
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}
