<?php
/**
 * Alert Monitoring Service
 * Runs periodically to check alert rules and fire notifications
 * Should be executed via cron: 5 * * * * php /dashboard/src/alert-monitor.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/WebhookNotifier.php';

$db = getDB();

error_log("Alert Monitor: Starting check cycle at " . date('Y-m-d H:i:s'));

// Load all enabled alert rules
$stmt = $db->query("SELECT * FROM alert_rules WHERE enabled = 1");
$rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

error_log("Alert Monitor: Found " . count($rules) . " enabled alert rules");

foreach ($rules as $rule) {
    try {
        checkAlertRule($db, $rule);
    } catch (Exception $e) {
        error_log("Alert Monitor: Error checking rule {$rule['id']}: " . $e->getMessage());
    }
}

error_log("Alert Monitor: Check cycle completed");

/**
 * Check a single alert rule and fire if conditions are met
 */
function checkAlertRule($db, $rule) {
    $config = json_decode($rule['config'], true) ?: [];
    $alertData = null;
    $shouldAlert = false;
    
    switch ($rule['rule_type']) {
        case 'delay':
            $shouldAlert = checkDelayAlert($db, $rule, $config, $alertData);
            break;
        case 'cert_expiry':
            $shouldAlert = checkCertExpiryAlert($db, $rule, $config, $alertData);
            break;
        case 'server_down':
            $shouldAlert = checkServerDownAlert($db, $rule, $config, $alertData);
            break;
        case 'error_rate':
            $shouldAlert = checkErrorRateAlert($db, $rule, $config, $alertData);
            break;
        case 'rate_limit_breach':
            $shouldAlert = checkRateLimitAlert($db, $rule, $config, $alertData);
            break;
    }
    
    if ($shouldAlert) {
        fireAlert($db, $rule, $alertData);
    }
}

/**
 * Check for high response time / delay
 */
function checkDelayAlert($db, $rule, $config, &$alertData) {
    $thresholdMs = $config['threshold_ms'] ?? 3000;
    $durationMinutes = $config['duration_minutes'] ?? 5;
    $minRequests = $config['min_requests'] ?? 10;
    
    $whereClause = "WHERE timestamp > DATE_SUB(NOW(), INTERVAL $durationMinutes MINUTE)";
    if ($rule['site_id']) {
        $whereClause .= " AND site_id = " . (int)$rule['site_id'];
    }
    
    $stmt = $db->query("
        SELECT 
            AVG(response_time) as avg_time,
            COUNT(*) as request_count,
            domain
        FROM request_telemetry
        $whereClause
        GROUP BY domain
        HAVING request_count >= $minRequests AND avg_time > " . ($thresholdMs / 1000) . "
    ");
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($results)) {
        $alertData = [
            'domains' => $results,
            'threshold_ms' => $thresholdMs,
            'duration_minutes' => $durationMinutes
        ];
        return true;
    }
    
    return false;
}

/**
 * Check for expiring certificates
 */
function checkCertExpiryAlert($db, $rule, $config, &$alertData) {
    $warningDays = $config['warning_days'] ?? 30;
    $criticalDays = $config['critical_days'] ?? 7;
    
    // Get all sites with SSL enabled
    $whereClause = "WHERE ssl_enabled = 1";
    if ($rule['site_id']) {
        $whereClause .= " AND id = " . (int)$rule['site_id'];
    }
    
    $stmt = $db->query("SELECT id, domain FROM sites $whereClause");
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $expiringCerts = [];
    
    foreach ($sites as $site) {
        // Check certificate expiry via shell command
        $domain = preg_replace('/[^a-z0-9.-]/i', '', $site['domain']);
        $certPath = "/etc/nginx/certs/{$domain}/fullchain.pem";
        
        $cmd = sprintf(
            "docker exec waf-nginx sh -c 'test -f %s && openssl x509 -in %s -noout -enddate 2>/dev/null || echo not_found'",
            escapeshellarg($certPath),
            escapeshellarg($certPath)
        );
        $output = shell_exec($cmd);
        
        if ($output && $output !== 'not_found' && strpos($output, 'notAfter=') !== false) {
            $dateStr = str_replace('notAfter=', '', trim($output));
            $expiryTime = strtotime($dateStr);
            $now = time();
            $daysUntilExpiry = floor(($expiryTime - $now) / 86400);
            
            if ($daysUntilExpiry <= $criticalDays) {
                $expiringCerts[] = [
                    'domain' => $site['domain'],
                    'days_remaining' => $daysUntilExpiry,
                    'level' => 'critical'
                ];
            } elseif ($daysUntilExpiry <= $warningDays) {
                $expiringCerts[] = [
                    'domain' => $site['domain'],
                    'days_remaining' => $daysUntilExpiry,
                    'level' => 'warning'
                ];
            }
        }
    }
    
    if (!empty($expiringCerts)) {
        $alertData = [
            'expiring_certificates' => $expiringCerts,
            'warning_days' => $warningDays,
            'critical_days' => $criticalDays
        ];
        return true;
    }
    
    return false;
}

/**
 * Check for backend servers down
 */
