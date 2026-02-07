<?php
/**
 * Page Preview API Endpoint
 * Integrates with Playwright container for screenshots
 */

function handlePreviewRequest(string $method, array $path, PDO $pdo): void
{
    $action = $path[1] ?? 'screenshot';

    switch ($action) {
        case 'screenshot':
            handleScreenshot($method, $pdo);
            break;
        case 'thumbnail':
            handleThumbnail($method, $pdo);
            break;
        case 'list':
            handleList($method, $pdo);
            break;
        case 'delete':
            handleDelete($method, $path, $pdo);
            break;
        case 'cache':
            handleCache($method, $path, $pdo);
            break;
        case 'settings':
            handlePreviewSettings($method, $pdo);
            break;
        case 'status':
            handleStatus($method, $pdo);
            break;
        default:
            sendResponse(['error' => 'Unknown action'], 404);
    }
}

/**
 * Get Playwright service URL
 */
function getPlaywrightUrl(): string
{
    return getenv('PLAYWRIGHT_URL') ?: 'http://playwright:3000';
}

/**
 * Get API key for Playwright service
 */
function getPlaywrightApiKey(): string
{
    return getenv('PLAYWRIGHT_API_KEY') ?: '';
}

/**
 * Make request to Playwright service
 */
function playwrightRequest(string $endpoint, string $method = 'GET', ?array $data = null): array
{
    $url = getPlaywrightUrl() . $endpoint;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $headers = ['Content-Type: application/json'];
    $apiKey = getPlaywrightApiKey();
    if ($apiKey) {
        $headers[] = "X-API-Key: {$apiKey}";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => "Connection error: {$error}", 'http_code' => 0];
    }
    
    $decoded = json_decode($response, true);
    if ($decoded === null) {
        return ['success' => false, 'error' => 'Invalid response from Playwright service', 'http_code' => $httpCode];
    }
    
    $decoded['http_code'] = $httpCode;
    return $decoded;
}

/**
 * Take screenshot of a URL
 */
function handleScreenshot(string $method, PDO $pdo): void
{
    if ($method !== 'POST') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $url = $input['url'] ?? null;

    if (!$url) {
        sendResponse(['error' => 'URL is required'], 400);
        return;
    }

    // Validate URL format
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        sendResponse(['error' => 'Invalid URL format'], 400);
        return;
    }

    // Check if service is enabled
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'preview_enabled'");
    $enabled = $stmt->fetchColumn();
    if ($enabled === 'false') {
        sendResponse(['error' => 'Preview service is disabled'], 503);
        return;
    }

    // Get privacy settings
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'preview_mask_selectors'");
    $maskSelectors = json_decode($stmt->fetchColumn() ?: '[]', true);

    // Build request
    $requestData = [
        'url' => $url,
        'width' => $input['width'] ?? 1280,
        'height' => $input['height'] ?? 800,
        'fullPage' => $input['full_page'] ?? false,
        'format' => $input['format'] ?? 'png',
        'quality' => $input['quality'] ?? 80,
        'timeout' => $input['timeout'] ?? 30000,
        'waitUntil' => $input['wait_until'] ?? 'networkidle',
        'delay' => $input['delay'] ?? 0,
        'selector' => $input['selector'] ?? null,
        'maskSelectors' => array_merge($maskSelectors, $input['mask_selectors'] ?? []),
        'useCache' => $input['use_cache'] ?? true
    ];

    $result = playwrightRequest('/screenshot', 'POST', $requestData);

    if (isset($result['error'])) {
        sendResponse(['error' => $result['error']], $result['http_code'] ?: 500);
        return;
    }

    // Add full URL to result
    if (isset($result['path'])) {
        $result['full_url'] = getPlaywrightUrl() . $result['path'];
    }

    // Log the screenshot request
    logPreviewRequest($pdo, $url, 'screenshot', $result['success'] ?? false);

    sendResponse($result);
}

/**
 * Generate thumbnail
 */
