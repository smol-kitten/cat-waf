<?php

function handleStats($method, $params, $db) {
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    $action = $params[0] ?? 'general';
    
    switch ($action) {
        case 'modsecurity':
            // ModSecurity-specific stats
            $rulesLoaded = 0;
            
            // Read rules count from stats file created by nginx container
            $statsFile = '/etc/nginx/sites-enabled/.modsec_stats';
            if (file_exists($statsFile)) {
                $content = file_get_contents($statsFile);
                if ($content !== false) {
                    $rulesLoaded = intval(trim($content));
                }
            }
            
            // Get blocks and warnings from telemetry
            $blocksStmt = $db->query("SELECT COUNT(*) as count FROM request_telemetry WHERE status_code >= 400 AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $blocks = $blocksStmt->fetch()['count'] ?? 0;
            
            $warningsStmt = $db->query("SELECT COUNT(*) as count FROM request_telemetry WHERE status_code >= 300 AND status_code < 400 AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $warnings = $warningsStmt->fetch()['count'] ?? 0;
            
            $stats = [
                'rules_loaded' => $rulesLoaded,
                'blocks_today' => $blocks,
                'warnings_today' => $warnings,
                'paranoia_level' => 1
            ];
            
            try {
                // Blocks today
                $stmt = $db->query("
                    SELECT COUNT(*) as count 
                    FROM modsec_events 
                    WHERE action = 'BLOCKED' 
                    AND timestamp > CURDATE()
                ");
                $result = $stmt->fetch();
                $stats['blocks_today'] = $result ? (int)$result['count'] : 0;
                
                // Warnings today
                $stmt = $db->query("
                    SELECT COUNT(*) as count 
                    FROM modsec_events 
                    WHERE severity = 'WARNING' 
                    AND timestamp > CURDATE()
                ");
                $result = $stmt->fetch();
                $stats['warnings_today'] = $result ? (int)$result['count'] : 0;
                
                // Get paranoia level from settings
                $stmt = $db->query("SELECT value FROM settings WHERE `key` = 'paranoia_level'");
                $result = $stmt->fetch();
                if ($result) {
                    $stats['paranoia_level'] = (int)$result['value'];
                }
            } catch (Exception $e) {
                // Database not ready yet - return default values
            }
            
            sendResponse($stats);
            break;
            
        case 'bots':
            // Bot statistics (handled by bots.php)
            require_once 'endpoints/bots.php';
            handleBots('GET', ['stats'], $db);
            break;
            
        case 'general':
        default:
            $period = $_GET['period'] ?? '24h';
            
            $interval = match($period) {
                '1h' => 'INTERVAL 1 HOUR',
                '24h' => 'INTERVAL 24 HOUR',
                '7d' => 'INTERVAL 7 DAY',
                '30d' => 'INTERVAL 30 DAY',
                default => 'INTERVAL 24 HOUR'
            };
            
            $stats = [];
            
            // Total requests
            $stmt = $db->query("
                SELECT COUNT(*) as count 
                FROM access_logs 
                WHERE timestamp > DATE_SUB(NOW(), $interval)
            ");
            $result = $stmt->fetch();
            $stats['total_requests'] = $result ? (int)$result['count'] : 0;
            
            // Blocked requests
            $stmt = $db->query("
                SELECT COUNT(*) as count 
                FROM access_logs 
                WHERE timestamp > DATE_SUB(NOW(), $interval) AND blocked = 1
            ");
            $result = $stmt->fetch();
            $stats['blocked_requests'] = $result ? (int)$result['count'] : 0;
            
            // Unique IPs
            $stmt = $db->query("
                SELECT COUNT(DISTINCT ip_address) as count 
                FROM access_logs 
                WHERE timestamp > DATE_SUB(NOW(), $interval)
            ");
            $result = $stmt->fetch();
            $stats['unique_ips'] = $result ? (int)$result['count'] : 0;
            
            // Status codes distribution
            $stmt = $db->query("
                SELECT status_code, COUNT(*) as count 
                FROM access_logs 
                WHERE timestamp > DATE_SUB(NOW(), $interval)
                GROUP BY status_code
                ORDER BY count DESC
            ");
            $stats['status_codes'] = $stmt->fetchAll();
            
            // Top domains
            $stmt = $db->query("
                SELECT domain, COUNT(*) as count 
                FROM access_logs 
                WHERE timestamp > DATE_SUB(NOW(), $interval)
                GROUP BY domain
                ORDER BY count DESC
                LIMIT 10
            ");
            $stats['top_domains'] = $stmt->fetchAll();
            
            // Top IPs
            $stmt = $db->query("
                SELECT ip_address, COUNT(*) as count 
                FROM access_logs 
                WHERE timestamp > DATE_SUB(NOW(), $interval)
                GROUP BY ip_address
                ORDER BY count DESC
                LIMIT 10
            ");
            $stats['top_ips'] = $stmt->fetchAll();
            
            // Requests over time
            $stmt = $db->query("
                SELECT 
                    DATE_FORMAT(timestamp, '%Y-%m-%d %H:00:00') as hour,
                    COUNT(*) as count
                FROM access_logs 
                WHERE timestamp > DATE_SUB(NOW(), $interval)
                GROUP BY hour
                ORDER BY hour
            ");
            $stats['requests_over_time'] = $stmt->fetchAll();
            
            // Active bans
            $stmt = $db->query("
                SELECT COUNT(*) as count 
                FROM banned_ips 
                WHERE (expires_at IS NULL OR expires_at > NOW()) OR is_permanent = 1
            ");
            $result = $stmt->fetch();
            $stats['active_bans'] = $result ? (int)$result['count'] : 0;
            
            // Sites stats
            $stmt = $db->query("SELECT COUNT(*) as count FROM sites WHERE enabled = 1");
            $result = $stmt->fetch();
            $stats['active_sites'] = $result ? (int)$result['count'] : 0;
            
            sendResponse(['stats' => $stats, 'period' => $period]);
            break;
    }
}
