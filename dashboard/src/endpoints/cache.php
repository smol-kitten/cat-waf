<?php
/**
 * Caching & Image Optimization API Endpoint
 */

require_once __DIR__ . '/../lib/CacheManager.php';
require_once __DIR__ . '/../lib/ImageOptimizer.php';

function handleCacheRequest(string $method, array $path, PDO $pdo): void
{
    $cacheManager = new CacheManager($pdo);
    $imageOptimizer = new ImageOptimizer($pdo);
    
    $action = $path[1] ?? 'stats';

    switch ($action) {
        case 'stats':
            handleCacheStats($method, $cacheManager);
            break;
        case 'config':
            handleCacheConfig($method, $path, $pdo, $cacheManager);
            break;
        case 'purge':
            handlePurge($method, $path, $pdo, $cacheManager);
            break;
        case 'warm':
            handleWarm($method, $path, $pdo, $cacheManager);
            break;
        case 'history':
            handlePurgeHistory($method, $path, $pdo, $cacheManager);
            break;
        case 'image':
            handleImageOptimization($method, $path, $pdo, $imageOptimizer);
            break;
        case 'settings':
            handleCacheSettings($method, $pdo, $cacheManager);
            break;
        default:
            sendResponse(['error' => 'Unknown action'], 404);
    }
}

/**
 * Get cache statistics
 */
function handleCacheStats(string $method, CacheManager $cacheManager): void
{
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $stats = $cacheManager->getCacheStats();
    $warmStatus = $cacheManager->getWarmQueueStatus();

    sendResponse([
        'cache' => $stats,
        'warm_queue' => $warmStatus
    ]);
}

/**
 * Get/set cache configuration for domain
 */
