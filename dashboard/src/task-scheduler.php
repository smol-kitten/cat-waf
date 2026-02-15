<?php
/**
 * Task Scheduler Service
 * Runs scheduled tasks based on cron expressions
 * 
 * This service should run as a supervisor program
 */

require_once __DIR__ . '/config.php';

class TaskScheduler {
    private $pdo;
    private $running = true;
    private $currentTask = null;
    
    public function __construct() {
        $this->pdo = getDB();
    }
    
    public function run() {
        logMessage("Task Scheduler started");
        
        // Check if scheduler is enabled
        if (!$this->isEnabled()) {
            logMessage("Task Scheduler is disabled, exiting");
            return;
        }
        
        while ($this->running) {
            try {
                $this->processDueTasks();
            } catch (Exception $e) {
                logMessage("Scheduler error: " . $e->getMessage());
            }
            
            // Sleep for 30 seconds between checks
            sleep(30);
        }
    }
    
    private function isEnabled(): bool {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'task_scheduler_enabled'");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && $result['setting_value'] === '1';
    }
    
    private function processDueTasks() {
        $now = new DateTime();
        
        // Get all enabled tasks
        $stmt = $this->pdo->prepare("
            SELECT * FROM scheduled_tasks 
            WHERE enabled = 1 
            ORDER BY id
        ");
        $stmt->execute();
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tasks as $task) {
            if ($this->isDue($task, $now)) {
                $this->executeTask($task);
            }
        }
    }
    
    private function isDue(array $task, DateTime $now): bool {
        // If next_run is set and it's in the future, not due
        if ($task['next_run']) {
            $nextRun = new DateTime($task['next_run']);
            if ($nextRun > $now) {
                return false;
            }
        }
        
        // Check if task is currently running
        if ($task['last_status'] === 'running') {
            return false;
        }
        
        // Parse cron expression and check if due
        return $this->matchesCron($task['schedule'], $now);
    }
    
    private function matchesCron(string $cron, DateTime $now): bool {
        $parts = preg_split('/\s+/', trim($cron));
        if (count($parts) !== 5) {
            return false;
        }
        
        list($minute, $hour, $day, $month, $weekday) = $parts;
        
        return $this->matchesCronPart($minute, (int)$now->format('i')) &&
               $this->matchesCronPart($hour, (int)$now->format('G')) &&
               $this->matchesCronPart($day, (int)$now->format('j')) &&
               $this->matchesCronPart($month, (int)$now->format('n')) &&
               $this->matchesCronPart($weekday, (int)$now->format('w'));
    }
    
    private function matchesCronPart(string $part, int $value): bool {
        // Wildcard
        if ($part === '*') {
            return true;
        }
        
        // Direct match
        if (is_numeric($part) && (int)$part === $value) {
            return true;
        }
        
        // Step values (*/5)
        if (preg_match('/^\*\/(\d+)$/', $part, $matches)) {
            return $value % (int)$matches[1] === 0;
        }
        
        // Range (1-5)
        if (preg_match('/^(\d+)-(\d+)$/', $part, $matches)) {
            return $value >= (int)$matches[1] && $value <= (int)$matches[2];
        }
        
        // List (1,3,5)
        if (strpos($part, ',') !== false) {
            $values = array_map('intval', explode(',', $part));
            return in_array($value, $values);
        }
        
        return false;
    }
    
    private function executeTask(array $task) {
        $taskId = $task['id'];
        $startTime = time();
        
        logMessage("Executing task: {$task['task_name']}");
        
        // Mark as running
        $this->updateTaskStatus($taskId, 'running');
        
        // Create execution record
        $executionId = $this->createExecution($taskId, 'scheduler');
        
        try {
            $output = '';
            $success = false;
            
            if ($task['php_handler']) {
                // Execute PHP handler
                $handlerPath = __DIR__ . '/' . $task['php_handler'];
                if (file_exists($handlerPath)) {
                    ob_start();
                    $result = include $handlerPath;
                    $output = ob_get_clean();
                    $success = $result !== false;
                } else {
                    throw new Exception("Handler not found: {$task['php_handler']}");
                }
            } elseif ($task['command']) {
                // Execute shell command with timeout
                $timeout = $task['timeout'] ?: 300;
                $command = "timeout {$timeout} " . $task['command'] . " 2>&1";
                $output = shell_exec($command);
                $success = true; // Basic success check
            } else {
                throw new Exception("No handler or command specified");
            }
            
            $duration = time() - $startTime;
            
            // Update task status
            $this->updateTaskStatus($taskId, 'success', $output, $duration);
            $this->completeExecution($executionId, 'success', $output, $duration);
            $this->calculateNextRun($taskId, $task['schedule']);
            
            logMessage("Task completed: {$task['task_name']} (duration: {$duration}s)");
            
        } catch (Exception $e) {
            $duration = time() - $startTime;
            $errorMsg = $e->getMessage();
            
            $this->updateTaskStatus($taskId, 'failed', $errorMsg, $duration);
            $this->completeExecution($executionId, 'failed', '', $duration, $errorMsg);
            $this->incrementFailCount($taskId);
            
            // Send notification if configured
            if ($task['notify_on_fail']) {
                $this->notifyFailure($task, $errorMsg);
            }
            
            // Handle retry
            if ($task['retry_on_fail'] && $task['fail_count'] < $task['max_retries']) {
                $retryTime = time() + $task['retry_delay'];
                $this->scheduleRetry($taskId, $retryTime);
            } else {
                $this->calculateNextRun($taskId, $task['schedule']);
            }
            
            logMessage("Task failed: {$task['task_name']} - {$errorMsg}");
        }
    }
    
