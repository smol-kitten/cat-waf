<?php
/**
 * Scheduled Tasks API Endpoint
 * Manages cron jobs and scheduled task execution
 */

function handleScheduledTasks($method, $path, $body = null) {
    $pdo = getDbConnection();
    
    // Parse path: /tasks, /tasks/123, /tasks/123/run, /tasks/history
    $pathParts = explode('/', trim($path, '/'));
    $taskId = isset($pathParts[1]) && is_numeric($pathParts[1]) ? (int)$pathParts[1] : null;
    $action = isset($pathParts[2]) ? $pathParts[2] : null;
    
    // Special routes
    if (isset($pathParts[1])) {
        if ($pathParts[1] === 'history') {
            return getExecutionHistory($pdo);
        }
        if ($pathParts[1] === 'settings') {
            if ($method === 'GET') {
                return getSchedulerSettings($pdo);
            } elseif ($method === 'PUT') {
                return updateSchedulerSettings($pdo, $body);
            }
        }
        if ($pathParts[1] === 'types') {
            return getTaskTypes();
        }
    }
    
    switch ($method) {
        case 'GET':
            if ($taskId && $action === 'history') {
                return getTaskHistory($pdo, $taskId);
            } elseif ($taskId) {
                return getTask($pdo, $taskId);
            }
            return listTasks($pdo);
            
        case 'POST':
            if ($taskId && $action === 'run') {
                return runTaskNow($pdo, $taskId);
            } elseif ($taskId && $action === 'toggle') {
                return toggleTask($pdo, $taskId);
            }
            return createTask($pdo, $body);
            
        case 'PUT':
            if ($taskId) {
                return updateTask($pdo, $taskId, $body);
            }
            return sendResponse(['error' => 'Task ID required'], 400);
            
        case 'DELETE':
            if ($taskId) {
                return deleteTask($pdo, $taskId);
            }
            return sendResponse(['error' => 'Task ID required'], 400);
            
        default:
            return sendResponse(['error' => 'Method not allowed'], 405);
    }
}

