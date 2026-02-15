<?php
/**
 * Health Check Task
 * Checks all backend services and sends alerts if issues detected
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/WebhookNotifier.php';

$pdo = getDB();

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
$sites = $pdo->query("SELECT id, domain, backend_url FROM sites WHERE enabled = 1")->fetchAll(PDO::FETCH_ASSOC);
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
// Check cert files directly — the sites table doesn't have an ssl_expiry column
$sslSites = $pdo->query("SELECT domain FROM sites WHERE ssl_enabled = 1 AND enabled = 1")->fetchAll(PDO::FETCH_ASSOC);
$expiringSsl = [];

foreach ($sslSites as $sslSite) {
    $certPath = "/etc/nginx/certs/{$sslSite['domain']}/fullchain.pem";
    if (!file_exists($certPath)) continue;
    
    $certData = @openssl_x509_parse(@file_get_contents($certPath));
    if (!$certData || !isset($certData['validTo_time_t'])) continue;
    
    $daysLeft = (int)floor(($certData['validTo_time_t'] - time()) / 86400);
    if ($daysLeft < 7) {
        $expiringSsl[] = ['domain' => $sslSite['domain'], 'days_left' => $daysLeft];
    }
}

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

// ONE-TIME ALERT SYSTEM: Only alert on NEW issues, not recurring ones
// Track issues and only notify when they first appear
$newIssues = [];
$resolvedIssues = [];

foreach ($issues as $issueText) {
    // Create a unique key for this issue
    $issueKey = md5($issueText);
    $issueType = 'health_check';
    
    // Check if this issue already exists and is unresolved
    $stmt = $pdo->prepare("
        SELECT id, alert_sent FROM active_issues 
        WHERE issue_type = ? AND issue_key = ? AND resolved_at IS NULL
    ");
    $stmt->execute([$issueType, $issueKey]);
    $existingIssue = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingIssue) {
        // Issue already tracked and unresolved - update last_detected but don't alert again
        $stmt = $pdo->prepare("UPDATE active_issues SET last_detected = NOW() WHERE id = ?");
        $stmt->execute([$existingIssue['id']]);
        echo "Issue still active (already alerted): $issueText\n";
    } else {
        // New issue - insert and mark for alerting
        $stmt = $pdo->prepare("
            INSERT INTO active_issues (issue_type, issue_key, alert_sent, details)
            VALUES (?, ?, 1, ?)
        ");
        $stmt->execute([$issueType, $issueKey, json_encode(['message' => $issueText])]);
        $newIssues[] = $issueText;
        echo "NEW issue detected: $issueText\n";
    }
}

// Check for resolved issues - mark issues that no longer appear
$stmt = $pdo->prepare("
    SELECT id, details FROM active_issues 
    WHERE issue_type = 'health_check' AND resolved_at IS NULL
");
$stmt->execute();
$activeIssues = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($activeIssues as $activeIssue) {
    $details = json_decode($activeIssue['details'], true);
    $wasFound = false;
    
    foreach ($issues as $issueText) {
        $issueKey = md5($issueText);
        if (isset($details['message']) && md5($details['message']) === md5($issueText)) {
            $wasFound = true;
            break;
        }
    }
    
    if (!$wasFound) {
        // Issue has been resolved
        $stmt = $pdo->prepare("UPDATE active_issues SET resolved_at = NOW() WHERE id = ?");
        $stmt->execute([$activeIssue['id']]);
        $resolvedIssues[] = $details['message'] ?? 'Unknown issue';
        echo "Issue RESOLVED: " . ($details['message'] ?? 'Unknown') . "\n";
    }
}

// Send notification only for NEW issues
if (!empty($newIssues)) {
    echo "\n⚠ NEW issues detected! Sending notification...\n";
    
    try {
        $notifier = new WebhookNotifier($pdo);
        $issueList = implode("\n• ", $newIssues);
        $notifier->sendCustomNotification(
            "🏥 Health Check Alert",
            "The following NEW issues were detected:\n\n• {$issueList}",
            15158332 // Orange
        );
        
        // Also store in alert_history for dashboard visibility
        $healthRuleStmt = $pdo->query("SELECT id FROM alert_rules WHERE rule_type = 'health_check' LIMIT 1");
        $healthRule = $healthRuleStmt->fetch();
        if ($healthRule) {
            $stmt = $pdo->prepare("INSERT INTO alert_history (alert_rule_id, alert_data) VALUES (?, ?)");
            $stmt->execute([$healthRule['id'], json_encode(['issues' => $newIssues])]);
        }
        
        echo "Notification sent\n";
    } catch (Exception $e) {
        echo "Failed to send notification: " . $e->getMessage() . "\n";
    }
} else {
    echo "\nNo NEW issues (existing issues already alerted).\n";
}

// Optionally notify when issues are resolved
if (!empty($resolvedIssues)) {
    echo "\n✅ Issues resolved: " . count($resolvedIssues) . "\n";
    try {
        $notifier = new WebhookNotifier($pdo);
        $resolvedList = implode("\n• ", $resolvedIssues);
        $notifier->sendCustomNotification(
            "✅ Health Check - Issues Resolved",
            "The following issues have been resolved:\n\n• {$resolvedList}",
            5763719 // Green
        );
    } catch (Exception $e) {
        // Silent fail for resolution notifications
    }
}

echo "\nHealth check complete\n";

return empty($issues);
