<?php
/**
 * On-the-fly image optimization endpoint
 * 
 * Fetches an image from the backend, optimizes it (resize, format conversion),
 * caches the result, and serves it directly.
 * 
 * Query parameters:
 *   url     - Original image URL path (required)
 *   w       - Max width (optional, default: original)
 *   h       - Max height (optional, default: original)
 *   q       - Quality 1-100 (optional, default: 80)
 *   f       - Output format: webp, avif, jpeg, png (optional, auto-negotiated from Accept header)
 *   domain  - Domain for config lookup (set by nginx)
 */

require_once __DIR__ . '/../lib/ImageOptimizer.php';

const MAX_IMAGE_DIMENSION = 4096;
const MAX_FIELD_LENGTH = 500;

function handleImageOptimize($method, $params, $db) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo 'Method not allowed';
        exit;
    }

    $url = $_GET['url'] ?? '';
    $width = max(1, min(MAX_IMAGE_DIMENSION, (int)($_GET['w'] ?? 0))) ?: null;
    $height = max(1, min(MAX_IMAGE_DIMENSION, (int)($_GET['h'] ?? 0))) ?: null;
    $quality = max(1, min(100, (int)($_GET['q'] ?? 80)));
    $format = $_GET['f'] ?? null;
    $domain = $_GET['domain'] ?? ($_SERVER['HTTP_X_CATWAF_DOMAIN'] ?? '');

    if (empty($url)) {
        http_response_code(400);
        echo 'Missing url parameter';
        exit;
    }

    // Validate URL path - must be a relative path, not an external URL
    if (preg_match('#^https?://#', $url) || strpos($url, '..') !== false) {
        http_response_code(400);
        echo 'Invalid image URL';
        exit;
    }

    // Validate file extension
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowedExtensions)) {
        http_response_code(400);
        echo 'Unsupported image format';
        exit;
    }

    // Auto-negotiate format from Accept header if not specified
    if (!$format) {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (strpos($accept, 'image/avif') !== false) {
            $format = 'avif';
        } elseif (strpos($accept, 'image/webp') !== false) {
            $format = 'webp';
        } else {
            $format = ($ext === 'png') ? 'png' : 'jpeg';
        }
    }

    // Validate format
    $allowedFormats = ['webp', 'avif', 'jpeg', 'jpg', 'png'];
    if (!in_array($format, $allowedFormats)) {
        $format = 'jpeg';
    }

    // Generate cache key based on all parameters
    $cacheKey = md5($domain . '|' . $url . '|' . ($width ?? 'auto') . '|' . ($height ?? 'auto') . '|' . $quality . '|' . $format);
    $cacheDir = '/var/cache/nginx/images';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $cachePath = $cacheDir . '/' . $cacheKey . '.' . $format;

    // Serve from cache if available
    if (file_exists($cachePath)) {
        serveImage($cachePath, $format);
        return;
    }

    // Look up backend for this domain to fetch the original image
    $backendUrl = null;
    if ($domain) {
        try {
            $stmt = $db->prepare("SELECT backend_url, backends FROM sites WHERE domain = ? LIMIT 1");
            $stmt->execute([$domain]);
            $site = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($site) {
                // Try backends array first
                if (!empty($site['backends'])) {
                    $backends = json_decode($site['backends'], true);
                    if (!empty($backends) && isset($backends[0]['address'])) {
                        $protocol = $backends[0]['protocol'] ?? 'http';
                        $backendUrl = $protocol . '://' . $backends[0]['address'];
                    }
                }
                // Fall back to backend_url
                if (!$backendUrl && !empty($site['backend_url'])) {
                    $backendUrl = 'http://' . $site['backend_url'];
                }
            }
        } catch (PDOException $e) {
            error_log("Image optimize DB error: " . $e->getMessage());
        }
    }

    if (!$backendUrl) {
        http_response_code(502);
        echo 'Backend not configured';
        exit;
    }

    // Fetch original image from backend
    $imageUrl = rtrim($backendUrl, '/') . '/' . ltrim($url, '/');
    $tmpFile = tempnam(sys_get_temp_dir(), 'catimg_');

    $ch = curl_init($imageUrl);
    $fp = fopen($tmpFile, 'wb');
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => ['Accept: image/*']
    ]);
    $success = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$success || $httpCode !== 200 || !file_exists($tmpFile) || filesize($tmpFile) === 0) {
        @unlink($tmpFile);
        http_response_code(502);
        echo 'Failed to fetch original image';
        exit;
    }

    // Optimize the image
    try {
        $optimizer = new ImageOptimizer($db);
        $result = $optimizer->optimize($tmpFile, [
            'format' => $format,
            'quality' => $quality,
            'max_width' => $width ?? MAX_IMAGE_DIMENSION,
            'max_height' => $height ?? MAX_IMAGE_DIMENSION,
            'strip_metadata' => true
        ]);

        if ($result['success'] && isset($result['path']) && file_exists($result['path'])) {
            // Copy optimized result to our cache path
            copy($result['path'], $cachePath);
            @unlink($tmpFile);
            serveImage($cachePath, $format);
        } else {
            // Optimization failed - serve original
            @unlink($tmpFile);
            http_response_code(500);
            echo 'Image optimization failed: ' . ($result['error'] ?? 'unknown error');
        }
    } catch (Exception $e) {
        @unlink($tmpFile);
        error_log("Image optimization error: " . $e->getMessage());
        http_response_code(500);
        echo 'Image optimization error';
    }
}

/**
 * Serve an image file with proper headers
 */
function serveImage(string $path, string $format): void {
    $mimeTypes = [
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif'
    ];

    $contentType = $mimeTypes[$format] ?? 'application/octet-stream';
    $size = filesize($path);

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . $size);
    header('Cache-Control: public, max-age=2592000, immutable');
    header('Vary: Accept');
    header('X-Image-Optimized: CatWAF');
    
    readfile($path);
    exit;
}
