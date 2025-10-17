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
            
            sendResponse(['success' => true, 'message' => 'Setting updated']);
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}
