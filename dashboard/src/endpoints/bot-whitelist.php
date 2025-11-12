<?php

/**
 * Bot Whitelist Management API
 * 
 * Endpoints:
 * - GET /api/bot-whitelist - List all bot rules
 * - GET /api/bot-whitelist/:id - Get specific bot rule
 * - POST /api/bot-whitelist - Create new bot rule
 * - PATCH /api/bot-whitelist/:id - Update bot rule
 * - DELETE /api/bot-whitelist/:id - Delete bot rule
 * - POST /api/bot-whitelist/regenerate - Regenerate nginx bot-protection.conf
 */

function handleBotWhitelist($method, $params, $db) {
    switch ($method) {
        case 'GET':
            if (empty($params[0])) {
                // List all bot rules
                $stmt = $db->query("
                    SELECT * FROM bot_whitelist 
                    ORDER BY priority ASC, id ASC
                ");
                sendResponse(['bots' => $stmt->fetchAll()]);
            } else {
                // Get specific bot rule
                $stmt = $db->prepare("SELECT * FROM bot_whitelist WHERE id = ?");
                $stmt->execute([$params[0]]);
                $bot = $stmt->fetch();
                if ($bot) {
                    sendResponse(['bot' => $bot]);
                } else {
                    sendResponse(['error' => 'Bot rule not found'], 404);
                }
            }
            break;
            
        case 'POST':
            // Check if this is regenerate action
            if (!empty($params[0]) && $params[0] === 'regenerate') {
                $result = regenerateBotProtectionConfig($db);
                if ($result['success']) {
                    sendResponse($result);
                } else {
                    sendResponse($result, 500);
                }
                break;
            }
            
            // Create new bot rule
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['pattern'])) {
                sendResponse(['error' => 'Pattern is required'], 400);
            }
            
            if (empty($data['action']) || !in_array($data['action'], ['allow', 'flag', 'block'])) {
                sendResponse(['error' => 'Action must be allow, flag, or block'], 400);
            }
            
            $stmt = $db->prepare("
                INSERT INTO bot_whitelist (pattern, action, description, enabled, priority)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['pattern'],
                $data['action'],
                $data['description'] ?? null,
                $data['enabled'] ?? 1,
                $data['priority'] ?? 100
            ]);
            
            $botId = $db->lastInsertId();
            
            // Auto-regenerate nginx config
            regenerateBotProtectionConfig($db);
            
            sendResponse(['success' => true, 'id' => $botId, 'message' => 'Bot rule created'], 201);
            break;
            
        case 'PATCH':
            // Update bot rule
            if (empty($params[0])) {
                sendResponse(['error' => 'Bot ID required'], 400);
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data)) {
                sendResponse(['error' => 'No data provided'], 400);
            }
            
            // Validate action if provided
            if (isset($data['action']) && !in_array($data['action'], ['allow', 'flag', 'block'])) {
                sendResponse(['error' => 'Action must be allow, flag, or block'], 400);
            }
            
            $fields = [];
            $values = [];
            
            $updatableFields = ['pattern', 'action', 'description', 'enabled', 'priority'];
            
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
            $stmt = $db->prepare("UPDATE bot_whitelist SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($values);
            
            // Auto-regenerate nginx config
            regenerateBotProtectionConfig($db);
            
            sendResponse(['success' => true, 'message' => 'Bot rule updated']);
            break;
            
        case 'DELETE':
            // Delete bot rule
            if (empty($params[0])) {
                sendResponse(['error' => 'Bot ID required'], 400);
            }
            
            $stmt = $db->prepare("DELETE FROM bot_whitelist WHERE id = ?");
            $stmt->execute([$params[0]]);
            
            // Auto-regenerate nginx config
            regenerateBotProtectionConfig($db);
            
            sendResponse(['success' => true, 'message' => 'Bot rule deleted']);
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Regenerate nginx bot-protection.conf from database
 * Returns: ['success' => bool, 'message' => string, 'rules' => int]
 */
function regenerateBotProtectionConfig($db) {
    // Fetch all enabled bot rules ordered by priority
    $stmt = $db->query("
        SELECT pattern, action, description 
        FROM bot_whitelist 
        WHERE enabled = 1 
        ORDER BY priority ASC, id ASC
    ");
    $bots = $stmt->fetchAll();
    
    // Convert action to nginx map value
    $actionMap = [
        'allow' => 0,
        'flag' => 1,
        'block' => 2
    ];
    
    // Generate config content
    $config = "# Bot Detection Map\n";
    $config .= "# Auto-generated from database bot_whitelist table\n";
    $config .= "# DO NOT EDIT MANUALLY - Changes will be overwritten\n";
    $config .= "# Last updated: " . date('Y-m-d H:i:s') . "\n\n";
    $config .= "map \$http_user_agent \$bot_detected {\n";
    $config .= "    default 0;  # 0 = allow, 1 = flag (rate limit), 2 = block\n\n";
    
    // Group by action for better organization
    $grouped = ['allow' => [], 'flag' => [], 'block' => []];
    foreach ($bots as $bot) {
        $grouped[$bot['action']][] = $bot;
    }
    
    // Add allow rules first
    if (!empty($grouped['allow'])) {
        $config .= "    # Good bots (allow)\n";
        foreach ($grouped['allow'] as $bot) {
            $comment = $bot['description'] ? "  # {$bot['description']}" : "";
            $config .= "    {$bot['pattern']} {$actionMap['allow']};{$comment}\n";
        }
        $config .= "\n";
    }
    
    // Add flag rules
    if (!empty($grouped['flag'])) {
        $config .= "    # Suspicious bots (flag for rate limiting)\n";
        foreach ($grouped['flag'] as $bot) {
            $comment = $bot['description'] ? "  # {$bot['description']}" : "";
            $config .= "    {$bot['pattern']} {$actionMap['flag']};{$comment}\n";
        }
        $config .= "\n";
    }
    
    // Add block rules
    if (!empty($grouped['block'])) {
        $config .= "    # Bad bots (block)\n";
        foreach ($grouped['block'] as $bot) {
            $comment = $bot['description'] ? "  # {$bot['description']}" : "";
            $config .= "    {$bot['pattern']} {$actionMap['block']};{$comment}\n";
        }
    }
    
    $config .= "}\n";
    
    // Write to nginx container
    $configPath = "/etc/nginx/conf.d/bot-protection.conf";
    $tempFile = "/tmp/bot-protection-" . uniqid() . ".conf";
    
    // Write to temp file first
    if (!file_put_contents($tempFile, $config)) {
        return [
            'success' => false,
            'message' => 'Failed to write temporary config file'
        ];
    }
    
    // Copy to nginx container
    $copyCmd = "docker cp {$tempFile} waf-nginx:{$configPath} 2>&1";
    exec($copyCmd, $copyOutput, $copyReturn);
    
    // Clean up temp file
    unlink($tempFile);
    
    if ($copyReturn !== 0) {
        return [
            'success' => false,
            'message' => 'Failed to copy config to nginx container: ' . implode("\n", $copyOutput)
        ];
    }
    
    // Test nginx config before reloading
    exec("docker exec waf-nginx nginx -t 2>&1", $testOutput, $testReturn);
    
    if ($testReturn !== 0) {
        return [
            'success' => false,
            'message' => 'Nginx config test failed: ' . implode("\n", $testOutput)
        ];
    }
    
    // Reload nginx
    exec("docker exec waf-nginx nginx -s reload 2>&1", $reloadOutput, $reloadReturn);
    
    if ($reloadReturn !== 0) {
        return [
            'success' => false,
            'message' => 'Failed to reload nginx: ' . implode("\n", $reloadOutput)
        ];
    }
    
    return [
        'success' => true,
        'message' => 'Bot protection config regenerated and nginx reloaded',
        'rules' => count($bots)
    ];
}
