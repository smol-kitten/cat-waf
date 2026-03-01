#!/usr/bin/env php
<?php
/**
 * Emergency Log Cleanup - Run via docker exec without starting the full dashboard
 *
 * Usage:
 *   docker exec waf-log-parser php /app/emergency-cleanup.php [--days=N] [--tables=TABLE,...] [--truncate]
 *
 * Examples:
 *   docker exec waf-log-parser php /app/emergency-cleanup.php                    # Delete data >7 days
 *   docker exec waf-log-parser php /app/emergency-cleanup.php --days=1           # Delete data >1 day
 *   docker exec waf-log-parser php /app/emergency-cleanup.php --truncate         # TRUNCATE all tables (instant)
 *   docker exec waf-log-parser php /app/emergency-cleanup.php --tables=access_logs,request_telemetry
 */

echo "=== CAT-WAF Emergency Log Cleanup ===\n\n";

// Parse CLI arguments
$options = getopt('', ['days::', 'tables::', 'truncate', 'help', 'batch-size::']);

if (isset($options['help'])) {
    echo <<<HELP
Usage: php emergency-cleanup.php [OPTIONS]

Options:
  --days=N          Delete records older than N days (default: 7)
  --tables=LIST     Comma-separated list of tables to clean (default: all)
  --truncate        TRUNCATE tables instead of DELETE (instant, no recovery!)
  --batch-size=N    Rows per DELETE batch (default: 50000)
  --help            Show this help

Tables:
  access_logs, request_telemetry, modsec_events, bot_detections,
  scanner_ips, scanner_requests, stats_cache_hourly, stats_cache_daily,
  stats_top_ips, stats_top_domains

HELP;
    exit(0);
}

$days = (int)($options['days'] ?? 7);
$truncate = isset($options['truncate']);
$batchSize = (int)($options['batch-size'] ?? 50000);

$allTables = [
    'access_logs', 'request_telemetry', 'modsec_events', 'bot_detections',
    'scanner_ips', 'scanner_requests',
    'stats_cache_hourly', 'stats_cache_daily', 'stats_top_ips', 'stats_top_domains'
];
$cacheTables = ['stats_cache_hourly', 'stats_cache_daily', 'stats_top_ips', 'stats_top_domains'];

if (isset($options['tables']) && $options['tables'] !== false) {
    $selectedTables = array_intersect(
        array_map('trim', explode(',', $options['tables'])),
        $allTables
    );
} else {
    $selectedTables = $allTables;
}

if (empty($selectedTables)) {
    echo "Error: No valid tables specified.\n";
    exit(1);
}

// Database connection
$dbHost = getenv('DB_HOST') ?: 'mariadb';
$dbName = getenv('DB_NAME') ?: 'waf_db';
$dbUser = getenv('DB_USER') ?: 'waf_user';
$dbPass = getenv('DB_PASSWORD') ?: 'changeme';

echo "Connecting to database {$dbHost}/{$dbName}...\n";

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 30);
    echo "Connected.\n\n";
} catch (PDOException $e) {
    echo "Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Show current sizes
echo "Current table sizes:\n";
echo str_pad("Table", 30) . str_pad("Rows", 15) . "Data Size\n";
echo str_repeat("-", 60) . "\n";

foreach ($allTables as $table) {
    try {
        $stmt = $pdo->query("SELECT TABLE_ROWS, 
                             ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb
                             FROM information_schema.TABLES 
                             WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$table}'");
        $info = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($info) {
            $marker = in_array($table, $selectedTables) ? " *" : "";
            echo str_pad($table . $marker, 30) 
                 . str_pad(number_format((int)$info['TABLE_ROWS']), 15) 
                 . $info['size_mb'] . " MB\n";
        }
    } catch (PDOException $e) {
        // Table might not exist
    }
}
echo "\n(* = will be cleaned)\n\n";

// Perform cleanup
if ($truncate) {
    echo "MODE: TRUNCATE (instant, all data will be lost!)\n\n";
    foreach ($selectedTables as $table) {
        try {
            $pdo->exec("TRUNCATE TABLE {$table}");
            echo "  ✅ TRUNCATED {$table}\n";
        } catch (PDOException $e) {
            echo "  ⚠️  SKIP {$table}: " . $e->getMessage() . "\n";
        }
    }
} else {
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    echo "MODE: DELETE records older than {$days} days (before {$cutoff})\n";
    echo "Batch size: {$batchSize} rows\n\n";

    foreach ($selectedTables as $table) {
        // Cache tables get truncated regardless
        if (in_array($table, $cacheTables)) {
            try {
                $pdo->exec("TRUNCATE TABLE {$table}");
                echo "  ✅ TRUNCATED cache table {$table}\n";
            } catch (PDOException $e) {
                echo "  ⚠️  SKIP {$table}: " . $e->getMessage() . "\n";
            }
            continue;
        }

        // Determine timestamp column (scanner_ips uses last_seen)
        $tsCol = ($table === 'scanner_ips') ? 'last_seen' : 'timestamp';

        try {
            $totalDeleted = 0;
            $startTime = microtime(true);
            do {
                $stmt = $pdo->prepare("DELETE FROM {$table} WHERE {$tsCol} < ? LIMIT {$batchSize}");
                $stmt->execute([$cutoff]);
                $deleted = $stmt->rowCount();
                $totalDeleted += $deleted;
                if ($deleted > 0) {
                    echo "  ... {$table}: deleted {$totalDeleted} so far\r";
                    usleep(50000); // 50ms pause between batches
                }
            } while ($deleted >= $batchSize);

            $elapsed = round(microtime(true) - $startTime, 2);
            echo "  ✅ {$table}: deleted " . number_format($totalDeleted) . " rows ({$elapsed}s)\n";
        } catch (PDOException $e) {
            echo "  ⚠️  {$table}: " . $e->getMessage() . "\n";
        }
    }
}

// Reset parser position files to re-scan from current position  
echo "\nResetting parser position files...\n";
$posDir = '/app/data';
if (is_dir($posDir)) {
    $files = glob($posDir . '/*.pos');
    foreach ($files as $file) {
        @unlink($file);
    }
    $inodes = glob($posDir . '/*.inode');
    foreach ($inodes as $file) {
        @unlink($file);
    }
    echo "  ✅ Position files cleared (parser will re-scan from end of logs)\n";
} else {
    echo "  ⚠️  Position directory not found (will be created on next parser run)\n";
}

// Final sizes
echo "\nTable sizes after cleanup:\n";
echo str_pad("Table", 30) . str_pad("Rows", 15) . "Data Size\n";
echo str_repeat("-", 60) . "\n";

foreach ($allTables as $table) {
    try {
        // Force fresh stats
        $pdo->exec("ANALYZE TABLE {$table}");
        $stmt = $pdo->query("SELECT TABLE_ROWS, 
                             ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb
                             FROM information_schema.TABLES 
                             WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$table}'");
        $info = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($info) {
            echo str_pad($table, 30) 
                 . str_pad(number_format((int)$info['TABLE_ROWS']), 15) 
                 . $info['size_mb'] . " MB\n";
        }
    } catch (PDOException $e) {
        // Table might not exist
    }
}

echo "\n✅ Emergency cleanup complete.\n";
echo "Tip: Run 'docker restart waf-log-parser' to restart the log parser.\n";
