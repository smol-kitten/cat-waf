<?php
/**
 * Webhook Notification Service
 * Sends notifications to Discord and other webhook endpoints
 */

class WebhookNotifier {
    private $db;
    private $settings;
    
    public function __construct($db) {
        $this->db = $db;
        $this->loadSettings();
    }
    
    /**
     * Load webhook settings from database
     */
    private function loadSettings() {
        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'webhook_%' OR setting_key LIKE 'discord_%' OR setting_key LIKE 'notifications_%'");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->settings = [];
            foreach ($rows as $row) {
                $this->settings[$row['setting_key']] = $row['setting_value'];
            }
            
            // Default values
            $this->settings['webhook_enabled'] = $this->settings['webhook_enabled'] ?? '0';
            $this->settings['discord_webhook_url'] = $this->settings['discord_webhook_url'] ?? '';
            $this->settings['notifications_critical'] = $this->settings['notifications_critical'] ?? '1';
            $this->settings['notifications_autoban'] = $this->settings['notifications_autoban'] ?? '1';
            $this->settings['notifications_cert_expiry'] = $this->settings['notifications_cert_expiry'] ?? '1';
            $this->settings['notifications_server_down'] = $this->settings['notifications_server_down'] ?? '1';
            $this->settings['notifications_high_delay'] = $this->settings['notifications_high_delay'] ?? '1';
            
        } catch (Exception $e) {
            error_log("Failed to load webhook settings: " . $e->getMessage());
            $this->settings = ['webhook_enabled' => '0'];
        }
    }
    
    /**
     * Check if webhook notifications are enabled
     */
    public function isEnabled() {
        return $this->settings['webhook_enabled'] === '1' && 
               !empty($this->settings['discord_webhook_url']);
    }
    
    /**
     * Send a critical security event alert
     */
    public function sendCriticalSecurityAlert($event) {
        if (!$this->isEnabled() || $this->settings['notifications_critical'] !== '1') {
            return false;
        }
        
        $embed = [
            'title' => '🚨 Critical Security Event Detected',
            'color' => 15548997, // Red color
            'fields' => [
                ['name' => 'Time', 'value' => $event['timestamp'], 'inline' => true],
                ['name' => 'IP Address', 'value' => $event['ip_address'], 'inline' => true],
                ['name' => 'Domain', 'value' => $event['domain'], 'inline' => false],
                ['name' => 'URI', 'value' => substr($event['uri'], 0, 100), 'inline' => false],
                ['name' => 'Rule ID', 'value' => $event['rule_id'], 'inline' => true],
                ['name' => 'Severity', 'value' => strtoupper($event['severity']), 'inline' => true],
                ['name' => 'Message', 'value' => substr($event['message'], 0, 200), 'inline' => false],
                ['name' => 'Action', 'value' => $event['action'], 'inline' => true]
            ],
            'timestamp' => date('c')
        ];
        
        return $this->sendDiscordEmbed($embed);
    }
    
    /**
     * Send auto-ban notification
     */
    public function sendAutoBanAlert($ip, $reason, $blockCount) {
        if (!$this->isEnabled() || $this->settings['notifications_autoban'] !== '1') {
            return false;
        }
        
        $embed = [
            'title' => '🔒 IP Address Auto-Banned',
            'color' => 15105570, // Orange color
            'fields' => [
                ['name' => 'IP Address', 'value' => $ip, 'inline' => true],
                ['name' => 'Block Count', 'value' => (string)$blockCount, 'inline' => true],
                ['name' => 'Reason', 'value' => $reason, 'inline' => false],
                ['name' => 'Ban Duration', 'value' => '1 hour', 'inline' => true],
                ['name' => 'Time', 'value' => date('Y-m-d H:i:s'), 'inline' => true]
            ],
            'timestamp' => date('c')
        ];
        
        return $this->sendDiscordEmbed($embed);
    }
    
    /**
     * Send certificate expiry warning
     */
    public function sendCertExpiryAlert($domain, $daysRemaining) {
        if (!$this->isEnabled() || $this->settings['notifications_cert_expiry'] !== '1') {
            return false;
        }
        
        $urgency = $daysRemaining <= 7 ? 'URGENT' : 'WARNING';
        $color = $daysRemaining <= 7 ? 15548997 : 15105570; // Red or Orange
        
        $embed = [
            'title' => "⚠️ {$urgency}: SSL Certificate Expiring Soon",
            'color' => $color,
            'fields' => [
                ['name' => 'Domain', 'value' => $domain, 'inline' => false],
                ['name' => 'Days Remaining', 'value' => "{$daysRemaining} days", 'inline' => true],
                ['name' => 'Action Required', 'value' => 'Please renew the SSL certificate before it expires.', 'inline' => false]
            ],
            'timestamp' => date('c')
        ];
        
        return $this->sendDiscordEmbed($embed);
    }
    
    /**
     * Send server down alert
     */
    public function sendServerDownAlert($backend, $site) {
        if (!$this->isEnabled() || $this->settings['notifications_server_down'] !== '1') {
            return false;
        }
        
        $embed = [
            'title' => '❌ Backend Server Down',
            'color' => 15548997, // Red color
            'fields' => [
                ['name' => 'Site', 'value' => $site, 'inline' => true],
                ['name' => 'Backend', 'value' => $backend, 'inline' => true],
                ['name' => 'Status', 'value' => 'Unreachable', 'inline' => true],
                ['name' => 'Time', 'value' => date('Y-m-d H:i:s'), 'inline' => true]
            ],
            'timestamp' => date('c')
        ];
        
        return $this->sendDiscordEmbed($embed);
    }
    
    /**
     * Send high delay alert
     */
    public function sendHighDelayAlert($site, $avgResponseTime, $threshold) {
        if (!$this->isEnabled() || $this->settings['notifications_high_delay'] !== '1') {
            return false;
        }
        
        $embed = [
            'title' => '⏱️ High Response Time Detected',
            'color' => 16776960, // Yellow color
            'fields' => [
                ['name' => 'Site', 'value' => $site, 'inline' => true],
                ['name' => 'Average Response Time', 'value' => round($avgResponseTime * 1000, 1) . ' ms', 'inline' => true],
                ['name' => 'Threshold', 'value' => round($threshold * 1000, 1) . ' ms', 'inline' => true],
                ['name' => 'Time', 'value' => date('Y-m-d H:i:s'), 'inline' => false]
            ],
            'timestamp' => date('c')
        ];
        
        return $this->sendDiscordEmbed($embed);
    }
    
    /**
     * Send Discord webhook with embed
     */
    private function sendDiscordEmbed($embed) {
        $webhookUrl = $this->settings['discord_webhook_url'];
        
        if (empty($webhookUrl)) {
            return false;
        }
        
        $payload = [
            'username' => 'CatWAF Monitor',
            'avatar_url' => 'https://raw.githubusercontent.com/twitter/twemoji/master/assets/72x72/1f431.png',
            'embeds' => [$embed]
        ];
        
        return $this->sendWebhook($webhookUrl, $payload);
    }
    
    /**
     * Send webhook POST request
     */
    private function sendWebhook($url, $payload) {
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode >= 200 && $httpCode < 300) {
                error_log("Webhook sent successfully to {$url}");
                return true;
            } else {
                error_log("Failed to send webhook to {$url}: HTTP {$httpCode}");
                return false;
            }
            
        } catch (Exception $e) {
            error_log("Webhook send error: " . $e->getMessage());
            return false;
        }
    }
}
