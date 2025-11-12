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
            $excludeDomains = isset($_GET['exclude']) ? explode(',', $_GET['exclude']) : [];
            
            try {
                $excludePlaceholders = '';
                $params = [];
                
                if (!empty($excludeDomains)) {
                    $excludePlaceholders = ' AND domain NOT IN (' . implode(',', array_fill(0, count($excludeDomains), '?')) . ')';
                    $params = $excludeDomains;
                }
                
                $params[] = $limit;
                
                // Normalize URI by stripping volatile query parameters (t, ts, nonce, _)
                $stmt = $db->prepare("
                    SELECT 
                        domain,
                        SUBSTRING_INDEX(uri, '?', 1) AS path,
                        AVG(response_time) as avg_response,
                        COUNT(*) as request_count,
                        MAX(response_time) as p95,
                        MAX(response_time) as p99
                    FROM request_telemetry 
                    WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    AND response_time IS NOT NULL
                    AND response_time > 0
                    $excludePlaceholders
                    GROUP BY domain, path
                    ORDER BY avg_response DESC
                    LIMIT ?
                ");
                $stmt->execute($params);
                
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
        
        case 'top-endpoints':
            // Get top accessed endpoints per site (up to 3)
            $siteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : null;
            $range = $_GET['range'] ?? '24h';
            
            // Convert range to hours
            $hours = 24;
            if ($range === '1h') $hours = 1;
            elseif ($range === '7d') $hours = 168;
            
            if ($siteId) {
                // Get site domain for filtering
                $stmt = $db->prepare("SELECT domain FROM sites WHERE id = ?");
                $stmt->execute([$siteId]);
                $site = $stmt->fetch();
                
                if (!$site) {
                    sendResponse(['error' => 'Site not found'], 404);
                    return;
                }
                
                $domain = $site['domain'];
                
                // Top 3 accessed endpoints for this site
                $stmt = $db->prepare("
                    SELECT 
                        SUBSTRING_INDEX(uri, '?', 1) AS path,
                        COUNT(*) as hit_count,
                        AVG(response_time) as avg_response_time
                    FROM request_telemetry 
                    WHERE domain = ?
                        AND timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)
                        AND status_code < 400
                    GROUP BY path
                    ORDER BY hit_count DESC
                    LIMIT 3
                ");
                $stmt->execute([$domain, $hours]);
                $topEndpoints = $stmt->fetchAll();
                
                $formatted = array_map(function($row) {
                    return [
                        'path' => $row['path'] ?? '/',
                        'hit_count' => (int)($row['hit_count'] ?? 0),
                        'avg_response_time' => round(($row['avg_response_time'] ?? 0) * 1000, 1)
                    ];
                }, $topEndpoints);
                
                sendResponse($formatted);
            } else {
                // Get top endpoints across all sites
                $stmt = $db->prepare("
                    SELECT 
                        domain,
                        SUBSTRING_INDEX(uri, '?', 1) AS path,
                        COUNT(*) as hit_count,
                        AVG(response_time) as avg_response_time
                    FROM request_telemetry 
                    WHERE timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)
                        AND status_code < 400
                    GROUP BY domain, path
                    ORDER BY hit_count DESC
                    LIMIT 10
                ");
                $stmt->execute([$hours]);
                $topEndpoints = $stmt->fetchAll();
                
                $formatted = array_map(function($row) {
                    return [
                        'domain' => $row['domain'] ?? 'unknown',
                        'path' => $row['path'] ?? '/',
                        'hit_count' => (int)($row['hit_count'] ?? 0),
                        'avg_response_time' => round(($row['avg_response_time'] ?? 0) * 1000, 1)
                    ];
                }, $topEndpoints);
                
                sendResponse($formatted);
            }
            break;
        
        case 'top-404s':
            // Get most common 404 endpoints
            $siteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : null;
            $range = $_GET['range'] ?? '24h';
            
            // Convert range to hours
            $hours = 24;
            if ($range === '1h') $hours = 1;
            elseif ($range === '7d') $hours = 168;
            
            if ($siteId) {
                // Get site domain for filtering
                $stmt = $db->prepare("SELECT domain FROM sites WHERE id = ?");
                $stmt->execute([$siteId]);
                $site = $stmt->fetch();
                
                if (!$site) {
                    sendResponse(['error' => 'Site not found'], 404);
                    return;
                }
                
                $domain = $site['domain'];
                
                // Top 404s for this site
                $stmt = $db->prepare("
                    SELECT 
                        SUBSTRING_INDEX(uri, '?', 1) AS path,
                        COUNT(*) as hit_count,
                        MAX(timestamp) as last_seen
                    FROM request_telemetry 
                    WHERE domain = ?
                        AND timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)
                        AND status_code = 404
                    GROUP BY path
                    ORDER BY hit_count DESC
                    LIMIT 10
                ");
                $stmt->execute([$domain, $hours]);
                $top404s = $stmt->fetchAll();
                
                $formatted = array_map(function($row) {
                    return [
                        'path' => $row['path'] ?? '/',
                        'hit_count' => (int)($row['hit_count'] ?? 0),
                        'last_seen' => $row['last_seen'] ?? null
                    ];
                }, $top404s);
                
                sendResponse($formatted);
            } else {
                // Top 404s across all sites
                $stmt = $db->prepare("
                    SELECT 
                        domain,
                        SUBSTRING_INDEX(uri, '?', 1) AS path,
                        COUNT(*) as hit_count,
                        MAX(timestamp) as last_seen
                    FROM request_telemetry 
                    WHERE timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)
                        AND status_code = 404
                    GROUP BY domain, path
                    ORDER BY hit_count DESC
                    LIMIT 10
                ");
                $stmt->execute([$hours]);
                $top404s = $stmt->fetchAll();
                
                $formatted = array_map(function($row) {
                    return [
                        'domain' => $row['domain'] ?? 'unknown',
                        'path' => $row['path'] ?? '/',
                        'hit_count' => (int)($row['hit_count'] ?? 0),
                        'last_seen' => $row['last_seen'] ?? null
                    ];
                }, $top404s);
                
                sendResponse($formatted);
            }
            break;
        
        case 'site-performance':
            // Get performance metrics grouped by site/domain
            $range = $_GET['range'] ?? '24h';
            $excludeDomains = isset($_GET['exclude']) ? explode(',', $_GET['exclude']) : [];
            
            // Convert range to hours
            $hours = 24;
            if ($range === '1h') $hours = 1;
            elseif ($range === '7d') $hours = 168;
            
            $excludePlaceholders = '';
            $params = [$hours];
            
            if (!empty($excludeDomains)) {
                $excludePlaceholders = ' AND domain NOT IN (' . implode(',', array_fill(0, count($excludeDomains), '?')) . ')';
                $params = array_merge($params, $excludeDomains);
            }
            
            $stmt = $db->prepare("
                SELECT 
                    domain,
                    COUNT(*) as hits,
                    AVG(response_time) as avg_response,
                    MIN(response_time) as min_response,
                    MAX(response_time) as max_response,
                    SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as errors,
                    SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) as server_errors,
                    SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END) as client_errors
                FROM request_telemetry 
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)
                    AND response_time IS NOT NULL
                    AND status_code IS NOT NULL
                    $excludePlaceholders
                GROUP BY domain
                ORDER BY hits DESC
            ");
            $stmt->execute($params);
            
            $results = $stmt->fetchAll();
            
            // Format the results
            $formatted = array_map(function($row) {
                $hits = (int)$row['hits'];
                $errors = (int)$row['errors'];
                $errorRate = $hits > 0 ? round(($errors / $hits) * 100, 2) : 0;
                
                return [
                    'domain' => $row['domain'] ?? 'unknown',
                    'hits' => $hits,
                    'avg_response' => round(($row['avg_response'] ?? 0) * 1000, 1),
                    'min_response' => round(($row['min_response'] ?? 0) * 1000, 1),
                    'max_response' => round(($row['max_response'] ?? 0) * 1000, 1),
                    'errors' => $errors,
                    'server_errors' => (int)$row['server_errors'],
                    'client_errors' => (int)$row['client_errors'],
                    'error_rate' => $errorRate
                ];
            }, $results);
            
            sendResponse($formatted);
            break;
        
        case 'error-logs':
            // Get detailed error logs with filtering
            $range = $_GET['range'] ?? '24h';
            $statusCode = isset($_GET['status']) ? (int)$_GET['status'] : null;
            $domain = $_GET['domain'] ?? null;
            $limit = min((int)($_GET['limit'] ?? 100), 500);
            
            // Convert range to hours
            $hours = 24;
            if ($range === '1h') $hours = 1;
            elseif ($range === '7d') $hours = 168;
            elseif ($range === '30d') $hours = 720;
            
            $conditions = ["timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)"];
            $params = [$hours];
            
            // Filter by status code (or range)
            if ($statusCode) {
                $conditions[] = "status_code = ?";
                $params[] = $statusCode;
            } else {
                // Only errors (4xx and 5xx)
                $conditions[] = "status_code >= 400";
            }
            
            // Filter by domain
            if ($domain) {
                $conditions[] = "domain = ?";
                $params[] = $domain;
            }
            
            $whereClause = implode(' AND ', $conditions);
            
            $stmt = $db->prepare("
                SELECT 
                    timestamp,
                    domain,
                    uri,
                    method,
                    status_code,
                    response_time,
                    backend_server,
                    ip_address,
                    user_agent,
                    referer
                FROM request_telemetry 
                WHERE $whereClause
                ORDER BY timestamp DESC
                LIMIT ?
            ");
            $params[] = $limit;
            $stmt->execute($params);
            
            $results = $stmt->fetchAll();
            
            // Format the results
            $formatted = array_map(function($row) {
                return [
                    'timestamp' => $row['timestamp'],
                    'domain' => $row['domain'] ?? 'unknown',
                    'uri' => $row['uri'] ?? '/',
                    'method' => $row['method'] ?? 'GET',
                    'status_code' => (int)($row['status_code'] ?? 0),
                    'response_time' => round(($row['response_time'] ?? 0) * 1000, 1),
                    'backend_server' => $row['backend_server'] ?? 'unknown',
                    'ip_address' => $row['ip_address'] ?? 'unknown',
                    'user_agent' => substr($row['user_agent'] ?? '', 0, 100),
                    'referer' => $row['referer'] ?? ''
                ];
            }, $results);
            
            sendResponse($formatted);
            break;
            
        case 'error-summary':
            // Get summary of errors by status code
            $range = $_GET['range'] ?? '24h';
            
            // Convert range to hours
            $hours = 24;
            if ($range === '1h') $hours = 1;
            elseif ($range === '7d') $hours = 168;
            elseif ($range === '30d') $hours = 720;
            
            $stmt = $db->prepare("
                SELECT 
                    status_code,
                    COUNT(*) as count,
                    COUNT(DISTINCT domain) as affected_domains,
                    COUNT(DISTINCT ip_address) as unique_ips
                FROM request_telemetry 
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)
                    AND status_code >= 400
                    AND status_code IS NOT NULL
                GROUP BY status_code
                ORDER BY count DESC
            ");
            $stmt->execute([$hours]);
            
            $results = $stmt->fetchAll();
            
            // Add status code descriptions
            $statusDescriptions = [
                400 => 'Bad Request',
                401 => 'Unauthorized',
                403 => 'Forbidden',
                404 => 'Not Found',
                429 => 'Too Many Requests',
                500 => 'Internal Server Error',
                502 => 'Bad Gateway',
                503 => 'Service Unavailable',
                504 => 'Gateway Timeout'
            ];
            
            $formatted = array_map(function($row) use ($statusDescriptions) {
                $statusCode = (int)$row['status_code'];
                return [
                    'status_code' => $statusCode,
                    'description' => $statusDescriptions[$statusCode] ?? 'Unknown Error',
                    'count' => (int)$row['count'],
                    'affected_domains' => (int)$row['affected_domains'],
                    'unique_ips' => (int)$row['unique_ips']
                ];
            }, $results);
            
            sendResponse($formatted);
            break;
        
        case 'recent-requests':
            // Get recent requests for a specific site
            $domain = $_GET['domain'] ?? null;
            $limit = min((int)($_GET['limit'] ?? 10), 100);
            
            if (!$domain) {
                sendResponse(['error' => 'Domain parameter required'], 400);
                return;
            }
            
            $stmt = $db->prepare("
                SELECT 
                    timestamp,
                    method,
                    uri,
                    status_code,
                    response_time,
                    backend_server,
                    user_agent
                FROM request_telemetry 
                WHERE domain = ?
                ORDER BY timestamp DESC
                LIMIT ?
            ");
            $stmt->execute([$domain, $limit]);
            
            $results = $stmt->fetchAll();
            
            // Format the results
            $formatted = array_map(function($row) {
                return [
                    'timestamp' => $row['timestamp'],
                    'method' => $row['method'] ?? 'GET',
                    'uri' => $row['uri'] ?? '/',
                    'status_code' => (int)($row['status_code'] ?? 0),
                    'response_time' => round(($row['response_time'] ?? 0) * 1000, 1),
                    'backend_server' => $row['backend_server'] ?? 'unknown',
                    'user_agent' => $row['user_agent'] ?? ''
                ];
            }, $results);
            
            sendResponse($formatted);
            break;
            
        default:
            sendResponse(['error' => 'Unknown action'], 404);
    }
}
