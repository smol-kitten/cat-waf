<?php

function handleTelemetry($method, $params, $db) {
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    $action = $params[0] ?? 'stats';
    
    switch ($action) {
        case 'stats':
            $range = $_GET['range'] ?? '24h';
            
            // Convert range to hours
            $hours = 24;
            if ($range === '1h') $hours = 1;
            elseif ($range === '7d') $hours = 168;
            
            $stats = [
                'avg_response_time' => 0,
                'requests_per_minute' => 0,
                'cache_hit_rate' => 0,
                'error_rate' => 0
            ];
            
            // Average response time (convert to milliseconds, exclude NULLs)
            $stmt = $db->query("
                SELECT AVG(response_time) as avg_time 
                FROM request_telemetry 
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL $hours HOUR)
                AND response_time IS NOT NULL
                AND response_time > 0
            ");
            $result = $stmt->fetch();
            $stats['avg_response_time'] = $result && $result['avg_time'] ? round($result['avg_time'] * 1000, 1) : 0;
            
            // Requests per minute
            $stmt = $db->query("
                SELECT COUNT(*) as count 
                FROM request_telemetry 
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL $hours HOUR)
            ");
            $result = $stmt->fetch();
            $totalRequests = $result ? (int)$result['count'] : 0;
            $stats['requests_per_minute'] = $hours > 0 ? round($totalRequests / ($hours * 60), 0) : 0;
            
            // Cache hit rate
            $stmt = $db->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN cache_status = 'HIT' THEN 1 ELSE 0 END) as hits
                FROM request_telemetry 
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL $hours HOUR)
            ");
            $result = $stmt->fetch();
            if ($result && $result['total'] > 0) {
                $stats['cache_hit_rate'] = round(($result['hits'] / $result['total']) * 100, 1);
            }
            
            // Error rate (exclude NULL status codes)
            $stmt = $db->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as errors
                FROM request_telemetry 
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL $hours HOUR)
                AND status_code IS NOT NULL
            ");
            $result = $stmt->fetch();
            if ($result && $result['total'] > 0) {
                $stats['error_rate'] = round(($result['errors'] / $result['total']) * 100, 2);
            }
            
            sendResponse($stats);
            break;
            
        case 'slowest-endpoints':
            $limit = min((int)($_GET['limit'] ?? 10), 100);
            
            try {
                $stmt = $db->prepare("
                    SELECT 
                        domain,
                        uri as path,
                        AVG(response_time) as avg_response,
                        COUNT(*) as request_count,
                        MAX(response_time) as p95,
                        MAX(response_time) as p99
                    FROM request_telemetry 
                    WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    AND response_time IS NOT NULL
                    AND response_time > 0
                    GROUP BY domain, uri
                    ORDER BY avg_response DESC
                    LIMIT ?
                ");
                $stmt->execute([$limit]);
                
                $results = $stmt->fetchAll();
                
                // Format the results (convert to milliseconds)
                $formatted = array_map(function($row) {
                    return [
                        'domain' => $row['domain'] ?? 'unknown',
                        'path' => $row['path'] ?? '/',
                        'avg_response' => round(($row['avg_response'] ?? 0) * 1000, 1),
                        'p95' => round(($row['p95'] ?? 0) * 1000, 1),
                        'p99' => round(($row['p99'] ?? 0) * 1000, 1),
                        'request_count' => (int)($row['request_count'] ?? 0)
                    ];
                }, $results);
                
                sendResponse($formatted);
            } catch (Exception $e) {
                // Table doesn't exist yet or columns missing - return empty array
                sendResponse([]);
            }
            break;
            
        case 'backend-performance':
            $stmt = $db->query("
                SELECT 
                    backend_server,
                    COUNT(*) as request_count,
                    AVG(response_time) as avg_response,
                    SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as error_count,
                    'up' as status
                FROM request_telemetry 
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                AND response_time IS NOT NULL
                AND status_code IS NOT NULL
                GROUP BY backend_server
                ORDER BY request_count DESC
            ");
            
            $results = $stmt->fetchAll();
            
            // Format the results (convert to milliseconds)
            $formatted = array_map(function($row) {
                return [
                    'backend_server' => $row['backend_server'] ?: 'unknown',
                    'request_count' => (int)$row['request_count'],
                    'avg_response' => round(($row['avg_response'] ?? 0) * 1000, 1),
                    'error_count' => (int)$row['error_count'],
                    'status' => $row['status']
                ];
            }, $results);
            
            sendResponse($formatted);
            break;
        
        case 'response-time-distribution':
            // Get response time distribution for chart
            $stmt = $db->query("
                SELECT 
                    CASE
                        WHEN response_time < 0.05 THEN '0-50ms'
                        WHEN response_time < 0.1 THEN '50-100ms'
                        WHEN response_time < 0.2 THEN '100-200ms'
                        WHEN response_time < 0.5 THEN '200-500ms'
                        WHEN response_time < 1.0 THEN '500ms-1s'
                        WHEN response_time < 2.0 THEN '1-2s'
                        ELSE '2s+'
                    END as time_bucket,
                    COUNT(*) as count
                FROM request_telemetry
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    AND response_time IS NOT NULL
                    AND response_time > 0
                GROUP BY time_bucket
                ORDER BY 
                    CASE time_bucket
                        WHEN '0-50ms' THEN 1
                        WHEN '50-100ms' THEN 2
                        WHEN '100-200ms' THEN 3
                        WHEN '200-500ms' THEN 4
                        WHEN '500ms-1s' THEN 5
                        WHEN '1-2s' THEN 6
                        WHEN '2s+' THEN 7
                    END
            ");
            
            $distribution = [];
            $buckets = ['0-50ms', '50-100ms', '100-200ms', '200-500ms', '500ms-1s', '1-2s', '2s+'];
            
            foreach ($buckets as $bucket) {
                $distribution[$bucket] = 0;
            }
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $distribution[$row['time_bucket']] = (int)$row['count'];
            }
            
            sendResponse([
                'labels' => array_keys($distribution),
                'data' => array_values($distribution)
            ]);
            break;
            
        default:
            sendResponse(['error' => 'Unknown action'], 404);
    }
}
