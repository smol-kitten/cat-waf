<?php
/**
 * Log Cleanup Task
 * Removes old access logs and request telemetry data
 */

require_once __DIR__ . '/../config.php';

$pdo = getDB();

// Get retention days from settings
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'log_retention_days'");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$retentionDays = $result ? (int)$result['setting_value'] : 7;

echo "Log Cleanup Task - Retention: {$retentionDays} days\n";

// Clean access_logs
$stmt = $pdo->prepare("DELETE FROM access_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)");
$stmt->execute([$retentionDays]);
$accessDeleted = $stmt->rowCount();
echo "Deleted {$accessDeleted} old access log entries\n";

// Clean modsec_events (keep longer - 30 days minimum)
$modsecRetention = max($retentionDays, 30);
$stmt = $pdo->prepare("DELETE FROM modsec_events WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)");
$stmt->execute([$modsecRetention]);
$modsecDeleted = $stmt->rowCount();
echo "Deleted {$modsecDeleted} old ModSecurity events\n";

// Clean scanner_requests
$stmt = $pdo->prepare("DELETE FROM scanner_requests WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)");
$stmt->execute([$retentionDays]);
$scannerDeleted = $stmt->rowCount();
echo "Deleted {$scannerDeleted} old scanner request entries\n";

// Clean task_executions
$taskRetentionStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'task_log_retention_days'");
$taskRetentionStmt->execute();
$taskResult = $taskRetentionStmt->fetch(PDO::FETCH_ASSOC);
$taskRetention = $taskResult ? (int)$taskResult['setting_value'] : 30;

$stmt = $pdo->prepare("DELETE FROM task_executions WHERE started_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
$stmt->execute([$taskRetention]);
$taskDeleted = $stmt->rowCount();
echo "Deleted {$taskDeleted} old task execution records\n";

// Optimize tables
echo "Optimizing tables...\n";
$pdo->exec("OPTIMIZE TABLE access_logs, modsec_events, scanner_requests, task_executions");

$total = $accessDeleted + $modsecDeleted + $scannerDeleted + $taskDeleted;
echo "Log cleanup complete. Total records removed: {$total}\n";

return true;
