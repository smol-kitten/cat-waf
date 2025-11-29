<?php
// Simple script to regenerate all site configs
require_once 'config.php';
require_once 'endpoints/sites.php';

$db = getDB();

// Clean up orphaned config files first
echo "Checking for orphaned config files...\n";
$cleanupCount = cleanupOrphanedConfigs($db);
if ($cleanupCount > 0) {
    echo "✅ Removed {$cleanupCount} orphaned config(s)\n\n";
} else {
    echo "✅ No orphaned configs found\n\n";
}

// Get all enabled sites - ORDER BY specificity (exact domains first, longer domains first)
$stmt = $db->query("
    SELECT id, domain FROM sites 
    WHERE enabled = 1
    ORDER BY 
        wildcard_subdomains ASC,
        CHAR_LENGTH(domain) DESC,
        domain ASC
");
$sites = $stmt->fetchAll();

echo "Regenerating configs for " . count($sites) . " sites...\n";

foreach ($sites as $site) {
    echo "Generating config for {$site['domain']}... ";
    $success = generateSiteConfig($site['id'], []);
    echo $success ? "✅\n" : "❌\n";
}

echo "\nDone! Triggering NGINX reload...\n";
touch("/etc/nginx/sites-enabled/.reload_needed");
echo "✅ Reload signal sent\n";
