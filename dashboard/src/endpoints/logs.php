<?php

function handleLogs($method, $params, $db) {
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    $type = $params[0] ?? 'access';
    $limit = min((int)($_GET['limit'] ?? 100), 1000);
    $offset = (int)($_GET['offset'] ?? 0);
    $domain = $_GET['domain'] ?? null;
    
    switch ($type) {
        case 'access':
            // Tail access log
            $logFile = NGINX_LOG_PATH . '/access.log';
            $lines = tailLog($logFile, $limit);
            $parsed = array_map('parseAccessLog', array_filter($lines));
            
            // Filter by domain if specified
            if ($domain) {
                $parsed = array_filter($parsed, function($log) use ($domain) {
                    // Check if log has a domain field (from structured logs)
                    if (isset($log['domain']) && $log['domain'] === $domain) {
                        return true;
                    }
                    return false;
                });
                $parsed = array_values($parsed); // Re-index array
            }
            
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
            $sql = "SELECT * FROM access_logs ";
            $params_array = [];
            
            if ($domain) {
                $sql .= "WHERE domain = ? OR host = ? ";
                $params_array[] = $domain;
                $params_array[] = $domain;
            }
            
            $sql .= "ORDER BY timestamp DESC LIMIT ? OFFSET ?";
            $params_array[] = $limit;
            $params_array[] = $offset;
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params_array);
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
    // Parser for CatWAF NGINX access log
    // Format: $host $http_x_real_ip $remote_addr - [$time_local] "$request" ...
    $pattern = '/^(\S+) (\S+) (\S+) - \[([^\]]+)\] "([^"]*)" (\d+) (\d+) "([^"]*)" "([^"]*)"/';
    
    if (preg_match($pattern, $line, $matches)) {
        return [
            'domain' => $matches[1],
            'x_real_ip' => $matches[2] !== '-' ? $matches[2] : null,
            'ip' => $matches[3],
            'timestamp' => $matches[4],
            'request' => $matches[5],
            'status' => (int)$matches[6],
            'size' => (int)$matches[7],
            'referer' => $matches[8],
            'user_agent' => $matches[9],
            'raw' => $line
        ];
    }
    
    return ['raw' => $line];
}
