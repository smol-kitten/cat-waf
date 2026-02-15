<?php

/**
 * Stats endpoint - Optimized to use cached stats when available
 * Falls back to direct queries if cache tables don't exist or are empty
 */

function handleStats($method, $params, $db) {
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    $action = $params[0] ?? 'general';
    
    switch ($action) {
        case 'modsecurity':
            sendResponse(getModSecurityStats($db));
            break;
            
        case 'bots':
            require_once 'endpoints/bots.php';
            handleBots('GET', ['stats'], $db);
            break;
            
        case 'refresh':
            // Force refresh stats cache
            refreshStatsCache($db);
            sendResponse(['success' => true, 'message' => 'Stats cache refreshed']);
            break;
            
        case 'general':
        default:
            $period = $_GET['period'] ?? '24h';
            $useCache = ($_GET['cache'] ?? 'true') !== 'false';
            
            // Try cached stats first (much faster)
            if ($useCache && statsCacheExists($db)) {
                $stats = getCachedStats($db, $period);
            } else {
                $stats = getDirectStats($db, $period);
            }
            
            sendResponse(['stats' => $stats, 'period' => $period]);
            break;
    }
}

/**
 * Check if stats cache tables exist and have data
 */
function statsCacheExists($db) {
    try {
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM stats_cache_hourly WHERE hour_bucket > DATE_SUB(NOW(), INTERVAL 2 HOUR)");
        $result = $stmt->fetch();
        return $result && $result['cnt'] > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get stats from cache tables (fast path)
 */
function getCachedStats($db, $period) {
    $stats = [];
    
    $intervalHours = match($period) {
        '1h' => 1,
        '24h' => 24,
        '7d' => 168,
        '30d' => 720,
        default => 24
    };
    
    // Get aggregated stats from hourly cache
    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(total_requests), 0) AS total_requests,
            COALESCE(SUM(blocked_requests), 0) AS blocked_requests,
            COALESCE(SUM(unique_ips), 0) AS unique_ips,
            COALESCE(SUM(status_2xx), 0) AS status_2xx,
            COALESCE(SUM(status_3xx), 0) AS status_3xx,
            COALESCE(SUM(status_4xx), 0) AS status_4xx,
            COALESCE(SUM(status_5xx), 0) AS status_5xx,
            COALESCE(SUM(modsec_blocks), 0) AS modsec_blocks,
            COALESCE(AVG(avg_response_time), 0) AS avg_response_time
        FROM stats_cache_hourly
        WHERE domain = '_all_'
          AND hour_bucket > DATE_SUB(NOW(), INTERVAL ? HOUR)
    ");
    $stmt->execute([$intervalHours]);
    $cached = $stmt->fetch();
    
    $stats['total_requests'] = (int)($cached['total_requests'] ?? 0);
    $stats['blocked_requests'] = (int)($cached['blocked_requests'] ?? 0);
    $stats['unique_ips'] = (int)($cached['unique_ips'] ?? 0);
    
    // Status codes from cached data
    $stats['status_codes'] = [
        ['status_code' => '2xx', 'count' => (int)($cached['status_2xx'] ?? 0)],
        ['status_code' => '3xx', 'count' => (int)($cached['status_3xx'] ?? 0)],
        ['status_code' => '4xx', 'count' => (int)($cached['status_4xx'] ?? 0)],
        ['status_code' => '5xx', 'count' => (int)($cached['status_5xx'] ?? 0)]
    ];
    
    // Top domains from cache
    $stmt = $db->prepare("
        SELECT domain, request_count as count 
        FROM stats_top_domains 
        WHERE period = ?
        ORDER BY request_count DESC 
        LIMIT 10
    ");
    $stmt->execute([$period]);
    $stats['top_domains'] = $stmt->fetchAll() ?: [];
    
    // Top IPs from cache
    $stmt = $db->prepare("
        SELECT ip_address, request_count as count, country_code
        FROM stats_top_ips 
        WHERE period = ?
        ORDER BY request_count DESC 
        LIMIT 10
    ");
    $stmt->execute([$period]);
    $stats['top_ips'] = $stmt->fetchAll() ?: [];
    
    // Traffic over time from hourly cache
    $stmt = $db->prepare("
        SELECT 
            hour_bucket as hour,
            total_requests as count,
            status_2xx,
            status_3xx,
            status_4xx,
            status_5xx
        FROM stats_cache_hourly
        WHERE domain = '_all_'
          AND hour_bucket > DATE_SUB(NOW(), INTERVAL ? HOUR)
        ORDER BY hour_bucket
    ");
    $stmt->execute([$intervalHours]);
    $timeData = $stmt->fetchAll();
    
    // Format for charts
    $stats['labels'] = [];
    $stats['status_2xx'] = [];
    $stats['status_3xx'] = [];
    $stats['status_4xx'] = [];
    $stats['status_5xx'] = [];
    $stats['requests_over_time'] = [];
    
    foreach ($timeData as $row) {
        $stats['labels'][] = date('H:i', strtotime($row['hour']));
        $stats['status_2xx'][] = (int)$row['status_2xx'];
        $stats['status_3xx'][] = (int)$row['status_3xx'];
        $stats['status_4xx'][] = (int)$row['status_4xx'];
        $stats['status_5xx'][] = (int)$row['status_5xx'];
        $stats['requests_over_time'][] = [
            'hour' => $row['hour'],
            'count' => (int)$row['count']
        ];
    }
    
    // Active bans (small table, direct query is fast)
    $stmt = $db->query("
        SELECT COUNT(*) as count 
        FROM banned_ips 
        WHERE (expires_at IS NULL OR expires_at > NOW()) OR is_permanent = 1
    ");
    $result = $stmt->fetch();
    $stats['active_bans'] = $result ? (int)$result['count'] : 0;
    
    // Active sites (small table)
    $stmt = $db->query("SELECT COUNT(*) as count FROM sites WHERE enabled = 1");
    $result = $stmt->fetch();
    $stats['active_sites'] = $result ? (int)$result['count'] : 0;
    
    return $stats;
}

/**
 * Get stats directly from access_logs (fallback, slower)
 */
function getDirectStats($db, $period) {
    $interval = match($period) {
        '1h' => 'INTERVAL 1 HOUR',
        '24h' => 'INTERVAL 24 HOUR',
        '7d' => 'INTERVAL 7 DAY',
        '30d' => 'INTERVAL 30 DAY',
        default => 'INTERVAL 24 HOUR'
    };
    
    $stats = [];
    
    // Single optimized query for basic stats
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN blocked = 1 THEN 1 ELSE 0 END) as blocked_requests,
            COUNT(DISTINCT ip_address) as unique_ips,
            SUM(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 ELSE 0 END) as status_2xx,
            SUM(CASE WHEN status_code >= 300 AND status_code < 400 THEN 1 ELSE 0 END) as status_3xx,
            SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END) as status_4xx,
            SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) as status_5xx
        FROM access_logs 
        WHERE timestamp > DATE_SUB(NOW(), $interval)
    ");
    $result = $stmt->fetch();
    
    $stats['total_requests'] = (int)($result['total_requests'] ?? 0);
    $stats['blocked_requests'] = (int)($result['blocked_requests'] ?? 0);
    $stats['unique_ips'] = (int)($result['unique_ips'] ?? 0);
    
    $stats['status_codes'] = [
        ['status_code' => '2xx', 'count' => (int)($result['status_2xx'] ?? 0)],
        ['status_code' => '3xx', 'count' => (int)($result['status_3xx'] ?? 0)],
        ['status_code' => '4xx', 'count' => (int)($result['status_4xx'] ?? 0)],
        ['status_code' => '5xx', 'count' => (int)($result['status_5xx'] ?? 0)]
    ];
    
    // Top domains (with limit on subquery for speed)
    $stmt = $db->query("
        SELECT domain, COUNT(*) as count 
        FROM access_logs 
        WHERE timestamp > DATE_SUB(NOW(), $interval)
          AND domain IS NOT NULL
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
    
    // Requests over time by status code
    $stmt = $db->query("
        SELECT 
            DATE_FORMAT(timestamp, '%Y-%m-%d %H:00:00') as hour,
            COUNT(*) as count,
            SUM(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 ELSE 0 END) as status_2xx,
            SUM(CASE WHEN status_code >= 300 AND status_code < 400 THEN 1 ELSE 0 END) as status_3xx,
            SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END) as status_4xx,
            SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) as status_5xx
        FROM access_logs 
        WHERE timestamp > DATE_SUB(NOW(), $interval)
        GROUP BY hour
        ORDER BY hour
    ");
    $timeData = $stmt->fetchAll();
    
    // Format for Chart.js
    $stats['labels'] = [];
    $stats['status_2xx'] = [];
    $stats['status_3xx'] = [];
    $stats['status_4xx'] = [];
    $stats['status_5xx'] = [];
    $stats['requests_over_time'] = [];
    
    foreach ($timeData as $row) {
        $stats['labels'][] = date('H:i', strtotime($row['hour']));
        $stats['status_2xx'][] = (int)$row['status_2xx'];
        $stats['status_3xx'][] = (int)$row['status_3xx'];
        $stats['status_4xx'][] = (int)$row['status_4xx'];
        $stats['status_5xx'][] = (int)$row['status_5xx'];
        $stats['requests_over_time'][] = [
            'hour' => $row['hour'],
            'count' => (int)$row['count']
        ];
    }
    
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
    
    return $stats;
}

/**
 * Get ModSecurity statistics
 */
function getModSecurityStats($db) {
    $stats = [
        'rules_loaded' => 0,
        'blocks_today' => 0,
        'warnings_today' => 0,
        'paranoia_level' => 1
    ];
    
    // Parse ModSecurity rules loaded from nginx error log
    $logFile = '/var/log/nginx/error.log';
    if (file_exists($logFile)) {
        $cmd = "tail -100 " . escapeshellarg($logFile) . " | grep 'ModSecurity-nginx' | grep 'rules loaded' | tail -1";
        $output = shell_exec($cmd);
        if ($output && preg_match('/rules loaded inline\/local\/remote: (\d+)\/(\d+)\/(\d+)/', $output, $matches)) {
            $stats['rules_loaded'] = (int)$matches[1] + (int)$matches[2] + (int)$matches[3];
        }
    }
    
    try {
        // Try to get from cache first
        $stmt = $db->query("
            SELECT COALESCE(SUM(modsec_blocks), 0) as blocks, COALESCE(SUM(modsec_warnings), 0) as warnings
            FROM stats_cache_hourly 
            WHERE domain = '_all_' AND hour_bucket >= CURDATE()
        ");
        $cached = $stmt->fetch();
        if ($cached && ($cached['blocks'] > 0 || $cached['warnings'] > 0)) {
            $stats['blocks_today'] = (int)$cached['blocks'];
            $stats['warnings_today'] = (int)$cached['warnings'];
        } else {
            // Fallback to direct query
            $stmt = $db->query("
                SELECT COUNT(*) as count FROM modsec_events 
                WHERE action = 'BLOCKED' AND timestamp > CURDATE()
            ");
            $result = $stmt->fetch();
            $stats['blocks_today'] = $result ? (int)$result['count'] : 0;
            
            $stmt = $db->query("
                SELECT COUNT(*) as count FROM modsec_events 
                WHERE severity = 'WARNING' AND timestamp > CURDATE()
            ");
            $result = $stmt->fetch();
            $stats['warnings_today'] = $result ? (int)$result['count'] : 0;
        }
        
        // Get paranoia level from settings
        $stmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'paranoia_level'");
        $result = $stmt->fetch();
        if ($result) {
            $stats['paranoia_level'] = (int)$result['setting_value'];
        }
    } catch (Exception $e) {
        // Database not ready yet - return default values
    }
    
    return $stats;
}

/**
 * Refresh stats cache by calling stored procedure
 */
function refreshStatsCache($db) {
    try {
        $db->query("CALL refresh_all_stats()");
        return true;
    } catch (Exception $e) {
        // Stored procedure doesn't exist yet, do manual refresh
        refreshStatsCacheManual($db);
        return true;
    }
}

/**
 * Manual stats cache refresh (fallback if stored procedures not available)
 */
function refreshStatsCacheManual($db) {
    $currentHour = date('Y-m-d H:00:00');
    
    try {
        // Refresh current hour stats
        $db->exec("
            INSERT INTO stats_cache_hourly (
                hour_bucket, domain, total_requests, blocked_requests, unique_ips,
                status_2xx, status_3xx, status_4xx, status_5xx,
                avg_response_time, total_bytes_sent
            )
            SELECT 
                '$currentHour',
                '_all_',
                COUNT(*),
                SUM(CASE WHEN blocked = 1 THEN 1 ELSE 0 END),
                COUNT(DISTINCT ip_address),
                SUM(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 ELSE 0 END),
                SUM(CASE WHEN status_code >= 300 AND status_code < 400 THEN 1 ELSE 0 END),
                SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END),
                SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END),
                COALESCE(AVG(response_time), 0),
                COALESCE(SUM(bytes_sent), 0)
            FROM access_logs
            WHERE timestamp >= '$currentHour'
              AND timestamp < DATE_ADD('$currentHour', INTERVAL 1 HOUR)
            ON DUPLICATE KEY UPDATE
                total_requests = VALUES(total_requests),
                blocked_requests = VALUES(blocked_requests),
                unique_ips = VALUES(unique_ips),
                status_2xx = VALUES(status_2xx),
                status_3xx = VALUES(status_3xx),
                status_4xx = VALUES(status_4xx),
                status_5xx = VALUES(status_5xx),
                avg_response_time = VALUES(avg_response_time),
                total_bytes_sent = VALUES(total_bytes_sent),
                updated_at = NOW()
        ");
    } catch (Exception $e) {
        error_log("Stats cache refresh error: " . $e->getMessage());
    }
}
