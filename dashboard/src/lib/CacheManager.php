<?php
/**
 * Cache Manager
 * Handles nginx proxy cache operations, purging, and warming
 */

class CacheManager
{
    private PDO $pdo;
    private string $cachePath;
    private string $purgeSocket = '/var/run/nginx-cache-purge.sock';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->cachePath = $this->getSetting('cache_path') ?: '/var/cache/nginx';
    }

    /**
     * Get cache configuration for a domain
     */
    public function getCacheConfig(int $domainId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM cache_configs WHERE domain_id = ?");
        $stmt->execute([$domainId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($config) {
            $config['cache_bypass_cookies'] = json_decode($config['cache_bypass_cookies'] ?: '[]', true);
            $config['cache_bypass_args'] = json_decode($config['cache_bypass_args'] ?: '[]', true);
            $config['cache_key_includes'] = json_decode($config['cache_key_includes'] ?: '{}', true);
        }

        return $config;
    }

    /**
     * Set cache configuration for a domain
     */
    public function setCacheConfig(int $domainId, array $config): bool
    {
        $bypassCookies = isset($config['cache_bypass_cookies']) ? json_encode($config['cache_bypass_cookies']) : '[]';
        $bypassArgs = isset($config['cache_bypass_args']) ? json_encode($config['cache_bypass_args']) : '[]';
        $keyIncludes = isset($config['cache_key_includes']) ? json_encode($config['cache_key_includes']) : '{}';

        $stmt = $this->pdo->prepare("
            INSERT INTO cache_configs 
            (domain_id, cache_enabled, cache_static_ttl, cache_dynamic_ttl, 
             cache_bypass_cookies, cache_bypass_args, cache_key_includes,
             stale_while_revalidate, stale_if_error)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                cache_enabled = VALUES(cache_enabled),
                cache_static_ttl = VALUES(cache_static_ttl),
                cache_dynamic_ttl = VALUES(cache_dynamic_ttl),
                cache_bypass_cookies = VALUES(cache_bypass_cookies),
                cache_bypass_args = VALUES(cache_bypass_args),
                cache_key_includes = VALUES(cache_key_includes),
                stale_while_revalidate = VALUES(stale_while_revalidate),
                stale_if_error = VALUES(stale_if_error)
        ");

        return $stmt->execute([
            $domainId,
            $config['cache_enabled'] ?? true,
            $config['cache_static_ttl'] ?? 86400,
            $config['cache_dynamic_ttl'] ?? 3600,
            $bypassCookies,
            $bypassArgs,
            $keyIncludes,
            $config['stale_while_revalidate'] ?? 60,
            $config['stale_if_error'] ?? 3600
        ]);
    }

    /**
     * Purge cache for a domain
     */
    public function purgeAll(int $domainId, string $purgedBy = 'system'): array
    {
        $stmt = $this->pdo->prepare("SELECT domain FROM domains WHERE id = ?");
        $stmt->execute([$domainId]);
        $domain = $stmt->fetchColumn();

        if (!$domain) {
            return ['success' => false, 'error' => 'Domain not found'];
        }

        // Execute purge
        $result = $this->executePurge('all', $domain);

        // Log the purge
        $this->logPurge($domainId, 'all', $domain, $purgedBy, $result['success'], $result['error'] ?? null);

        return $result;
    }

    /**
     * Purge specific URL from cache
     */
    public function purgeUrl(int $domainId, string $url, string $purgedBy = 'system'): array
    {
        $stmt = $this->pdo->prepare("SELECT domain FROM domains WHERE id = ?");
        $stmt->execute([$domainId]);
        $domain = $stmt->fetchColumn();

        if (!$domain) {
            return ['success' => false, 'error' => 'Domain not found'];
        }

        // Validate URL belongs to domain
        $urlHost = parse_url($url, PHP_URL_HOST);
        if ($urlHost && $urlHost !== $domain && !str_ends_with($urlHost, '.' . $domain)) {
            return ['success' => false, 'error' => 'URL does not belong to this domain'];
        }

        $result = $this->executePurge('url', $url);
        $this->logPurge($domainId, 'url', $url, $purgedBy, $result['success'], $result['error'] ?? null);

        return $result;
    }

    /**
     * Purge by pattern (wildcard)
     */
    public function purgePattern(int $domainId, string $pattern, string $purgedBy = 'system'): array
    {
        $stmt = $this->pdo->prepare("SELECT domain FROM domains WHERE id = ?");
        $stmt->execute([$domainId]);
        $domain = $stmt->fetchColumn();

        if (!$domain) {
            return ['success' => false, 'error' => 'Domain not found'];
        }

        $result = $this->executePurge('pattern', "{$domain}{$pattern}");
        $this->logPurge($domainId, 'pattern', $pattern, $purgedBy, $result['success'], $result['error'] ?? null);

        return $result;
    }

    /**
     * Execute cache purge operation
     */
    private function executePurge(string $type, string $target): array
    {
        // Method 1: Use nginx cache purge module if available
        if ($type === 'url') {
            $purgeUrl = str_replace(['http://', 'https://'], '', $target);
            $ch = curl_init("http://127.0.0.1/purge/{$purgeUrl}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 || $httpCode === 404) {
                return ['success' => true, 'method' => 'nginx_purge_module'];
            }
        }

        // Method 2: Delete cache files directly
        $cacheDir = $this->cachePath;
        
        if ($type === 'all') {
            // Delete all cache for domain
            $hash = md5($target);
            $pattern = "{$cacheDir}/*/{$hash[0]}/{$hash[1]}/*";
            $deleted = $this->deleteGlob($pattern);
            
            // Also try clearing by domain pattern in cache key
            $this->clearCacheByDomain($target);
            
            return ['success' => true, 'method' => 'file_delete', 'deleted' => $deleted];
        }

        if ($type === 'url') {
            // Generate cache key hash (nginx default)
            $cacheKey = md5($target);
            $subdir1 = substr($cacheKey, -1);
            $subdir2 = substr($cacheKey, -3, 2);
            $cachePath = "{$cacheDir}/{$subdir1}/{$subdir2}/{$cacheKey}";

            if (file_exists($cachePath)) {
                unlink($cachePath);
                return ['success' => true, 'method' => 'file_delete', 'path' => $cachePath];
            }
            
            return ['success' => true, 'method' => 'file_delete', 'note' => 'File not in cache'];
        }

        if ($type === 'pattern') {
            // Pattern-based deletion requires scanning
            $deleted = $this->clearCacheByPattern($target);
            return ['success' => true, 'method' => 'pattern_scan', 'deleted' => $deleted];
        }

        return ['success' => false, 'error' => 'Unknown purge type'];
    }

    /**
     * Clear cache files matching a domain
     */
    private function clearCacheByDomain(string $domain): int
    {
        $deleted = 0;
        $cacheDir = $this->cachePath;

        // Recursively scan cache directory
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                // Read first few KB to check if it matches domain
                $content = file_get_contents($file->getPathname(), false, null, 0, 4096);
                if (strpos($content, $domain) !== false) {
                    unlink($file->getPathname());
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Clear cache by URL pattern
     */
    private function clearCacheByPattern(string $pattern): int
    {
        $deleted = 0;
        $regex = str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/'));
        $cacheDir = $this->cachePath;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $content = file_get_contents($file->getPathname(), false, null, 0, 4096);
                if (preg_match("/{$regex}/i", $content)) {
                    unlink($file->getPathname());
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Delete files matching glob pattern
     */
    private function deleteGlob(string $pattern): int
    {
        $deleted = 0;
        $files = glob($pattern);
        
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
                $deleted++;
            }
        }
        
        return $deleted;
    }

    /**
     * Log purge operation
     */
    private function logPurge(?int $domainId, string $type, string $target, string $purgedBy, bool $success, ?string $error): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO cache_purge_log (domain_id, purge_type, purge_target, purged_by, success, error_message)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$domainId, $type, $target, $purgedBy, $success ? 1 : 0, $error]);
    }

    /**
     * Get purge history
     */
    public function getPurgeHistory(?int $domainId = null, int $limit = 100): array
    {
        $sql = "
            SELECT l.*, d.domain 
            FROM cache_purge_log l
            LEFT JOIN domains d ON l.domain_id = d.id
        ";
        
        if ($domainId) {
            $sql .= " WHERE l.domain_id = ?";
        }
        
        $sql .= " ORDER BY l.purged_at DESC LIMIT ?";

        $stmt = $this->pdo->prepare($sql);
        
        if ($domainId) {
            $stmt->execute([$domainId, $limit]);
        } else {
            $stmt->execute([$limit]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        $stats = [
            'cache_path' => $this->cachePath,
            'total_size' => 0,
            'file_count' => 0,
            'oldest_file' => null,
            'newest_file' => null
        ];

        if (!is_dir($this->cachePath)) {
            return $stats;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cachePath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $oldestTime = PHP_INT_MAX;
        $newestTime = 0;

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $stats['file_count']++;
                $stats['total_size'] += $file->getSize();
                
                $mtime = $file->getMTime();
                if ($mtime < $oldestTime) {
                    $oldestTime = $mtime;
                    $stats['oldest_file'] = date('Y-m-d H:i:s', $mtime);
                }
                if ($mtime > $newestTime) {
                    $newestTime = $mtime;
                    $stats['newest_file'] = date('Y-m-d H:i:s', $mtime);
                }
            }
        }

        $stats['total_size_mb'] = round($stats['total_size'] / 1024 / 1024, 2);

        return $stats;
    }

    /**
     * Add URL to cache warming queue
     */
    public function addToWarmQueue(int $domainId, string $url, int $priority = 5): bool
    {
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO cache_warm_queue (domain_id, url, priority)
            VALUES (?, ?, ?)
        ");
        return $stmt->execute([$domainId, $url, $priority]);
    }

    /**
     * Add multiple URLs to warming queue
     */
    public function addBulkToWarmQueue(int $domainId, array $urls, int $priority = 5): int
    {
        $added = 0;
        foreach ($urls as $url) {
            if ($this->addToWarmQueue($domainId, $url, $priority)) {
                $added++;
            }
        }
        return $added;
    }

    /**
     * Process cache warming queue
     */
    public function processWarmQueue(int $limit = 10): array
    {
        $concurrency = (int)$this->getSetting('cache_warm_concurrency') ?: 5;
        $results = ['processed' => 0, 'success' => 0, 'failed' => 0];

        // Get pending items
        $stmt = $this->pdo->prepare("
            SELECT id, domain_id, url 
            FROM cache_warm_queue 
            WHERE status = 'pending' OR (status = 'failed' AND attempts < 3)
            ORDER BY priority DESC, created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            // Mark as processing
            $stmt = $this->pdo->prepare("UPDATE cache_warm_queue SET status = 'processing', last_attempt = NOW() WHERE id = ?");
            $stmt->execute([$item['id']]);

            // Make request to warm cache
            $ch = curl_init($item['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'CatWAF-CacheWarmer/1.0'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $results['processed']++;

            if ($httpCode >= 200 && $httpCode < 400 && !$error) {
                $stmt = $this->pdo->prepare("UPDATE cache_warm_queue SET status = 'completed' WHERE id = ?");
                $stmt->execute([$item['id']]);
                $results['success']++;
            } else {
                $stmt = $this->pdo->prepare("
                    UPDATE cache_warm_queue 
                    SET status = 'failed', attempts = attempts + 1, error_message = ?
                    WHERE id = ?
                ");
                $stmt->execute([$error ?: "HTTP {$httpCode}", $item['id']]);
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Get warming queue status
     */
    public function getWarmQueueStatus(?int $domainId = null): array
    {
        $where = $domainId ? "WHERE domain_id = ?" : "";
        
        $stmt = $this->pdo->prepare("
            SELECT status, COUNT(*) as count 
            FROM cache_warm_queue 
            {$where}
            GROUP BY status
        ");
        
        if ($domainId) {
            $stmt->execute([$domainId]);
        } else {
            $stmt->execute();
        }

        $statuses = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $statuses[$row['status']] = (int)$row['count'];
        }

        return [
            'pending' => $statuses['pending'] ?? 0,
            'processing' => $statuses['processing'] ?? 0,
            'completed' => $statuses['completed'] ?? 0,
            'failed' => $statuses['failed'] ?? 0,
            'total' => array_sum($statuses)
        ];
    }

    /**
     * Clear warming queue
     */
    public function clearWarmQueue(?int $domainId = null, ?string $status = null): int
    {
        $sql = "DELETE FROM cache_warm_queue WHERE 1=1";
        $params = [];

        if ($domainId) {
            $sql .= " AND domain_id = ?";
            $params[] = $domainId;
        }

        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->rowCount();
    }

    /**
     * Generate nginx cache configuration for domain
     */
    public function generateNginxCacheConfig(int $domainId): string
    {
        $config = $this->getCacheConfig($domainId);
        
        if (!$config || !$config['cache_enabled']) {
            return "# Caching disabled for this domain\n";
        }

        $bypassConditions = [];
        
        // Cookie bypass
        foreach ($config['cache_bypass_cookies'] as $cookie) {
            $bypassConditions[] = "\$cookie_{$cookie}";
        }

        // Query arg bypass
        foreach ($config['cache_bypass_args'] as $arg) {
            $bypassConditions[] = "\$arg_{$arg}";
        }

        $bypassVar = empty($bypassConditions) ? '0' : implode('', $bypassConditions);

        $nginxConfig = "
# Cache configuration
proxy_cache_valid 200 {$config['cache_dynamic_ttl']}s;
proxy_cache_valid 301 302 {$config['cache_static_ttl']}s;
proxy_cache_valid 404 1m;

# Stale content settings
proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
proxy_cache_background_update on;
proxy_cache_lock on;

# Cache bypass conditions
set \$cache_bypass {$bypassVar};
proxy_cache_bypass \$cache_bypass;
proxy_no_cache \$cache_bypass;

# Add cache status header
add_header X-Cache-Status \$upstream_cache_status;
";

        return $nginxConfig;
    }

    private function getSetting(string $key): ?string
    {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        return $stmt->fetchColumn() ?: null;
    }

    public function getSettings(): array
    {
        $stmt = $this->pdo->query("
            SELECT setting_key, setting_value 
            FROM settings 
            WHERE setting_key LIKE 'cache_%' OR setting_key LIKE 'image_%'
        ");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    public function updateSettings(array $settings): int
    {
        $updated = 0;
        foreach ($settings as $key => $value) {
            if (strpos($key, 'cache_') === 0 || strpos($key, 'image_') === 0) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO settings (setting_key, setting_value) 
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                ");
                if ($stmt->execute([$key, $value])) {
                    $updated++;
                }
            }
        }
        return $updated;
    }
}