function handleCacheConfig(string $method, array $path, PDO $pdo, CacheManager $cacheManager): void
{
    $domainId = $path[2] ?? null;

    if (!$domainId || !is_numeric($domainId)) {
        sendResponse(['error' => 'Domain ID required'], 400);
        return;
    }

    $domainId = (int)$domainId;

    switch ($method) {
        case 'GET':
            $config = $cacheManager->getCacheConfig($domainId);
            if ($config) {
                sendResponse(['config' => $config]);
            } else {
                sendResponse(['config' => null, 'message' => 'No custom configuration, using defaults']);
            }
            break;

        case 'PUT':
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            if ($cacheManager->setCacheConfig($domainId, $input)) {
                // Regenerate nginx config
                triggerConfigRegeneration($pdo, $domainId);
                sendResponse(['success' => true]);
            } else {
                sendResponse(['error' => 'Failed to save configuration'], 500);
            }
            break;

        case 'DELETE':
            // Remove custom config (revert to defaults)
            $stmt = $pdo->prepare("DELETE FROM cache_configs WHERE domain_id = ?");
            $stmt->execute([$domainId]);
            triggerConfigRegeneration($pdo, $domainId);
            sendResponse(['success' => true]);
            break;

        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Handle cache purge operations
 */
function handlePurge(string $method, array $path, PDO $pdo, CacheManager $cacheManager): void
{
    if ($method !== 'POST') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $domainId = $input['domain_id'] ?? null;
    $type = $input['type'] ?? 'all';
    $target = $input['target'] ?? null;
    $purgedBy = $input['purged_by'] ?? 'api';

    if (!$domainId) {
        sendResponse(['error' => 'domain_id is required'], 400);
        return;
    }

    switch ($type) {
        case 'all':
            $result = $cacheManager->purgeAll((int)$domainId, $purgedBy);
            break;
        case 'url':
            if (!$target) {
                sendResponse(['error' => 'target URL is required for url purge'], 400);
                return;
            }
            $result = $cacheManager->purgeUrl((int)$domainId, $target, $purgedBy);
            break;
        case 'pattern':
            if (!$target) {
                sendResponse(['error' => 'target pattern is required for pattern purge'], 400);
                return;
            }
            $result = $cacheManager->purgePattern((int)$domainId, $target, $purgedBy);
            break;
        default:
            sendResponse(['error' => 'Invalid purge type'], 400);
            return;
    }

    sendResponse($result, $result['success'] ? 200 : 500);
}

/**
 * Handle cache warming
 */
function handleWarm(string $method, array $path, PDO $pdo, CacheManager $cacheManager): void
{
    $subAction = $path[2] ?? null;

    switch ($method) {
        case 'GET':
            // Get warming queue status
            $domainId = $_GET['domain_id'] ?? null;
            $status = $cacheManager->getWarmQueueStatus($domainId ? (int)$domainId : null);
            sendResponse(['queue' => $status]);
            break;

        case 'POST':
            if ($subAction === 'process') {
                // Process warming queue
                $limit = $_GET['limit'] ?? 10;
                $result = $cacheManager->processWarmQueue((int)$limit);
                sendResponse($result);
                return;
            }

            if ($subAction === 'clear') {
                // Clear warming queue
                $input = json_decode(file_get_contents('php://input'), true);
                $domainId = $input['domain_id'] ?? null;
                $status = $input['status'] ?? null;
                $cleared = $cacheManager->clearWarmQueue(
                    $domainId ? (int)$domainId : null,
                    $status
                );
                sendResponse(['success' => true, 'cleared' => $cleared]);
                return;
            }

            // Add URLs to warming queue
            $input = json_decode(file_get_contents('php://input'), true);
            $domainId = $input['domain_id'] ?? null;
            $urls = $input['urls'] ?? [];
            $priority = $input['priority'] ?? 5;

            if (!$domainId || empty($urls)) {
                sendResponse(['error' => 'domain_id and urls are required'], 400);
                return;
            }

            if (is_string($urls)) {
                $urls = [$urls];
            }

            $added = $cacheManager->addBulkToWarmQueue((int)$domainId, $urls, (int)$priority);
            sendResponse(['success' => true, 'added' => $added]);
            break;

        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Get purge history
 */
function handlePurgeHistory(string $method, array $path, PDO $pdo, CacheManager $cacheManager): void
{
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $domainId = $_GET['domain_id'] ?? null;
    $limit = (int)($_GET['limit'] ?? 100);

    $history = $cacheManager->getPurgeHistory(
        $domainId ? (int)$domainId : null,
        $limit
    );

    sendResponse(['history' => $history]);
}

/**
 * Handle image optimization
 */
function handleImageOptimization(string $method, array $path, PDO $pdo, ImageOptimizer $imageOptimizer): void
{
    $subAction = $path[2] ?? 'stats';

    switch ($subAction) {
        case 'stats':
            if ($method !== 'GET') {
                sendResponse(['error' => 'Method not allowed'], 405);
                return;
            }
            $stats = $imageOptimizer->getCacheStats();
            $processors = $imageOptimizer->getAvailableProcessors();
            sendResponse([
                'cache' => $stats,
                'processors' => $processors
            ]);
            break;

        case 'config':
            $domainId = $path[3] ?? null;
            if (!$domainId || !is_numeric($domainId)) {
                sendResponse(['error' => 'Domain ID required'], 400);
                return;
            }

            if ($method === 'GET') {
                $config = $imageOptimizer->getConfig((int)$domainId);
                sendResponse(['config' => $config]);
            } elseif ($method === 'PUT' || $method === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                if ($imageOptimizer->setConfig((int)$domainId, $input)) {
                    triggerConfigRegeneration($pdo, (int)$domainId);
                    sendResponse(['success' => true]);
                } else {
                    sendResponse(['error' => 'Failed to save configuration'], 500);
                }
            } else {
                sendResponse(['error' => 'Method not allowed'], 405);
            }
            break;

        case 'optimize':
            if ($method !== 'POST') {
                sendResponse(['error' => 'Method not allowed'], 405);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $imagePath = $input['path'] ?? null;
            $format = $input['format'] ?? 'webp';
            $quality = $input['quality'] ?? 80;

            if (!$imagePath) {
                sendResponse(['error' => 'Image path is required'], 400);
                return;
            }

            $result = $imageOptimizer->optimize($imagePath, [
                'format' => $format,
                'quality' => (int)$quality,
                'max_width' => $input['max_width'] ?? 2048,
                'max_height' => $input['max_height'] ?? 2048,
                'strip_metadata' => $input['strip_metadata'] ?? true
            ]);

            sendResponse($result, $result['success'] ? 200 : 500);
            break;

        case 'clear':
            if ($method !== 'POST') {
                sendResponse(['error' => 'Method not allowed'], 405);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $format = $input['format'] ?? null;
            $cleared = $imageOptimizer->clearCache($format);
            sendResponse(['success' => true, 'cleared' => $cleared]);
            break;

        case 'processors':
            if ($method !== 'GET') {
                sendResponse(['error' => 'Method not allowed'], 405);
                return;
            }
            sendResponse(['processors' => $imageOptimizer->getAvailableProcessors()]);
            break;

        default:
            sendResponse(['error' => 'Unknown image action'], 404);
    }
}

/**
 * Handle cache settings
 */
function handleCacheSettings(string $method, PDO $pdo, CacheManager $cacheManager): void
{
    switch ($method) {
        case 'GET':
            sendResponse(['settings' => $cacheManager->getSettings()]);
            break;

        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            $updated = $cacheManager->updateSettings($input);
            sendResponse(['success' => true, 'updated' => $updated]);
            break;

        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Trigger nginx config regeneration for domain
 */
function triggerConfigRegeneration(PDO $pdo, int $domainId): void
{
    // Add job to queue for config regeneration
    $stmt = $pdo->prepare("
        INSERT INTO jobs (job_type, payload, status, created_at)
        VALUES ('regenerate_config', ?, 'pending', NOW())
    ");
    $stmt->execute([json_encode(['domain_id' => $domainId])]);
}
