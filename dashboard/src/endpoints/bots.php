<?php

function handleBots($method, $params, $db) {
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    $action = $params[0] ?? 'detections';
    
    switch ($action) {
        case 'detections':
            $limit = min((int)($_GET['limit'] ?? 50), 1000);
            
            $stmt = $db->prepare("
                SELECT * FROM bot_detections 
                ORDER BY timestamp DESC 
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            
            sendResponse($stmt->fetchAll());
            break;
            
        case 'stats':
            // Get bot statistics with period support
            $period = $_GET['period'] ?? '24h';
            $hours = $period === '24h' ? 24 : ($period === 'today' ? 24 : 24);
            
            $stats = [
                'total_detected' => 0,
                'good_bots' => 0,
                'bad_bots' => 0,
                'total_blocked' => 0,
                'detection_rate' => 0
            ];
            
            // Total detections
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM bot_detections 
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            $stmt->execute([$hours]);
            $result = $stmt->fetch();
            $stats['total_detected'] = $result ? (int)$result['count'] : 0;
            
            // Good bots
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM bot_detections 
                WHERE bot_type = 'good' 
                AND timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            $stmt->execute([$hours]);
            $result = $stmt->fetch();
            $stats['good_bots'] = $result ? (int)$result['count'] : 0;
            
            // Bad bots (blocked)
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM bot_detections 
                WHERE bot_type = 'bad'
                OR action = 'blocked'
                AND timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            $stmt->execute([$hours]);
            $result = $stmt->fetch();
            $stats['bad_bots'] = $result ? (int)$result['count'] : 0;
            $stats['total_blocked'] = $stats['bad_bots'];
            
            // Detection rate
            if ($stats['total_detected'] > 0) {
                $stats['detection_rate'] = round(($stats['bad_bots'] / $stats['total_detected']) * 100, 1);
            }
            
            sendResponse($stats);
            break;
            
        case 'bot-stats':
            // Get per-bot statistics over different time periods
            $stmt = $db->query("
                SELECT 
                    bot_name,
                    bot_type,
                    action,
                    SUM(CASE WHEN timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) as count_1h,
                    SUM(CASE WHEN timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as count_24h,
                    SUM(CASE WHEN timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as count_7d,
                    MAX(timestamp) as last_seen
                FROM bot_detections
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY bot_name, bot_type, action
                HAVING count_24h > 0
                ORDER BY count_24h DESC
                LIMIT 50
            ");
            
            sendResponse($stmt->fetchAll());
            break;
            
        default:
            sendResponse(['error' => 'Unknown action'], 404);
    }
}
