<?php
// Regenerate all site configurations
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../endpoints/sites.php';

    $pdo = getDatabaseConnection();

    echo "Regenerating all site configurations...\n";

$sites = $pdo->query('SELECT * FROM sites')->fetchAll(PDO::FETCH_ASSOC);

foreach ($sites as $site) {
    $domain = $site['domain'];
    
    try {
        $success = generateSiteConfig($site['id'], $site);
        if ($success) {
            echo "✓ Generated: $domain\n";
        } else {
            echo "✗ Failed: $domain\n";
        }
    } catch (Exception $e) {
        echo "✗ Failed: $domain - " . $e->getMessage() . "\n";
    }
}// Signal NGINX to reload
touch('/etc/nginx/sites-enabled/.reload_needed');
echo "\nAll done! NGINX will reload automatically.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
