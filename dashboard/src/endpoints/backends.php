<?php
// Backends Management API
// GET /api/sites/:id/backends - Get backends for site
// POST /api/sites/:id/backends - Set backends for site
// PUT /api/sites/:id/backends - Update load balancing config

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];

// Parse site ID from URI
preg_match('#/sites/(\d+)/backends#', $requestUri, $matches);
$siteId = $matches[1] ?? null;

if (!$siteId) {
    http_response_code(400);
    echo json_encode(['error' => 'Site ID required']);
    exit;
}

// Verify site exists
$stmt = $db->prepare("SELECT id, domain, backend_url, backends, lb_method, health_check_enabled, health_check_interval, health_check_path FROM sites WHERE id = ?");
$stmt->execute([$siteId]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$site) {
    http_response_code(404);
    echo json_encode(['error' => 'Site not found']);
    exit;
}

switch ($method) {
    case 'GET':
        getBackends($site);
        break;
    
    case 'POST':
    case 'PUT':
        updateBackends($site);
        break;
    
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

function getBackends($site) {
    $backends = $site['backends'] ? json_decode($site['backends'], true) : [];
    
    // If no backends defined, create one from backend_url
    if (empty($backends) && !empty($site['backend_url'])) {
        $backends = [[
            'address' => $site['backend_url'],
            'weight' => 1,
            'max_fails' => 3,
            'fail_timeout' => 30,
            'backup' => false,
            'down' => false
        ]];
    }
    
    echo json_encode([
        'site_id' => $site['id'],
        'domain' => $site['domain'],
        'backends' => $backends,
        'lb_method' => $site['lb_method'] ?? 'round_robin',
        'health_check_enabled' => (bool)$site['health_check_enabled'],
        'health_check_interval' => $site['health_check_interval'] ?? 30,
        'health_check_path' => $site['health_check_path'] ?? '/'
    ]);
}

function updateBackends($site) {
    global $db;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }
    
    $backends = $input['backends'] ?? [];
    $lbMethod = $input['lb_method'] ?? 'round_robin';
    $healthCheckEnabled = isset($input['health_check_enabled']) ? (int)$input['health_check_enabled'] : 0;
    $healthCheckInterval = $input['health_check_interval'] ?? 30;
    $healthCheckPath = $input['health_check_path'] ?? '/';
    
    // Validate backends
    if (!is_array($backends) || empty($backends)) {
        http_response_code(400);
        echo json_encode(['error' => 'At least one backend required']);
        return;
    }
    
    // Validate load balancing method
    $validMethods = ['round_robin', 'least_conn', 'ip_hash', 'hash'];
    if (!in_array($lbMethod, $validMethods)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid load balancing method. Valid: ' . implode(', ', $validMethods)]);
        return;
    }
    
    // Validate each backend
    foreach ($backends as $idx => $backend) {
        if (empty($backend['address'])) {
            http_response_code(400);
            echo json_encode(['error' => "Backend $idx missing address"]);
            return;
        }
        
        // Set defaults
        $backends[$idx]['weight'] = $backend['weight'] ?? 1;
        $backends[$idx]['max_fails'] = $backend['max_fails'] ?? 3;
        $backends[$idx]['fail_timeout'] = $backend['fail_timeout'] ?? 30;
        $backends[$idx]['backup'] = $backend['backup'] ?? false;
        $backends[$idx]['down'] = $backend['down'] ?? false;
    }
    
    // Update database
    $stmt = $db->prepare("
        UPDATE sites 
        SET backends = ?, 
            lb_method = ?,
            health_check_enabled = ?,
            health_check_interval = ?,
            health_check_path = ?
        WHERE id = ?
    ");
    
    $backendsJson = json_encode($backends);
    $stmt->execute([
        $backendsJson,
        $lbMethod,
        $healthCheckEnabled,
        $healthCheckInterval,
        $healthCheckPath,
        $site['id']
    ]);
    
    // Regenerate NGINX config
    require_once __DIR__ . '/sites.php';
    generateNginxConfig($site['id']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Backends updated successfully',
        'backends' => $backends,
        'lb_method' => $lbMethod,
        'health_check_enabled' => (bool)$healthCheckEnabled,
        'health_check_interval' => $healthCheckInterval,
        'health_check_path' => $healthCheckPath
    ]);
}
