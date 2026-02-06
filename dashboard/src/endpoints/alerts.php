<?php
// Alert Rules Management API
// GET /api/alerts - Get all alert rules
// GET /api/alerts/:id - Get specific alert rule
// POST /api/alerts - Create new alert rule
// PUT /api/alerts/:id - Update alert rule
// DELETE /api/alerts/:id - Delete alert rule
// GET /api/alerts/history - Get alert history
// POST /api/alerts/history/:id/acknowledge - Acknowledge alert

function handleAlerts($method, $params, $db) {
    switch ($method) {
        case 'GET':
            if (empty($params[0])) {
                // Get all alert rules
                getAllAlertRules($db);
            } elseif ($params[0] === 'history') {
                // Get alert history
                getAlertHistory($db);
            } else {
                // Get specific alert rule
                getAlertRule($db, $params[0]);
            }
            break;
            
        case 'POST':
            if (!empty($params[0]) && $params[0] === 'history' && !empty($params[1]) && $params[2] === 'acknowledge') {
                // Acknowledge alert
                acknowledgeAlert($db, $params[1]);
            } else {
                // Create new alert rule
                createAlertRule($db);
            }
            break;
            
        case 'PUT':
            if (empty($params[0])) {
                sendResponse(['error' => 'Alert rule ID required'], 400);
            }
            updateAlertRule($db, $params[0]);
            break;
            
        case 'DELETE':
            if (empty($params[0])) {
                sendResponse(['error' => 'Alert rule ID required'], 400);
            }
            deleteAlertRule($db, $params[0]);
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

function getAllAlertRules($db) {
    try {
        $stmt = $db->query("
            SELECT ar.*, s.domain as site_domain
            FROM alert_rules ar
            LEFT JOIN sites s ON ar.site_id = s.id
            ORDER BY ar.rule_type, ar.rule_name
        ");
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse JSON config for each rule
        foreach ($rules as &$rule) {
            if (!empty($rule['config'])) {
                $rule['config'] = json_decode($rule['config'], true);
            }
        }
        
        sendResponse(['rules' => $rules]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to fetch alert rules: ' . $e->getMessage()], 500);
    }
}

function getAlertRule($db, $id) {
    try {
        $stmt = $db->prepare("
            SELECT ar.*, s.domain as site_domain
            FROM alert_rules ar
            LEFT JOIN sites s ON ar.site_id = s.id
            WHERE ar.id = ?
        ");
        $stmt->execute([$id]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$rule) {
            sendResponse(['error' => 'Alert rule not found'], 404);
        }
        
        if (!empty($rule['config'])) {
            $rule['config'] = json_decode($rule['config'], true);
        }
        
        sendResponse(['rule' => $rule]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to fetch alert rule: ' . $e->getMessage()], 500);
    }
}

function createAlertRule($db) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['rule_name']) || empty($data['rule_type'])) {
            sendResponse(['error' => 'Rule name and type are required'], 400);
        }
        
        $config = isset($data['config']) ? json_encode($data['config']) : null;
        
        $stmt = $db->prepare("
            INSERT INTO alert_rules (rule_name, rule_type, enabled, site_id, config)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['rule_name'],
            $data['rule_type'],
            $data['enabled'] ?? 1,
            $data['site_id'] ?? null,
            $config
        ]);
        
        $id = $db->lastInsertId();
        
        sendResponse(['success' => true, 'id' => $id, 'message' => 'Alert rule created']);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to create alert rule: ' . $e->getMessage()], 500);
    }
}

function updateAlertRule($db, $id) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $updates = [];
        $values = [];
        
        if (isset($data['rule_name'])) {
            $updates[] = 'rule_name = ?';
            $values[] = $data['rule_name'];
        }
        
        if (isset($data['rule_type'])) {
            $updates[] = 'rule_type = ?';
            $values[] = $data['rule_type'];
        }
        
        if (isset($data['enabled'])) {
            $updates[] = 'enabled = ?';
            $values[] = $data['enabled'];
        }
        
        if (isset($data['site_id'])) {
            $updates[] = 'site_id = ?';
            $values[] = $data['site_id'];
        }
        
        if (isset($data['config'])) {
            $updates[] = 'config = ?';
            $values[] = json_encode($data['config']);
        }
        
        if (empty($updates)) {
            sendResponse(['error' => 'No fields to update'], 400);
        }
        
        $values[] = $id;
        
        $sql = "UPDATE alert_rules SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($values);
        
        sendResponse(['success' => true, 'message' => 'Alert rule updated']);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to update alert rule: ' . $e->getMessage()], 500);
    }
}

function deleteAlertRule($db, $id) {
    try {
        $stmt = $db->prepare("DELETE FROM alert_rules WHERE id = ?");
        $stmt->execute([$id]);
        
        sendResponse(['success' => true, 'message' => 'Alert rule deleted']);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to delete alert rule: ' . $e->getMessage()], 500);
    }
}

function getAlertHistory($db) {
    try {
        $limit = min((int)($_GET['limit'] ?? 100), 1000);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        
        $stmt = $db->prepare("
            SELECT ah.*, ar.rule_name, ar.rule_type
            FROM alert_history ah
            JOIN alert_rules ar ON ah.alert_rule_id = ar.id
            ORDER BY ah.fired_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse JSON alert_data for each entry
        foreach ($history as &$entry) {
            if (!empty($entry['alert_data'])) {
                $entry['alert_data'] = json_decode($entry['alert_data'], true);
            }
        }
        
        // Get total count
        $countStmt = $db->query("SELECT COUNT(*) FROM alert_history");
        $total = $countStmt->fetchColumn();
        
        sendResponse([
            'history' => $history,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to fetch alert history: ' . $e->getMessage()], 500);
    }
}

function acknowledgeAlert($db, $id) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $acknowledgedBy = $data['acknowledged_by'] ?? 'system';
        
        $stmt = $db->prepare("
            UPDATE alert_history 
            SET acknowledged = 1, acknowledged_at = NOW(), acknowledged_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$acknowledgedBy, $id]);
        
        sendResponse(['success' => true, 'message' => 'Alert acknowledged']);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to acknowledge alert: ' . $e->getMessage()], 500);
    }
}
