<?php

function handleLogs($method, $params, $db) {
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    $type = $params[0] ?? 'access';
    $limit = min((int)($_GET['limit'] ?? 100), 1000);
    $offset = (int)($_GET['offset'] ?? 0);
    
    switch ($type) {
        case 'access':
            // Tail access log
            $logFile = NGINX_LOG_PATH . '/access.log';
            $lines = tailLog($logFile, $limit);
            $parsed = array_map('parseAccessLog', array_filter($lines));
            sendResponse(['logs' => $parsed, 'count' => count($parsed)]);
            break;
            
        case 'modsec':
            // Tail ModSecurity audit log
            $logFile = MODSEC_LOG_PATH . '/modsec_audit.log';
            $lines = tailLog($logFile, $limit);
            sendResponse(['logs' => $lines, 'count' => count($lines)]);
            break;
            
        case 'error':
            // Tail error log
            $logFile = NGINX_LOG_PATH . '/error.log';
            $lines = tailLog($logFile, $limit);
            sendResponse(['logs' => $lines, 'count' => count($lines)]);
            break;
            
        case 'database':
            // Get from database
            $stmt = $db->prepare("
                SELECT * FROM access_logs 
                ORDER BY timestamp DESC 
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$limit, $offset]);
            sendResponse(['logs' => $stmt->fetchAll()]);
            break;
            
        default:
            sendResponse(['error' => 'Invalid log type'], 400);
    }
}

function tailLog($file, $lines = 100) {
    if (!file_exists($file)) {
        return [];
    }
    
    $result = [];
    exec("tail -n $lines " . escapeshellarg($file) . " 2>&1", $result);
    return $result;
}

function parseAccessLog($line) {
    // Simple parser for NGINX access log
    // Format: IP - - [timestamp] "request" status size "referer" "user-agent"
    $pattern = '/^(\S+) \S+ \S+ \[([^\]]+)\] "([^"]*)" (\d+) (\d+) "([^"]*)" "([^"]*)"/';
    
    if (preg_match($pattern, $line, $matches)) {
        return [
            'ip' => $matches[1],
            'timestamp' => $matches[2],
            'request' => $matches[3],
            'status' => (int)$matches[4],
            'size' => (int)$matches[5],
            'referer' => $matches[6],
            'user_agent' => $matches[7]
        ];
    }
    
    return ['raw' => $line];
}
