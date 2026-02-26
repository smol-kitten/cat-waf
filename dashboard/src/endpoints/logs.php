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
            // Query access logs from database (no longer file-based)
            $sql = "SELECT domain, ip_address AS ip, uri AS request_uri, method, 
                           status_code AS status, bytes_sent AS size, 
                           user_agent, referer, response_time, 
                           blocked, blocked_reason, timestamp
                    FROM request_telemetry ";
            $params_array = [];
            
            if ($domain) {
                $sql .= "WHERE domain = ? ";
                $params_array[] = $domain;
            }
            
            $sql .= "ORDER BY timestamp DESC LIMIT ? OFFSET ?";
            $params_array[] = $limit;
            $params_array[] = $offset;
            
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($params_array);
                $logs = $stmt->fetchAll();
                sendResponse(['logs' => $logs, 'count' => count($logs)]);
            } catch (PDOException $e) {
                sendResponse(['logs' => [], 'count' => 0, 'error' => 'Query failed']);
            }
            break;
            
        case 'modsec':
            // Tail ModSecurity audit log (still file-based)
            $logFile = MODSEC_LOG_PATH . '/modsec_audit.log';
            $lines = tailLog($logFile, $limit);
            sendResponse(['logs' => $lines, 'count' => count($lines)]);
            break;
            
        case 'error':
            // Error logs now go to stderr (docker logs). Query modsec_events for WAF-related errors.
            try {
                $sql = "SELECT domain, ip_address, uri, rule_id, severity, 
                               action, message, timestamp 
                        FROM modsec_events ";
                $params_array = [];
                if ($domain) {
                    $sql .= "WHERE domain = ? ";
                    $params_array[] = $domain;
                }
                $sql .= "ORDER BY timestamp DESC LIMIT ? OFFSET ?";
                $params_array[] = $limit;
                $params_array[] = $offset;
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params_array);
                $logs = $stmt->fetchAll();
                sendResponse(['logs' => $logs, 'count' => count($logs), 
                             'info' => 'NGINX error logs are now in container stderr (docker logs). This shows WAF events.']);
            } catch (PDOException $e) {
                sendResponse(['logs' => [], 'count' => 0, 'error' => 'Query failed']);
            }
            break;
            
        case 'database':
            // Get from database (request_telemetry is the primary table)
            $sql = "SELECT domain, uri, method, status_code, ip_address,
                           bytes_sent, response_time, cache_status, backend_server,
                           user_agent, referer, blocked, blocked_reason, timestamp
                    FROM request_telemetry ";
            $params_array = [];
            
            if ($domain) {
                $sql .= "WHERE domain = ? ";
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
    exec("tail -n " . (int)$lines . " " . escapeshellarg($file) . " 2>&1", $result);
    return $result;
}
