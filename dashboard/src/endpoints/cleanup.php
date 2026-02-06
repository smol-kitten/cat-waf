<?php
// Cleanup API - Delete old logs and telemetry data
// DELETE /api/cleanup/logs?days=30
// DELETE /api/cleanup/telemetry?days=30
// DELETE /api/cleanup/modsec?days=30
// DELETE /api/cleanup/all?days=30

global $db;

$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];

// Parse cleanup type from URI
if (preg_match('#/cleanup/(logs|telemetry|modsec|all)$#', $requestUri, $matches)) {
    $type = $matches[1];
    
    if ($method !== 'DELETE' && $method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    
    // Get days parameter (default 30)
    $days = isset($_GET['days']) ? max(1, min(365, (int)$_GET['days'])) : 30;
    
    try {
        $results = [];
        
        // Calculate cutoff date
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        if ($type === 'logs' || $type === 'all') {
            // Delete old access logs
            $stmt = $db->prepare("DELETE FROM access_logs WHERE timestamp < ?");
            $stmt->execute([$cutoff]);
            $results['access_logs_deleted'] = $stmt->rowCount();
        }
        
        if ($type === 'telemetry' || $type === 'all') {
            // Delete old telemetry data
            $stmt = $db->prepare("DELETE FROM request_telemetry WHERE timestamp < ?");
            $stmt->execute([$cutoff]);
            $results['telemetry_deleted'] = $stmt->rowCount();
        }
        
        if ($type === 'modsec' || $type === 'all') {
            // Delete old ModSecurity events
            $stmt = $db->prepare("DELETE FROM modsec_events WHERE timestamp < ?");
            $stmt->execute([$cutoff]);
            $results['modsec_events_deleted'] = $stmt->rowCount();
        }
        
        if ($type === 'all') {
            // Also clean up old bot detections
            $stmt = $db->prepare("DELETE FROM bot_detections WHERE timestamp < ?");
            $stmt->execute([$cutoff]);
            $results['bot_detections_deleted'] = $stmt->rowCount();
        }
        
        $results['cutoff_date'] = $cutoff;
        $results['days'] = $days;
        
        echo json_encode([
            'success' => true,
            'message' => "Deleted records older than {$days} days",
            'results' => $results
        ]);
        
    } catch (Exception $e) {
        error_log("Cleanup error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to clean up data',
            'details' => $e->getMessage()
        ]);
    }
    
    exit;
}

// Get cleanup statistics (how much data exists)
if (preg_match('#/cleanup/stats$#', $requestUri)) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    
    try {
        $stats = [];
        
        // Count records by age
        $tables = [
            'access_logs' => 'timestamp',
            'request_telemetry' => 'timestamp',
            'modsec_events' => 'timestamp',
            'bot_detections' => 'timestamp'
        ];
        
        foreach ($tables as $table => $timestampCol) {
            try {
                $stmt = $db->query("
                    SELECT 
                        COUNT(*) as total,
                        COUNT(CASE WHEN {$timestampCol} > DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as last_7_days,
                        COUNT(CASE WHEN {$timestampCol} > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as last_30_days,
                        COUNT(CASE WHEN {$timestampCol} < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as older_than_30_days,
                        COUNT(CASE WHEN {$timestampCol} < DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 1 END) as older_than_90_days
                    FROM {$table}
                ");
                $stats[$table] = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // Table doesn't exist, skip it
                error_log("Stats query skipped for {$table}: " . $e->getMessage());
                $stats[$table] = [
                    'total' => 0,
                    'last_7_days' => 0,
                    'last_30_days' => 0,
                    'older_than_30_days' => 0,
                    'older_than_90_days' => 0
                ];
            }
        }
        
        echo json_encode([
            'success' => true,
            'stats' => $stats
        ]);
        
    } catch (Exception $e) {
        error_log("Cleanup stats error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to get cleanup statistics',
            'details' => $e->getMessage()
        ]);
    }
    
    exit;
}

// Cleanup orphaned site configs
// POST /api/cleanup/orphan-configs - Find and optionally remove orphaned configs
if (preg_match('#/cleanup/orphan-configs$#', $requestUri)) {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    
    try {
        // Get all site domains from database
        $stmt = $db->query("SELECT domain FROM sites");
        $dbDomains = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get all nginx config files
        $configDir = '/etc/nginx/sites-enabled';
        $nginxConfigs = [];
        
        // Use docker exec to list config files in nginx container
        $listCmd = "docker exec waf-nginx sh -c 'ls -1 /etc/nginx/sites-enabled/*.conf 2>/dev/null || true'";
        exec($listCmd, $configFiles);
        
        foreach ($configFiles as $configPath) {
            $filename = basename($configPath, '.conf');
            // Skip special configs
            if ($filename !== 'default' && $filename !== '_' && 
                $filename !== 'emergency-fallback' && !empty($filename)) {
                $nginxConfigs[] = $filename;
            }
        }
        
        // Find orphans (configs without database entry)
        $orphans = array_diff($nginxConfigs, $dbDomains);
        $orphansList = array_values($orphans);
        
        // Check if we should remove them
        $remove = isset($_GET['remove']) && $_GET['remove'] === 'true';
        $removed = [];
        
        if ($remove && count($orphansList) > 0) {
            foreach ($orphansList as $orphan) {
                // Validate domain name to prevent command injection
                if (!preg_match('/^[a-z0-9.-]+$/i', $orphan)) {
                    error_log("Skipping invalid orphan config name: {$orphan}");
                    continue;
                }
                
                // Move to quarantine instead of deleting
                $quarantineCmd = sprintf(
                    "docker exec waf-nginx sh -c 'mkdir -p /etc/nginx/sites-quarantine && mv /etc/nginx/sites-enabled/%s.conf /etc/nginx/sites-quarantine/%s.conf 2>&1'",
                    escapeshellarg($orphan),
                    escapeshellarg($orphan)
                );
                exec($quarantineCmd, $output, $returnCode);
                
                if ($returnCode === 0) {
                    $removed[] = $orphan;
                    error_log("Quarantined orphan config: {$orphan}.conf");
                }
            }
            
            // Reload nginx after cleanup
            if (count($removed) > 0) {
                exec("docker exec waf-nginx nginx -t 2>&1", $testOutput, $testReturn);
                if ($testReturn === 0) {
                    exec("docker exec waf-nginx nginx -s reload 2>&1");
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'orphans_found' => count($orphansList),
            'orphans' => $orphansList,
            'removed' => $removed,
            'message' => $remove 
                ? sprintf('Quarantined %d orphaned config(s)', count($removed))
                : sprintf('Found %d orphaned config(s). Add ?remove=true to quarantine them.', count($orphansList))
        ]);
        
    } catch (Exception $e) {
        error_log("Orphan config cleanup error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to clean up orphan configs',
            'details' => $e->getMessage()
        ]);
    }
    
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Endpoint not found']);
