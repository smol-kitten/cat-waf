<?php
/**
 * Ban Cleanup Task
 * Removes expired bans from the banlist
 */

require_once __DIR__ . '/../config.php';

$pdo = getDbConnection();

echo "Ban Cleanup Task\n";

// Find expired bans
$stmt = $pdo->query("
    SELECT id, ip_address, banned_by 
    FROM banned_ips 
    WHERE expires_at IS NOT NULL AND expires_at < NOW()
");
$expiredBans = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($expiredBans)) {
    echo "No expired bans to clean up\n";
    return true;
}

echo "Found " . count($expiredBans) . " expired bans\n";

// Delete expired bans
$deleteStmt = $pdo->prepare("DELETE FROM banned_ips WHERE id = ?");

$deleted = 0;
foreach ($expiredBans as $ban) {
    $deleteStmt->execute([$ban['id']]);
    echo "Removed expired ban: {$ban['ip_address']} (banned by: {$ban['banned_by']})\n";
    $deleted++;
}

// Regenerate nginx banlist
$regenerated = false;
try {
    $ips = $pdo->query("SELECT ip_address FROM banned_ips WHERE (expires_at IS NULL OR expires_at > NOW())")->fetchAll(PDO::FETCH_COLUMN);
    
    $banlistPath = '/etc/nginx/conf.d/banlist.conf';
    if (is_writable(dirname($banlistPath))) {
        $content = "# Auto-generated banlist - " . date('Y-m-d H:i:s') . "\n";
        $content .= "geo \$banned_ip {\n";
        $content .= "    default 0;\n";
        foreach ($ips as $ip) {
            $content .= "    {$ip} 1;\n";
        }
        $content .= "}\n";
        
        file_put_contents($banlistPath, $content);
        
        // Signal nginx reload
        if (file_exists('/tmp/nginx-reload-signal')) {
            touch('/tmp/nginx-reload-signal');
        }
        
        $regenerated = true;
        echo "Regenerated nginx banlist with " . count($ips) . " IPs\n";
    }
} catch (Exception $e) {
    echo "Warning: Could not regenerate banlist: " . $e->getMessage() . "\n";
}

echo "Ban cleanup complete. Removed: {$deleted} bans\n";

return true;
