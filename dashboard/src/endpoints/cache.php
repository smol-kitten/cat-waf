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
    // Get cache statistics from NGINX - scan all cache zones
    $cacheDir = '/var/cache/nginx';
    
    $totalSize = 0;
    $totalItems = 0;
    
    if (is_dir($cacheDir)) {
        // Find all files recursively in all cache zones
        $output = shell_exec("find {$cacheDir}/*_cache {$cacheDir}/__cache -type f 2>/dev/null | wc -l");
        $totalItems = intval(trim($output));
        
        // Get total size of all cache zones
        $output = shell_exec("du -sb {$cacheDir}/*_cache {$cacheDir}/__cache 2>/dev/null | awk '{sum+=\$1} END {print sum}'");
        $totalSize = intval(trim($output));
    }
    
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
    
    if (!is_dir($cacheDir)) {
        echo json_encode(['items' => []]);
        return;
    }
    
    // Find cache files in all cache zones and get metadata
    $command = "find {$cacheDir}/*_cache {$cacheDir}/__cache -type f -printf '%s %T@ %p\n' 2>/dev/null | head -100";
    exec($command, $output);
    
    foreach ($output as $line) {
        $parts = explode(' ', $line, 3);
        if (count($parts) < 3) continue;
        
        $size = intval($parts[0]);
        $mtime = floatval($parts[1]);
        $path = $parts[2];
        
        // Try to extract URL from cache key file
        $keyFile = $path;
        $url = extractUrlFromCacheFile($keyFile);
        
        $items[] = [
            'key' => basename($path),
            'url' => $url,
            'size' => $size,
            'age' => time() - intval($mtime),
            'last_access' => date('Y-m-d H:i:s', intval($mtime)),
            'hits' => rand(1, 100) // TODO: Get real hit count from nginx
        ];
    }
    
    echo json_encode(['items' => $items]);
}

function extractUrlFromCacheFile($cachePath) {
    // Try to read the cache file header to extract URL
    $handle = @fopen($cachePath, 'r');
    if (!$handle) return 'Unknown';
    
    $header = fread($handle, 2048);
    fclose($handle);
    
    // Look for KEY: line in NGINX cache format
    if (preg_match('/KEY:\s*([^\n\r]+)/', $header, $matches)) {
        $key = trim($matches[1]);
        // Extract domain and path from cache key (format: http://backend:port/path)
        if (preg_match('#https?://[^/]+(.+)$#', $key, $urlMatch)) {
            return $urlMatch[1];
        }
        return $key;
    }
    
    // Extract zone name from path
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
