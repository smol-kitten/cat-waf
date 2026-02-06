<?php

/**
 * Path-based routing API endpoint
 * Manages path-based backend routing for sub-page routing
 */

function handlePathRoutes($method, $params, $db) {
    error_log("handlePathRoutes called: method=$method, params=" . json_encode($params));
    
    switch ($method) {
        case 'GET':
            if (!empty($params[0]) && $params[0] === 'site') {
                // Get all path routes for a specific site: GET /api/path-routes/site/:site_id
                if (empty($params[1])) {
                    sendResponse(['error' => 'Site ID required'], 400);
                }
                
                $stmt = $db->prepare("
                    SELECT * FROM path_routes 
                    WHERE site_id = ? 
                    ORDER BY priority DESC, path ASC
                ");
                $stmt->execute([$params[1]]);
                $routes = $stmt->fetchAll();
                
                sendResponse(['routes' => $routes]);
            } elseif (!empty($params[0])) {
                // Get specific path route: GET /api/path-routes/:id
                $stmt = $db->prepare("SELECT * FROM path_routes WHERE id = ?");
                $stmt->execute([$params[0]]);
                $route = $stmt->fetch();
                
                if ($route) {
                    sendResponse(['route' => $route]);
                } else {
                    sendResponse(['error' => 'Path route not found'], 404);
                }
            } else {
                // List all path routes
                $stmt = $db->query("
                    SELECT pr.*, s.domain as site_domain 
                    FROM path_routes pr 
                    LEFT JOIN sites s ON pr.site_id = s.id 
                    ORDER BY s.domain ASC, pr.priority DESC, pr.path ASC
                ");
                sendResponse(['routes' => $stmt->fetchAll()]);
            }
            break;
            
        case 'POST':
            // Create new path route
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['site_id']) || empty($data['path']) || empty($data['backend_url'])) {
                sendResponse(['error' => 'Missing required fields: site_id, path, backend_url'], 400);
            }
            
            // Validate site exists
            $stmt = $db->prepare("SELECT id FROM sites WHERE id = ?");
            $stmt->execute([$data['site_id']]);
            if (!$stmt->fetch()) {
                sendResponse(['error' => 'Site not found'], 404);
            }
            
            // Validate path format (must start with /)
            if ($data['path'][0] !== '/') {
                sendResponse(['error' => 'Path must start with /'], 400);
            }
            
            // Validate backend URL format (hostname:port or IP:port)
            $backend = preg_replace('#^https?://#', '', $data['backend_url']);
            
            // More strict validation for hostname:port or IP:port
            $parts = explode(':', $backend);
            if (count($parts) !== 2) {
                sendResponse(['error' => 'Invalid backend URL format. Must be hostname:port or IP:port'], 400);
            }
            
            $host = $parts[0];
            $port = $parts[1];
            
            // Validate port is numeric and in valid range
            if (!is_numeric($port) || $port < 1 || $port > 65535) {
                sendResponse(['error' => 'Invalid port number. Must be 1-65535'], 400);
            }
            
            // Validate hostname (alphanumeric, dots, hyphens) or IP address
            if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$/', $host) && 
                !filter_var($host, FILTER_VALIDATE_IP)) {
                sendResponse(['error' => 'Invalid hostname or IP address'], 400);
            }
            
            $stmt = $db->prepare("
                INSERT INTO path_routes (
                    site_id, path, backend_url, backend_protocol, priority, enabled,
                    strip_path, enable_modsecurity, enable_rate_limit, custom_rate_limit,
                    rate_limit_burst, custom_headers, custom_config, max_body_size
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['site_id'],
                $data['path'],
                $data['backend_url'],
                $data['backend_protocol'] ?? 'http',
                $data['priority'] ?? 0,
                $data['enabled'] ?? 1,
                $data['strip_path'] ?? 0,
                $data['enable_modsecurity'] ?? 1,
                $data['enable_rate_limit'] ?? 1,
                $data['custom_rate_limit'] ?? null,
                $data['rate_limit_burst'] ?? 20,
                $data['custom_headers'] ?? null,
                $data['custom_config'] ?? null,
                $data['max_body_size'] ?? null
            ]);
            
            $routeId = $db->lastInsertId();
            
            // Regenerate site config
            require_once __DIR__ . '/regenerate.php';
            regenerateSiteConfig($data['site_id']);
            
            sendResponse(['success' => true, 'id' => $routeId, 'message' => 'Path route created'], 201);
            break;
            
        case 'PUT':
        case 'PATCH':
            // Update path route
            if (empty($params[0])) {
                sendResponse(['error' => 'Route ID required'], 400);
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data)) {
                sendResponse(['error' => 'No data provided'], 400);
            }
            
            // Build update query dynamically
            $fields = [];
            $values = [];
            
            $updatableFields = [
                'path', 'backend_url', 'backend_protocol', 'priority', 'enabled',
                'strip_path', 'enable_modsecurity', 'enable_rate_limit', 'custom_rate_limit',
                'rate_limit_burst', 'custom_headers', 'custom_config', 'max_body_size'
            ];
            
            foreach ($updatableFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = ?";
                    $values[] = $data[$field];
                }
            }
            
            if (empty($fields)) {
                sendResponse(['error' => 'No valid fields to update'], 400);
            }
            
            $values[] = $params[0];
            $stmt = $db->prepare("UPDATE path_routes SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($values);
            
            // Get site_id to regenerate config
            $stmt = $db->prepare("SELECT site_id FROM path_routes WHERE id = ?");
            $stmt->execute([$params[0]]);
            $route = $stmt->fetch();
            
            if ($route) {
                require_once __DIR__ . '/regenerate.php';
                regenerateSiteConfig($route['site_id']);
            }
            
            sendResponse(['success' => true, 'message' => 'Path route updated']);
            break;
            
        case 'DELETE':
            // Delete path route
            if (empty($params[0])) {
                sendResponse(['error' => 'Route ID required'], 400);
            }
            
            // Get site_id before deleting
            $stmt = $db->prepare("SELECT site_id FROM path_routes WHERE id = ?");
            $stmt->execute([$params[0]]);
            $route = $stmt->fetch();
            
            if (!$route) {
                sendResponse(['error' => 'Path route not found'], 404);
            }
            
            $stmt = $db->prepare("DELETE FROM path_routes WHERE id = ?");
            $stmt->execute([$params[0]]);
            
            // Regenerate site config
            require_once __DIR__ . '/regenerate.php';
            regenerateSiteConfig($route['site_id']);
            
            sendResponse(['success' => true, 'message' => 'Path route deleted']);
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}
