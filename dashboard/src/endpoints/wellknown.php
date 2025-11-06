<?php
// Well-Known Files Management API
// Handles robots.txt, security.txt, humans.txt, ads.txt

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));

// Routes:
// GET    /api/wellknown/global           - Get all global defaults
// GET    /api/wellknown/global/:type     - Get specific global default (robots|security|humans|ads)
// PUT    /api/wellknown/global/:type     - Update specific global default
// GET    /api/wellknown/site/:id/:type   - Get site-specific well-known file
// PUT    /api/wellknown/site/:id/:type   - Update site-specific well-known file
// DELETE /api/wellknown/site/:id/:type   - Delete site-specific (fall back to global)

try {
    $db = getDB();
    
    // Parse route
    $scope = $pathParts[1] ?? null; // 'global' or 'site'
    
    if ($scope === 'global') {
        handleGlobal($db, $method, $pathParts);
    } elseif ($scope === 'site') {
        handleSite($db, $method, $pathParts);
    } else {
        throw new Exception('Invalid scope. Use /global or /site/:id');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleGlobal($db, $method, $pathParts) {
    $type = $pathParts[2] ?? null; // robots|security|humans|ads
    
    if ($method === 'GET' && !$type) {
        // Get all global defaults
        $stmt = $db->query("SELECT * FROM wellknown_global WHERE id = 1");
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            // Initialize if not exists
            $db->exec("INSERT IGNORE INTO wellknown_global (id) VALUES (1)");
            $data = ['id' => 1, 'robots_txt' => null, 'ads_txt' => null, 'humans_txt' => null, 'security_txt' => null];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        return;
    }
    
    if (!$type || !in_array($type, ['robots', 'security', 'humans', 'ads'])) {
        throw new Exception('Invalid type. Use: robots, security, humans, or ads');
    }
    
    $column = $type . '_txt';
    
    if ($method === 'GET') {
        // Get specific global default
        $stmt = $db->prepare("SELECT $column FROM wellknown_global WHERE id = 1");
        $stmt->execute();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'type' => $type,
            'content' => $data[$column] ?? null
        ]);
        
    } elseif ($method === 'PUT') {
        // Update global default
        $input = json_decode(file_get_contents('php://input'), true);
        $content = $input['content'] ?? null;
        
        // Ensure record exists
        $db->exec("INSERT IGNORE INTO wellknown_global (id) VALUES (1)");
        
        $stmt = $db->prepare("UPDATE wellknown_global SET $column = :content WHERE id = 1");
        $stmt->execute(['content' => $content]);
        
        // Trigger NGINX config regeneration
        touchRegenerationFlag();
        
        echo json_encode([
            'success' => true,
            'message' => "Global $type.txt updated successfully"
        ]);
        
    } else {
        throw new Exception('Method not allowed');
    }
}

function handleSite($db, $method, $pathParts) {
    $siteId = intval($pathParts[2] ?? 0);
    $type = $pathParts[3] ?? null;
    
    if (!$siteId) {
        throw new Exception('Invalid site ID');
    }
    
    if (!$type || !in_array($type, ['robots', 'security', 'humans', 'ads'])) {
        throw new Exception('Invalid type. Use: robots, security, humans, or ads');
    }
    
    $column = $type . '_txt';
    
    if ($method === 'GET') {
        // Get site-specific well-known file (or global fallback)
        $stmt = $db->prepare("SELECT domain, $column FROM sites WHERE id = :id");
        $stmt->execute(['id' => $siteId]);
        $site = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$site) {
            throw new Exception('Site not found');
        }
        
        $content = $site[$column];
        $isCustom = !empty($content);
        
        // If no custom content, get global default
        if (!$isCustom) {
            $stmt = $db->query("SELECT $column FROM wellknown_global WHERE id = 1");
            $global = $stmt->fetch(PDO::FETCH_ASSOC);
            $content = $global[$column] ?? null;
        }
        
        echo json_encode([
            'success' => true,
            'site_id' => $siteId,
            'domain' => $site['domain'],
            'type' => $type,
            'content' => $content,
            'is_custom' => $isCustom,
            'using_global' => !$isCustom
        ]);
        
    } elseif ($method === 'PUT') {
        // Update site-specific well-known file
        $input = json_decode(file_get_contents('php://input'), true);
        $content = $input['content'] ?? null;
        
        $stmt = $db->prepare("UPDATE sites SET $column = :content WHERE id = :id");
        $stmt->execute(['content' => $content, 'id' => $siteId]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Site not found');
        }
        
        // Trigger NGINX config regeneration
        touchRegenerationFlag();
        
        echo json_encode([
            'success' => true,
            'message' => "Site-specific $type.txt updated successfully"
        ]);
        
    } elseif ($method === 'DELETE') {
        // Delete site-specific (revert to global)
        // First check if site exists
        $stmt = $db->prepare("SELECT id FROM sites WHERE id = :id");
        $stmt->execute(['id' => $siteId]);
        if (!$stmt->fetch()) {
            throw new Exception('Site not found');
        }
        
        // Now update to NULL
        $stmt = $db->prepare("UPDATE sites SET $column = NULL WHERE id = :id");
        $stmt->execute(['id' => $siteId]);
        
        // Trigger NGINX config regeneration
        touchRegenerationFlag();
        
        echo json_encode([
            'success' => true,
            'message' => "Site-specific $type.txt removed, using global default"
        ]);
        
    } else {
        throw new Exception('Method not allowed');
    }
}

function touchRegenerationFlag() {
    touch('/shared-data/regenerate-nginx-configs');
}
