<?php
// GeoIP Lookup API
// GET /api/geoip/:ip - Get location info for an IP

require_once __DIR__ . '/../lib/GeoIPLocal.php';
require_once __DIR__ . '/../lib/GeoIP.php'; // Keep as fallback

// Don't set header again if already set by index.php
if (!headers_sent()) {
    header('Content-Type: application/json');
}

$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];

// Database info endpoint
if (preg_match('#/geoip/info$#', $requestUri) && $method === 'GET') {
    echo json_encode(GeoIPLocal::getDatabaseInfo());
    exit;
}

// Parse IP from URI
if (preg_match('#/geoip/([^/]+)$#', $requestUri, $matches)) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    
    $ip = urldecode($matches[1]);
    $location = GeoIPLocal::lookup($ip);
    
    if ($location) {
        $location['flag'] = GeoIPLocal::getFlag($location['countryCode']);
        echo json_encode($location);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Location not found']);
    }
    exit;
}

// Bulk lookup
if (preg_match('#/geoip/bulk$#', $requestUri) && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $ips = $input['ips'] ?? [];
    
    if (empty($ips) || !is_array($ips)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid IP list']);
        exit;
    }
    
    $results = [];
    foreach ($ips as $ip) {
        $location = GeoIPLocal::lookup($ip);
        if ($location) {
            $location['flag'] = GeoIPLocal::getFlag($location['countryCode']);
            $results[$ip] = $location;
        }
    }
    
    echo json_encode(['locations' => $results]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Endpoint not found']);
