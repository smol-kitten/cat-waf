<?php

function handleBans($method, $params, $db) {
    switch ($method) {
        case 'GET':
            $action = $params[0] ?? null;
            
            if ($action === 'auto') {
                // Get auto-ban statistics
                $period = $_GET['period'] ?? 'today';
                $hours = $period === 'today' ? 24 : 24;
                
                $stmt = $db->prepare("
                    SELECT COUNT(*) as count
                    FROM banned_ips
                    WHERE banned_by = 'auto-ban'
                    AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                ");
                $stmt->execute([$hours]);
                $result = $stmt->fetch();
                
                sendResponse(['count' => $result ? (int)$result['count'] : 0]);
                return;
            }
            
            if (empty($action)) {
                // List all bans
                $filter = $_GET['filter'] ?? 'active';
                
                $sql = "SELECT * FROM banned_ips";
                if ($filter === 'active') {
                    $sql .= " WHERE (expires_at IS NULL OR expires_at > NOW())";
                } elseif ($filter === 'permanent') {
                    $sql .= " WHERE is_permanent = 1";
                } elseif ($filter === 'expired') {
                    $sql .= " WHERE expires_at < NOW() AND is_permanent = 0";
                }
                $sql .= " ORDER BY banned_at DESC LIMIT 1000";
                
                $stmt = $db->query($sql);
                sendResponse(['bans' => $stmt->fetchAll()]);
            } else {
                // Get specific ban
                $stmt = $db->prepare("SELECT * FROM banned_ips WHERE id = ? OR ip_address = ?");
                $stmt->execute([$params[0], $params[0]]);
                $ban = $stmt->fetch();
                sendResponse(['ban' => $ban]);
            }
            break;
            
        case 'POST':
            // Manual ban
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['ip_address'])) {
                sendResponse(['error' => 'IP address required'], 400);
            }
            
            $duration = $data['duration'] ?? 3600;
            $isPermanent = $data['permanent'] ?? false;
            $expiresAt = $isPermanent ? null : date('Y-m-d H:i:s', time() + $duration);
            
            $stmt = $db->prepare("
                INSERT INTO banned_ips (ip_address, reason, jail, ban_duration, expires_at, is_permanent)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    reason = VALUES(reason),
                    banned_at = NOW(),
                    expires_at = VALUES(expires_at),
                    is_permanent = VALUES(is_permanent)
            ");
            
            $stmt->execute([
                $data['ip_address'],
                $data['reason'] ?? 'Manual ban',
                'manual',
                $duration,
                $expiresAt,
                $isPermanent
            ]);
            
            // Add to NGINX banlist
            addToBanlist($data['ip_address']);
            
            sendResponse(['success' => true, 'message' => 'IP banned'], 201);
            break;
            
        case 'DELETE':
            // Unban IP
            if (empty($params[0])) {
                sendResponse(['error' => 'Ban ID or IP required'], 400);
            }
            
            // Get IP address
            $stmt = $db->prepare("SELECT ip_address FROM banned_ips WHERE id = ? OR ip_address = ?");
            $stmt->execute([$params[0], $params[0]]);
            $ban = $stmt->fetch();
            
            if ($ban) {
                // Remove from database
                $stmt = $db->prepare("DELETE FROM banned_ips WHERE ip_address = ?");
                $stmt->execute([$ban['ip_address']]);
                
                // Remove from NGINX banlist
                removeFromBanlist($ban['ip_address']);
                
                sendResponse(['success' => true, 'message' => 'IP unbanned']);
            } else {
                sendResponse(['error' => 'Ban not found'], 404);
            }
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

function addToBanlist($ip) {
    $banlistPath = BANLIST_PATH;
    $content = file_get_contents($banlistPath);
    
    // Check if IP already exists
    if (strpos($content, $ip) === false) {
        // Add before closing brace
        $content = str_replace('}', "    $ip 1;\n}", $content);
        file_put_contents($banlistPath, $content);
        
        // Reload NGINX
        exec('nginx -s reload 2>&1', $output, $return);
        error_log("NGINX reload: " . implode("\n", $output));
    }
}

function removeFromBanlist($ip) {
    $banlistPath = BANLIST_PATH;
    $content = file_get_contents($banlistPath);
    
    // Remove IP line
    $content = preg_replace("/\s*$ip\s+1;.*\n/", "", $content);
    file_put_contents($banlistPath, $content);
    
    // Reload NGINX
    exec('nginx -s reload 2>&1', $output, $return);
    error_log("NGINX reload: " . implode("\n", $output));
}
