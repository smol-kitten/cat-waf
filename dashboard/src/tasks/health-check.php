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

// Check backend health for each site (only sites with health checks enabled)
$sites = $pdo->query("SELECT id, domain, backend_url FROM sites WHERE enabled = 1 AND health_check_enabled = 1")->fetchAll(PDO::FETCH_ASSOC);
$backendIssues = [];
$backendIssueDomains = [];

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
        $backendIssueDomains[] = $site['domain'];
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

/**
 * Extract a stable issue key from issue text.
 * Uses category + domain/identifier instead of full error text to prevent
 * resolved/new loops caused by variable error details (e.g. timeout durations).
 */
function extractStableIssueKey($issueText) {
    // Backend issues: "Backend issues: domain1: error, domain2: error"
    if (strpos($issueText, 'Backend issues:') === 0) {
        // Extract domain names from the backend issues text
        $details = substr($issueText, strlen('Backend issues: '));
        // Get just the domain parts (before the colon in each entry)
        preg_match_all('/([a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*)\s*:/i', $details, $matches);
        $domains = !empty($matches[1]) ? $matches[1] : ['unknown'];
        sort($domains);
        return 'backend_down:' . implode(',', $domains);
    }
    
    // Disk space: "Disk space critical: XX% used"
    if (strpos($issueText, 'Disk space') !== false) {
        return 'disk_space_critical';
    }
    
    // Memory: "Memory usage high: XX%"
    if (strpos($issueText, 'Memory usage') !== false) {
        return 'memory_usage_high';
    }
    
    // NGINX: "NGINX is not running"
    if (strpos($issueText, 'NGINX') !== false) {
        return 'nginx_down';
    }
    
    // Database: "Database: error"
    if (strpos($issueText, 'Database:') === 0) {
        return 'database_error';
    }
    
    // SSL: "SSL certificates expiring: domain1, domain2"
    if (strpos($issueText, 'SSL certificates expiring') !== false) {
        $parts = explode(': ', $issueText, 2);
        return 'ssl_expiring:' . ($parts[1] ?? 'unknown');
    }
    
    // Fallback: use the full text for unknown issue types
    return $issueText;
}

// ONE-TIME ALERT SYSTEM: Only alert on NEW issues, not recurring ones
// Track issues and only notify when they first appear
$newIssues = [];
$resolvedIssues = [];

// Check if health_check alert rule exists and is enabled before sending notifications
$healthRuleStmt = $pdo->query("SELECT id, enabled FROM alert_rules WHERE rule_type = 'health_check' LIMIT 1");
$healthRule = $healthRuleStmt->fetch(PDO::FETCH_ASSOC);
$notificationsEnabled = $healthRule && (int)$healthRule['enabled'] === 1;

// Build stable issue keys based on category + domain, not variable error text
// This prevents loops where slightly different error messages create new/resolved cycles
$currentIssueKeys = [];

foreach ($issues as $issueText) {
    // Extract a stable key from the issue text (category-based, not error-message-based)
    $stableKey = extractStableIssueKey($issueText);
    $issueKey = md5($stableKey);
    $issueType = 'health_check';
    $currentIssueKeys[$issueKey] = true;
    
    // Check if this issue already exists and is unresolved
    $stmt = $pdo->prepare("
        SELECT id, alert_sent FROM active_issues 
        WHERE issue_type = ? AND issue_key = ? AND resolved_at IS NULL
    ");
    $stmt->execute([$issueType, $issueKey]);
    $existingIssue = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingIssue) {
        // Issue already tracked and unresolved - update last_detected and details but don't alert again
        $stmt = $pdo->prepare("UPDATE active_issues SET last_detected = NOW(), details = ? WHERE id = ?");
        $stmt->execute([json_encode(['message' => $issueText, 'stable_key' => $stableKey]), $existingIssue['id']]);
        echo "Issue still active (already alerted): $issueText\n";
    } else {
        // New issue - insert and mark for alerting
        $stmt = $pdo->prepare("
            INSERT INTO active_issues (issue_type, issue_key, alert_sent, details)
            VALUES (?, ?, 1, ?)
        ");
        $stmt->execute([$issueType, $issueKey, json_encode(['message' => $issueText, 'stable_key' => $stableKey])]);
        $newIssues[] = $issueText;
        echo "NEW issue detected: $issueText\n";
    }
}

// Check for resolved issues - mark issues that no longer appear
$stmt = $pdo->prepare("
    SELECT id, issue_key, details FROM active_issues 
    WHERE issue_type = 'health_check' AND resolved_at IS NULL
");
$stmt->execute();
$activeIssues = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($activeIssues as $activeIssue) {
    $details = json_decode($activeIssue['details'], true);
    
    // Use the stable issue_key stored in the DB to match against current keys
    if (!isset($currentIssueKeys[$activeIssue['issue_key']])) {
        // Issue has been resolved
        $stmt = $pdo->prepare("UPDATE active_issues SET resolved_at = NOW() WHERE id = ?");
        $stmt->execute([$activeIssue['id']]);
        $resolvedIssues[] = $details['message'] ?? 'Unknown issue';
        echo "Issue RESOLVED: " . ($details['message'] ?? 'Unknown') . "\n";
    }
}

// Send notification only for NEW issues (and only if alert rule is enabled)
if (!empty($newIssues) && $notificationsEnabled) {
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
        if ($healthRule) {
            $stmt = $pdo->prepare("INSERT INTO alert_history (alert_rule_id, alert_data) VALUES (?, ?)");
            $stmt->execute([$healthRule['id'], json_encode(['issues' => $newIssues])]);
        }
        
        echo "Notification sent\n";
    } catch (Exception $e) {
        echo "Failed to send notification: " . $e->getMessage() . "\n";
    }
} elseif (!empty($newIssues)) {
    echo "\nNew issues detected but notifications disabled (alert rule disabled or deleted).\n";
} else {
    echo "\nNo NEW issues (existing issues already alerted).\n";
}

// Optionally notify when issues are resolved (only if notifications enabled)
if (!empty($resolvedIssues) && $notificationsEnabled) {
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
} elseif (!empty($resolvedIssues)) {
    echo "\n✅ Issues resolved: " . count($resolvedIssues) . " (notifications disabled)\n";
}

echo "\nHealth check complete\n";

return empty($issues);
