<?php

function handleJobs($method, $params, $db) {
    // Ensure jobs table exists
    createJobsTableIfNotExists($db);
    
    $action = $params[0] ?? '';
    
    switch ($method) {
        case 'GET':
            if ($action === 'stats') {
                getJobStats($db);
            } elseif ($action && is_numeric($action)) {
                getJob($db, $action);
            } else {
                listJobs($db);
            }
            break;
            
        case 'POST':
            createJob($db);
            break;
            
        case 'DELETE':
            if ($action && is_numeric($action)) {
                deleteJob($db, $action);
            } else {
                sendResponse(['error' => 'Job ID required'], 400);
            }
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

function createJobsTableIfNotExists($db) {
    try {
        $sql = "CREATE TABLE IF NOT EXISTS jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(50) NOT NULL COMMENT 'Job type: cert_provision, config_regen, etc',
            payload JSON COMMENT 'Job-specific data',
            status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, running, completed, failed',
            priority INT DEFAULT 0 COMMENT 'Higher priority jobs run first',
            attempts INT DEFAULT 0 COMMENT 'Number of execution attempts',
            max_attempts INT DEFAULT 3 COMMENT 'Maximum retry attempts',
            error TEXT COMMENT 'Error message if failed',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            INDEX idx_status (status),
            INDEX idx_type (type),
            INDEX idx_priority (priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $db->exec($sql);
    } catch (PDOException $e) {
        // Table might already exist, ignore
    }
}

function listJobs($db) {
    $status = $_GET['status'] ?? 'all';
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    
    $sql = "SELECT * FROM jobs";
    if ($status !== 'all') {
        $sql .= " WHERE status = :status";
    }
    $sql .= " ORDER BY priority DESC, created_at DESC LIMIT :limit";
    
    $stmt = $db->prepare($sql);
    if ($status !== 'all') {
        $stmt->bindValue(':status', $status);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $jobs = $stmt->fetchAll();
    
    sendResponse(['jobs' => $jobs]);
}

function getJob($db, $id) {
    $stmt = $db->prepare("SELECT * FROM jobs WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $job = $stmt->fetch();
    
    if (!$job) {
        sendResponse(['error' => 'Job not found'], 404);
    }
    
    sendResponse(['job' => $job]);
}

function createJob($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['type'])) {
        sendResponse(['error' => 'Job type is required'], 400);
    }
    
    $type = $input['type'];
    $payload = isset($input['payload']) ? json_encode($input['payload']) : null;
    $priority = $input['priority'] ?? 0;
    $maxAttempts = $input['max_attempts'] ?? 3;
    
    $stmt = $db->prepare("
        INSERT INTO jobs (type, payload, priority, max_attempts, status)
        VALUES (:type, :payload, :priority, :max_attempts, 'pending')
    ");
    
    $stmt->execute([
        'type' => $type,
        'payload' => $payload,
        'priority' => $priority,
        'max_attempts' => $maxAttempts
    ]);
    
    $jobId = $db->lastInsertId();
    
    sendResponse([
        'success' => true,
        'job_id' => $jobId,
        'message' => 'Job created successfully'
    ], 201);
}

function deleteJob($db, $id) {
    $stmt = $db->prepare("DELETE FROM jobs WHERE id = :id");
    $stmt->execute(['id' => $id]);
    
    if ($stmt->rowCount() === 0) {
        sendResponse(['error' => 'Job not found'], 404);
    }
    
    sendResponse(['success' => true, 'message' => 'Job deleted']);
}

function getJobStats($db) {
    $stats = [
        'pending' => 0,
        'running' => 0,
        'completed' => 0,
        'failed' => 0,
        'total' => 0
    ];
    
    $stmt = $db->query("
        SELECT status, COUNT(*) as count
        FROM jobs
        GROUP BY status
    ");
    
    while ($row = $stmt->fetch()) {
        $stats[$row['status']] = (int)$row['count'];
        $stats['total'] += (int)$row['count'];
    }
    
    sendResponse(['stats' => $stats]);
}