function checkServerDownAlert($db, $rule, $config, &$alertData) {
    // Get sites with health checks enabled
    $whereClause = "WHERE health_check_enabled = 1";
    if ($rule['site_id']) {
        $whereClause .= " AND id = " . (int)$rule['site_id'];
    }
    
    $stmt = $db->query("SELECT id, domain, backends FROM sites $whereClause");
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $downBackends = [];
    
    foreach ($sites as $site) {
        $backends = json_decode($site['backends'], true);
        if (empty($backends)) continue;
        
        foreach ($backends as $backend) {
            $url = $backend['url'] ?? '';
            if (empty($url)) continue;
            
            // Simple health check via curl
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 0 || $httpCode >= 500) {
                $downBackends[] = [
                    'site' => $site['domain'],
                    'backend' => $url,
                    'status_code' => $httpCode
                ];
            }
        }
    }
    
    if (!empty($downBackends)) {
        $alertData = [
            'down_backends' => $downBackends
        ];
        return true;
    }
    
    return false;
}

/**
 * Check for high error rate
 */
function checkErrorRateAlert($db, $rule, $config, &$alertData) {
    $thresholdPercent = $config['threshold_percent'] ?? 10;
    $durationMinutes = $config['duration_minutes'] ?? 5;
    $minRequests = $config['min_requests'] ?? 20;
    
    $whereClause = "WHERE timestamp > DATE_SUB(NOW(), INTERVAL $durationMinutes MINUTE)";
    if ($rule['site_id']) {
        $whereClause .= " AND site_id = " . (int)$rule['site_id'];
    }
    
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) as error_count,
            domain
        FROM request_telemetry
        $whereClause
        GROUP BY domain
        HAVING total_requests >= $minRequests
    ");
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $highErrorSites = [];
    
    foreach ($results as $result) {
        $errorRate = ($result['error_count'] / $result['total_requests']) * 100;
        if ($errorRate >= $thresholdPercent) {
            $highErrorSites[] = [
                'domain' => $result['domain'],
                'error_rate' => round($errorRate, 2),
                'error_count' => $result['error_count'],
                'total_requests' => $result['total_requests']
            ];
        }
    }
    
    if (!empty($highErrorSites)) {
        $alertData = [
            'high_error_sites' => $highErrorSites,
            'threshold_percent' => $thresholdPercent,
            'duration_minutes' => $durationMinutes
        ];
        return true;
    }
    
    return false;
}

/**
 * Check for rate limit breaches
 */
function checkRateLimitAlert($db, $rule, $config, &$alertData) {
    $thresholdBlocks = $config['threshold_blocks'] ?? 100;
    $durationMinutes = $config['duration_minutes'] ?? 5;
    
    // Check modsec events for rate limit blocks
    $whereClause = "WHERE timestamp > DATE_SUB(NOW(), INTERVAL $durationMinutes MINUTE) AND message LIKE '%rate limit%'";
    if ($rule['site_id']) {
        $whereClause .= " AND site_id = " . (int)$rule['site_id'];
    }
    
    $stmt = $db->query("
        SELECT 
            COUNT(*) as block_count,
            domain
        FROM modsec_events
        $whereClause
        GROUP BY domain
        HAVING block_count >= $thresholdBlocks
    ");
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($results)) {
        $alertData = [
            'rate_limit_breaches' => $results,
            'threshold_blocks' => $thresholdBlocks,
            'duration_minutes' => $durationMinutes
        ];
        return true;
    }
    
    return false;
}

/**
 * Fire an alert and record in history
 */
function fireAlert($db, $rule, $alertData) {
    // Check if we already fired this alert recently (prevent spam)
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM alert_history 
        WHERE alert_rule_id = ? 
        AND fired_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$rule['id']]);
    $recentAlerts = $stmt->fetch()['count'];
    
    if ($recentAlerts > 0) {
        error_log("Alert Monitor: Skipping alert {$rule['id']} - already fired recently");
        return;
    }
    
    // Insert into alert history
    $stmt = $db->prepare("
        INSERT INTO alert_history (alert_rule_id, alert_data)
        VALUES (?, ?)
    ");
    $stmt->execute([$rule['id'], json_encode($alertData)]);
    
    // Send notifications
    $webhookNotifier = new WebhookNotifier($db);
    
    switch ($rule['rule_type']) {
        case 'delay':
            foreach ($alertData['domains'] as $domain) {
                $avgTime = $domain['avg_time'];
                $threshold = $alertData['threshold_ms'] / 1000;
                $webhookNotifier->sendHighDelayAlert(
                    $domain['domain'],
                    $avgTime,
                    $threshold
                );
            }
            break;
        case 'cert_expiry':
            foreach ($alertData['expiring_certificates'] as $cert) {
                $webhookNotifier->sendCertExpiryAlert(
                    $cert['domain'],
                    $cert['days_remaining']
                );
            }
            break;
        case 'server_down':
            foreach ($alertData['down_backends'] as $backend) {
                $webhookNotifier->sendServerDownAlert(
                    $backend['backend'],
                    $backend['site']
                );
            }
            break;
    }
    
    error_log("Alert Monitor: Fired alert {$rule['id']} ({$rule['rule_name']})");
}
