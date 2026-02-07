<?php
/**
 * Notifications API Endpoint
 * 
 * Handles notification configuration, testing, and history
 * 
 * Routes:
 * GET  /api/notifications              - Get notification settings
 * POST /api/notifications/test         - Test notification delivery
 * POST /api/notifications/send         - Send notification manually
 * GET  /api/notifications/history      - Get notification history
 * PUT  /api/notifications/settings     - Update notification settings
 */

require_once __DIR__ . '/../lib/WebhookNotifier.php';

function handleNotifications($method, $params, $db) {
    $action = $params[0] ?? '';
    
    switch ($method) {
        case 'GET':
            if ($action === 'history') {
                getNotificationHistory($db);
            } else {
                getNotificationSettings($db);
            }
            break;
            
        case 'POST':
            switch ($action) {
                case 'test':
                    testNotification($db);
                    break;
                case 'send':
                    sendManualNotification($db);
                    break;
                default:
                    sendResponse(['error' => 'Unknown action'], 400);
            }
            break;
            
        case 'PUT':
            if ($action === 'settings') {
                updateNotificationSettings($db);
            } else {
                sendResponse(['error' => 'Unknown action'], 400);
            }
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Get current notification settings
 */
function getNotificationSettings($db) {
    try {
        $stmt = $db->query("
            SELECT setting_key, setting_value 
            FROM settings 
            WHERE setting_key LIKE 'webhook_%' 
               OR setting_key LIKE 'discord_%' 
               OR setting_key LIKE 'notifications_%'
               OR setting_key LIKE 'email_%'
               OR setting_key LIKE 'smtp_%'
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $settings = [];
        foreach ($rows as $row) {
            // Mask sensitive values
            $key = $row['setting_key'];
            $value = $row['setting_value'];
            
            if (strpos($key, 'password') !== false || strpos($key, 'token') !== false) {
                $value = $value ? '********' : '';
            }
            
            $settings[$key] = $value;
        }
        
        // Add defaults for missing settings
        $defaults = [
            'webhook_enabled' => '0',
            'discord_webhook_url' => '',
            'notifications_critical' => '1',
            'notifications_autoban' => '1',
            'notifications_cert_expiry' => '1',
            'notifications_server_down' => '1',
            'notifications_high_delay' => '1',
            'email_enabled' => '0',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_from_email' => '',
            'smtp_from_name' => 'CatWAF'
        ];
        
        foreach ($defaults as $key => $default) {
            if (!isset($settings[$key])) {
                $settings[$key] = $default;
            }
        }
        
        sendResponse(['settings' => $settings]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to fetch notification settings: ' . $e->getMessage()], 500);
    }
}

/**
 * Test notification delivery
 */
function testNotification($db) {
    try {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $type = $data['type'] ?? 'webhook'; // webhook, email, or all
        
        $results = [];
        
        // Test webhook notification
        if ($type === 'webhook' || $type === 'all') {
            $notifier = new WebhookNotifier($db);
            
            if (!$notifier->isEnabled()) {
                $results['webhook'] = [
                    'success' => false,
                    'error' => 'Webhook notifications are not enabled or Discord URL not configured'
                ];
            } else {
                $testEvent = [
                    'timestamp' => date('Y-m-d H:i:s'),
                    'ip_address' => '127.0.0.1',
                    'domain' => 'test.example.com',
                    'uri' => '/test-notification',
                    'rule_id' => 'TEST-001',
                    'severity' => 'test',
                    'message' => 'This is a test notification from CatWAF',
                    'action' => 'test'
                ];
                
                // Use a custom test message
                $success = $notifier->sendTestNotification();
                
                $results['webhook'] = [
                    'success' => $success,
                    'message' => $success ? 'Test notification sent successfully' : 'Failed to send test notification'
                ];
            }
        }
        
        // Test email notification
        if ($type === 'email' || $type === 'all') {
            $emailResult = testEmailNotification($db);
            $results['email'] = $emailResult;
        }
        
        // Log the test
        logNotification($db, 'test', 'Test notification triggered', json_encode($results));
        
        $allSuccess = true;
        foreach ($results as $result) {
            if (!$result['success']) {
                $allSuccess = false;
                break;
            }
        }
        
        sendResponse([
            'success' => $allSuccess,
            'results' => $results
        ]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to test notification: ' . $e->getMessage()], 500);
    }
}

/**
 * Test email notification
 */
function testEmailNotification($db) {
    try {
        // Get email settings
        $stmt = $db->query("
            SELECT setting_key, setting_value 
            FROM settings 
            WHERE setting_key LIKE 'smtp_%' OR setting_key LIKE 'email_%'
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        if (empty($settings['email_enabled']) || $settings['email_enabled'] !== '1') {
            return [
                'success' => false,
                'error' => 'Email notifications are not enabled'
            ];
        }
        
        if (empty($settings['smtp_host']) || empty($settings['smtp_username'])) {
            return [
                'success' => false,
                'error' => 'SMTP configuration incomplete'
            ];
        }
        
        // TODO: Implement actual email sending with PHPMailer or similar
        // For now, return a placeholder response
        return [
            'success' => false,
            'error' => 'Email sending not yet implemented'
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Send manual notification
 */
function sendManualNotification($db) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['title']) || empty($data['message'])) {
            sendResponse(['error' => 'Title and message are required'], 400);
        }
        
        $notifier = new WebhookNotifier($db);
        
        if (!$notifier->isEnabled()) {
            sendResponse(['error' => 'Webhook notifications are not enabled'], 400);
        }
        
        $success = $notifier->sendCustomNotification(
            $data['title'],
            $data['message'],
            $data['color'] ?? 'blue',
            $data['fields'] ?? []
        );
        
        // Log the notification
        logNotification($db, 'manual', $data['title'], $data['message'], $success);
        
        sendResponse([
            'success' => $success,
            'message' => $success ? 'Notification sent' : 'Failed to send notification'
        ]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to send notification: ' . $e->getMessage()], 500);
    }
}

/**
 * Get notification history
 */
function getNotificationHistory($db) {
    try {
        $limit = min((int)($_GET['limit'] ?? 50), 200);
        $type = $_GET['type'] ?? null;
        
        $sql = "SELECT * FROM notification_history";
        $params = [];
        
        if ($type) {
            $sql .= " WHERE notification_type = ?";
            $params[] = $type;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse(['history' => $history]);
    } catch (Exception $e) {
        // Table might not exist yet
        if (strpos($e->getMessage(), "doesn't exist") !== false) {
            sendResponse(['history' => [], 'message' => 'Notification history table not yet created']);
        } else {
            sendResponse(['error' => 'Failed to fetch notification history: ' . $e->getMessage()], 500);
        }
    }
}

/**
 * Update notification settings
 */
function updateNotificationSettings($db) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data)) {
            sendResponse(['error' => 'No data provided'], 400);
        }
        
        // Allowed settings keys
        $allowedKeys = [
            'webhook_enabled', 'discord_webhook_url',
            'notifications_critical', 'notifications_autoban',
            'notifications_cert_expiry', 'notifications_server_down',
            'notifications_high_delay',
            'email_enabled', 'smtp_host', 'smtp_port',
            'smtp_username', 'smtp_password', 'smtp_from_email', 'smtp_from_name'
        ];
        
        $updated = 0;
        
        foreach ($data as $key => $value) {
            if (!in_array($key, $allowedKeys)) {
                continue;
            }
            
            // Upsert setting
            $stmt = $db->prepare("
                INSERT INTO settings (setting_key, setting_value, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
            ");
            $stmt->execute([$key, $value, $value]);
            $updated++;
        }
        
        sendResponse([
            'success' => true,
            'message' => "Updated $updated settings"
        ]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to update notification settings: ' . $e->getMessage()], 500);
    }
}

/**
 * Log a notification event
 */
function logNotification($db, $type, $title, $message, $success = true) {
    try {
        // Check if table exists, create if not
        $db->exec("
            CREATE TABLE IF NOT EXISTS notification_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                notification_type VARCHAR(50) NOT NULL,
                title VARCHAR(255),
                message TEXT,
                success TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_type (notification_type),
                INDEX idx_created (created_at)
            )
        ");
        
        $stmt = $db->prepare("
            INSERT INTO notification_history (notification_type, title, message, success)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$type, $title, $message, $success ? 1 : 0]);
    } catch (Exception $e) {
        error_log("Failed to log notification: " . $e->getMessage());
    }
}
