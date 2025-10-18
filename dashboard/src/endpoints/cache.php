<?php
// Cache Management API
// GET /api/cache/stats - Get cache statistics
// GET /api/cache/items - List cached items
// POST /api/cache/purge - Purge all or pattern-based cache
// DELETE /api/cache/items/:key - Purge specific cache item

// Use $db from index.php (already connected)
global $db;

// Don't set header again if already set by index.php
if (!headers_sent()) {
    header('Content-Type: application/json');
}

$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];

// Parse action from URI - use both full URI and path segment for compatibility
$pathSegment = $_GET['action'] ?? '';

if (preg_match('#/cache/stats$#', $requestUri) || $pathSegment === 'stats') {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    getCacheStats();
    exit;
}

if (preg_match('#/cache/items/([^/]+)$#', $requestUri, $matches) || ($pathSegment === 'items' && isset($_GET['key']))) {
    $key = isset($matches[1]) ? urldecode($matches[1]) : urldecode($_GET['key']);
    if ($method === 'DELETE') {
        purgeCacheItem($key);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    exit;
}

if (preg_match('#/cache/items$#', $requestUri)) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    getCacheItems();
    exit;
}

if (preg_match('#/cache/purge$#', $requestUri)) {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    purgeCache();
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Endpoint not found']);
exit;

function getCacheStats() {
    // Get cache statistics from NGINX container using optimized cache-stats.sh script
    $output = shell_exec("docker exec waf-nginx sh -c '/usr/local/bin/cache-stats.sh' 2>&1");
    
    if (empty($output)) {
        echo json_encode([
            'total_items' => 0,
            'total_size' => 0,
            'zones' => []
        ]);
        return;
    }
    
    $cacheData = json_decode($output, true);
    
    if (!$cacheData || !isset($cacheData['total'])) {
        echo json_encode([
            'total_items' => 0,
            'total_size' => 0,
            'zones' => []
        ]);
        return;
    }
    
    $totalSize = $cacheData['total']['size'];
    $totalItems = $cacheData['total']['items'];
    
    // Get hit/miss stats from access logs
    // Use global $db from index.php
    global $db;
    try {
        $stmt = $db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN cache_status = 'HIT' THEN 1 ELSE 0 END) as hits,
                SUM(CASE WHEN cache_status = 'MISS' THEN 1 ELSE 0 END) as misses
            FROM request_telemetry 
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $total = $stats['total'] ?? 0;
        $hits = $stats['hits'] ?? 0;
        $misses = $stats['misses'] ?? 0;
        
        $hitRate = $total > 0 ? ($hits / $total) * 100 : 0;
        $missRate = $total > 0 ? ($misses / $total) * 100 : 0;
        
        echo json_encode([
            'stats' => [
                'total_items' => $totalItems,
                'total_size' => $totalSize,
                'hit_rate' => round($hitRate, 2),
                'miss_rate' => round($missRate, 2),
                'hits' => $hits,
                'misses' => $misses,
                'total_requests' => $total
            ]
        ]);
    } catch (Exception $e) {
        error_log("Error getting cache stats: " . $e->getMessage());
        echo json_encode([
            'stats' => [
                'total_items' => $totalItems,
                'total_size' => $totalSize,
                'hit_rate' => 0,
                'miss_rate' => 0
            ]
        ]);
    }
}

function getCacheItems() {
    $cacheDir = '/var/cache/nginx';
    $items = [];

    // Run find inside the nginx container - BusyBox find doesn't support -printf
    // So we use find + stat to get file info
    $innerFind = "find {$cacheDir} -type f \\( -path '{$cacheDir}/*_cache/*' -o -path '{$cacheDir}/__cache/*' \\) 2>/dev/null | head -100";
    $findCmd = "docker exec waf-nginx sh -c " . escapeshellarg($innerFind) . " 2>&1";
    $output = shell_exec($findCmd);
    if (!$output) {
        echo json_encode(['items' => []]);
        return;
    }

    $paths = array_filter(array_map('trim', explode("\n", $output)));
    foreach ($paths as $path) {
        if (empty($path)) continue;
        
        // Get file info using stat (BusyBox compatible)
        $statCmd = "docker exec waf-nginx stat -c '%s %Y' " . escapeshellarg($path) . " 2>/dev/null";
        $statOutput = trim(shell_exec($statCmd));
        if (!$statOutput) continue;
        
        $statParts = explode(' ', $statOutput);
        if (count($statParts) < 2) continue;
        
        $size = intval($statParts[0]);
        $mtime = intval($statParts[1]);

        // Try to extract URL/key from the cache file by grepping inside the nginx container
        $url = extractUrlFromCacheFile($path);

        $items[] = [
            'key' => basename($path),
            'url' => $url,
            'size' => $size,
            'age' => time() - intval($mtime),
            'last_access' => date('Y-m-d H:i:s', intval($mtime)),
            'hits' => null // TODO: Get real hit count from nginx if available
        ];
    }

    echo json_encode(['items' => $items]);
}

function extractUrlFromCacheFile($cachePath) {
    // Because the cache files live inside the nginx container, try to read
    // the first part of the file there and grep for a KEY: line.

    // Escape the path for inclusion in shell command and run grep inside the nginx container
    $innerGrep = 'grep -a -m1 "KEY:" ' . escapeshellarg($cachePath) . ' 2>/dev/null || true';
    $cmd = "docker exec waf-nginx sh -c " . escapeshellarg($innerGrep) . " 2>&1";
    $output = shell_exec($cmd);
    if ($output) {
        // Output may contain lines; find the KEY: part
        if (preg_match('/KEY:\s*(.+)/', $output, $m)) {
            $key = trim($m[1]);
            // Try to extract path portion from a full URL-like key
            if (preg_match('#https?://[^/]+(.+)$#', $key, $urlMatch)) {
                return $urlMatch[1];
            }
            return $key;
        }
    }

    // If we couldn't extract a key, try to derive a short label from the path
    if (preg_match('#/([^/]+_cache|__cache)/#', $cachePath, $match)) {
        return 'Cached item (' . $match[1] . ')';
    }

    return basename($cachePath);
}

function purgeCache() {
    $input = json_decode(file_get_contents('php://input'), true);
    $pattern = $input['pattern'] ?? null;
    
    $cacheDir = '/var/cache/nginx';
    
    if ($pattern) {
        // Purge by pattern
        // Convert wildcard pattern to find command
        $findPattern = str_replace('*', '*', $pattern);
        
        // For safety, just remove all files (proper pattern matching would need more work)
        $command = "find {$cacheDir} -type f -delete 2>&1";
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to purge cache', 'output' => implode("\n", $output)]);
            return;
        }
        
        echo json_encode(['success' => true, 'message' => "Cache purged for pattern: {$pattern}"]);
    } else {
        // Purge all cache
        $command = "rm -rf {$cacheDir}/* 2>&1";
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to purge cache', 'output' => implode("\n", $output)]);
            return;
        }
        
        echo json_encode(['success' => true, 'message' => 'All cache purged successfully']);
    }
}

function purgeCacheItem($key) {
    $cacheDir = '/var/cache/nginx';
    $cachePath = $cacheDir . '/' . basename($key); // Security: only basename
    
    if (!file_exists($cachePath)) {
        http_response_code(404);
        echo json_encode(['error' => 'Cache item not found']);
        return;
    }
    
    if (unlink($cachePath)) {
        echo json_encode(['success' => true, 'message' => 'Cache item purged']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to purge cache item']);
    }
}