function listTasks($pdo) {
    $stmt = $pdo->query("
        SELECT 
            t.*,
            (SELECT COUNT(*) FROM task_executions WHERE task_id = t.id) as total_runs,
            (SELECT COUNT(*) FROM task_executions WHERE task_id = t.id AND status = 'failed') as total_failures
        FROM scheduled_tasks t
        ORDER BY t.task_type, t.task_name
    ");
    
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate human-readable next run
    foreach ($tasks as &$task) {
        $task['next_run_human'] = $task['next_run'] ? humanTimeDiff($task['next_run']) : 'Not scheduled';
        $task['last_run_human'] = $task['last_run'] ? humanTimeDiff($task['last_run']) : 'Never';
        $task['schedule_human'] = cronToHuman($task['schedule']);
    }
    
    return sendResponse(['tasks' => $tasks]);
}

function getTask($pdo, $taskId) {
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            (SELECT COUNT(*) FROM task_executions WHERE task_id = t.id) as total_runs,
            (SELECT COUNT(*) FROM task_executions WHERE task_id = t.id AND status = 'failed') as total_failures
        FROM scheduled_tasks t
        WHERE t.id = ?
    ");
    $stmt->execute([$taskId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task) {
        return sendResponse(['error' => 'Task not found'], 404);
    }
    
    $task['next_run_human'] = $task['next_run'] ? humanTimeDiff($task['next_run']) : 'Not scheduled';
    $task['last_run_human'] = $task['last_run'] ? humanTimeDiff($task['last_run']) : 'Never';
    $task['schedule_human'] = cronToHuman($task['schedule']);
    
    // Get recent executions
    $histStmt = $pdo->prepare("
        SELECT * FROM task_executions 
        WHERE task_id = ? 
        ORDER BY started_at DESC 
        LIMIT 10
    ");
    $histStmt->execute([$taskId]);
    $task['recent_executions'] = $histStmt->fetchAll(PDO::FETCH_ASSOC);
    
    return sendResponse(['task' => $task]);
}

function createTask($pdo, $body) {
    if (!$body || !isset($body['task_name'], $body['task_type'], $body['schedule'])) {
        return sendResponse(['error' => 'Missing required fields: task_name, task_type, schedule'], 400);
    }
    
    // Validate cron expression
    if (!isValidCron($body['schedule'])) {
        return sendResponse(['error' => 'Invalid cron expression'], 400);
    }
    
    $validTypes = ['ssl_renewal', 'log_cleanup', 'backup', 'health_check', 'ban_sync', 'geoip_update', 'custom'];
    if (!in_array($body['task_type'], $validTypes)) {
        return sendResponse(['error' => 'Invalid task type'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO scheduled_tasks 
            (task_name, task_type, description, schedule, command, php_handler, enabled, timeout, retry_on_fail, notify_on_fail)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $body['task_name'],
            $body['task_type'],
            $body['description'] ?? null,
            $body['schedule'],
            $body['command'] ?? null,
            $body['php_handler'] ?? null,
            $body['enabled'] ?? 1,
            $body['timeout'] ?? 300,
            $body['retry_on_fail'] ?? 0,
            $body['notify_on_fail'] ?? 1
        ]);
        
        $taskId = $pdo->lastInsertId();
        
        return sendResponse(['success' => true, 'task_id' => $taskId, 'message' => 'Task created']);
        
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            return sendResponse(['error' => 'A task with this name already exists'], 409);
        }
        throw $e;
    }
}

function updateTask($pdo, $taskId, $body) {
    // Check task exists
    $checkStmt = $pdo->prepare("SELECT id, task_type FROM scheduled_tasks WHERE id = ?");
    $checkStmt->execute([$taskId]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existing) {
        return sendResponse(['error' => 'Task not found'], 404);
    }
    
    $updates = [];
    $params = [];
    
    $allowedFields = ['task_name', 'description', 'schedule', 'command', 'php_handler', 
                      'enabled', 'timeout', 'retry_on_fail', 'retry_delay', 'max_retries', 'notify_on_fail'];
    
    foreach ($allowedFields as $field) {
        if (isset($body[$field])) {
            // Validate cron if updating schedule
            if ($field === 'schedule' && !isValidCron($body[$field])) {
                return sendResponse(['error' => 'Invalid cron expression'], 400);
            }
            $updates[] = "$field = ?";
            $params[] = $body[$field];
        }
    }
    
    if (empty($updates)) {
        return sendResponse(['error' => 'No valid fields to update'], 400);
    }
    
    $params[] = $taskId;
    $sql = "UPDATE scheduled_tasks SET " . implode(', ', $updates) . " WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return sendResponse(['success' => true, 'message' => 'Task updated']);
}

function deleteTask($pdo, $taskId) {
    // Check if it's a system task
    $checkStmt = $pdo->prepare("SELECT task_type FROM scheduled_tasks WHERE id = ?");
    $checkStmt->execute([$taskId]);
    $task = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task) {
        return sendResponse(['error' => 'Task not found'], 404);
    }
    
    // Allow deletion but warn for system tasks
    $stmt = $pdo->prepare("DELETE FROM scheduled_tasks WHERE id = ?");
    $stmt->execute([$taskId]);
    
    return sendResponse(['success' => true, 'message' => 'Task deleted']);
}

function toggleTask($pdo, $taskId) {
    $stmt = $pdo->prepare("UPDATE scheduled_tasks SET enabled = NOT enabled WHERE id = ?");
    $stmt->execute([$taskId]);
    
    if ($stmt->rowCount() === 0) {
        return sendResponse(['error' => 'Task not found'], 404);
    }
    
    // Get new state
    $checkStmt = $pdo->prepare("SELECT enabled FROM scheduled_tasks WHERE id = ?");
    $checkStmt->execute([$taskId]);
    $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    return sendResponse([
        'success' => true, 
        'enabled' => (bool)$result['enabled'],
        'message' => $result['enabled'] ? 'Task enabled' : 'Task disabled'
    ]);
}

function runTaskNow($pdo, $taskId) {
    // Get task details
    $stmt = $pdo->prepare("SELECT * FROM scheduled_tasks WHERE id = ?");
    $stmt->execute([$taskId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task) {
        return sendResponse(['error' => 'Task not found'], 404);
    }
    
    // Check if already running
    if ($task['last_status'] === 'running') {
        return sendResponse(['error' => 'Task is already running'], 409);
    }
    
    // Mark as running
    $updateStmt = $pdo->prepare("UPDATE scheduled_tasks SET last_status = 'running' WHERE id = ?");
    $updateStmt->execute([$taskId]);
    
    // Create execution record
    $execStmt = $pdo->prepare("INSERT INTO task_executions (task_id, triggered_by, status) VALUES (?, 'manual', 'running')");
    $execStmt->execute([$taskId]);
    $executionId = $pdo->lastInsertId();
    
    $startTime = time();
    $output = '';
    $status = 'success';
    $errorMsg = null;
    
    try {
        if ($task['php_handler']) {
            $handlerPath = __DIR__ . '/' . $task['php_handler'];
            if (file_exists($handlerPath)) {
                ob_start();
                $result = include $handlerPath;
                $output = ob_get_clean();
                if ($result === false) {
                    throw new Exception("Handler returned false");
                }
            } else {
                throw new Exception("Handler not found: {$task['php_handler']}");
            }
        } elseif ($task['command']) {
            $timeout = $task['timeout'] ?: 300;
            $output = shell_exec("timeout {$timeout} " . $task['command'] . " 2>&1");
        } else {
            throw new Exception("No handler or command configured");
        }
    } catch (Exception $e) {
        $status = 'failed';
        $errorMsg = $e->getMessage();
        $output = $errorMsg;
    }
    
    $duration = time() - $startTime;
    
    // Update task
    $finalStmt = $pdo->prepare("
        UPDATE scheduled_tasks 
        SET last_status = ?, last_run = NOW(), last_output = ?, last_duration = ?, run_count = run_count + 1
        WHERE id = ?
    ");
    $finalStmt->execute([$status, substr($output, 0, 65535), $duration, $taskId]);
    
    // Update execution record
    $execUpdateStmt = $pdo->prepare("
        UPDATE task_executions 
        SET finished_at = NOW(), status = ?, output = ?, duration = ?, error_message = ?
        WHERE id = ?
    ");
    $execUpdateStmt->execute([$status, $output, $duration, $errorMsg, $executionId]);
    
    return sendResponse([
        'success' => $status === 'success',
        'status' => $status,
        'duration' => $duration,
        'output' => $output,
        'error' => $errorMsg
    ]);
}

function getTaskHistory($pdo, $taskId) {
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 20;
    
    $stmt = $pdo->prepare("
        SELECT * FROM task_executions 
        WHERE task_id = ? 
        ORDER BY started_at DESC 
        LIMIT ?
    ");
    $stmt->execute([$taskId, $limit]);
    
    return sendResponse(['history' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function getExecutionHistory($pdo) {
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
    $status = $_GET['status'] ?? null;
    
    $sql = "
        SELECT e.*, t.task_name, t.task_type 
        FROM task_executions e
        JOIN scheduled_tasks t ON e.task_id = t.id
    ";
    $params = [];
    
    if ($status) {
        $sql .= " WHERE e.status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY e.started_at DESC LIMIT ?";
    $params[] = $limit;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return sendResponse(['history' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function getSchedulerSettings($pdo) {
    $stmt = $pdo->query("
        SELECT setting_key, setting_value 
        FROM settings 
        WHERE setting_key IN ('task_scheduler_enabled', 'task_default_timeout', 'task_log_retention_days')
    ");
    
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    return sendResponse(['settings' => $settings]);
}

function updateSchedulerSettings($pdo, $body) {
    $allowedSettings = ['task_scheduler_enabled', 'task_default_timeout', 'task_log_retention_days'];
    
    $updated = 0;
    foreach ($allowedSettings as $key) {
        if (isset($body[$key])) {
            $stmt = $pdo->prepare("
                INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = ?
            ");
            $stmt->execute([$key, $body[$key], $body[$key]]);
            $updated++;
        }
    }
    
    return sendResponse(['success' => true, 'updated' => $updated]);
}

function getTaskTypes() {
    return sendResponse([
        'types' => [
            ['value' => 'ssl_renewal', 'label' => 'SSL Certificate Renewal', 'icon' => 'lock'],
            ['value' => 'log_cleanup', 'label' => 'Log Cleanup', 'icon' => 'trash'],
            ['value' => 'backup', 'label' => 'Backup', 'icon' => 'database'],
            ['value' => 'health_check', 'label' => 'Health Check', 'icon' => 'heart'],
            ['value' => 'ban_sync', 'label' => 'Ban Sync', 'icon' => 'shield'],
            ['value' => 'geoip_update', 'label' => 'GeoIP Update', 'icon' => 'globe'],
            ['value' => 'custom', 'label' => 'Custom Task', 'icon' => 'code']
        ]
    ]);
}

function isValidCron(string $cron): bool {
    $parts = preg_split('/\s+/', trim($cron));
    if (count($parts) !== 5) {
        return false;
    }
    
    // Basic validation - check each part has valid characters
    $pattern = '/^(\*|\d+|\d+-\d+|\*\/\d+|\d+(,\d+)*)$/';
    foreach ($parts as $part) {
        if (!preg_match($pattern, $part)) {
            return false;
        }
    }
    
    return true;
}

function cronToHuman(string $cron): string {
    $parts = preg_split('/\s+/', trim($cron));
    if (count($parts) !== 5) {
        return $cron;
    }
    
    list($minute, $hour, $day, $month, $weekday) = $parts;
    
    // Common patterns
    if ($cron === '* * * * *') return 'Every minute';
    if ($cron === '*/5 * * * *') return 'Every 5 minutes';
    if ($cron === '*/10 * * * *') return 'Every 10 minutes';
    if ($cron === '*/15 * * * *') return 'Every 15 minutes';
    if ($cron === '*/30 * * * *') return 'Every 30 minutes';
    if ($cron === '0 * * * *') return 'Every hour';
    if (preg_match('/^0 (\d+) \* \* \*$/', $cron, $m)) return "Daily at {$m[1]}:00";
    if (preg_match('/^(\d+) (\d+) \* \* \*$/', $cron, $m)) return "Daily at {$m[2]}:" . str_pad($m[1], 2, '0', STR_PAD_LEFT);
    if (preg_match('/^0 (\d+) \* \* 0$/', $cron, $m)) return "Weekly on Sunday at {$m[1]}:00";
    if (preg_match('/^0 (\d+) 1 \* \*$/', $cron, $m)) return "Monthly on 1st at {$m[1]}:00";
    
    return $cron;
}

function humanTimeDiff(string $datetime): string {
    $time = strtotime($datetime);
    $now = time();
    $diff = $time - $now;
    
    if ($diff > 0) {
        // Future
        if ($diff < 60) return "in {$diff} seconds";
        if ($diff < 3600) return "in " . round($diff / 60) . " minutes";
        if ($diff < 86400) return "in " . round($diff / 3600) . " hours";
        return "in " . round($diff / 86400) . " days";
    } else {
        // Past
        $diff = abs($diff);
        if ($diff < 60) return "{$diff} seconds ago";
        if ($diff < 3600) return round($diff / 60) . " minutes ago";
        if ($diff < 86400) return round($diff / 3600) . " hours ago";
        return round($diff / 86400) . " days ago";
    }
}
