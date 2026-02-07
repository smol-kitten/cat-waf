<?php
/**
 * Router Integration API Endpoint
 * Manages router configurations and DROP rules
 */

require_once __DIR__ . '/../lib/Router/RouterManager.php';

use CatWAF\Router\RouterManager;

function handleRouters($method, $path, $body = null) {
    $pdo = getDbConnection();
    $manager = new RouterManager($pdo);
    
    // Parse path: /routers, /routers/123, /routers/123/test, /routers/123/sync
    $pathParts = explode('/', trim($path, '/'));
    $routerId = isset($pathParts[1]) && is_numeric($pathParts[1]) ? (int)$pathParts[1] : null;
    $action = isset($pathParts[2]) ? $pathParts[2] : null;
    
    // Special routes
    if (isset($pathParts[1])) {
        switch ($pathParts[1]) {
            case 'settings':
                return handleRouterSettings($pdo, $method, $body);
            case 'sync-all':
                if ($method === 'POST') return syncAllRouters($manager);
                break;
            case 'log':
                return getRouterLog($pdo);
            case 'types':
                return getRouterTypes();
        }
    }
    
    switch ($method) {
        case 'GET':
            if ($routerId && $action === 'rules') {
                return getRouterRules($manager, $routerId);
            } elseif ($routerId && $action === 'info') {
                return getRouterInfo($manager, $routerId);
            } elseif ($routerId && $action === 'log') {
                return getRouterLogById($pdo, $routerId);
            } elseif ($routerId) {
                return getRouter($pdo, $routerId);
            }
            return listRouters($pdo);
            
        case 'POST':
            if ($routerId && $action === 'test') {
                return testRouterConnection($manager, $routerId);
            } elseif ($routerId && $action === 'sync') {
                return syncRouter($manager, $pdo, $routerId);
            } elseif ($routerId && $action === 'add-rule') {
                return addRouterRule($manager, $routerId, $body);
            } elseif ($routerId && $action === 'remove-rule') {
                return removeRouterRule($manager, $routerId, $body);
            }
            return createRouter($pdo, $manager, $body);
            
        case 'PUT':
            if ($routerId) {
                return updateRouter($pdo, $manager, $routerId, $body);
            }
            return sendResponse(['error' => 'Router ID required'], 400);
            
        case 'DELETE':
            if ($routerId) {
                return deleteRouter($pdo, $routerId);
            }
            return sendResponse(['error' => 'Router ID required'], 400);
            
        default:
            return sendResponse(['error' => 'Method not allowed'], 405);
    }
}

