<?php

// Include sites.php for generateSiteConfig function
require_once __DIR__ . '/sites.php';

function handleRegenerate($method, $params, $db) {
    if ($method !== 'POST') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    $action = $params[0] ?? 'all';
    
    switch ($action) {
        case 'all':
            // Regenerate all site configs
            regenerateAllConfigs($db);
            sendResponse(['success' => true, 'message' => 'All site configs regenerated']);
            break;
            
        case 'site':
            // Regenerate specific site
            if (empty($params[1])) {
                sendResponse(['error' => 'Site ID required'], 400);
            }
            regenerateSiteConfig($params[1], $db);
            sendResponse(['success' => true, 'message' => 'Site config regenerated']);
            break;
            
        default:
            sendResponse(['error' => 'Invalid action'], 400);
    }
}

function regenerateAllConfigs($db) {
    // Clean up orphaned configs first (sites deleted but config still exists)
    $orphansRemoved = 0;
    if (function_exists('cleanupOrphanedConfigs')) {
        $orphansRemoved = cleanupOrphanedConfigs($db);
        if ($orphansRemoved > 0) {
            error_log("Cleaned up {$orphansRemoved} orphaned config(s)");
        }
    }
    
    // Get all enabled sites
    $stmt = $db->query("SELECT * FROM sites WHERE enabled = 1 ORDER BY domain");
    $sites = $stmt->fetchAll();
    
    $success = 0;
    $failed = 0;
    $hasCatchall = false;
    
    foreach ($sites as $site) {
        try {
            generateSiteConfig($site['id'], $site);
            $success++;
            
            // Check if we have a catch-all site
            if ($site['domain'] === '_') {
                $hasCatchall = true;
            }
        } catch (Exception $e) {
            error_log("Failed to regenerate config for site {$site['domain']}: " . $e->getMessage());
            $failed++;
        }
    }
    
    // If no catch-all "_" site exists, copy default.conf to nginx
    if (!$hasCatchall) {
        error_log("No catch-all site found, ensuring default.conf is loaded");
        $copyCmd = "docker exec waf-nginx sh -c 'test -f /etc/nginx/conf.d/default.conf || cp /etc/nginx/conf.d/default.conf.backup /etc/nginx/conf.d/default.conf' 2>&1";
        exec($copyCmd);
    } else {
        // If catch-all exists, remove default.conf to avoid conflicts
        error_log("Catch-all site exists, removing default.conf if present");
        $removeCmd = "docker exec waf-nginx rm -f /etc/nginx/conf.d/default.conf 2>&1";
        exec($removeCmd);
    }
    
    // Trigger reload
    touch("/etc/nginx/sites-enabled/.reload_needed");
    
    error_log("Regenerated configs: $success successful, $failed failed");
    return ['success' => $success, 'failed' => $failed];
}

function regenerateSiteConfig($siteId, $db) {
    $stmt = $db->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->execute([$siteId]);
    $site = $stmt->fetch();
    
    if (!$site) {
        throw new Exception("Site not found");
    }
    
    generateSiteConfig($siteId, $site);
    
    // Trigger reload
    touch("/etc/nginx/sites-enabled/.reload_needed");
    
    return true;
}
