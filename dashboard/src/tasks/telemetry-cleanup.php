<?php
/**
 * Telemetry Cleanup Task
 * Archives and removes old request telemetry data
 */

require_once __DIR__ . '/../config.php';

$pdo = getDB();

// Get retention setting
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'telemetry_retention_days'");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$retentionDays = $result ? (int)$result['setting_value'] : 14;

echo "Telemetry Cleanup Task - Retention: {$retentionDays} days\n";

// Count records to be deleted
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM request_telemetry WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)");
$countStmt->execute([$retentionDays]);
$toDelete = $countStmt->fetchColumn();

echo "Records to delete: {$toDelete}\n";

if ($toDelete > 0) {
    // Delete in batches to avoid long locks
    $batchSize = 10000;
    $deleted = 0;
    
    while ($deleted < $toDelete) {
        $stmt = $pdo->prepare("
            DELETE FROM request_telemetry 
            WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY) 
            LIMIT ?
        ");
        $stmt->execute([$retentionDays, $batchSize]);
        $batchDeleted = $stmt->rowCount();
        $deleted += $batchDeleted;
        
        echo "Deleted batch: {$batchDeleted} (total: {$deleted})\n";
        
        if ($batchDeleted < $batchSize) {
            break;
        }
        
        // Small pause between batches
        usleep(100000); // 100ms
    }
    
    echo "Total deleted: {$deleted}\n";
}

// Also clean old bot_detections
$stmt = $pdo->prepare("DELETE FROM bot_detections WHERE detected_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
$stmt->execute([$retentionDays * 2]); // Keep bot detections longer
$botDeleted = $stmt->rowCount();
echo "Deleted {$botDeleted} old bot detection records\n";

// Optimize table if significant records were deleted
if ($toDelete > 10000) {
    echo "Optimizing request_telemetry table...\n";
    $pdo->exec("OPTIMIZE TABLE request_telemetry");
}

echo "Telemetry cleanup complete\n";

return true;