function listRouters($pdo) {
    $stmt = $pdo->query("
        SELECT r.*, 
            (SELECT COUNT(*) FROM router_rules_cache WHERE router_id = r.id) as cached_rules,
            (SELECT COUNT(*) FROM router_rule_log WHERE router_id = r.id AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as recent_actions
        FROM router_configs r
        ORDER BY r.name
    ");
    
    $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Remove sensitive data
    foreach ($routers as &$router) {
        unset($router['password_encrypted']);
        unset($router['api_key']);
    }
    
    return sendResponse(['routers' => $routers]);
}

function getRouter($pdo, $routerId) {
    $stmt = $pdo->prepare("
        SELECT r.*, 
            (SELECT COUNT(*) FROM router_rules_cache WHERE router_id = r.id) as cached_rules
        FROM router_configs r
        WHERE r.id = ?
    ");
    $stmt->execute([$routerId]);
    $router = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$router) {
        return sendResponse(['error' => 'Router not found'], 404);
    }
    
    // Remove sensitive data but indicate if set
    $router['has_password'] = !empty($router['password_encrypted']);
    $router['has_api_key'] = !empty($router['api_key']);
    unset($router['password_encrypted']);
    unset($router['api_key']);
    
    // Get recent log entries
    $logStmt = $pdo->prepare("
        SELECT * FROM router_rule_log 
        WHERE router_id = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $logStmt->execute([$routerId]);
    $router['recent_log'] = $logStmt->fetchAll(PDO::FETCH_ASSOC);
    
    return sendResponse(['router' => $router]);
}

function createRouter($pdo, $manager, $body) {
    if (!$body || !isset($body['name'], $body['router_type'], $body['host'])) {
        return sendResponse(['error' => 'Missing required fields: name, router_type, host'], 400);
    }
    
    $validTypes = ['mikrotik', 'opnsense', 'pfsense', 'iptables', 'nftables', 'custom'];
    if (!in_array($body['router_type'], $validTypes)) {
        return sendResponse(['error' => 'Invalid router type'], 400);
    }
    
    try {
        // Encrypt password if provided
        $passwordEncrypted = null;
        if (!empty($body['password'])) {
            $passwordEncrypted = $manager->encryptPassword($body['password']);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO router_configs 
            (name, router_type, host, port, username, password_encrypted, api_key, 
             ssl_enabled, ssl_verify, enabled, whitelist_subnets, address_list_name, 
             rule_chain, rule_comment_prefix, sync_on_ban, sync_on_unban)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $body['name'],
            $body['router_type'],
            $body['host'],
            $body['port'] ?? 8728,
            $body['username'] ?? null,
            $passwordEncrypted,
            $body['api_key'] ?? null,
            $body['ssl_enabled'] ?? 0,
            $body['ssl_verify'] ?? 1,
            $body['enabled'] ?? 1,
            json_encode($body['whitelist_subnets'] ?? []),
            $body['address_list_name'] ?? 'catwaf-banned',
            $body['rule_chain'] ?? 'forward',
            $body['rule_comment_prefix'] ?? '[CatWAF]',
            $body['sync_on_ban'] ?? 1,
            $body['sync_on_unban'] ?? 1
        ]);
        
        $routerId = $pdo->lastInsertId();
        
        return sendResponse(['success' => true, 'router_id' => $routerId, 'message' => 'Router created']);
        
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            return sendResponse(['error' => 'A router with this name already exists'], 409);
        }
        throw $e;
    }
}

function updateRouter($pdo, $manager, $routerId, $body) {
    $checkStmt = $pdo->prepare("SELECT id FROM router_configs WHERE id = ?");
    $checkStmt->execute([$routerId]);
    if (!$checkStmt->fetch()) {
        return sendResponse(['error' => 'Router not found'], 404);
    }
    
    $updates = [];
    $params = [];
    
    $allowedFields = ['name', 'host', 'port', 'username', 'ssl_enabled', 'ssl_verify', 
                      'enabled', 'address_list_name', 'rule_chain', 'rule_comment_prefix',
                      'sync_on_ban', 'sync_on_unban'];
    
    foreach ($allowedFields as $field) {
        if (isset($body[$field])) {
            $updates[] = "$field = ?";
            $params[] = $body[$field];
        }
    }
    
    // Handle password update
    if (isset($body['password']) && !empty($body['password'])) {
        $updates[] = "password_encrypted = ?";
        $params[] = $manager->encryptPassword($body['password']);
    }
    
    // Handle whitelist subnets
    if (isset($body['whitelist_subnets'])) {
        $updates[] = "whitelist_subnets = ?";
        $params[] = json_encode($body['whitelist_subnets']);
    }
    
    if (empty($updates)) {
        return sendResponse(['error' => 'No valid fields to update'], 400);
    }
    
    $params[] = $routerId;
    $sql = "UPDATE router_configs SET " . implode(', ', $updates) . " WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return sendResponse(['success' => true, 'message' => 'Router updated']);
}

function deleteRouter($pdo, $routerId) {
    $stmt = $pdo->prepare("DELETE FROM router_configs WHERE id = ?");
    $stmt->execute([$routerId]);
    
    if ($stmt->rowCount() === 0) {
        return sendResponse(['error' => 'Router not found'], 404);
    }
    
    return sendResponse(['success' => true, 'message' => 'Router deleted']);
}

function testRouterConnection($manager, $routerId) {
    $result = $manager->testConnection($routerId);
    return sendResponse($result);
}

function syncRouter($manager, $pdo, $routerId) {
    $adapter = $manager->getAdapter($routerId);
    
    if (!$adapter) {
        return sendResponse(['error' => 'Router not found or disabled'], 404);
    }
    
    // Get banned IPs
    $stmt = $pdo->query("
        SELECT DISTINCT ip_address 
        FROM banned_ips 
        WHERE (expires_at IS NULL OR expires_at > NOW())
    ");
    $bannedIps = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $result = $adapter->syncRules($bannedIps);
    return sendResponse($result);
}

function syncAllRouters($manager) {
    $results = $manager->syncAll();
    
    $totalAdded = 0;
    $totalRemoved = 0;
    $errors = [];
    
    foreach ($results as $routerId => $result) {
        $totalAdded += $result['added'] ?? 0;
        $totalRemoved += $result['removed'] ?? 0;
        if (!$result['success']) {
            $errors[$routerId] = $result['message'];
        }
    }
    
    return sendResponse([
        'success' => empty($errors),
        'routers_synced' => count($results),
        'total_added' => $totalAdded,
        'total_removed' => $totalRemoved,
        'errors' => $errors
    ]);
}

function getRouterRules($manager, $routerId) {
    $adapter = $manager->getAdapter($routerId);
    
    if (!$adapter) {
        return sendResponse(['error' => 'Router not found or disabled'], 404);
    }
    
    $result = $adapter->listDropRules();
    return sendResponse($result);
}

function getRouterInfo($manager, $routerId) {
    $adapter = $manager->getAdapter($routerId);
    
    if (!$adapter) {
        return sendResponse(['error' => 'Router not found or disabled'], 404);
    }
    
    $info = $adapter->getInfo();
    return sendResponse(['info' => $info]);
}

function addRouterRule($manager, $routerId, $body) {
    if (!$body || !isset($body['ip'])) {
        return sendResponse(['error' => 'IP address required'], 400);
    }
    
    $adapter = $manager->getAdapter($routerId);
    
    if (!$adapter) {
        return sendResponse(['error' => 'Router not found or disabled'], 404);
    }
    
    $result = $adapter->addDropRule(
        $body['ip'],
        $body['duration'] ?? null,
        $body['comment'] ?? ''
    );
    
    return sendResponse($result);
}

function removeRouterRule($manager, $routerId, $body) {
    if (!$body || !isset($body['ip'])) {
        return sendResponse(['error' => 'IP address required'], 400);
    }
    
    $adapter = $manager->getAdapter($routerId);
    
    if (!$adapter) {
        return sendResponse(['error' => 'Router not found or disabled'], 404);
    }
    
    $result = $adapter->removeDropRule($body['ip']);
    return sendResponse($result);
}

function getRouterLog($pdo) {
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
    $status = $_GET['status'] ?? null;
    
    $sql = "
        SELECT l.*, r.name as router_name 
        FROM router_rule_log l
        JOIN router_configs r ON l.router_id = r.id
    ";
    $params = [];
    
    if ($status) {
        $sql .= " WHERE l.status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY l.created_at DESC LIMIT ?";
    $params[] = $limit;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return sendResponse(['log' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function getRouterLogById($pdo, $routerId) {
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
    
    $stmt = $pdo->prepare("
        SELECT * FROM router_rule_log 
        WHERE router_id = ? 
        ORDER BY created_at DESC 
        LIMIT ?
    ");
    $stmt->execute([$routerId, $limit]);
    
    return sendResponse(['log' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function handleRouterSettings($pdo, $method, $body) {
    if ($method === 'GET') {
        $stmt = $pdo->query("
            SELECT setting_key, setting_value 
            FROM settings 
            WHERE setting_key LIKE 'router_%'
        ");
        
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['setting_key'] !== 'router_encryption_key') { // Don't expose encryption key
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        
        return sendResponse(['settings' => $settings]);
    }
    
    if ($method === 'PUT' && $body) {
        $allowedSettings = ['router_integration_enabled', 'router_sync_interval', 'router_dry_run', 'router_default_ban_duration'];
        
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
    
    return sendResponse(['error' => 'Method not allowed'], 405);
}

function getRouterTypes() {
    return sendResponse([
        'types' => [
            ['value' => 'mikrotik', 'label' => 'MikroTik RouterOS', 'supported' => true, 'port' => 8728],
            ['value' => 'opnsense', 'label' => 'OPNsense', 'supported' => false, 'port' => 443],
            ['value' => 'pfsense', 'label' => 'pfSense', 'supported' => false, 'port' => 443],
            ['value' => 'iptables', 'label' => 'iptables (SSH)', 'supported' => false, 'port' => 22],
            ['value' => 'nftables', 'label' => 'nftables (SSH)', 'supported' => false, 'port' => 22],
            ['value' => 'custom', 'label' => 'Custom Script', 'supported' => false, 'port' => null]
        ]
    ]);
}
