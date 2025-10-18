<?php
// Config Verification API
// POST /api/sites/verify - Verify site configuration before saving

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get site configuration from request body
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['domain'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$db = getDB();
$issues = [];
$warnings = [];

// 1. Check for domain conflicts
$domain = $input['domain'];
$siteId = $input['id'] ?? null;

$stmt = $db->prepare("SELECT id, domain FROM sites WHERE domain = ? AND id != ?");
$stmt->execute([$domain, $siteId ?? 0]);
$conflict = $stmt->fetch();

if ($conflict) {
    $issues[] = [
        'type' => 'error',
        'category' => 'domain',
        'message' => "Domain '{$domain}' is already configured in site ID {$conflict['id']}"
    ];
}

// 2. Check backend reachability
if (!empty($input['backends']) && is_array($input['backends'])) {
    foreach ($input['backends'] as $index => $backend) {
        $address = $backend['address'] ?? '';
        $port = $backend['port'] ?? 80;
        
        if (empty($address)) {
            $warnings[] = [
                'type' => 'warning',
                'category' => 'backend',
                'message' => "Backend #{$index}: No address specified"
            ];
            continue;
        }
        
        // Test backend connectivity (with timeout)
        $testResult = testBackendConnectivity($address, $port);
        
        if (!$testResult['reachable']) {
            $warnings[] = [
                'type' => 'warning',
                'category' => 'backend',
                'message' => "Backend #{$index} ({$address}:{$port}): {$testResult['message']}"
            ];
        }
    }
}

// 3. Check port conflicts (if using specific ports)
if (isset($input['port']) && !empty($input['port'])) {
    $port = $input['port'];
    $stmt = $db->prepare("SELECT id, domain, port FROM sites WHERE port = ? AND id != ? AND port IS NOT NULL");
    $stmt->execute([$port, $siteId ?? 0]);
    $portConflicts = $stmt->fetchAll();
    
    if (!empty($portConflicts)) {
        $conflictDomains = array_map(fn($s) => $s['domain'], $portConflicts);
        $warnings[] = [
            'type' => 'warning',
            'category' => 'port',
            'message' => "Port {$port} is already used by: " . implode(', ', $conflictDomains)
        ];
    }
}

// 4. Check SSL certificate configuration
if (isset($input['ssl_enabled']) && $input['ssl_enabled']) {
    $sslChallenge = $input['ssl_challenge_type'] ?? 'snakeoil';
    
    if ($sslChallenge === 'dns-01') {
        // Check Cloudflare credentials
        $cfToken = $input['cf_api_token'] ?? '';
        $cfZoneId = $input['cf_zone_id'] ?? '';
        
        if (empty($cfToken) && empty(getenv('CLOUDFLARE_API_TOKEN'))) {
            $issues[] = [
                'type' => 'error',
                'category' => 'ssl',
                'message' => 'DNS-01 challenge requires Cloudflare API token (site-specific or global)'
            ];
        }
        
        if (empty($cfZoneId)) {
            $warnings[] = [
                'type' => 'warning',
                'category' => 'ssl',
                'message' => 'No Cloudflare Zone ID specified. Auto-detection will be attempted but may fail.'
            ];
        }
    } elseif ($sslChallenge === 'http-01') {
        // Check if domain is publicly accessible
        $warnings[] = [
            'type' => 'warning',
            'category' => 'ssl',
            'message' => 'HTTP-01 challenge requires domain to be publicly accessible on port 80'
        ];
    }
}

// 5. Check load balancing configuration
if (isset($input['load_balancing_enabled']) && $input['load_balancing_enabled']) {
    $method = $input['load_balancing_method'] ?? '';
    
    if (empty($method)) {
        $issues[] = [
            'type' => 'error',
            'category' => 'load_balancing',
            'message' => 'Load balancing is enabled but no method is specified'
        ];
    }
    
    if (empty($input['backends']) || count($input['backends']) < 2) {
        $warnings[] = [
            'type' => 'warning',
            'category' => 'load_balancing',
            'message' => 'Load balancing is enabled but less than 2 backends configured'
        ];
    }
}

// 6. Check caching configuration
if (isset($input['caching_enabled']) && $input['caching_enabled']) {
    $cacheTtl = $input['cache_ttl'] ?? 0;
    
    if ($cacheTtl <= 0) {
        $warnings[] = [
            'type' => 'warning',
            'category' => 'caching',
            'message' => 'Caching is enabled but TTL is 0 or not set'
        ];
    }
}

// 7. Check wildcard subdomain conflicts
if (isset($input['wildcard_subdomains']) && $input['wildcard_subdomains']) {
    $baseDomain = preg_replace('/^(\*\.|www\.)/', '', $domain);
    
    $stmt = $db->prepare("SELECT id, domain FROM sites WHERE (domain = ? OR domain LIKE ?) AND id != ?");
    $stmt->execute([$baseDomain, "%.{$baseDomain}", $siteId ?? 0]);
    $wildcardConflicts = $stmt->fetchAll();
    
    if (!empty($wildcardConflicts)) {
        $conflicts = array_map(fn($s) => $s['domain'], $wildcardConflicts);
        $warnings[] = [
            'type' => 'warning',
            'category' => 'domain',
            'message' => "Wildcard subdomain may conflict with existing sites: " . implode(', ', $conflicts)
        ];
    }
}

// Return verification results
echo json_encode([
    'valid' => empty($issues),
    'issues' => $issues,
    'warnings' => $warnings,
    'summary' => [
        'total_issues' => count($issues),
        'total_warnings' => count($warnings),
        'can_save' => empty($issues) // Can save if no errors, warnings are acceptable
    ]
]);

function testBackendConnectivity($address, $port, $timeout = 2) {
    // Skip localhost and private IPs for now
    if (in_array($address, ['localhost', '127.0.0.1', '::1']) || 
        preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $address)) {
        return [
            'reachable' => true,
            'message' => 'Local/private address (skipped connectivity test)'
        ];
    }
    
    // Try to establish connection
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($address, $port, $errno, $errstr, $timeout);
    
    if ($socket) {
        fclose($socket);
        return [
            'reachable' => true,
            'message' => 'Backend is reachable'
        ];
    }
    
    return [
        'reachable' => false,
        'message' => "Connection failed: {$errstr} (error {$errno})"
    ];
}
