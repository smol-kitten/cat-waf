<?php
/**
 * Health Check Task
 * Checks all backend services and sends alerts if issues detected
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/WebhookNotifier.php';

$pdo = getDbConnection();

echo "Health Check Task\n";

$issues = [];
$checks = [];

// Check database connectivity
$checks['database'] = ['status' => 'ok', 'message' => 'Connected'];
try {
    $pdo->query("SELECT 1");
} catch (Exception $e) {
    $checks['database'] = ['status' => 'error', 'message' => $e->getMessage()];
    $issues[] = "Database: " . $e->getMessage();
}

// Check nginx process
$nginxRunning = shell_exec("pgrep nginx") !== null;
$checks['nginx'] = $nginxRunning 
    ? ['status' => 'ok', 'message' => 'Running']
    : ['status' => 'error', 'message' => 'Not running'];
if (!$nginxRunning) {
    $issues[] = "NGINX is not running";
}

// Check disk space
$diskFree = disk_free_space('/');
$diskTotal = disk_total_space('/');
$diskUsedPercent = round((1 - $diskFree / $diskTotal) * 100, 1);
$checks['disk'] = [
    'status' => $diskUsedPercent > 90 ? 'warning' : ($diskUsedPercent > 95 ? 'error' : 'ok'),
    'message' => "{$diskUsedPercent}% used",
    'free_gb' => round($diskFree / 1024 / 1024 / 1024, 2)
];
if ($diskUsedPercent > 90) {
    $issues[] = "Disk space critical: {$diskUsedPercent}% used";
}

// Check memory
$memInfo = [];
if (file_exists('/proc/meminfo')) {
    $lines = file('/proc/meminfo');
    foreach ($lines as $line) {
        if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
            $memInfo[$m[1]] = (int)$m[2];
        }
    }
    
    if (isset($memInfo['MemTotal'], $memInfo['MemAvailable'])) {
        $memUsedPercent = round((1 - $memInfo['MemAvailable'] / $memInfo['MemTotal']) * 100, 1);
        $checks['memory'] = [
            'status' => $memUsedPercent > 90 ? 'warning' : ($memUsedPercent > 95 ? 'error' : 'ok'),
            'message' => "{$memUsedPercent}% used",
            'available_mb' => round($memInfo['MemAvailable'] / 1024, 0)
        ];
        if ($memUsedPercent > 90) {
            $issues[] = "Memory usage high: {$memUsedPercent}%";
        }
    }
}

// Check backend health for each site
$sites = $pdo->query("SELECT id, domain, backend_url FROM sites WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC);
$backendIssues = [];

foreach ($sites as $site) {
    $url = $site['backend_url'];
    if (!$url) continue;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_NOBODY => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 0 || $httpCode >= 500) {
        $backendIssues[] = "{$site['domain']}: " . ($error ?: "HTTP {$httpCode}");
    }
}

if (!empty($backendIssues)) {
    $checks['backends'] = [
        'status' => 'warning',
        'message' => count($backendIssues) . ' backends unreachable',
        'details' => $backendIssues
    ];
    $issues[] = "Backend issues: " . implode(', ', $backendIssues);
} else {
    $checks['backends'] = ['status' => 'ok', 'message' => count($sites) . ' backends healthy'];
}

// Check SSL certificates expiring soon (7 days)
$sslStmt = $pdo->query("
    SELECT domain, ssl_expiry 
    FROM sites 
    WHERE ssl_enabled = 1 AND ssl_expiry IS NOT NULL AND ssl_expiry < DATE_ADD(NOW(), INTERVAL 7 DAY)
");
$expiringSsl = $sslStmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($expiringSsl)) {
    $domains = array_column($expiringSsl, 'domain');
    $checks['ssl_expiry'] = [
        'status' => 'warning',
        'message' => count($expiringSsl) . ' certificates expiring soon',
        'domains' => $domains
    ];
    $issues[] = "SSL certificates expiring: " . implode(', ', $domains);
} else {
    $checks['ssl_expiry'] = ['status' => 'ok', 'message' => 'All certificates valid'];
}

// Output results
echo "\nHealth Check Results:\n";
echo str_repeat('-', 50) . "\n";
foreach ($checks as $name => $check) {
    $icon = $check['status'] === 'ok' ? '✓' : ($check['status'] === 'warning' ? '⚠' : '✗');
    echo "{$icon} {$name}: {$check['message']}\n";
}

// Send notification if issues found
if (!empty($issues)) {
    echo "\n⚠ Issues detected! Sending notification...\n";
    
    try {
        $notifier = new WebhookNotifier($pdo);
        $issueList = implode("\n• ", $issues);
        $notifier->sendCustomNotification(
            "🏥 Health Check Alert",
            "The following issues were detected:\n\n• {$issueList}",
            15158332 // Orange
        );
        echo "Notification sent\n";
    } catch (Exception $e) {
        echo "Failed to send notification: " . $e->getMessage() . "\n";
    }
}

echo "\nHealth check complete\n";

return empty($issues);
