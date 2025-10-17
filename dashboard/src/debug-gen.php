<?php
require 'config.php';
require 'endpoints/sites.php';

$db = getDB();
$stmt = $db->query("SELECT id, domain FROM sites WHERE domain='_'");
$site = $stmt->fetch();

echo "Site: " . $site['domain'] . " (ID: " . $site['id'] . ")\n";

$result = generateSiteConfig($site['id'], []);
echo "Generation result: " . ($result ? 'SUCCESS' : 'FAIL') . "\n";

// Read the generated config
$config = file_get_contents("/etc/nginx/sites-enabled/_.conf");
echo "\n=== Generated Config ===\n";
echo $config;
