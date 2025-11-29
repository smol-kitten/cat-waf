<?php
/**
 * Telemetry Configuration Endpoint
 * Manage opt-in settings, intervals, and privacy controls
 */

function handleTelemetryConfig($method, $path, $db) {
    if ($method === 'GET' && empty($path)) {
        getTelemetryConfig($db);
    } elseif ($method === 'POST' && $path[0] === 'update') {
        updateTelemetryConfig($db);
    } elseif ($method === 'POST' && $path[0] === 'generate-uuids') {
        generateSiteUUIDs($db);
    } elseif ($method === 'POST' && $path[0] === 'submit-now') {
        submitTelemetryNow($db);
    } elseif ($method === 'GET' && $path[0] === 'preview') {
        previewTelemetryData($db);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
    }
}

/**
 * Get current telemetry configuration
 */
function getTelemetryConfig($db) {
    $stmt = $db->query("SELECT * FROM telemetry_config LIMIT 1");
    $config = $stmt->fetch();
    
    if (!$config) {
        // Create default config
        $uuid = generateUUID();
        $stmt = $db->prepare("INSERT INTO telemetry_config (system_uuid) VALUES (?)");
        $stmt->execute([$uuid]);
        $stmt = $db->query("SELECT * FROM telemetry_config LIMIT 1");
        $config = $stmt->fetch();
    }
    
    // Get site UUIDs
    $stmt = $db->query("
        SELECT s.id as site_id, s.domain, stu.site_uuid, stu.metrics_enabled
        FROM sites s
        LEFT JOIN site_telemetry_uuids stu ON stu.site_id = s.id
    ");
    $siteUuids = $stmt->fetchAll();
    
    // Get submission history  
    $stmt = $db->query("
        SELECT category, COUNT(*) as count, MAX(submitted_at) as last_submission
        FROM telemetry_submissions
        WHERE status = 'success'
        GROUP BY category
    ");
    $submissions = $stmt->fetchAll();
    
    echo json_encode([
        'config' => $config,
        'site_uuids' => $siteUuids,
        'submission_history' => $submissions,
        'next_collection' => getNextCollectionTime($config)
    ]);
}

/**
 * Update telemetry configuration
 */
function updateTelemetryConfig($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $updates = [];
    $params = [];
    
    if (isset($input['opt_in_enabled'])) {
        $updates[] = 'opt_in_enabled = ?';
        $params[] = $input['opt_in_enabled'] ? 1 : 0;
        
        if ($input['opt_in_enabled']) {
            $stmt = $db->query("SELECT opt_in_date FROM telemetry_config WHERE opt_in_date IS NOT NULL");
            if (!$stmt->fetch()) {
                $updates[] = 'opt_in_date = NOW()';
            }
        }
    }
    
    if (isset($input['collection_interval'])) {
        $updates[] = 'collection_interval = ?';
        $params[] = $input['collection_interval'];
    }
    
    if (isset($input['collect_usage'])) {
        $updates[] = 'collect_usage = ?';
        $params[] = $input['collect_usage'] ? 1 : 0;
    }
    
    if (isset($input['collect_settings'])) {
        $updates[] = 'collect_settings = ?';
        $params[] = $input['collect_settings'] ? 1 : 0;
    }
    
    if (isset($input['collect_system'])) {
        $updates[] = 'collect_system = ?';
        $params[] = $input['collect_system'] ? 1 : 0;
    }
    
    if (isset($input['collect_security'])) {
        $updates[] = 'collect_security = ?';
        $params[] = $input['collect_security'] ? 1 : 0;
    }
    
    if (isset($input['collect_404_paths'])) {
        $updates[] = 'collect_404_paths = ?';
        $params[] = $input['collect_404_paths'] ? 1 : 0;
    }
    
    if (isset($input['min_404_hits'])) {
        $updates[] = 'min_404_hits = ?';
        $params[] = (int)$input['min_404_hits'];
    }
    
    if (isset($input['telemetry_endpoint'])) {
        $updates[] = 'telemetry_endpoint = ?';
        $params[] = $input['telemetry_endpoint'];
    }
    
    if (isset($input['api_key'])) {
        $updates[] = 'api_key = ?';
        $params[] = $input['api_key'];
    }
    
    if (!empty($updates)) {
        $sql = "UPDATE telemetry_config SET " . implode(', ', $updates);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    }
    
    echo json_encode(['success' => true, 'message' => 'Configuration updated']);
}

/**
 * Generate UUIDs for all sites
 */
function generateSiteUUIDs($db) {
    $stmt = $db->query("SELECT id FROM sites");
    $sites = $stmt->fetchAll();
    $generated = 0;
    
    foreach ($sites as $site) {
        $stmt = $db->prepare("SELECT id FROM site_telemetry_uuids WHERE site_id = ?");
        $stmt->execute([$site['id']]);
        $existing = $stmt->fetch();
        
        if (!$existing) {
            $uuid = generateUUID();
            $stmt = $db->prepare("INSERT INTO site_telemetry_uuids (site_id, site_uuid) VALUES (?, ?)");
            $stmt->execute([$site['id'], $uuid]);
            $generated++;
        }
    }
    
    echo json_encode(['success' => true, 'generated' => $generated]);
}

/**
 * Submit telemetry immediately (manual trigger)
 */
function submitTelemetryNow($db) {
    $stmt = $db->query("SELECT * FROM telemetry_config LIMIT 1");
    $config = $stmt->fetch();
    
    if (!$config['opt_in_enabled']) {
        http_response_code(403);
        echo json_encode(['error' => 'Telemetry not opted in']);
        return;
    }
    
    require_once __DIR__ . '/../lib/TelemetryCollector.php';
    $collector = new TelemetryCollector($db);
    
    $results = [];
    
    if ($config['collect_usage']) {
        $results['usage'] = $collector->collectAndSubmitUsage($config);
    }
    
    if ($config['collect_settings']) {
        $results['settings'] = $collector->collectAndSubmitSettings($config);
    }
    
    if ($config['collect_system']) {
        $results['system'] = $collector->collectAndSubmitSystem($config);
    }
    
    if ($config['collect_security']) {
        $results['security'] = $collector->collectAndSubmitSecurity($config);
    }
    
    // Update last collection time
    $stmt = $db->prepare("UPDATE telemetry_config SET last_collection = NOW()");
    $stmt->execute();
    
    echo json_encode(['success' => true, 'results' => $results]);
}

/**
 * Preview telemetry data before sending
 */
function previewTelemetryData($db) {
    $stmt = $db->query("SELECT * FROM telemetry_config LIMIT 1");
    $config = $stmt->fetch();
    
    require_once __DIR__ . '/../lib/TelemetryCollector.php';
    $collector = new TelemetryCollector($db);
    
    $preview = [];
    
    if ($config['collect_usage']) {
        $preview['usage'] = $collector->collectUsageMetrics();
    }
    
    if ($config['collect_settings']) {
        $preview['settings'] = $collector->collectSettingsMetrics();
    }
    
    if ($config['collect_system']) {
        $preview['system'] = $collector->collectSystemMetrics();
    }
    
    if ($config['collect_security']) {
        $preview['security'] = $collector->collectSecurityMetrics();
    }
    
    echo json_encode($preview, JSON_PRETTY_PRINT);
}

/**
 * Generate UUID v4
 */
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

/**
 * Get next collection time based on interval
 */
function getNextCollectionTime($config) {
    if ($config['collection_interval'] === 'off' || $config['collection_interval'] === 'manual') {
        return 'manual';
    }
    
    if (!$config['last_collection']) {
        return 'now';
    }
    
    $last = strtotime($config['last_collection']);
    
    switch ($config['collection_interval']) {
        case 'daily':
            return date('c', $last + 86400);
        case 'weekly':
            return date('c', $last + 604800);
        case 'monthly':
            return date('c', $last + 2592000);
        default:
            return 'unknown';
    }
}
