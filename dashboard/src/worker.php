#!/usr/bin/env php
<?php
/**
 * Background Job Worker
 * Processes jobs from the queue.
 * NOTE: ModSecurity audit log parsing is handled exclusively by the log-parser container.
 */

require_once __DIR__ . '/config.php';

$running = true;
$sleepTime = 5; // seconds between checks

echo "Job worker started. Waiting for jobs...\n";

while ($running) {
    
    try {
        $db = getDB();
        
        // Get next pending job with highest priority
        $stmt = $db->query("
            SELECT * FROM jobs 
            WHERE status = 'pending' 
            AND attempts < max_attempts
            ORDER BY priority DESC, created_at ASC 
            LIMIT 1
        ");
        
        $job = $stmt->fetch();
        
        if ($job) {
            processJob($db, $job);
        } else {
            // No jobs, sleep
            sleep($sleepTime);
        }
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        sleep($sleepTime);
    }
}

echo "Job worker stopped.\n";

function processJob($db, $job) {
    echo "Processing job {$job['id']}: {$job['type']}\n";
    
    // Mark as running
    $stmt = $db->prepare("UPDATE jobs SET status = 'running', started_at = NOW() WHERE id = ?");
    $stmt->execute([$job['id']]);
    
    try {
        switch ($job['type']) {
            case 'parse_logs':
                // No-op: ModSec parsing now handled by log-parser container
                echo "Skipping parse_logs — handled by log-parser container\n";
                break;
            default:
                echo "Unknown job type: {$job['type']}\n";
        }
        
        // Mark as completed
        $stmt = $db->prepare("UPDATE jobs SET status = 'completed', completed_at = NOW() WHERE id = ?");
        $stmt->execute([$job['id']]);
        
    } catch (Exception $e) {
        echo "Job {$job['id']} failed: {$e->getMessage()}\n";
        
        // Mark as failed
        $stmt = $db->prepare("UPDATE jobs SET status = 'failed', error = ?, attempts = attempts + 1 WHERE id = ?");
        $stmt->execute([$e->getMessage(), $job['id']]);
    }
}
