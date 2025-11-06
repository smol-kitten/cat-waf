<?php
// Custom Error Pages Management API
// Handles uploading and managing custom HTML error pages per site

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));

// Routes:
// GET    /api/error-pages/site/:id           - Get all error pages for a site
// GET    /api/error-pages/site/:id/:code     - Get specific error page (403/404/429/500/502/503)
// PUT    /api/error-pages/site/:id/:code     - Update specific error page HTML
// DELETE /api/error-pages/site/:id/:code     - Delete custom error page (revert to template)
// POST   /api/error-pages/site/:id/toggle    - Toggle custom error pages on/off
// GET    /api/error-pages/templates          - Get all error page templates
// GET    /api/error-pages/templates/:name    - Get specific template

try {
    $db = getDB();
    
    $scope = $pathParts[1] ?? null; // 'site' or 'templates'
    
    if ($scope === 'site') {
        handleSiteErrorPages($db, $method, $pathParts);
    } elseif ($scope === 'templates') {
        handleTemplates($db, $method, $pathParts);
    } else {
        throw new Exception('Invalid scope. Use /site/:id or /templates');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleSiteErrorPages($db, $method, $pathParts) {
    $siteId = intval($pathParts[2] ?? 0);
    $code = $pathParts[3] ?? null;
    
    if (!$siteId) {
        throw new Exception('Invalid site ID');
    }
    
    // Handle toggle endpoint
    if ($code === 'toggle' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $enabled = $input['enabled'] ?? false;
        
        $stmt = $db->prepare("UPDATE sites SET custom_error_pages_enabled = :enabled WHERE id = :id");
        $stmt->execute(['enabled' => $enabled ? 1 : 0, 'id' => $siteId]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Site not found');
        }
        
        touchRegenerationFlag();
        
        echo json_encode([
            'success' => true,
            'enabled' => $enabled,
            'message' => 'Custom error pages ' . ($enabled ? 'enabled' : 'disabled')
        ]);
        return;
    }
    
    $validCodes = ['403', '404', '429', '500', '502', '503'];
    
    if ($method === 'GET' && !$code) {
        // Get all error pages for a site
        $stmt = $db->prepare("
            SELECT 
                id, domain, 
                custom_error_pages_enabled,
                custom_403_html, custom_404_html, custom_429_html,
                custom_500_html, custom_502_html, custom_503_html
            FROM sites WHERE id = :id
        ");
        $stmt->execute(['id' => $siteId]);
        $site = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$site) {
            throw new Exception('Site not found');
        }
        
        echo json_encode([
            'success' => true,
            'site_id' => $siteId,
            'domain' => $site['domain'],
            'custom_enabled' => (bool)$site['custom_error_pages_enabled'],
            'pages' => [
                '403' => $site['custom_403_html'],
                '404' => $site['custom_404_html'],
                '429' => $site['custom_429_html'],
                '500' => $site['custom_500_html'],
                '502' => $site['custom_502_html'],
                '503' => $site['custom_503_html']
            ]
        ]);
        return;
    }
    
    if (!$code || !in_array($code, $validCodes)) {
        throw new Exception('Invalid error code. Use: ' . implode(', ', $validCodes));
    }
    
    $column = "custom_{$code}_html";
    
    if ($method === 'GET') {
        // Get specific error page
        $stmt = $db->prepare("SELECT domain, custom_error_pages_enabled, $column FROM sites WHERE id = :id");
        $stmt->execute(['id' => $siteId]);
        $site = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$site) {
            throw new Exception('Site not found');
        }
        
        $html = $site[$column];
        $isCustom = !empty($html);
        
        // If no custom HTML, get from default template
        if (!$isCustom) {
            $stmt = $db->prepare("SELECT html_{$code} FROM error_page_templates WHERE is_default = 1 LIMIT 1");
            $stmt->execute();
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            $html = $template["html_{$code}"] ?? null;
        }
        
        echo json_encode([
            'success' => true,
            'site_id' => $siteId,
            'domain' => $site['domain'],
            'code' => $code,
            'html' => $html,
            'is_custom' => $isCustom,
            'custom_enabled' => (bool)$site['custom_error_pages_enabled']
        ]);
        
    } elseif ($method === 'PUT') {
        // Update specific error page
        $input = json_decode(file_get_contents('php://input'), true);
        $html = $input['html'] ?? null;
        
        if ($html === null) {
            throw new Exception('HTML content is required');
        }
        
        // Validate HTML (basic check)
        if (strlen($html) > 5000000) { // 5MB limit
            throw new Exception('HTML content too large (max 5MB)');
        }
        
        $stmt = $db->prepare("UPDATE sites SET $column = :html WHERE id = :id");
        $stmt->execute(['html' => $html, 'id' => $siteId]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Site not found');
        }
        
        touchRegenerationFlag();
        
        echo json_encode([
            'success' => true,
            'message' => "Custom {$code} error page updated successfully"
        ]);
        
    } elseif ($method === 'DELETE') {
        // Delete custom error page (revert to template)
        // First check if site exists
        $stmt = $db->prepare("SELECT id FROM sites WHERE id = :id");
        $stmt->execute(['id' => $siteId]);
        if (!$stmt->fetch()) {
            throw new Exception('Site not found');
        }
        
        // Now update to NULL
        $stmt = $db->prepare("UPDATE sites SET $column = NULL WHERE id = :id");
        $stmt->execute(['id' => $siteId]);
        
        touchRegenerationFlag();
        
        echo json_encode([
            'success' => true,
            'message' => "Custom {$code} error page removed, using template"
        ]);
        
    } else {
        throw new Exception('Method not allowed');
    }
}

function handleTemplates($db, $method, $pathParts) {
    if ($method !== 'GET') {
        throw new Exception('Only GET method is allowed for templates');
    }
    
    $name = $pathParts[2] ?? null;
    
    if (!$name) {
        // Get all templates - serve NGINX error pages as "default" template
        echo json_encode([
            'success' => true,
            'templates' => [
                [
                    'id' => 1,
                    'name' => 'default',
                    'description' => 'CatWAF styled error pages from NGINX',
                    'is_default' => 1,
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]
        ]);
        
    } else {
        // Get specific template - load from NGINX error-pages directory
        if ($name === 'default') {
            // Read actual NGINX error pages
            $possiblePaths = [
                '/var/www/html/templates/error-pages',
                '/shared-data/error-pages',
                '../nginx/error-pages',
                '/etc/nginx/error-pages',
                dirname(__DIR__, 2) . '/nginx/error-pages'
            ];
            
            $errorPagesPath = null;
            foreach ($possiblePaths as $path) {
                if (is_dir($path)) {
                    $errorPagesPath = $path;
                    break;
                }
            }
            
            if (!$errorPagesPath) {
                // Fallback to database if files not found
                $stmt = $db->prepare("SELECT * FROM error_page_templates WHERE name = :name");
                $stmt->execute(['name' => $name]);
                $template = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$template) {
                    throw new Exception('Template not found');
                }
                
                echo json_encode([
                    'success' => true,
                    'template' => $template
                ]);
                return;
            }
            
            // Load real NGINX error pages
            $template = [
                'id' => 1,
                'name' => 'default',
                'description' => 'CatWAF styled error pages',
                'is_default' => 1,
                'html_403' => file_get_contents("$errorPagesPath/403.html"),
                'html_404' => file_get_contents("$errorPagesPath/404.html"),
                'html_429' => file_get_contents("$errorPagesPath/429.html"),
                'html_500' => file_get_contents("$errorPagesPath/500.html"),
                'html_502' => file_get_contents("$errorPagesPath/502-no-backend.html"),
                'html_503' => file_get_contents("$errorPagesPath/503.html"),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            echo json_encode([
                'success' => true,
                'template' => $template
            ]);
        } else {
            throw new Exception('Template not found');
        }
    }
}

function touchRegenerationFlag() {
    touch('/shared-data/regenerate-nginx-configs');
}
