<?php
/**
 * Custom Block Rules API Endpoint
 * Manages path-based blocking rules that integrate with ModSecurity
 */

function handleCustomBlockRules($method, $params, $db) {
    $action = $params[0] ?? 'list';
    
    switch ($method) {
        case 'GET':
            handleGetCustomBlockRules($action, $db);
            break;
        case 'POST':
            handleCreateCustomBlockRule($db);
            break;
        case 'PUT':
            handleUpdateCustomBlockRule($params, $db);
            break;
        case 'DELETE':
            handleDeleteCustomBlockRule($params, $db);
            break;
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

function handleGetCustomBlockRules($action, $db) {
    if ($action === 'regenerate') {
        // Regenerate ModSecurity rules file
        $result = regenerateCustomBlockRules($db);
        sendResponse($result);
        return;
    }
    
    // List all custom block rules
    $stmt = $db->query("
        SELECT 
            id, name, pattern, pattern_type, enabled, 
            block_message, severity, rule_id, 
            created_at, updated_at, created_by
        FROM custom_block_rules 
        ORDER BY severity DESC, name ASC
    ");
    
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    sendResponse($rules);
}

function handleCreateCustomBlockRule($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (empty($data['name']) || empty($data['pattern'])) {
        sendResponse(['error' => 'Name and pattern are required'], 400);
        return;
    }
    
    // Validate pattern type
    $validTypes = ['exact', 'prefix', 'suffix', 'contains', 'regex'];
    $patternType = $data['pattern_type'] ?? 'exact';
    if (!in_array($patternType, $validTypes)) {
        sendResponse(['error' => 'Invalid pattern type'], 400);
        return;
    }
    
    // Validate severity
    $validSeverities = ['CRITICAL', 'WARNING', 'NOTICE'];
    $severity = $data['severity'] ?? 'CRITICAL';
    if (!in_array($severity, $validSeverities)) {
        sendResponse(['error' => 'Invalid severity'], 400);
        return;
    }
    
    // Generate unique rule ID (10000-19999 range for custom rules)
    $stmt = $db->query("SELECT MAX(rule_id) as max_id FROM custom_block_rules WHERE rule_id >= 10000 AND rule_id < 20000");
    $result = $stmt->fetch();
    $ruleId = ($result['max_id'] ?? 9999) + 1;
    
    if ($ruleId >= 20000) {
        sendResponse(['error' => 'Maximum custom rules limit reached (9999 rules)'], 400);
        return;
    }
    
    try {
        $stmt = $db->prepare("
            INSERT INTO custom_block_rules 
            (name, pattern, pattern_type, enabled, block_message, severity, rule_id, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'admin')
        ");
        
        $stmt->execute([
            $data['name'],
            $data['pattern'],
            $patternType,
            $data['enabled'] ?? 1,
            $data['block_message'] ?? 'Access forbidden',
            $severity,
            $ruleId
        ]);
        
        $id = $db->lastInsertId();
        
        // Regenerate ModSecurity rules
        regenerateCustomBlockRules($db);
        
        sendResponse([
            'success' => true,
            'id' => $id,
            'rule_id' => $ruleId,
            'message' => 'Custom block rule created successfully'
        ], 201);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            sendResponse(['error' => 'Pattern already exists'], 409);
        } else {
            sendResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
        }
    }
}

function handleUpdateCustomBlockRule($params, $db) {
    $id = $params[1] ?? null;
    if (!$id) {
        sendResponse(['error' => 'Rule ID required'], 400);
        return;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $updates = [];
    $values = [];
    
    $allowedFields = ['name', 'pattern', 'pattern_type', 'enabled', 'block_message', 'severity'];
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $values[] = $data[$field];
        }
    }
    
    if (empty($updates)) {
        sendResponse(['error' => 'No fields to update'], 400);
        return;
    }
    
    $values[] = $id;
    
    try {
        $stmt = $db->prepare("
            UPDATE custom_block_rules 
            SET " . implode(', ', $updates) . " 
            WHERE id = ?
        ");
        
        $stmt->execute($values);
        
        // Regenerate ModSecurity rules
        regenerateCustomBlockRules($db);
        
        sendResponse([
            'success' => true,
            'message' => 'Custom block rule updated successfully'
        ]);
    } catch (PDOException $e) {
        sendResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

function handleDeleteCustomBlockRule($params, $db) {
    $id = $params[1] ?? null;
    if (!$id) {
        sendResponse(['error' => 'Rule ID required'], 400);
        return;
    }
    
    try {
        $stmt = $db->prepare("DELETE FROM custom_block_rules WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() === 0) {
            sendResponse(['error' => 'Rule not found'], 404);
            return;
        }
        
        // Regenerate ModSecurity rules
        regenerateCustomBlockRules($db);
        
        sendResponse([
            'success' => true,
            'message' => 'Custom block rule deleted successfully'
        ]);
    } catch (PDOException $e) {
        sendResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

function regenerateCustomBlockRules($db) {
    try {
        // Fetch all enabled rules
        $stmt = $db->query("
            SELECT id, pattern, pattern_type, block_message, severity, rule_id 
            FROM custom_block_rules 
            WHERE enabled = 1 
            ORDER BY rule_id ASC
        ");
        
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate ModSecurity rules file
        $rulesContent = "# Custom Block Rules - Auto-generated by CatWAF\n";
        $rulesContent .= "# Generated: " . date('Y-m-d H:i:s') . "\n";
        $rulesContent .= "# Total rules: " . count($rules) . "\n\n";
        
        foreach ($rules as $rule) {
            $rulesContent .= generateModSecRule($rule);
        }
        
        // Write to custom rules file
        $rulesFile = '/etc/nginx/modsecurity/custom-rules/path-blocks.conf';
        $result = file_put_contents($rulesFile, $rulesContent);
        
        if ($result === false) {
            return [
                'success' => false,
                'error' => 'Failed to write rules file'
            ];
        }
        
        // Reload NGINX
        $output = [];
        $returnCode = 0;
        exec('docker exec waf-nginx nginx -t 2>&1', $output, $returnCode);
        
        if ($returnCode === 0) {
            exec('docker exec waf-nginx nginx -s reload 2>&1', $output, $returnCode);
            
            return [
                'success' => true,
                'message' => 'Custom block rules regenerated and applied',
                'rules_count' => count($rules),
                'reload_output' => implode("\n", $output)
            ];
        } else {
            return [
                'success' => false,
                'error' => 'NGINX config test failed',
                'output' => implode("\n", $output)
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Failed to regenerate rules: ' . $e->getMessage()
        ];
    }
}

function generateModSecRule($rule) {
    $ruleId = $rule['rule_id'];
    $pattern = $rule['pattern'];
    $patternType = $rule['pattern_type'];
    $message = $rule['block_message'];
    $severity = strtolower($rule['severity']);
    
    // Convert severity to ModSecurity severity level
    $severityMap = [
        'critical' => '2',  // CRITICAL
        'warning' => '3',   // ERROR
        'notice' => '4'     // WARNING
    ];
    $modSecSeverity = $severityMap[$severity] ?? '2';
    
    // Build the operator based on pattern type
    $operator = '';
    switch ($patternType) {
        case 'exact':
            $operator = "@streq \"$pattern\"";
            break;
        case 'prefix':
            $operator = "@beginsWith \"$pattern\"";
            break;
        case 'suffix':
            $operator = "@endsWith \"$pattern\"";
            break;
        case 'contains':
            $operator = "@contains \"$pattern\"";
            break;
        case 'regex':
            $operator = "@rx \"$pattern\"";
            break;
        default:
            $operator = "@streq \"$pattern\"";
    }
    
    $ruleContent = "# Rule $ruleId: Block pattern '$pattern' ($patternType)\n";
    $ruleContent .= "SecRule REQUEST_URI \"$operator\" \\\n";
    $ruleContent .= "    \"id:$ruleId,\\\n";
    $ruleContent .= "    phase:1,\\\n";
    $ruleContent .= "    t:none,t:urlDecodeUni,t:lowercase,\\\n";
    $ruleContent .= "    deny,\\\n";
    $ruleContent .= "    status:403,\\\n";
    $ruleContent .= "    log,\\\n";
    $ruleContent .= "    severity:'$modSecSeverity',\\\n";
    $ruleContent .= "    msg:'$message',\\\n";
    $ruleContent .= "    tag:'custom-block',\\\n";
    $ruleContent .= "    tag:'path-protection'\"\n\n";
    
    return $ruleContent;
}
