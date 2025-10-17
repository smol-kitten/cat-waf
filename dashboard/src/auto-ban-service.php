<?php
// Auto-Ban Service - Monitors ModSecurity events and automatically bans IPs
// Run this as a background service or cron job

require_once __DIR__ . '/config.php';

// Configuration
$CHECK_INTERVAL = 60; // Check every 60 seconds
$THRESHOLD_COUNT = 5; // Number of blocks
$THRESHOLD_WINDOW = 300; // Within 5 minutes (300 seconds)
$BAN_DURATION = 3600; // Ban for 1 hour (3600 seconds)

// Cloudflare IP ranges (update periodically from https://www.cloudflare.com/ips/)
//https://www.cloudflare.com/ips-v4/#
//https://www.cloudflare.com/ips-v6/#
//FETCH at start and every 24 hours

/*
$CLOUDFLARE_IPS = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    // IPv6
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32'
];*/
$CLOUDFLARE_IPS = [];
// Fetch Cloudflare IPs
function fetchCloudflareIPs() {
    $ips = [];
    $urls = [
        'https://www.cloudflare.com/ips-v4',
        'https://www.cloudflare.com/ips-v6'
    ];
    
    foreach ($urls as $url) {
        $data = file_get_contents($url);
        if ($data !== false) {
            $lines = explode("\n", trim($data));
            foreach ($lines as $line) {
                $line = trim($line);
                if (filter_var(explode('/', $line)[0], FILTER_VALIDATE_IP)) {
                    $ips[] = $line;
                }
            }
        }
    }
    
    return $ips;
}
$CLOUDFLARE_IPS = fetchCloudflareIPs();
$lastFetchTime = time();
// Refresh Cloudflare IPs every 24 hours
$CLOUDFLARE_REFRESH_INTERVAL = 86400; // 24 hours

function isCloudflareIP($ip, $ranges) {

    // Check if IP is in any of the given CIDR ranges
    //if old data is stale, refresh
    global $lastFetchTime, $CLOUDFLARE_REFRESH_INTERVAL, $CLOUDFLARE_IPS;
    if (time() - $lastFetchTime > $CLOUDFLARE_REFRESH_INTERVAL) {
        $CLOUDFLARE_IPS = fetchCloudflareIPs();
        $lastFetchTime = time();
        $ranges = $CLOUDFLARE_IPS;
    }


    foreach ($ranges as $range) {
        if (strpos($range, '/') !== false) {
            // CIDR notation
            list($subnet, $mask) = explode('/', $range);
            
            // Check if IPv6
            if (strpos($ip, ':') !== false) {
                // IPv6 check
                $ip_bin = inet_pton($ip);
                $subnet_bin = inet_pton($subnet);
                if ($ip_bin === false || $subnet_bin === false) continue;
                
                $ip_bits = unpack('C*', $ip_bin);
                $subnet_bits = unpack('C*', $subnet_bin);
                $match = true;
                
                $bytes = (int)($mask / 8);
                $bits = $mask % 8;
                
                for ($i = 1; $i <= $bytes; $i++) {
                    if ($ip_bits[$i] !== $subnet_bits[$i]) {
                        $match = false;
                        break;
                    }
                }
                
                if ($match && $bits > 0) {
                    $byte_mask = (0xFF << (8 - $bits)) & 0xFF;
                    if (($ip_bits[$bytes + 1] & $byte_mask) !== ($subnet_bits[$bytes + 1] & $byte_mask)) {
                        $match = false;
                    }
                }
                
                if ($match) return true;
            } else {
                // IPv4 check
                $ip_long = ip2long($ip);
                $subnet_long = ip2long($subnet);
                $mask_long = -1 << (32 - $mask);
                
                if (($ip_long & $mask_long) === ($subnet_long & $mask_long)) {
                    return true;
                }
            }
        }
    }
    return false;
}

