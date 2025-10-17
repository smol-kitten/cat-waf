<?php
/**
 * Cloudflare Zone Auto-Detection
 * 
 * Automatically detects and updates Cloudflare zone IDs for sites
 * Uses Cloudflare API to query zones by domain name
 */

require_once '../config.php';

header('Content-Type: application/json');

// Authentication
$headers = getallheaders();
$token = $headers['Authorization'] ?? '';
if (strpos($token, 'Bearer ') === 0) {
    $token = substr($token, 7);
}

if ($token !== API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get Cloudflare credentials from environment
$cfApiToken = getenv('CLOUDFLARE_API_TOKEN') ?: null;
$cfApiKey = getenv('CLOUDFLARE_API_KEY') ?: null;
$cfEmail = getenv('CLOUDFLARE_EMAIL') ?: null;

// Trim whitespace from credentials
if ($cfApiToken) {
    $cfApiToken = trim($cfApiToken);
    error_log("CF API Token length: " . strlen($cfApiToken));
}
if ($cfApiKey) {
    $cfApiKey = trim($cfApiKey);
}
if ($cfEmail) {
    $cfEmail = trim($cfEmail);
}

if (!$cfApiToken && (!$cfApiKey || !$cfEmail)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Cloudflare credentials not configured',
        'message' => 'Set CLOUDFLARE_API_TOKEN (preferred) or CLOUDFLARE_API_KEY + CLOUDFLARE_EMAIL environment variables'
    ]);
    exit;
}

$db = getDB();

try {
    // Get site ID if specified, otherwise detect all sites
    $siteId = $_GET['site_id'] ?? null;
    
    $query = "SELECT id, domain, cf_zone_id FROM sites WHERE enabled = 1";
    $params = [];
    
    if ($siteId) {
        $query .= " AND id = ?";
        $params[] = $siteId;
    } else {
        // Only detect sites without zone ID or if force=1
        $force = $_GET['force'] ?? false;
        if (!$force) {
            $query .= " AND (cf_zone_id IS NULL OR cf_zone_id = '')";
        }
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $sites = $stmt->fetchAll();
    
    if (empty($sites)) {
        echo json_encode([
            'success' => true,
            'message' => 'No sites to process',
            'detected' => 0,
            'failed' => 0,
            'sites' => []
        ]);
        exit;
    }
    
    $results = [];
    $detected = 0;
    $failed = 0;
    
    foreach ($sites as $site) {
        $domain = $site['domain'];
        $siteId = $site['id'];
        
        // Extract root domain (remove wildcards and subdomains for zone lookup)
        $rootDomain = extractRootDomain($domain);
        
        // Query Cloudflare API
        $zoneId = getCloudflareZoneId($rootDomain, $cfApiToken, $cfApiKey, $cfEmail);
        
        if ($zoneId) {
            // Update database
            $updateStmt = $db->prepare("UPDATE sites SET cf_zone_id = ? WHERE id = ?");
            $updateStmt->execute([$zoneId, $siteId]);
            
            $results[] = [
                'domain' => $domain,
                'root_domain' => $rootDomain,
                'zone_id' => $zoneId,
                'status' => 'detected',
                'previous_zone_id' => $site['cf_zone_id']
            ];
            $detected++;
        } else {
            $results[] = [
                'domain' => $domain,
                'root_domain' => $rootDomain,
                'zone_id' => null,
                'status' => 'not_found',
                'message' => 'Zone not found in Cloudflare account'
            ];
            $failed++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'detected' => $detected,
        'failed' => $failed,
        'sites' => $results
    ]);
    
} catch (Exception $e) {
    error_log("Cloudflare zone detection error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Zone detection failed',
        'message' => $e->getMessage()
    ]);
}

/**
 * Extract root domain from a domain (handles wildcards and subdomains)
 */
function extractRootDomain($domain) {
    // Remove wildcard prefix
    $domain = preg_replace('/^\*\./', '', $domain);
    
    // Split by dots
    $parts = explode('.', $domain);
    $count = count($parts);
    
    // Handle special TLDs (co.uk, com.au, etc.)
    if ($count >= 3 && in_array($parts[$count - 2] . '.' . $parts[$count - 1], [
        'co.uk', 'com.au', 'co.nz', 'co.za', 'com.br', 'com.mx',
        'co.jp', 'co.in', 'co.kr', 'ac.uk', 'gov.uk', 'org.uk'
    ])) {
        return $parts[$count - 3] . '.' . $parts[$count - 2] . '.' . $parts[$count - 1];
    }
    
    // Return last two parts (domain.tld)
    if ($count >= 2) {
        return $parts[$count - 2] . '.' . $parts[$count - 1];
    }
    
    return $domain;
}

/**
 * Query Cloudflare API to get zone ID for a domain
 */
function getCloudflareZoneId($domain, $apiToken, $apiKey, $email) {
    $url = "https://api.cloudflare.com/client/v4/zones?name=" . urlencode($domain);
    
    $headers = [
        'Content-Type: application/json'
    ];
    
    // Use API Token (preferred) or API Key + Email
    if ($apiToken) {
        // Trim token and log details
        $apiToken = trim($apiToken);
        $tokenLength = strlen($apiToken);
        error_log("CF: Using API Token for $domain (length: $tokenLength)");
        $headers[] = 'Authorization: Bearer ' . $apiToken;
    } else {
        error_log("CF: Using API Key + Email for $domain");
        $headers[] = 'X-Auth-Key: ' . trim($apiKey);
        $headers[] = 'X-Auth-Email: ' . trim($email);
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log("Cloudflare API curl error for $domain: $curlError");
        return null;
    }
    
    if ($httpCode !== 200) {
        // Parse error response for better logging
        $errorData = json_decode($response, true);
        $errorMsg = isset($errorData['errors']) ? json_encode($errorData['errors']) : $response;
        error_log("Cloudflare API error for $domain: HTTP $httpCode - Error: $errorMsg");
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (!$data) {
        error_log("Cloudflare API invalid JSON for $domain: $response");
        return null;
    }
    
    if (!isset($data['success']) || !$data['success']) {
        $errors = isset($data['errors']) ? json_encode($data['errors']) : 'unknown error';
        error_log("Cloudflare API request failed for $domain: $errors");
        return null;
    }
    
    if (empty($data['result'])) {
        error_log("Cloudflare API no zones found for $domain (account may not contain this zone)");
        return null;
    }
    
    // Return the first zone ID (Cloudflare returns exact match with name parameter)
    $zoneId = $data['result'][0]['id'] ?? null;
    if ($zoneId) {
        error_log("Cloudflare zone found for $domain: $zoneId");
    }
    return $zoneId;
}
