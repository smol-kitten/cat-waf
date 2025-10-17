<?php
// Simple script to regenerate all site configs
require_once 'config.php';
require_once 'endpoints/sites.php';

$db = getDB();

// Get all enabled sites
$stmt = $db->query("SELECT id, domain FROM sites WHERE enabled = 1");
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
