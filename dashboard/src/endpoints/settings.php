<?php

function handleSettings($method, $params, $db) {
    switch ($method) {
        case 'GET':
            if (empty($params[0])) {
                // Get all settings as key-value pairs
                $stmt = $db->query("SELECT setting_key, setting_value FROM settings ORDER BY setting_key");
                $settings = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
                sendResponse(['settings' => $settings]);
            } else {
                // Get specific setting
                $stmt = $db->prepare("SELECT * FROM settings WHERE setting_key = ?");
                $stmt->execute([$params[0]]);
                $setting = $stmt->fetch();
                sendResponse(['setting' => $setting]);
            }
            break;
        
        case 'POST':
            // Update multiple settings
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data)) {
                sendResponse(['error' => 'No settings provided'], 400);
            }
            
            foreach ($data as $key => $value) {
                // Check if setting exists
                $stmt = $db->prepare("SELECT id FROM settings WHERE setting_key = ?");
                $stmt->execute([$key]);
                
                if ($stmt->fetch()) {
                    // Update existing
                    $stmt = $db->prepare("
                        UPDATE settings 
                        SET setting_value = ?, updated_at = NOW() 
                        WHERE setting_key = ?
                    ");
                    $stmt->execute([$value, $key]);
                } else {
                    // Insert new
                    $stmt = $db->prepare("
                        INSERT INTO settings (setting_key, setting_value, updated_at)
                        VALUES (?, ?, NOW())
                    ");
                    $stmt->execute([$key, $value]);
                }
                
                // Handle paranoia level update
                if ($key === 'paranoia_level') {
                    updateModSecurityParanoiaLevel($value);
                }
                
                // Handle auto-ban toggle
                if ($key === 'enable_auto_ban') {
                    controlAutoBanService($value === '1' || $value === true);
                }
            }
            
            sendResponse(['success' => true, 'message' => 'Settings updated']);
            break;
            
        case 'PUT':
            // Update single setting (upsert)
            if (empty($params[0])) {
                sendResponse(['error' => 'Setting key required'], 400);
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['value'])) {
                sendResponse(['error' => 'Value required'], 400);
            }
            
            // Use INSERT ... ON DUPLICATE KEY UPDATE for upsert behavior
            $stmt = $db->prepare("
                INSERT INTO settings (setting_key, setting_value, updated_at) 
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ");
            $stmt->execute([$params[0], $data['value']]);
            
            // Handle paranoia level update
            if ($params[0] === 'paranoia_level') {
                updateModSecurityParanoiaLevel($data['value']);
            }
            
            sendResponse(['success' => true, 'message' => 'Setting updated']);
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Update ModSecurity paranoia level
 * This updates the config file in the shared volume and signals nginx to reload
 */
function updateModSecurityParanoiaLevel($level) {
    $level = (int)$level;
    
    // Validate paranoia level (1-4)
    if ($level < 1 || $level > 4) {
        error_log("Invalid paranoia level: $level. Must be between 1 and 4.");
        return false;
    }
    
    // Path to the shared ModSecurity config (mounted in both containers)
    $configFile = '/etc/nginx/modsecurity/crs-setup-override.conf';
    
    // Create or update the override config
    $config = "# CatWAF ModSecurity Override Configuration\n";
    $config .= "# Auto-generated - DO NOT EDIT MANUALLY\n";
    $config .= "# Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $config .= "# Paranoia Level (1-4)\n";
    $config .= "SecAction \"id:900000,phase:1,nolog,pass,t:none,setvar:tx.paranoia_level=$level\"\n";
    
    // Write the config file
    $result = file_put_contents($configFile, $config);
    
    if ($result === false) {
        error_log("Failed to write ModSecurity config file: $configFile");
        return false;
    }
    
    // Signal nginx to reload by touching the reload signal file
    $reloadSignal = '/etc/nginx/sites-enabled/.reload_needed';
    touch($reloadSignal);
    
    error_log("ModSecurity paranoia level updated to $level. Reload signal sent.");
    
    return true;
}

/**
 * Control the auto-ban supervisord service
 */
function controlAutoBanService($enable) {
    $action = $enable ? 'start' : 'stop';
    
    // Use supervisorctl to control the service
    $cmd = "supervisorctl $action auto-ban-service 2>&1";
    $output = shell_exec($cmd);
    
    error_log("Auto-ban service $action: " . $output);
    
    return strpos($output, 'ERROR') === false;
}
