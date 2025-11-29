<?php
/**
 * Telemetry Data Collector
 * Collects metrics from WAF and submits to telemetry server
 */

class TelemetryCollector {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Collect and submit usage metrics
     */
    public function collectAndSubmitUsage($config) {
        $metrics = $this->collectUsageMetrics();
        return $this->submitToTelemetry($config, 'usage', $metrics);
    }
    
    /**
     * Collect usage metrics
     */
    public function collectUsageMetrics() {
        $periodStart = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $periodEnd = date('Y-m-d H:i:s');
        
        // Overall statistics
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(DISTINCT domain) as unique_domains,
                COUNT(*) as total_requests,
                SUM(bytes_sent) as total_bytes_transferred,
                AVG(bytes_sent) as avg_request_size,
                COUNT(DISTINCT ip_address) as unique_ips,
                AVG(response_time) as avg_response_time
            FROM request_telemetry
            WHERE timestamp >= ? AND timestamp <= ?
        ");
        $stmt->execute([$periodStart, $periodEnd]);
        $stats = $stmt->fetch();
        
        // Status code breakdown
        $stmt = $this->db->prepare("
            SELECT
                SUM(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 ELSE 0 END) as status_2xx_count,
                SUM(CASE WHEN status_code >= 300 AND status_code < 400 THEN 1 ELSE 0 END) as status_3xx_count,
                SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END) as status_4xx_count,
                SUM(CASE WHEN status_code >= 500 AND status_code < 600 THEN 1 ELSE 0 END) as status_5xx_count
            FROM request_telemetry
            WHERE timestamp >= ? AND timestamp <= ?
        ");
        $stmt->execute([$periodStart, $periodEnd]);
        $statusCounts = $stmt->fetch();
        
        // Site counts
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as site_count,
                SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) as active_site_count
            FROM sites
        ");
        $siteCounts = $stmt->fetch();
        
        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'site_count' => $siteCounts['site_count'] ?? 0,
            'active_site_count' => $siteCounts['active_site_count'] ?? 0,
            'total_requests' => $stats['total_requests'] ?? 0,
            'total_bytes_transferred' => $stats['total_bytes_transferred'] ?? 0,
            'avg_request_size' => round($stats['avg_request_size'] ?? 0),
            'unique_ips' => $stats['unique_ips'] ?? 0,
            'unique_domains' => $stats['unique_domains'] ?? 0,
            'status_2xx_count' => $statusCounts['status_2xx_count'] ?? 0,
            'status_3xx_count' => $statusCounts['status_3xx_count'] ?? 0,
            'status_4xx_count' => $statusCounts['status_4xx_count'] ?? 0,
            'status_5xx_count' => $statusCounts['status_5xx_count'] ?? 0,
            'avg_response_time' => round($stats['avg_response_time'] ?? 0, 2)
        ];
    }
    
    /**
     * Collect and submit settings metrics
     */
    public function collectAndSubmitSettings($config) {
        $metrics = $this->collectSettingsMetrics();
        return $this->submitToTelemetry($config, 'settings', ['settings' => $metrics]);
    }
    
    /**
     * Collect settings metrics
     */
    public function collectSettingsMetrics() {
        $settings = [];
        
        // Security features - only include global rules (site_id IS NULL)
        $stmt = $this->db->query("SELECT rule_type, enabled, config FROM security_rules WHERE site_id IS NULL");
        $securityRules = $stmt->fetchAll();
        foreach ($securityRules as $rule) {
            $settings[] = [
                'feature_name' => 'security_' . $rule['rule_type'],
                'enabled' => $rule['enabled'],
                'value' => json_decode($rule['config'], true)
            ];
        }
        
        // Site features
        $stmt = $this->db->query("
            SELECT 
                SUM(CASE WHEN disable_http_redirect = 0 AND ssl_enabled = 1 THEN 1 ELSE 0 END) as https_redirect_count,
                SUM(CASE WHEN ssl_enabled = 1 THEN 1 ELSE 0 END) as force_ssl_count,
                SUM(CASE WHEN cf_bypass_ratelimit = 1 THEN 1 ELSE 0 END) as cf_bypass_count
            FROM sites
        ");
        $sites = $stmt->fetch();
        
        $settings[] = ['feature_name' => 'https_redirect', 'enabled' => $sites['https_redirect_count'] > 0, 'value' => $sites['https_redirect_count']];
        $settings[] = ['feature_name' => 'force_ssl', 'enabled' => $sites['force_ssl_count'] > 0, 'value' => $sites['force_ssl_count']];
        $settings[] = ['feature_name' => 'cloudflare_bypass', 'enabled' => $sites['cf_bypass_count'] > 0, 'value' => $sites['cf_bypass_count']];
        
        return $settings;
    }
    
    /**
     * Collect and submit system metrics
     */
    public function collectAndSubmitSystem($config) {
        $metrics = $this->collectSystemMetrics();
        return $this->submitToTelemetry($config, 'system', $metrics);
    }
    
    /**
     * Collect system metrics
     */
    public function collectSystemMetrics() {
        // Docker container stats
        $containerCount = 0;
        $runningCount = 0;
        
        try {
            exec('docker ps -a --format "{{.ID}}" 2>/dev/null', $allContainers);
            exec('docker ps --format "{{.ID}}" 2>/dev/null', $runningContainers);
            $containerCount = count($allContainers);
            $runningCount = count($runningContainers);
        } catch (Exception $e) {
            // Docker not available
        }
        
        // System resources (if available)
        $cpuUsage = 0;
        $memoryUsed = 0;
        $memoryTotal = 0;
        $diskUsed = 0;
        $diskTotal = 0;
        
        if (PHP_OS_FAMILY === 'Linux') {
            // Get memory info
            $memInfo = @file_get_contents('/proc/meminfo');
            if ($memInfo) {
                preg_match('/MemTotal:\s+(\d+)/', $memInfo, $totalMatch);
                preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $availMatch);
                if ($totalMatch && $availMatch) {
                    $memoryTotal = round($totalMatch[1] / 1024); // MB
                    $memoryUsed = round(($totalMatch[1] - $availMatch[1]) / 1024); // MB
                }
            }
            
            // Get disk info
            $diskTotal = round(disk_total_space('/') / (1024 * 1024 * 1024), 2); // GB
            $diskUsed = round(($diskTotal - (disk_free_space('/') / (1024 * 1024 * 1024))), 2); // GB
        }
        
        return [
            'container_count' => $containerCount,
            'running_container_count' => $runningCount,
            'cpu_usage_percent' => $cpuUsage,
            'memory_used_mb' => $memoryUsed,
            'memory_total_mb' => $memoryTotal,
            'disk_used_gb' => $diskUsed,
            'disk_total_gb' => $diskTotal,
            'network_rx_bytes' => 0, // Could be collected from /sys/class/net
            'network_tx_bytes' => 0
        ];
    }
    
    /**
     * Collect and submit security metrics
     */
    public function collectAndSubmitSecurity($config) {
        $metrics = $this->collectSecurityMetrics();
        
        // Include 404 collection if enabled
        $collected404s = [];
        if ($config['collect_404_paths']) {
            $collected404s = $this->collect404Paths($config);
        }
        
        return $this->submitToTelemetry($config, 'security', array_merge($metrics, ['collected_404s' => $collected404s]));
    }
    
    /**
     * Collect security metrics
     */
    public function collectSecurityMetrics() {
        $periodStart = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $periodEnd = date('Y-m-d H:i:s');
        
        // Backend stats from sites table (backends is JSON column, not separate table)
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as site_count,
                SUM(CASE WHEN backends IS NOT NULL AND JSON_VALID(backends) THEN JSON_LENGTH(backends) ELSE 1 END) as backend_count
            FROM sites
            WHERE enabled = 1
        ");
        $backendStats = $stmt->fetch();
        
        // Ban statistics
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total_bans,
                SUM(CASE WHEN expires_at > NOW() OR is_permanent = 1 THEN 1 ELSE 0 END) as active_bans
            FROM banned_ips
        ");
        $banStats = $stmt->fetch();
        
        // Scanner detections
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as scanner_detections
            FROM scanner_detections
            WHERE last_seen >= ?
        ");
        $stmt->execute([$periodStart]);
        $scannerStats = $stmt->fetch();
        
        // Blocked requests (from request_telemetry)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as blocked_requests
            FROM request_telemetry
            WHERE status_code = 403 AND timestamp >= ?
        ");
        $stmt->execute([$periodStart]);
        $blockedRequests = $stmt->fetch();
        
        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'backend_count' => $backendStats['backend_count'] ?? 0,
            'site_count' => $backendStats['site_count'] ?? 0,
            'total_bans' => $banStats['total_bans'] ?? 0,
            'active_bans' => $banStats['active_bans'] ?? 0,
            'scanner_detections' => $scannerStats['scanner_detections'] ?? 0,
            'blocked_requests' => $blockedRequests['blocked_requests'] ?? 0,
            'rate_limit_hits' => 0 // Could be tracked separately
        ];
    }
    
    /**
     * Collect 404 paths for honeypot
     */
    private function collect404Paths($config) {
        $minHits = $config['min_404_hits'] ?? 5;
        
        $stmt = $this->db->prepare("
            SELECT domain, uri as path, COUNT(*) as count
            FROM request_telemetry
            WHERE status_code = 404
              AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY domain, uri
            HAVING count >= ?
            ORDER BY count DESC
            LIMIT 1000
        ");
        $stmt->execute([$minHits]);
        $paths = $stmt->fetchAll();
        
        return $paths;
    }
    
    /**
     * Submit data to telemetry server
     */
    private function submitToTelemetry($config, $category, $data) {
        $endpoint = $config['telemetry_endpoint'];
        
        // Use path-based routing (SSL friendly - no multi-level subdomains needed)
        // Format: telemetry.domain.tld/module/submit
        // This works with standard wildcard certificates (*.domain.tld)
        // 
        // Legacy subdomain routing still supported but discouraged due to SSL cert issues
        // (catwaf.telemetry.domain.tld requires *.*.domain.tld certificate)
        $endpoint = rtrim($endpoint, '/');
        
        // Map legacy categories to module names
        $moduleMap = [
            'usage' => 'catwaf',      // WAF usage metrics
            'settings' => 'catwaf',   // WAF settings
            'system' => 'catwaf',     // System metrics
            'security' => 'catwaf'    // Security metrics
        ];
        
        $module = $moduleMap[$category] ?? 'general';
        $url = "$endpoint/$module/submit";
        
        $payload = [
            'system_uuid' => $config['system_uuid'],
            'system_name' => 'CAT-WAF',
            'version' => '1.0',
            'category' => $category,
            'metrics' => $data
        ];
        
        // Calculate hash to prevent duplicates
        $dataHash = hash('sha256', json_encode($payload));
        
        // Check if already submitted
        $stmt = $this->db->prepare("
            SELECT id FROM telemetry_submissions 
            WHERE data_hash = ? AND submitted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$dataHash]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            return ['status' => 'skipped', 'reason' => 'Already submitted recently'];
        }
        
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-System-UUID: ' . $config['system_uuid'],
                    'X-API-Key: ' . ($config['api_key'] ?? '')
                ],
                CURLOPT_TIMEOUT => 30
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            // Log submission
            $stmt = $this->db->prepare("
                INSERT INTO telemetry_submissions (category, status, response_code, data_hash)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $category,
                $httpCode === 200 ? 'success' : 'failed',
                $httpCode,
                $dataHash
            ]);
            
            if ($httpCode === 200) {
                return ['status' => 'success', 'response' => json_decode($response, true)];
            } else {
                return ['status' => 'failed', 'code' => $httpCode, 'response' => $response];
            }
        } catch (Exception $e) {
            $stmt = $this->db->prepare("
                INSERT INTO telemetry_submissions (category, status, error_message, data_hash)
                VALUES (?, 'failed', ?, ?)
            ");
            $stmt->execute([$category, $e->getMessage(), $dataHash]);
            
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