function getSettings($pdo) {
    $settings = [];
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function checkAndBanIPs($pdo, $threshold_count, $threshold_window, $ban_duration, $exclude_cloudflare, $cloudflare_ips) {
    echo "[" . date('Y-m-d H:i:s') . "] Checking for IPs to auto-ban...\n";
    
    // Find IPs with multiple blocks in the time window
    $stmt = $pdo->prepare("
        SELECT 
            ip_address,
            COUNT(*) as block_count,
            MAX(timestamp) as last_block,
            GROUP_CONCAT(DISTINCT rule_id SEPARATOR ', ') as rule_ids
        FROM modsec_events
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL ? SECOND)
            AND action = 'blocked'
        GROUP BY ip_address
        HAVING block_count >= ?
    ");
    
    $stmt->execute([$threshold_window, $threshold_count]);
    $ips_to_ban = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $banned_count = 0;
    
    foreach ($ips_to_ban as $ip_data) {
        $ip = $ip_data['ip_address'];
        $block_count = $ip_data['block_count'];
        $rule_ids = $ip_data['rule_ids'];
        
        // Check if already banned
        $check_stmt = $pdo->prepare("SELECT id FROM banned_ips WHERE ip_address = ? AND (expires_at IS NULL OR expires_at > NOW())");
        $check_stmt->execute([$ip]);
        if ($check_stmt->fetch()) {
            echo "  [SKIP] $ip already banned\n";
            continue;
        }
        
        // Check if Cloudflare IP (if exclusion enabled)
        if ($exclude_cloudflare && isCloudflareIP($ip, $cloudflare_ips)) {
            echo "  [SKIP] $ip is Cloudflare IP (excluded)\n";
            continue;
        }
        
        // Ban the IP
        $expires_at = date('Y-m-d H:i:s', time() + $ban_duration);
        $reason = "Auto-banned: $block_count ModSecurity blocks (Rules: $rule_ids)";
        
        $ban_stmt = $pdo->prepare("
            INSERT INTO banned_ips (ip_address, reason, banned_by, expires_at, created_at)
            VALUES (?, ?, 'auto-ban', ?, NOW())
        ");
        
        if ($ban_stmt->execute([$ip, $reason, $expires_at])) {
            echo "  [BANNED] $ip ($block_count blocks, expires: $expires_at)\n";
            $banned_count++;
            
            // Regenerate fail2ban banlist
            regenerateBanList($pdo);
        } else {
            echo "  [ERROR] Failed to ban $ip\n";
        }
    }
    
    echo "  Result: Banned $banned_count IPs\n\n";
    return $banned_count;
}

function regenerateBanList($pdo) {
    // Get all active bans
    $stmt = $pdo->query("
        SELECT ip_address 
        FROM banned_ips 
        WHERE expires_at IS NULL OR expires_at > NOW()
    ");
    $banned_ips = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Write to fail2ban banlist
    $banlist_path = '/etc/fail2ban/state/banlist.conf';
    $content = "# Auto-generated banlist - DO NOT EDIT MANUALLY\n";
    $content .= "# Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    $content .= "geo \$ban {\n";
    $content .= "    default 0;\n";
    foreach ($banned_ips as $ip) {
        $content .= "    {$ip} 1;\n";
    }
    $content .= "}\n";
    
    file_put_contents($banlist_path, $content);
    
    // Signal NGINX to reload
    touch('/etc/nginx/sites-enabled/.reload_needed');
}

// Main loop
echo "🤖 Auto-Ban Service Started\n";
echo "Configuration:\n";
echo "  - Threshold: $THRESHOLD_COUNT blocks in " . ($THRESHOLD_WINDOW / 60) . " minutes\n";
echo "  - Ban Duration: " . ($BAN_DURATION / 3600) . " hours\n";
echo "  - Check Interval: $CHECK_INTERVAL seconds\n\n";

$db = getDB();

while (true) {
    try {
        // Get current settings
        $settings = getSettings($db);
        $enable_auto_ban = ($settings['enable_auto_ban'] ?? '0') === '1';
        $exclude_cloudflare = ($settings['exclude_cloudflare_ips'] ?? '0') === '1';
        
        if ($enable_auto_ban) {
            checkAndBanIPs($db, $THRESHOLD_COUNT, $THRESHOLD_WINDOW, $BAN_DURATION, $exclude_cloudflare, $CLOUDFLARE_IPS);
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] Auto-ban disabled\n";
        }
        
        // Clean up expired bans
        $db->exec("DELETE FROM banned_ips WHERE expires_at IS NOT NULL AND expires_at < NOW()");
        
    } catch (Exception $e) {
        echo "[ERROR] " . $e->getMessage() . "\n";
    }
    
    sleep($CHECK_INTERVAL);
}
