<?php

function handleModSec($method, $params, $db) {
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    $action = $params[0] ?? 'events';
    
    switch ($action) {
        case 'events':
            $limit = min((int)($_GET['limit'] ?? 100), 1000);
            $severity = $_GET['severity'] ?? null;
            $includeGeoIP = ($_GET['geoip'] ?? 'true') === 'true';
            
            $sql = "SELECT 
                id,
                unique_id,
                timestamp,
                ip_address as client_ip,
                domain,
                uri,
                method,
                rule_id,
                severity,
                message as rule_message,
                action
            FROM modsec_events";
            $whereParams = [];
            
            if ($severity) {
                $sql .= " WHERE severity = ?";
                $whereParams[] = $severity;
            }
            
            $sql .= " ORDER BY timestamp DESC LIMIT ?";
            $whereParams[] = $limit;
            
            $stmt = $db->prepare($sql);
            $stmt->execute($whereParams);
            $events = $stmt->fetchAll();
            
            // Add GeoIP data if requested
            if ($includeGeoIP && !empty($events)) {
                require_once __DIR__ . '/../lib/GeoIP.php';
                foreach ($events as &$event) {
                    $location = GeoIP::lookup($event['client_ip']);
                    if ($location) {
                        $event['country'] = $location['country'];
                        $event['countryCode'] = $location['countryCode'];
                        $event['city'] = $location['city'];
                        $event['flag'] = GeoIP::getFlag($location['countryCode']);
                    }
                }
            }
            
            sendResponse($events);
            break;
            
        case 'top-rules':
            try {
                $stmt = $db->query("
                    SELECT 
                        rule_id,
                        message as rule_message,
                        severity,
                        COUNT(*) as trigger_count 
                    FROM modsec_events 
                    WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    GROUP BY rule_id, message, severity
                    ORDER BY trigger_count DESC
                    LIMIT 10
                ");
                
                sendResponse($stmt->fetchAll());
            } catch (Exception $e) {
                // Table doesn't exist yet or columns missing - return empty array
                sendResponse([]);
            }
            break;
            
        case 'stats':
            // Get ModSecurity statistics
            $stats = [
                'total_events' => 0,
                'events_by_severity' => [],
                'events_by_rule' => [],
                'top_blocked_ips' => []
            ];
            
            // Total events (last 24 hours)
            $stmt = $db->query("
                SELECT COUNT(*) as count 
                FROM modsec_events 
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stats['total_events'] = $stmt->fetch()['count'];
            
            // By severity
            $stmt = $db->query("
                SELECT severity, COUNT(*) as count 
                FROM modsec_events 
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY severity
            ");
            $stats['events_by_severity'] = $stmt->fetchAll();
            
            // By rule
            $stmt = $db->query("
                SELECT rule_id, message, COUNT(*) as count 
                FROM modsec_events 
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY rule_id, message
                ORDER BY count DESC
                LIMIT 10
            ");
            $stats['events_by_rule'] = $stmt->fetchAll();
            
            // Top blocked IPs
            $stmt = $db->query("
                SELECT ip_address, COUNT(*) as count 
                FROM modsec_events 
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY ip_address
                ORDER BY count DESC
                LIMIT 10
            ");
            $stats['top_blocked_ips'] = $stmt->fetchAll();
            
            // Total blocks count
            $stmt = $db->query("
                SELECT COUNT(*) as count
                FROM modsec_events
                WHERE action = 'blocked'
                AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stats['total_blocks'] = $stmt->fetch()['count'] ?? 0;
            
            sendResponse($stats);
            break;
            
        default:
            sendResponse(['error' => 'Unknown action'], 404);
    }
}
