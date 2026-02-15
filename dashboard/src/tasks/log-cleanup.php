<?php
/**
 * Log Cleanup Task
 * Removes old access logs, request telemetry, and other historical data
 */

require_once __DIR__ . '/../config.php';

$pdo = getDB();

// Get retention days from settings
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'log_retention_days'");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$retentionDays = $result ? (int)$result['setting_value'] : 7;

echo "Log Cleanup Task - Retention: {$retentionDays} days\n";

$totalDeleted = 0;

// Clean access_logs
$stmt = $pdo->prepare("DELETE FROM access_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)");
$stmt->execute([$retentionDays]);
$accessDeleted = $stmt->rowCount();
$totalDeleted += $accessDeleted;
echo "Deleted {$accessDeleted} old access log entries\n";

// Clean request_telemetry (same retention as access logs)
$stmt = $pdo->prepare("DELETE FROM request_telemetry WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)");
$stmt->execute([$retentionDays]);
$telemetryDeleted = $stmt->rowCount();
$totalDeleted += $telemetryDeleted;
echo "Deleted {$telemetryDeleted} old request telemetry records\n";

// Clean modsec_events (keep longer - 30 days minimum)
$modsecRetention = max($retentionDays, 30);
$stmt = $pdo->prepare("DELETE FROM modsec_events WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)");
$stmt->execute([$modsecRetention]);
$modsecDeleted = $stmt->rowCount();
$totalDeleted += $modsecDeleted;
echo "Deleted {$modsecDeleted} old ModSecurity events\n";

// Clean scanner_requests
$stmt = $pdo->prepare("DELETE FROM scanner_requests WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)");
$stmt->execute([$retentionDays]);
$scannerDeleted = $stmt->rowCount();
$totalDeleted += $scannerDeleted;
echo "Deleted {$scannerDeleted} old scanner request entries\n";

// Clean bot_detections
$stmt = $pdo->prepare("DELETE FROM bot_detections WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)");
$stmt->execute([$retentionDays]);
$botDeleted = $stmt->rowCount();
$totalDeleted += $botDeleted;
echo "Deleted {$botDeleted} old bot detection entries\n";

// Clean resolved active_issues (keep for 90 days)
$stmt = $pdo->prepare("DELETE FROM active_issues WHERE resolved_at IS NOT NULL AND resolved_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
$stmt->execute();
$issuesDeleted = $stmt->rowCount();
$totalDeleted += $issuesDeleted;
echo "Deleted {$issuesDeleted} old resolved issues\n";

// Clean alert_history (keep for 90 days)
$stmt = $pdo->prepare("DELETE FROM alert_history WHERE fired_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
$stmt->execute();
$alertsDeleted = $stmt->rowCount();
$totalDeleted += $alertsDeleted;
echo "Deleted {$alertsDeleted} old alert history records\n";

// Clean security_check_history (keep for 30 days)
$stmt = $pdo->prepare("DELETE FROM security_check_history WHERE checked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
$stmt->execute();
$securityDeleted = $stmt->rowCount();
$totalDeleted += $securityDeleted;
echo "Deleted {$securityDeleted} old security check history records\n";

// Clean task_executions
$taskRetentionStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'task_log_retention_days'");
$taskRetentionStmt->execute();
$taskResult = $taskRetentionStmt->fetch(PDO::FETCH_ASSOC);
$taskRetention = $taskResult ? (int)$taskResult['setting_value'] : 30;

$stmt = $pdo->prepare("DELETE FROM task_executions WHERE started_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
$stmt->execute([$taskRetention]);
$taskDeleted = $stmt->rowCount();
$totalDeleted += $taskDeleted;
echo "Deleted {$taskDeleted} old task execution records\n";

// Optimize tables (only if significant deletes)
if ($totalDeleted > 1000) {
    echo "Optimizing tables...\n";
    try {
        $pdo->exec("OPTIMIZE TABLE access_logs, request_telemetry, modsec_events, scanner_requests, bot_detections, task_executions");
    } catch (PDOException $e) {
        echo "Note: Table optimization not supported (not critical)\n";
    }
}

echo "\nLog cleanup complete. Total records removed: {$totalDeleted}\n";

return true;