function handleThumbnail(string $method, PDO $pdo): void
{
    if ($method !== 'POST') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $url = $input['url'] ?? null;

    if (!$url) {
        sendResponse(['error' => 'URL is required'], 400);
        return;
    }

    $requestData = [
        'url' => $url,
        'width' => $input['width'] ?? 320,
        'height' => $input['height'] ?? 200,
        'format' => $input['format'] ?? 'webp',
        'quality' => $input['quality'] ?? 75,
        'useCache' => $input['use_cache'] ?? true
    ];

    $result = playwrightRequest('/thumbnail', 'POST', $requestData);

    if (isset($result['error'])) {
        sendResponse(['error' => $result['error']], $result['http_code'] ?: 500);
        return;
    }

    if (isset($result['path'])) {
        $result['full_url'] = getPlaywrightUrl() . $result['path'];
    }

    logPreviewRequest($pdo, $url, 'thumbnail', $result['success'] ?? false);

    sendResponse($result);
}

/**
 * List screenshots
 */
function handleList(string $method, PDO $pdo): void
{
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $result = playwrightRequest('/screenshots', 'GET');
    sendResponse($result);
}

/**
 * Delete screenshot
 */
function handleDelete(string $method, array $path, PDO $pdo): void
{
    if ($method !== 'DELETE') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $filename = $path[2] ?? null;
    if (!$filename) {
        sendResponse(['error' => 'Filename required'], 400);
        return;
    }

    // Sanitize filename
    $filename = basename($filename);

    $result = playwrightRequest("/screenshot/{$filename}", 'DELETE');
    sendResponse($result, $result['http_code'] ?: 200);
}

/**
 * Cache management
 */
function handleCache(string $method, array $path, PDO $pdo): void
{
    $subAction = $path[2] ?? 'stats';

    switch ($subAction) {
        case 'stats':
            if ($method !== 'GET') {
                sendResponse(['error' => 'Method not allowed'], 405);
                return;
            }
            $result = playwrightRequest('/cache/stats', 'GET');
            sendResponse($result);
            break;

        case 'clear':
            if ($method !== 'POST') {
                sendResponse(['error' => 'Method not allowed'], 405);
                return;
            }
            $result = playwrightRequest('/cache/clear', 'POST');
            sendResponse($result);
            break;

        default:
            sendResponse(['error' => 'Unknown cache action'], 404);
    }
}

/**
 * Preview settings
 */
function handlePreviewSettings(string $method, PDO $pdo): void
{
    switch ($method) {
        case 'GET':
            $stmt = $pdo->query("
                SELECT setting_key, setting_value 
                FROM settings 
                WHERE setting_key LIKE 'preview_%'
            ");
            $settings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            sendResponse(['settings' => $settings]);
            break;

        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            $updated = 0;
            
            foreach ($input as $key => $value) {
                if (strpos($key, 'preview_') === 0) {
                    // Handle JSON values
                    if (is_array($value)) {
                        $value = json_encode($value);
                    }
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO settings (setting_key, setting_value) 
                        VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                    ");
                    if ($stmt->execute([$key, $value])) {
                        $updated++;
                    }
                }
            }
            sendResponse(['success' => true, 'updated' => $updated]);
            break;

        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Check Playwright service status
 */
function handleStatus(string $method, PDO $pdo): void
{
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $result = playwrightRequest('/health', 'GET');
    
    $status = [
        'service_url' => getPlaywrightUrl(),
        'connected' => !isset($result['error']),
        'health' => $result
    ];

    sendResponse($status);
}

/**
 * Log preview request for analytics
 */
function logPreviewRequest(PDO $pdo, string $url, string $type, bool $success): void
{
    try {
        // Simple logging to telemetry table (reuse existing infrastructure)
        $stmt = $pdo->prepare("
            INSERT INTO telemetry (timestamp, domain_id, uri, status_code, response_time)
            VALUES (NOW(), 0, ?, ?, 0)
        ");
        $stmt->execute([
            "preview:{$type}:" . parse_url($url, PHP_URL_HOST),
            $success ? 200 : 500
        ]);
    } catch (Exception $e) {
        // Ignore logging errors
    }
}
