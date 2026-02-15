<?php
/**
 * Stats Refresh Task
 * Refreshes the stats cache tables for fast dashboard loading
 * 
 * Runs every minute to keep cache current
 */

require_once __DIR__ . '/../config.php';

function runStatsRefresh() {
    $pdo = getDB();
    
    logMessage("Starting stats cache refresh...");
    
    try {
        // Try to use stored procedure first (most efficient)
        try {
            $pdo->query("CALL refresh_all_stats()");
            logMessage("Stats cache refreshed via stored procedure");
            return ['success' => true, 'message' => 'Stats cache refreshed'];
        } catch (Exception $e) {
            // Fall back to manual refresh
            logMessage("Stored procedure not available, using manual refresh");
        }
        
        $currentHour = date('Y-m-d H:00:00');
        
        // Refresh hourly stats for current hour
        $pdo->exec("
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
        
        // Update ModSecurity stats
        $pdo->exec("
            UPDATE stats_cache_hourly h
            SET 
                modsec_blocks = (
                    SELECT COUNT(*) FROM modsec_events 
                    WHERE action = 'BLOCKED' 
                      AND timestamp >= '$currentHour' 
                      AND timestamp < DATE_ADD('$currentHour', INTERVAL 1 HOUR)
                ),
                modsec_warnings = (
                    SELECT COUNT(*) FROM modsec_events 
                    WHERE severity = 'WARNING' 
                      AND timestamp >= '$currentHour' 
                      AND timestamp < DATE_ADD('$currentHour', INTERVAL 1 HOUR)
                )
            WHERE h.hour_bucket = '$currentHour' AND h.domain = '_all_'
        ");
        
        // Refresh top IPs for 24h (runs every time)
        refreshTopIPs($pdo, '24h');
        
        // Refresh top domains for 24h
        refreshTopDomains($pdo, '24h');
        
        // Every 5 minutes, also refresh 7d and 30d (based on current minute)
        $minute = (int)date('i');
        if ($minute % 5 === 0) {
            refreshTopIPs($pdo, '7d');
            refreshTopDomains($pdo, '7d');
        }
        if ($minute % 15 === 0) {
            refreshTopIPs($pdo, '30d');
            refreshTopDomains($pdo, '30d');
        }
        
        // Clean up old cache entries (older than 31 days)
        $pdo->exec("DELETE FROM stats_cache_hourly WHERE hour_bucket < DATE_SUB(NOW(), INTERVAL 31 DAY)");
        $pdo->exec("DELETE FROM stats_cache_daily WHERE day_bucket < DATE_SUB(NOW(), INTERVAL 366 DAY)");
        
        logMessage("Stats cache refresh completed successfully");
        return ['success' => true, 'message' => 'Stats cache refreshed'];
        
    } catch (Exception $e) {
        logMessage("Stats cache refresh error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function refreshTopIPs($pdo, $period) {
    $interval = match($period) {
        '1h' => 'INTERVAL 1 HOUR',
        '24h' => 'INTERVAL 24 HOUR',
        '7d' => 'INTERVAL 7 DAY',
        '30d' => 'INTERVAL 30 DAY',
        default => 'INTERVAL 24 HOUR'
    };
    
    try {
        $pdo->exec("DELETE FROM stats_top_ips WHERE period = '$period'");
        $pdo->exec("
            INSERT INTO stats_top_ips (period, ip_address, request_count, blocked_count, last_seen, country_code)
            SELECT 
                '$period',
                ip_address,
                COUNT(*) as request_count,
                SUM(CASE WHEN blocked = 1 THEN 1 ELSE 0 END) as blocked_count,
                MAX(timestamp) as last_seen,
                MAX(country_code) as country_code
            FROM access_logs 
            WHERE timestamp > DATE_SUB(NOW(), $interval)
              AND ip_address IS NOT NULL
            GROUP BY ip_address
            ORDER BY request_count DESC
            LIMIT 100
        ");
    } catch (Exception $e) {
        logMessage("Error refreshing top IPs for $period: " . $e->getMessage());
    }
}

function refreshTopDomains($pdo, $period) {
    $interval = match($period) {
        '1h' => 'INTERVAL 1 HOUR',
        '24h' => 'INTERVAL 24 HOUR',
        '7d' => 'INTERVAL 7 DAY',
        '30d' => 'INTERVAL 30 DAY',
        default => 'INTERVAL 24 HOUR'
    };
    
    try {
        $pdo->exec("DELETE FROM stats_top_domains WHERE period = '$period'");
        $pdo->exec("
            INSERT INTO stats_top_domains (period, domain, request_count, blocked_count, avg_response_time)
            SELECT 
                '$period',
                domain,
                COUNT(*) as request_count,
                SUM(CASE WHEN blocked = 1 THEN 1 ELSE 0 END) as blocked_count,
                COALESCE(AVG(response_time), 0) as avg_response_time
            FROM access_logs 
            WHERE timestamp > DATE_SUB(NOW(), $interval)
              AND domain IS NOT NULL
            GROUP BY domain
            ORDER BY request_count DESC
            LIMIT 50
        ");
    } catch (Exception $e) {
        logMessage("Error refreshing top domains for $period: " . $e->getMessage());
    }
}

// Run if called directly
if (php_sapi_name() === 'cli' && realpath($argv[0]) === __FILE__) {
    $result = runStatsRefresh();
    echo json_encode($result) . "\n";
}