    private function updateTaskStatus(int $taskId, string $status, ?string $output = null, ?int $duration = null) {
        $sql = "UPDATE scheduled_tasks SET last_status = ?, last_run = NOW()";
        $params = [$status];
        
        if ($output !== null) {
            $sql .= ", last_output = ?";
            $params[] = substr($output, 0, 65535); // Truncate to TEXT max
        }
        
        if ($duration !== null) {
            $sql .= ", last_duration = ?";
            $params[] = $duration;
        }
        
        if ($status === 'success') {
            $sql .= ", run_count = run_count + 1";
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $taskId;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
    
    private function createExecution(int $taskId, string $triggeredBy): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO task_executions (task_id, triggered_by, status) 
            VALUES (?, ?, 'running')
        ");
        $stmt->execute([$taskId, $triggeredBy]);
        return $this->pdo->lastInsertId();
    }
    
    private function completeExecution(int $executionId, string $status, string $output, int $duration, ?string $error = null) {
        $stmt = $this->pdo->prepare("
            UPDATE task_executions 
            SET finished_at = NOW(), status = ?, output = ?, duration = ?, error_message = ?
            WHERE id = ?
        ");
        $stmt->execute([$status, $output, $duration, $error, $executionId]);
    }
    
    private function calculateNextRun(int $taskId, string $schedule) {
        // Calculate next run time based on cron expression
        $next = $this->getNextRunTime($schedule);
        
        $stmt = $this->pdo->prepare("UPDATE scheduled_tasks SET next_run = ? WHERE id = ?");
        $stmt->execute([$next->format('Y-m-d H:i:s'), $taskId]);
    }
    
    private function getNextRunTime(string $schedule): DateTime {
        $now = new DateTime();
        $now->modify('+1 minute');
        $now->setTime((int)$now->format('H'), (int)$now->format('i'), 0);
        
        // Simple approach: check next 1440 minutes (24 hours)
        for ($i = 0; $i < 1440; $i++) {
            if ($this->matchesCron($schedule, $now)) {
                return $now;
            }
            $now->modify('+1 minute');
        }
        
        // Default to tomorrow
        return (new DateTime())->modify('+1 day');
    }
    
    private function incrementFailCount(int $taskId) {
        $stmt = $this->pdo->prepare("UPDATE scheduled_tasks SET fail_count = fail_count + 1 WHERE id = ?");
        $stmt->execute([$taskId]);
    }
    
    private function scheduleRetry(int $taskId, int $retryTime) {
        $stmt = $this->pdo->prepare("UPDATE scheduled_tasks SET next_run = FROM_UNIXTIME(?) WHERE id = ?");
        $stmt->execute([$retryTime, $taskId]);
    }
    
    private function notifyFailure(array $task, string $error) {
        // Rate limit: don't spam on persistent failures
        // Only send if last notification for this task was >30 minutes ago
        $cacheFile = '/tmp/catwaf_notify_' . md5('task_' . $task['id']) . '.lock';
        if (file_exists($cacheFile)) {
            $lastNotify = (int)file_get_contents($cacheFile);
            if (time() - $lastNotify < 1800) { // 30 minutes
                logMessage("Notification suppressed for task {$task['task_name']} (rate limited, last sent " . (time() - $lastNotify) . "s ago)");
                return;
            }
        }
        file_put_contents($cacheFile, time());

        require_once __DIR__ . '/lib/WebhookNotifier.php';
        
        $notifier = new WebhookNotifier($this->pdo);
        $notifier->sendCustomNotification(
            "⚠️ Scheduled Task Failed",
            "Task **{$task['task_name']}** failed:\n```\n{$error}\n```",
            15158332 // Orange color
        );
    }
    
    public function stop() {
        $this->running = false;
    }
}

function logMessage($msg) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] {$msg}\n";
}

// Handle signals for graceful shutdown
// Define signal constants for Windows/non-pcntl systems
if (!defined('SIGTERM')) define('SIGTERM', 15);
if (!defined('SIGINT')) define('SIGINT', 2);

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() {
        global $scheduler;
        $scheduler->stop();
    });
    pcntl_signal(SIGINT, function() {
        global $scheduler;
        $scheduler->stop();
    });
}

// Run scheduler
$scheduler = new TaskScheduler();
$scheduler->run();
