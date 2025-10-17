<?php
/**
 * Email Notification Service
 * Sends email alerts for critical security events
 */

class EmailNotifier {
    private $db;
    private $settings;
    
    public function __construct($db) {
        $this->db = $db;
        $this->loadSettings();
    }
    
    /**
     * Load email settings from database
     */
    private function loadSettings() {
        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'email_%'");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->settings = [];
            foreach ($rows as $row) {
                $this->settings[$row['setting_key']] = $row['setting_value'];
            }
            
            // Default values
            $this->settings['email_enabled'] = $this->settings['email_enabled'] ?? '0';
            $this->settings['email_smtp_host'] = $this->settings['email_smtp_host'] ?? '';
            $this->settings['email_smtp_port'] = $this->settings['email_smtp_port'] ?? '587';
            $this->settings['email_smtp_user'] = $this->settings['email_smtp_user'] ?? '';
            $this->settings['email_smtp_pass'] = $this->settings['email_smtp_pass'] ?? '';
            $this->settings['email_from'] = $this->settings['email_from'] ?? 'waf@catboy.systems';
            $this->settings['email_to'] = $this->settings['email_to'] ?? '';
            $this->settings['email_alert_critical'] = $this->settings['email_alert_critical'] ?? '1';
            $this->settings['email_alert_autoban'] = $this->settings['email_alert_autoban'] ?? '1';
            $this->settings['email_alert_cert_expiry'] = $this->settings['email_alert_cert_expiry'] ?? '1';
            
        } catch (Exception $e) {
            error_log("Failed to load email settings: " . $e->getMessage());
            $this->settings = ['email_enabled' => '0'];
        }
    }
    
    /**
     * Check if email notifications are enabled
     */
    public function isEnabled() {
        return $this->settings['email_enabled'] === '1' && 
               !empty($this->settings['email_smtp_host']) && 
               !empty($this->settings['email_to']);
    }
    
    /**
     * Send a critical security event alert
     */
    public function sendCriticalSecurityAlert($event) {
        if (!$this->isEnabled() || $this->settings['email_alert_critical'] !== '1') {
            return false;
        }
        
        $subject = "🚨 Critical Security Event Detected";
        $body = $this->generateSecurityAlertBody($event);
        
        return $this->send($subject, $body);
    }
    
    /**
     * Send auto-ban notification
     */
    public function sendAutoBanAlert($ip, $reason, $blockCount) {
        if (!$this->isEnabled() || $this->settings['email_alert_autoban'] !== '1') {
            return false;
        }
        
        $subject = "🔒 IP Auto-Banned: {$ip}";
        $body = $this->generateAutoBanBody($ip, $reason, $blockCount);
        
        return $this->send($subject, $body);
    }
    
    /**
     * Send certificate expiry warning
     */
    public function sendCertExpiryAlert($domain, $daysRemaining) {
        if (!$this->isEnabled() || $this->settings['email_alert_cert_expiry'] !== '1') {
            return false;
        }
        
        $subject = "⚠️ SSL Certificate Expiring Soon: {$domain}";
        $body = $this->generateCertExpiryBody($domain, $daysRemaining);
        
        return $this->send($subject, $body);
    }
    
    /**
     * Generate HTML body for security alert
     */
    private function generateSecurityAlertBody($event) {
        $html = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #dc2626; color: white; padding: 15px; border-radius: 5px; }
                .content { background: #f8fafc; padding: 20px; margin: 20px 0; border-radius: 5px; }
                .detail { margin: 10px 0; }
                .label { font-weight: bold; color: #475569; }
                .value { color: #1e293b; }
                .footer { color: #64748b; font-size: 0.875rem; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>🚨 Critical Security Event Detected</h2>
                </div>
                <div class='content'>
                    <div class='detail'>
                        <span class='label'>Time:</span> 
                        <span class='value'>{$event['timestamp']}</span>
                    </div>
                    <div class='detail'>
                        <span class='label'>IP Address:</span> 
                        <span class='value'>{$event['ip_address']}</span>
                    </div>
                    <div class='detail'>
                        <span class='label'>Domain:</span> 
                        <span class='value'>{$event['domain']}</span>
                    </div>
                    <div class='detail'>
                        <span class='label'>URI:</span> 
                        <span class='value'>{$event['uri']}</span>
                    </div>
                    <div class='detail'>
                        <span class='label'>Rule ID:</span> 
                        <span class='value'>{$event['rule_id']}</span>
                    </div>
                    <div class='detail'>
                        <span class='label'>Severity:</span> 
                        <span class='value' style='color: #dc2626; font-weight: bold;'>{$event['severity']}</span>
                    </div>
                    <div class='detail'>
                        <span class='label'>Message:</span> 
                        <span class='value'>{$event['message']}</span>
                    </div>
                    <div class='detail'>
                        <span class='label'>Action:</span> 
                        <span class='value'>{$event['action']}</span>
                    </div>
                </div>
                <div class='footer'>
                    This is an automated alert from your WAF Security System.
                </div>
            </div>
        </body>
        </html>
        ";
        
        return $html;
    }
    
    /**
     * Generate HTML body for auto-ban alert
     */
    private function generateAutoBanBody($ip, $reason, $blockCount) {
        return "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #ea580c; color: white; padding: 15px; border-radius: 5px; }
                .content { background: #f8fafc; padding: 20px; margin: 20px 0; border-radius: 5px; }
                .detail { margin: 10px 0; }
                .label { font-weight: bold; color: #475569; }
                .value { color: #1e293b; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>🔒 IP Address Auto-Banned</h2>
                </div>
                <div class='content'>
                    <div class='detail'>
                        <span class='label'>IP Address:</span> 
                        <span class='value' style='font-weight: bold;'>{$ip}</span>
                    </div>
                    <div class='detail'>
                        <span class='label'>Block Count:</span> 
                        <span class='value'>{$blockCount}</span>
                    </div>
                    <div class='detail'>
                        <span class='label'>Reason:</span> 
                        <span class='value'>{$reason}</span>
                    </div>
                    <div class='detail'>
                        <span class='label'>Ban Duration:</span> 
                        <span class='value'>1 hour</span>
                    </div>
                    <div class='detail'>
                        <span class='label'>Time:</span> 
                        <span class='value'>" . date('Y-m-d H:i:s') . "</span>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    /**
     * Generate HTML body for cert expiry alert
     */
    private function generateCertExpiryBody($domain, $daysRemaining) {
        $urgency = $daysRemaining <= 7 ? 'URGENT' : 'WARNING';
        $color = $daysRemaining <= 7 ? '#dc2626' : '#ea580c';
        
        return "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: {$color}; color: white; padding: 15px; border-radius: 5px; }
                .content { background: #f8fafc; padding: 20px; margin: 20px 0; border-radius: 5px; }
                .detail { margin: 10px 0; }
                .label { font-weight: bold; color: #475569; }
                .value { color: #1e293b; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>⚠️ {$urgency}: SSL Certificate Expiring Soon</h2>
                </div>
                <div class='content'>
                    <div class='detail'>
                        <span class='label'>Domain:</span> 
                        <span class='value' style='font-weight: bold;'>{$domain}</span>
                    </div>
                    <div class='detail'>
                        <span class='label'>Days Remaining:</span> 
                        <span class='value' style='color: {$color}; font-weight: bold; font-size: 1.2rem;'>{$daysRemaining} days</span>
                    </div>
                    <div class='detail'>
                        <span class='label'>Action Required:</span> 
                        <span class='value'>Please renew the SSL certificate before it expires.</span>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    /**
     * Send email using PHP mail() function or SMTP
     */
    private function send($subject, $body) {
        try {
            $to = $this->settings['email_to'];
            $from = $this->settings['email_from'];
            
            // Headers
            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=UTF-8',
                "From: WAF Security <{$from}>",
                "Reply-To: {$from}",
                'X-Mailer: PHP/' . phpversion()
            ];
            
            // Use simple mail() for now - can be upgraded to PHPMailer if needed
            $success = mail($to, $subject, $body, implode("\r\n", $headers));
            
            if ($success) {
                error_log("Email sent successfully to {$to}: {$subject}");
            } else {
                error_log("Failed to send email to {$to}: {$subject}");
            }
            
            return $success;
            
        } catch (Exception $e) {
            error_log("Email send error: " . $e->getMessage());
            return false;
        }
    }
}
