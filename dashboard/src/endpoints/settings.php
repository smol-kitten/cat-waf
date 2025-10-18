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
            }
            
            sendResponse(['success' => true, 'message' => 'Settings updated']);
            break;
            
        case 'PUT':
            // Update single setting
            if (empty($params[0])) {
                sendResponse(['error' => 'Setting key required'], 400);
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['value'])) {
                sendResponse(['error' => 'Value required'], 400);
            }
            
            $stmt = $db->prepare("
                UPDATE settings 
                SET setting_value = ?, updated_at = NOW() 
                WHERE setting_key = ?
            ");
            $stmt->execute([$data['value'], $params[0]]);
            
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
 * Update ModSecurity paranoia level in the nginx container
 */
function updateModSecurityParanoiaLevel($level) {
    $level = (int)$level;
    
    // Validate paranoia level (1-4)
    if ($level < 1 || $level > 4) {
        error_log("Invalid paranoia level: $level. Must be between 1 and 4.");
        return false;
    }
    
    $configFile = '/etc/nginx/modsecurity/coreruleset/crs-setup.conf';
    
    // Read current config
    $dockerExec = "docker exec waf-nginx sh -c";
    $readCmd = "$dockerExec \"cat $configFile\"";
    $config = shell_exec($readCmd);
    
    if (!$config) {
        error_log("Failed to read ModSecurity config file");
        return false;
    }
    
    // Update paranoia level using regex
    $pattern = '/setvar:tx\.paranoia_level=\d+/';
    $replacement = "setvar:tx.paranoia_level=$level";
    
    if (preg_match($pattern, $config)) {
        // Replace existing paranoia level
        $newConfig = preg_replace($pattern, $replacement, $config);
    } else {
        // Paranoia level not found, append it
        $newConfig = $config . "\n\n# Set paranoia level\n";
        $newConfig .= "SecAction \"id:900000,phase:1,nolog,pass,t:none,setvar:tx.paranoia_level=$level\"\n";
    }
    
    // Write updated config back
    $escapedConfig = addslashes($newConfig);
    $writeCmd = "$dockerExec \"echo '$escapedConfig' > $configFile\"";
    shell_exec($writeCmd);
    
    // Reload nginx to apply changes
    $reloadCmd = "$dockerExec \"nginx -s reload\"";
    $output = shell_exec($reloadCmd . " 2>&1");
    
    error_log("ModSecurity paranoia level updated to $level. Nginx reload output: " . $output);
    
    return true;
}
