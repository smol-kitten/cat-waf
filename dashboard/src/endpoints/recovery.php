<?php

/**
 * Config Recovery API
 * 
 * Handles recovery from broken configurations
 * 
 * Endpoints:
 * - GET /api/recovery/quarantined - List quarantined configs
 * - POST /api/recovery/restore/:domain - Restore a quarantined config
 * - DELETE /api/recovery/quarantine/:domain - Permanently delete a quarantined config
 * - GET /api/recovery/logs - View quarantine logs
 */

function handleRecovery($method, $params, $db) {
    $action = $params[0] ?? '';
    
    switch ($action) {
        case 'quarantined':
            if ($method !== 'GET') {
                sendResponse(['error' => 'Method not allowed'], 405);
            }
            
            // List quarantined configs
            $quarantineDir = '/etc/nginx/sites-quarantine';
            $quarantined = [];
            
            if (is_dir($quarantineDir)) {
                $files = scandir($quarantineDir);
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..' && substr($file, -5) === '.conf') {
                        $domain = basename($file, '.conf');
                        $filePath = $quarantineDir . '/' . $file;
                        
                        $quarantined[] = [
                            'domain' => $domain,
                            'filename' => $file,
                            'size' => filesize($filePath),
                            'quarantined_at' => filemtime($filePath),
                            'quarantined_date' => date('Y-m-d H:i:s', filemtime($filePath))
                        ];
                    }
                }
            }
            
            sendResponse([
                'quarantined_count' => count($quarantined),
                'configs' => $quarantined
            ]);
            break;
            
        case 'restore':
            if ($method !== 'POST') {
                sendResponse(['error' => 'Method not allowed'], 405);
            }
            
            $domain = $params[1] ?? '';
            if (empty($domain)) {
                sendResponse(['error' => 'Domain required'], 400);
            }
            
            $quarantineFile = "/etc/nginx/sites-quarantine/{$domain}.conf";
            $targetFile = "/etc/nginx/sites-enabled/{$domain}.conf";
            
            if (!file_exists($quarantineFile)) {
                sendResponse(['error' => 'Quarantined config not found'], 404);
            }
            
            // Test the config before restoring
            $testResult = testQuarantinedConfig($domain);
            
            if (!$testResult['valid']) {
                sendResponse([
                    'error' => 'Config is still invalid',
                    'test_output' => $testResult['output']
                ], 400);
            }
            
            // Move back to sites-enabled
            if (!rename($quarantineFile, $targetFile)) {
                sendResponse(['error' => 'Failed to restore config'], 500);
            }
            
            // Reload nginx
            exec('docker exec waf-nginx nginx -s reload 2>&1', $output, $return);
            
            if ($return !== 0) {
                // Rollback if reload fails
                rename($targetFile, $quarantineFile);
                sendResponse([
                    'error' => 'Nginx reload failed, config re-quarantined',
                    'output' => implode("\n", $output)
                ], 500);
            }
            
            sendResponse([
                'success' => true,
                'message' => "Config for {$domain} restored successfully"
            ]);
            break;
            
        case 'quarantine':
            if ($method !== 'DELETE') {
                sendResponse(['error' => 'Method not allowed'], 405);
            }
            
            $domain = $params[1] ?? '';
            if (empty($domain)) {
                sendResponse(['error' => 'Domain required'], 400);
            }
            
            $quarantineFile = "/etc/nginx/sites-quarantine/{$domain}.conf";
            
            if (!file_exists($quarantineFile)) {
                sendResponse(['error' => 'Quarantined config not found'], 404);
            }
            
            if (!unlink($quarantineFile)) {
                sendResponse(['error' => 'Failed to delete config'], 500);
            }
            
            sendResponse([
                'success' => true,
                'message' => "Quarantined config for {$domain} deleted permanently"
            ]);
            break;
            
        case 'logs':
            if ($method !== 'GET') {
                sendResponse(['error' => 'Method not allowed'], 405);
            }
            
            $logFile = '/var/log/nginx/quarantine.log';
            $lines = (int)($_GET['lines'] ?? 100);
            
            if (!file_exists($logFile)) {
                sendResponse([
                    'logs' => '',
                    'message' => 'No quarantine events recorded'
                ]);
            }
            
            // Read last N lines
            exec("tail -n {$lines} {$logFile} 2>&1", $output);
            
            sendResponse([
                'logs' => implode("\n", $output),
                'lines' => count($output)
            ]);
            break;
            
        case 'emergency-status':
            if ($method !== 'GET') {
                sendResponse(['error' => 'Method not allowed'], 405);
            }
            
            // Check if emergency fallback is active
            $emergencyFile = '/etc/nginx/sites-enabled/emergency-fallback.conf';
            $quarantineDir = '/etc/nginx/sites-quarantine';
            
            $isEmergency = file_exists($emergencyFile);
            $quarantinedCount = 0;
            
            if (is_dir($quarantineDir)) {
                $files = scandir($quarantineDir);
                $quarantinedCount = count(array_filter($files, function($f) {
                    return substr($f, -5) === '.conf';
                }));
            }
            
            sendResponse([
                'emergency_mode' => $isEmergency,
                'quarantined_configs' => $quarantinedCount,
                'status' => $isEmergency ? 'emergency' : 'normal'
            ]);
            break;
            
        default:
            sendResponse(['error' => 'Invalid action'], 400);
    }
}

/**
 * Test a quarantined config in isolation
 */
function testQuarantinedConfig($domain) {
    $quarantineFile = "/etc/nginx/sites-quarantine/{$domain}.conf";
    
    if (!file_exists($quarantineFile)) {
        return ['valid' => false, 'output' => 'Config file not found'];
    }
    
    // Create temporary test config
    $testConfig = "/tmp/test-{$domain}-" . uniqid() . ".conf";
    
    $nginxTest = <<<TESTCONF
user nginx;
worker_processes 1;
error_log /dev/null;
pid /tmp/nginx-test-{$domain}.pid;
events { worker_connections 1024; }
http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    access_log /dev/null;
    include /etc/nginx/conf.d/*.conf;
    include {$quarantineFile};
}
TESTCONF;
    
    file_put_contents($testConfig, $nginxTest);
    
    // Run nginx -t
    exec("docker exec waf-nginx nginx -t -c {$testConfig} 2>&1", $output, $return);
    
    // Cleanup
    unlink($testConfig);
    exec("docker exec waf-nginx rm -f /tmp/nginx-test-{$domain}.pid 2>&1");
    
    return [
        'valid' => $return === 0,
        'output' => implode("\n", $output)
    ];
}
