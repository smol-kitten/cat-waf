<?php
require 'config.php';
require 'endpoints/sites.php';

$db = getDB();
$stmt = $db->query("SELECT * FROM sites WHERE domain='_'");
$siteData = $stmt->fetch();

echo "Site data:\n";
echo "Domain: " . $siteData['domain'] . "\n";
echo "Domain === '_': " . ($siteData['domain'] === '_' ? 'TRUE' : 'FALSE') . "\n";
echo "Domain type: " . gettype($siteData['domain']) . "\n";
echo "Domain length: " . strlen($siteData['domain']) . "\n";
echo "Domain bytes: " . bin2hex($siteData['domain']) . "\n";
