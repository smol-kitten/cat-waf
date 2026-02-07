<?php
/**
 * Bot Protection Center API
 * Comprehensive bot management endpoint
 */

function handleBotCenter($method, $path, $body = null) {
    $pdo = getDbConnection();
    
    $pathParts = explode('/', trim($path, '/'));
    $action = $pathParts[1] ?? null;
    $id = isset($pathParts[2]) && is_numeric($pathParts[2]) ? (int)$pathParts[2] : null;
    
    switch ($action) {
        case 'dashboard':
            return getBotDashboard($pdo);
        case 'detections':
            return getBotDetections($pdo);
        case 'rules':
            return handleBotRules($pdo, $method, $id, $body);
        case 'patterns':
            return handleBotPatterns($pdo, $method, $id, $body);
        case 'challenges':
            return handleBotChallenges($pdo, $method, $id, $body);
        case 'ips':
            return getBotIPs($pdo);
        case 'timeline':
            return getBotTimeline($pdo);
        case 'export':
            return exportBotRules($pdo);
        case 'import':
            return importBotRules($pdo, $body);
        case 'settings':
            return handleBotSettings($pdo, $method, $body);
        default:
            return getBotDashboard($pdo);
    }
}

function getBotDashboard($pdo) {
    // Get comprehensive bot statistics
    $period = $_GET['period'] ?? '24h';
    $hours = match($period) {
        '1h' => 1,
        '6h' => 6,
        '24h' => 24,
        '7d' => 168,
        '30d' => 720,
        default => 24
    };
    
    $stats = [
        'period' => $period,
        'total_requests' => 0,
        'bot_requests' => 0,
        'human_requests' => 0,
        'good_bots' => 0,
        'bad_bots' => 0,
        'blocked' => 0,
        'challenged' => 0,
        'allowed' => 0,
        'unique_bots' => 0,
        'unique_ips' => 0
    ];
    
    // Total bot detections
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN bot_type = 'good' THEN 1 ELSE 0 END) as good_bots,
            SUM(CASE WHEN bot_type = 'bad' THEN 1 ELSE 0 END) as bad_bots,
            SUM(CASE WHEN action = 'blocked' THEN 1 ELSE 0 END) as blocked,
            SUM(CASE WHEN action = 'challenged' THEN 1 ELSE 0 END) as challenged,
            SUM(CASE WHEN action = 'allowed' THEN 1 ELSE 0 END) as allowed,
            COUNT(DISTINCT bot_name) as unique_bots,
            COUNT(DISTINCT ip_address) as unique_ips
        FROM bot_detections 
        WHERE timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)
    ");
    $stmt->execute([$hours]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        $stats['bot_requests'] = (int)$result['total'];
        $stats['good_bots'] = (int)$result['good_bots'];
        $stats['bad_bots'] = (int)$result['bad_bots'];
        $stats['blocked'] = (int)$result['blocked'];
        $stats['challenged'] = (int)$result['challenged'];
        $stats['allowed'] = (int)$result['allowed'];
        $stats['unique_bots'] = (int)$result['unique_bots'];
        $stats['unique_ips'] = (int)$result['unique_ips'];
    }
    
    // Get total requests from telemetry
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM request_telemetry WHERE timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)");
    $stmt->execute([$hours]);
    $stats['total_requests'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $stats['human_requests'] = $stats['total_requests'] - $stats['bot_requests'];
    
    // Top detected bots
    $stmt = $pdo->prepare("
        SELECT bot_name, bot_type, action, COUNT(*) as count,
               COUNT(DISTINCT ip_address) as unique_ips,
               MAX(timestamp) as last_seen
        FROM bot_detections 
        WHERE timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)
        GROUP BY bot_name, bot_type, action
        ORDER BY count DESC
        LIMIT 10
    ");
    $stmt->execute([$hours]);
    $stats['top_bots'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent activity
    $stmt = $pdo->query("
        SELECT bot_name, bot_type, action, ip_address, domain, timestamp
        FROM bot_detections
        ORDER BY timestamp DESC
        LIMIT 20
    ");
    $stats['recent_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Rule counts
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) as enabled,
            SUM(CASE WHEN action = 'allow' THEN 1 ELSE 0 END) as allow_rules,
            SUM(CASE WHEN action = 'block' THEN 1 ELSE 0 END) as block_rules
        FROM bot_whitelist
    ");
    $stats['rules'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return sendResponse(['dashboard' => $stats]);
}

function getBotDetections($pdo) {
    $limit = min((int)($_GET['limit'] ?? 100), 1000);
    $offset = (int)($_GET['offset'] ?? 0);
    $botType = $_GET['bot_type'] ?? null;
    $action = $_GET['action'] ?? null;
    $botName = $_GET['bot_name'] ?? null;
    $ip = $_GET['ip'] ?? null;
    
    $where = ["1=1"];
    $params = [];
    
    if ($botType) {
        $where[] = "bot_type = ?";
        $params[] = $botType;
    }
    if ($action) {
        $where[] = "action = ?";
        $params[] = $action;
    }
    if ($botName) {
        $where[] = "bot_name LIKE ?";
        $params[] = "%{$botName}%";
    }
    if ($ip) {
        $where[] = "ip_address = ?";
        $params[] = $ip;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Get count
    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM bot_detections WHERE {$whereClause}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get detections
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare("
        SELECT * FROM bot_detections 
        WHERE {$whereClause}
        ORDER BY timestamp DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    
    return sendResponse([
        'detections' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ]);
}

function handleBotRules($pdo, $method, $id, $body) {
    switch ($method) {
        case 'GET':
            if ($id) {
                $stmt = $pdo->prepare("SELECT * FROM bot_whitelist WHERE id = ?");
                $stmt->execute([$id]);
                $rule = $stmt->fetch(PDO::FETCH_ASSOC);
                return $rule ? sendResponse(['rule' => $rule]) : sendResponse(['error' => 'Rule not found'], 404);
            }
            
            $stmt = $pdo->query("
                SELECT bw.*, 
                    (SELECT COUNT(*) FROM bot_detections WHERE user_agent REGEXP REPLACE(bw.pattern, '~*', '')) as match_count
                FROM bot_whitelist bw
                ORDER BY bw.priority ASC, bw.id ASC
            ");
            return sendResponse(['rules' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            
        case 'POST':
            if (!$body || empty($body['pattern'])) {
                return sendResponse(['error' => 'Pattern is required'], 400);
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO bot_whitelist (pattern, action, description, enabled, priority, category)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $body['pattern'],
                $body['action'] ?? 'allow',
                $body['description'] ?? null,
                $body['enabled'] ?? 1,
                $body['priority'] ?? 100,
                $body['category'] ?? 'custom'
            ]);
            
            regenerateBotConfig($pdo);
            return sendResponse(['success' => true, 'id' => $pdo->lastInsertId()], 201);
            
        case 'PUT':
            if (!$id) return sendResponse(['error' => 'Rule ID required'], 400);
            
            $updates = [];
            $params = [];
            
            foreach (['pattern', 'action', 'description', 'enabled', 'priority', 'category'] as $field) {
                if (isset($body[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $body[$field];
                }
            }
            
            if (empty($updates)) {
                return sendResponse(['error' => 'No fields to update'], 400);
            }
            
            $params[] = $id;
            $stmt = $pdo->prepare("UPDATE bot_whitelist SET " . implode(', ', $updates) . " WHERE id = ?");
            $stmt->execute($params);
            
            regenerateBotConfig($pdo);
            return sendResponse(['success' => true]);
            
        case 'DELETE':
            if (!$id) return sendResponse(['error' => 'Rule ID required'], 400);
            
            $stmt = $pdo->prepare("DELETE FROM bot_whitelist WHERE id = ?");
            $stmt->execute([$id]);
            
            regenerateBotConfig($pdo);
            return sendResponse(['success' => true]);
            
        default:
            return sendResponse(['error' => 'Method not allowed'], 405);
    }
}

function handleBotPatterns($pdo, $method, $id, $body) {
    // Preset bot patterns for easy selection
    $presetPatterns = [
        'search_engines' => [
            ['pattern' => 'googlebot', 'action' => 'allow', 'description' => 'Google Search Bot', 'category' => 'search'],
            ['pattern' => 'bingbot', 'action' => 'allow', 'description' => 'Bing Search Bot', 'category' => 'search'],
            ['pattern' => 'duckduckbot', 'action' => 'allow', 'description' => 'DuckDuckGo Bot', 'category' => 'search'],
            ['pattern' => 'yandexbot', 'action' => 'allow', 'description' => 'Yandex Bot', 'category' => 'search'],
            ['pattern' => 'baiduspider', 'action' => 'allow', 'description' => 'Baidu Spider', 'category' => 'search'],
        ],
        'social_media' => [
            ['pattern' => 'facebookexternalhit', 'action' => 'allow', 'description' => 'Facebook Crawler', 'category' => 'social'],
            ['pattern' => 'twitterbot', 'action' => 'allow', 'description' => 'Twitter Bot', 'category' => 'social'],
            ['pattern' => 'linkedinbot', 'action' => 'allow', 'description' => 'LinkedIn Bot', 'category' => 'social'],
            ['pattern' => 'discordbot', 'action' => 'allow', 'description' => 'Discord Bot', 'category' => 'social'],
            ['pattern' => 'slackbot', 'action' => 'allow', 'description' => 'Slack Bot', 'category' => 'social'],
            ['pattern' => 'telegrambot', 'action' => 'allow', 'description' => 'Telegram Bot', 'category' => 'social'],
            ['pattern' => 'whatsapp', 'action' => 'allow', 'description' => 'WhatsApp', 'category' => 'social'],
        ],
        'seo_tools' => [
            ['pattern' => 'ahrefsbot', 'action' => 'block', 'description' => 'Ahrefs SEO Bot', 'category' => 'seo'],
            ['pattern' => 'semrushbot', 'action' => 'block', 'description' => 'SEMRush Bot', 'category' => 'seo'],
            ['pattern' => 'mj12bot', 'action' => 'block', 'description' => 'Majestic Bot', 'category' => 'seo'],
            ['pattern' => 'dotbot', 'action' => 'block', 'description' => 'Moz DotBot', 'category' => 'seo'],
        ],
        'security_scanners' => [
            ['pattern' => 'nmap', 'action' => 'block', 'description' => 'Nmap Scanner', 'category' => 'scanner'],
            ['pattern' => 'nikto', 'action' => 'block', 'description' => 'Nikto Scanner', 'category' => 'scanner'],
            ['pattern' => 'sqlmap', 'action' => 'block', 'description' => 'SQLMap', 'category' => 'scanner'],
            ['pattern' => 'masscan', 'action' => 'block', 'description' => 'Masscan', 'category' => 'scanner'],
            ['pattern' => 'acunetix', 'action' => 'block', 'description' => 'Acunetix Scanner', 'category' => 'scanner'],
            ['pattern' => 'nessus', 'action' => 'block', 'description' => 'Nessus Scanner', 'category' => 'scanner'],
        ],
        'generic_bots' => [
            ['pattern' => 'python-requests', 'action' => 'flag', 'description' => 'Python Requests', 'category' => 'generic'],
            ['pattern' => 'curl/', 'action' => 'flag', 'description' => 'cURL', 'category' => 'generic'],
            ['pattern' => 'wget/', 'action' => 'flag', 'description' => 'Wget', 'category' => 'generic'],
            ['pattern' => 'go-http-client', 'action' => 'flag', 'description' => 'Go HTTP Client', 'category' => 'generic'],
            ['pattern' => 'java/', 'action' => 'flag', 'description' => 'Java HTTP Client', 'category' => 'generic'],
        ]
    ];
    
    if ($method === 'GET') {
        return sendResponse(['patterns' => $presetPatterns]);
    }
    
    if ($method === 'POST' && $body && isset($body['apply_preset'])) {
        $preset = $body['apply_preset'];
        if (!isset($presetPatterns[$preset])) {
            return sendResponse(['error' => 'Unknown preset'], 400);
        }
        
        $added = 0;
        foreach ($presetPatterns[$preset] as $p) {
            try {
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO bot_whitelist (pattern, action, description, category, enabled, priority)
                    VALUES (?, ?, ?, ?, 1, 100)
                ");
                $stmt->execute([$p['pattern'], $p['action'], $p['description'], $p['category']]);
                if ($stmt->rowCount() > 0) $added++;
            } catch (PDOException $e) {
                // Skip duplicates
            }
        }
        
        regenerateBotConfig($pdo);
        return sendResponse(['success' => true, 'added' => $added]);
    }
    
    return sendResponse(['error' => 'Invalid request'], 400);
}

function handleBotChallenges($pdo, $method, $id, $body) {
    // Bot challenge configuration
    if ($method === 'GET') {
        $settings = [];
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'bot_challenge_%'");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        return sendResponse([
            'settings' => $settings,
            'challenge_types' => [
                ['id' => 'none', 'name' => 'No Challenge', 'description' => 'Pass through or block based on rules'],
                ['id' => 'js', 'name' => 'JavaScript Challenge', 'description' => 'Require JS execution to pass'],
                ['id' => 'cookie', 'name' => 'Cookie Challenge', 'description' => 'Require cookie support'],
                ['id' => 'captcha', 'name' => 'CAPTCHA', 'description' => 'Require human verification (planned)']
            ]
        ]);
    }
    
    if ($method === 'PUT' && $body) {
        foreach ($body as $key => $value) {
            if (strpos($key, 'bot_challenge_') === 0) {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
        }
        return sendResponse(['success' => true]);
    }
    
    return sendResponse(['error' => 'Method not allowed'], 405);
}

function getBotIPs($pdo) {
    $stmt = $pdo->query("
        SELECT 
            ip_address,
            COUNT(*) as detection_count,
            COUNT(DISTINCT bot_name) as different_bots,
            GROUP_CONCAT(DISTINCT bot_name) as bot_names,
            SUM(CASE WHEN action = 'blocked' THEN 1 ELSE 0 END) as blocked_count,
            MIN(timestamp) as first_seen,
            MAX(timestamp) as last_seen
        FROM bot_detections
        WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY ip_address
        HAVING detection_count >= 5
        ORDER BY detection_count DESC
        LIMIT 100
    ");
    
    return sendResponse(['ips' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function getBotTimeline($pdo) {
    $hours = (int)($_GET['hours'] ?? 24);
    
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(timestamp, '%Y-%m-%d %H:00:00') as hour,
            COUNT(*) as total,
            SUM(CASE WHEN bot_type = 'good' THEN 1 ELSE 0 END) as good,
            SUM(CASE WHEN bot_type = 'bad' THEN 1 ELSE 0 END) as bad,
            SUM(CASE WHEN action = 'blocked' THEN 1 ELSE 0 END) as blocked
        FROM bot_detections
        WHERE timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)
        GROUP BY hour
        ORDER BY hour ASC
    ");
    $stmt->execute([$hours]);
    
    return sendResponse(['timeline' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function exportBotRules($pdo) {
    $stmt = $pdo->query("SELECT pattern, action, description, category, priority, enabled FROM bot_whitelist ORDER BY priority");
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return sendResponse([
        'export' => [
            'version' => '1.0',
            'exported_at' => date('Y-m-d H:i:s'),
            'rules' => $rules
        ]
    ]);
}

function importBotRules($pdo, $body) {
    if (!$body || !isset($body['rules'])) {
        return sendResponse(['error' => 'No rules provided'], 400);
    }
    
    $imported = 0;
    $skipped = 0;
    
    foreach ($body['rules'] as $rule) {
        if (empty($rule['pattern'])) continue;
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO bot_whitelist (pattern, action, description, category, priority, enabled)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    action = VALUES(action),
                    description = VALUES(description),
                    category = VALUES(category),
                    priority = VALUES(priority)
            ");
            $stmt->execute([
                $rule['pattern'],
                $rule['action'] ?? 'allow',
                $rule['description'] ?? null,
                $rule['category'] ?? 'imported',
                $rule['priority'] ?? 100,
                $rule['enabled'] ?? 1
            ]);
            $imported++;
        } catch (PDOException $e) {
            $skipped++;
        }
    }
    
    regenerateBotConfig($pdo);
    
    return sendResponse([
        'success' => true,
        'imported' => $imported,
        'skipped' => $skipped
    ]);
}

function handleBotSettings($pdo, $method, $body) {
    $settingKeys = [
        'bot_protection_enabled',
        'bot_block_empty_ua',
        'bot_rate_limit_good',
        'bot_rate_limit_bad',
        'bot_challenge_mode',
        'bot_log_all_requests'
    ];
    
    if ($method === 'GET') {
        $settings = [];
        $placeholders = implode(',', array_fill(0, count($settingKeys), '?'));
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ({$placeholders})");
        $stmt->execute($settingKeys);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        return sendResponse(['settings' => $settings]);
    }
    
    if ($method === 'PUT' && $body) {
        $updated = 0;
        foreach ($body as $key => $value) {
            if (in_array($key, $settingKeys)) {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
                $updated++;
            }
        }
        
        return sendResponse(['success' => true, 'updated' => $updated]);
    }
    
    return sendResponse(['error' => 'Method not allowed'], 405);
}

function regenerateBotConfig($pdo) {
    // Generate nginx bot-protection.conf from database rules
    $stmt = $pdo->query("SELECT * FROM bot_whitelist WHERE enabled = 1 ORDER BY priority ASC");
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $config = "# Bot Protection Configuration - Auto-generated " . date('Y-m-d H:i:s') . "\n\n";
    $config .= "map \$http_user_agent \$bot_action {\n";
    $config .= "    default 0;\n";
    
    foreach ($rules as $rule) {
        $action = match($rule['action']) {
            'block' => '403',
            'challenge' => '429',
            'flag' => '1',
            'allow' => '0',
            default => '0'
        };
        
        // Escape special nginx map characters
        $pattern = str_replace(['~', '*'], ['\\~', '\\*'], $rule['pattern']);
        $config .= "    ~*{$pattern} {$action}; # {$rule['description']}\n";
    }
    
    $config .= "}\n";
    
    // Write to shared config location
    $configPath = '/etc/nginx/conf.d/bot-protection-map.conf';
    if (is_writable(dirname($configPath))) {
        file_put_contents($configPath, $config);
        
        // Signal nginx reload
        if (file_exists('/tmp/nginx-reload-signal')) {
            touch('/tmp/nginx-reload-signal');
        }
        
        return true;
    }
    
    return false;
}
