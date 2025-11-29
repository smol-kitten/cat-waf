<?php
/**
 * Traffic Analysis Endpoint
 * Provides detailed breakdown of traffic for specific time ranges
 * Used for spike analysis and drill-down views
 */

function handleTrafficAnalysis($method, $params, $db) {
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    $action = $params[0] ?? 'hour-detail';
    
    switch ($action) {
        case 'hour-detail':
            // Detailed breakdown for a specific hour
            $timestamp = $_GET['timestamp'] ?? null;
            
            if (!$timestamp) {
                sendResponse(['error' => 'Timestamp parameter required'], 400);
                return;
            }
            
            // Parse timestamp
            $dt = new DateTime($timestamp);
            $hour_start = $dt->format('Y-m-d H:00:00');
            $hour_end = $dt->modify('+1 hour')->format('Y-m-d H:00:00');
            
            $analysis = [
                'period' => [
                    'start' => $hour_start,
                    'end' => $hour_end,
                    'hour' => (new DateTime($hour_start))->format('H:00')
                ],
                'summary' => [],
                'by_domain' => [],
                'by_status' => [],
                'by_ip' => [],
                'top_endpoints' => [],
                'errors' => [],
                'bots' => []
            ];
            
            // Summary stats
            $stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_requests,
                    COUNT(DISTINCT domain) as unique_domains,
                    COUNT(DISTINCT ip_address) as unique_ips,
                    SUM(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 ELSE 0 END) as success_count,
                    SUM(CASE WHEN status_code >= 300 AND status_code < 400 THEN 1 ELSE 0 END) as redirect_count,
                    SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END) as client_error_count,
                    SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) as server_error_count,
                    AVG(response_time) as avg_response_time
                FROM request_telemetry
                WHERE timestamp >= ? AND timestamp < ?
            ");
            $stmt->execute([$hour_start, $hour_end]);
            $analysis['summary'] = $stmt->fetch(PDO::FETCH_ASSOC);
            $analysis['summary']['avg_response_time'] = round(($analysis['summary']['avg_response_time'] ?? 0) * 1000, 1);
            
            // By domain (for pie chart)
            $stmt = $db->prepare("
                SELECT 
                    domain,
                    COUNT(*) as request_count,
                    SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as error_count
                FROM request_telemetry
                WHERE timestamp >= ? AND timestamp < ?
                GROUP BY domain
                ORDER BY request_count DESC
                LIMIT 10
            ");
            $stmt->execute([$hour_start, $hour_end]);
            $analysis['by_domain'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // By status code (for pie chart)
            $stmt = $db->prepare("
                SELECT 
                    status_code,
                    COUNT(*) as count
                FROM request_telemetry
                WHERE timestamp >= ? AND timestamp < ?
                GROUP BY status_code
                ORDER BY count DESC
                LIMIT 15
            ");
            $stmt->execute([$hour_start, $hour_end]);
            $analysis['by_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Top IPs (potential attackers)
            $stmt = $db->prepare("
                SELECT 
                    ip_address,
                    COUNT(*) as request_count,
                    SUM(CASE WHEN status_code = 404 THEN 1 ELSE 0 END) as not_found_count,
                    SUM(CASE WHEN status_code = 403 THEN 1 ELSE 0 END) as blocked_count,
                    COUNT(DISTINCT uri) as unique_paths
                FROM request_telemetry
                WHERE timestamp >= ? AND timestamp < ?
                GROUP BY ip_address
                ORDER BY request_count DESC
                LIMIT 20
            ");
            $stmt->execute([$hour_start, $hour_end]);
            $analysis['by_ip'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Top endpoints
            $stmt = $db->prepare("
                SELECT 
                    domain,
                    SUBSTRING_INDEX(uri, '?', 1) as path,
                    COUNT(*) as hit_count,
                    AVG(response_time) as avg_response
                FROM request_telemetry
                WHERE timestamp >= ? AND timestamp < ?
                GROUP BY domain, path
                ORDER BY hit_count DESC
                LIMIT 15
            ");
            $stmt->execute([$hour_start, $hour_end]);
            $topEndpoints = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $analysis['top_endpoints'] = array_map(function($row) {
                return [
                    'domain' => $row['domain'],
                    'path' => $row['path'],
                    'hit_count' => (int)$row['hit_count'],
                    'avg_response' => round(($row['avg_response'] ?? 0) * 1000, 1)
                ];
            }, $topEndpoints);
            
            // Top errors
            $stmt = $db->prepare("
                SELECT 
                    domain,
                    SUBSTRING_INDEX(uri, '?', 1) as path,
                    status_code,
                    COUNT(*) as error_count
                FROM request_telemetry
                WHERE timestamp >= ? AND timestamp < ?
                  AND status_code >= 400
                GROUP BY domain, path, status_code
                ORDER BY error_count DESC
                LIMIT 15
            ");
            $stmt->execute([$hour_start, $hour_end]);
            $analysis['errors'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Bot activity in this hour
            $stmt = $db->prepare("
                SELECT 
                    bot_name,
                    bot_type,
                    action,
                    COUNT(*) as detection_count,
                    COUNT(DISTINCT ip_address) as unique_ips
                FROM bot_detections
                WHERE timestamp >= ? AND timestamp < ?
                GROUP BY bot_name, bot_type, action
                ORDER BY detection_count DESC
                LIMIT 10
            ");
            $stmt->execute([$hour_start, $hour_end]);
            $analysis['bots'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            sendResponse($analysis);
            break;
            
        case 'scanner-activity':
            // Get recent scanner detections
            $limit = min((int)($_GET['limit'] ?? 50), 200);
            
            $stmt = $db->prepare("
                SELECT 
                    ip_address,
                    domain,
                    scan_type,
                    request_count,
                    error_404_count,
                    suspicious_paths,
                    first_seen,
                    last_seen,
                    auto_blocked,
                    block_reason
                FROM scanner_detections
                WHERE last_seen > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY last_seen DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $scanners = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            sendResponse($scanners);
            break;
            
        case 'spike-detection':
            // Automatically detect traffic spikes in last 24 hours
            $threshold_multiplier = (float)($_GET['threshold'] ?? 3.0); // 3x normal = spike
            
            // Get hourly traffic for last 24 hours
            $stmt = $db->query("
                SELECT 
                    DATE_FORMAT(timestamp, '%Y-%m-%d %H:00:00') as hour,
                    COUNT(*) as request_count
                FROM request_telemetry
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY hour
                ORDER BY hour
            ");
            $hourly = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($hourly) < 3) {
                sendResponse(['spikes' => [], 'average' => 0, 'threshold' => 0]);
                return;
            }
            
            // Calculate average (excluding top 10% to avoid spike contamination)
            $counts = array_column($hourly, 'request_count');
            sort($counts);
            $excludeTop = (int)(count($counts) * 0.9);
            $avgCounts = array_slice($counts, 0, $excludeTop);
            $average = array_sum($avgCounts) / count($avgCounts);
            $threshold = $average * $threshold_multiplier;
            
            // Find spikes
            $spikes = [];
            foreach ($hourly as $hour) {
                if ($hour['request_count'] > $threshold) {
                    $spikes[] = [
                        'hour' => $hour['hour'],
                        'request_count' => (int)$hour['request_count'],
                        'multiplier' => round($hour['request_count'] / $average, 2),
                        'above_threshold' => (int)($hour['request_count'] - $threshold)
                    ];
                }
            }
            
            sendResponse([
                'spikes' => $spikes,
                'average' => round($average, 0),
                'threshold' => round($threshold, 0),
                'total_hours' => count($hourly)
            ]);
            break;
            
        default:
            sendResponse(['error' => 'Invalid action'], 400);
    }
}
