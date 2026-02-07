<?php
/**
 * RSL Tester Endpoint
 * Test RSL implementations on both internal and external sites
 * Supports importing RSL documents from public URLs
 */

require_once __DIR__ . '/../config.php';

function handleRslTester(string $method, array $uri, PDO $db): void {
    $path = '/' . implode('/', $uri);
    
    switch (true) {
        // Test a URL for RSL implementation
        case $method === 'POST' && preg_match('#^/test/?$#', $path):
            testRslImplementation($db);
            break;
            
        // Scan a site for RSL documents
        case $method === 'POST' && preg_match('#^/scan/?$#', $path):
            scanSiteForRsl($db);
            break;
            
        // Import RSL document from external URL
        case $method === 'POST' && preg_match('#^/import/?$#', $path):
            importRslDocument($db);
            break;
            
        // Compare two RSL implementations
        case $method === 'POST' && preg_match('#^/compare/?$#', $path):
            compareRslImplementations($db);
            break;
            
        // Get test history
        case $method === 'GET' && preg_match('#^/history/?$#', $path):
            getRslTestHistory($db);
            break;
            
        // Get known RSL servers (registry)
        case $method === 'GET' && preg_match('#^/registry/?$#', $path):
            getRslRegistry($db);
            break;
            
        // Add to registry
        case $method === 'POST' && preg_match('#^/registry/?$#', $path):
            addToRslRegistry($db);
            break;
            
        // Validate RSL document
        case $method === 'POST' && preg_match('#^/validate/?$#', $path):
            validateRslDocument($db);
            break;
            
        // Generate sample RSL document
        case $method === 'GET' && preg_match('#^/sample/(\w+)/?$#', $path, $m):
            generateSampleRsl($m[1]);
            break;
            
        default:
            sendResponse(['error' => 'Not found'], 404);
    }
}

/**
 * Test RSL implementation on a URL
 */
function testRslImplementation(PDO $db): void {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $url = $data['url'] ?? null;
    $testType = $data['type'] ?? 'full'; // full, document, license-server, both
    
    if (!$url) {
        sendResponse(['error' => 'URL is required'], 400);
    }
    
    $results = [
        'url' => $url,
        'timestamp' => date('c'),
        'tests' => [],
        'overall_score' => 0,
        'status' => 'unknown'
    ];
    
    // Parse base URL
    $parsed = parse_url($url);
    $baseUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
    
    // Test 1: Check for robots.txt RSL directives
    if (in_array($testType, ['full', 'document'])) {
        $results['tests']['robots_txt'] = testRobotsTxtRsl($baseUrl);
    }
    
    // Test 2: Check for .well-known/rsl.json
    if (in_array($testType, ['full', 'document'])) {
        $results['tests']['well_known'] = testWellKnownRsl($baseUrl);
    }
    
    // Test 3: Check for RSL meta tags / link headers
    if (in_array($testType, ['full', 'document'])) {
        $results['tests']['html_integration'] = testHtmlRslIntegration($url);
    }
    
    // Test 4: Test license server endpoints
    if (in_array($testType, ['full', 'license-server', 'both'])) {
        $rslDoc = $results['tests']['well_known']['document'] ?? null;
        if ($rslDoc && isset($rslDoc['license_server'])) {
            $results['tests']['license_server'] = testLicenseServer($rslDoc['license_server']);
        }
    }
    
    // Test 5: Validate RSL document structure
    if (in_array($testType, ['full', 'document'])) {
        $rslDoc = $results['tests']['well_known']['document'] ?? null;
        if ($rslDoc) {
            $results['tests']['document_validation'] = validateRslDocumentStructure($rslDoc);
        }
    }
    
    // Calculate overall score
    $scores = [];
    foreach ($results['tests'] as $test) {
        if (isset($test['score'])) {
            $scores[] = $test['score'];
        }
    }
    $results['overall_score'] = count($scores) > 0 ? round(array_sum($scores) / count($scores)) : 0;
    $results['status'] = $results['overall_score'] >= 70 ? 'pass' : ($results['overall_score'] >= 40 ? 'partial' : 'fail');
    
    // Save to history
    saveTestHistory($db, $url, $results);
    
    sendResponse($results);
}

/**
 * Test robots.txt for RSL directives
 */
function testRobotsTxtRsl(string $baseUrl): array {
    $result = [
        'name' => 'robots.txt RSL Directives',
        'status' => 'not_found',
        'score' => 0,
        'details' => []
    ];
    
    $robotsTxt = fetchUrl("{$baseUrl}/robots.txt");
    if (!$robotsTxt['success']) {
        $result['details'][] = "Could not fetch robots.txt: " . ($robotsTxt['error'] ?? 'Unknown error');
        return $result;
    }
    
    $content = $robotsTxt['content'];
    $result['details'][] = "robots.txt fetched successfully";
    
    // Check for RSL-related directives
    $hasRslRef = false;
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        $line = trim($line);
        if (stripos($line, 'rsl:') === 0 || stripos($line, 'rsl-document:') === 0) {
            $hasRslRef = true;
            $result['details'][] = "Found RSL directive: {$line}";
        }
        if (preg_match('/Sitemap:\s*(.+\.rsl\.xml)/i', $line, $m)) {
            $hasRslRef = true;
            $result['details'][] = "Found RSL sitemap reference: {$m[1]}";
        }
    }
    
    if ($hasRslRef) {
        $result['status'] = 'found';
        $result['score'] = 100;
    } else {
        $result['details'][] = "No RSL directives found in robots.txt (optional)";
        $result['status'] = 'optional_missing';
        $result['score'] = 50; // Not critical
    }
    
    return $result;
}

/**
 * Test .well-known/rsl.json
 */
function testWellKnownRsl(string $baseUrl): array {
    $result = [
        'name' => '.well-known/rsl.json',
        'status' => 'not_found',
        'score' => 0,
        'details' => [],
        'document' => null
    ];
    
    $rslJson = fetchUrl("{$baseUrl}/.well-known/rsl.json");
    if (!$rslJson['success']) {
        $result['details'][] = "Could not fetch .well-known/rsl.json: " . ($rslJson['error'] ?? 'Unknown error');
        return $result;
    }
    
    $document = json_decode($rslJson['content'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $result['details'][] = "Invalid JSON: " . json_last_error_msg();
        $result['status'] = 'invalid';
        return $result;
    }
    
    $result['document'] = $document;
    $result['status'] = 'found';
    $result['score'] = 100;
    $result['details'][] = "Valid RSL document found";
    
    // Check version
    if (isset($document['version'])) {
        $result['details'][] = "RSL version: {$document['version']}";
    }
    
    // Check for required fields
    $requiredFields = ['content_owner', 'permissions'];
    foreach ($requiredFields as $field) {
        if (!isset($document[$field])) {
            $result['details'][] = "Missing recommended field: {$field}";
            $result['score'] -= 10;
        }
    }
    
    return $result;
}

/**
 * Test HTML page for RSL integration
 */
function testHtmlRslIntegration(string $url): array {
    $result = [
        'name' => 'HTML RSL Integration',
        'status' => 'not_found',
        'score' => 0,
        'details' => [],
        'meta_tags' => [],
        'link_headers' => []
    ];
    
    $response = fetchUrl($url, true);
    if (!$response['success']) {
        $result['details'][] = "Could not fetch page: " . ($response['error'] ?? 'Unknown error');
        return $result;
    }
    
    $content = $response['content'];
    $headers = $response['headers'] ?? [];
    
    // Check HTTP Link headers for RSL
    foreach ($headers as $header) {
        if (stripos($header, 'link:') === 0 && stripos($header, 'rsl') !== false) {
            $result['link_headers'][] = trim(substr($header, 5));
            $result['details'][] = "Found RSL Link header";
        }
    }
    
    // Check meta tags
    if (preg_match_all('/<meta[^>]*name=["\']rsl[^"\']*["\'][^>]*>/i', $content, $matches)) {
        $result['meta_tags'] = $matches[0];
        $result['details'][] = "Found " . count($matches[0]) . " RSL meta tag(s)";
    }
    
    // Check link elements
    if (preg_match('/<link[^>]*rel=["\']rsl["\'][^>]*>/i', $content, $match)) {
        $result['details'][] = "Found RSL link element";
        $result['link_element'] = $match[0];
    }
    
    // Calculate score
    $foundItems = count($result['meta_tags']) + count($result['link_headers']);
    if (isset($result['link_element'])) $foundItems++;
    
    if ($foundItems > 0) {
        $result['status'] = 'found';
        $result['score'] = min(100, $foundItems * 30);
    } else {
        $result['status'] = 'optional_missing';
        $result['score'] = 50; // HTML integration is optional
        $result['details'][] = "No RSL HTML integration found (optional)";
    }
    
    return $result;
}

/**
 * Test license server endpoints
 */
function testLicenseServer(array $serverConfig): array {
    $result = [
        'name' => 'License Server',
        'status' => 'unknown',
        'score' => 0,
        'details' => [],
        'endpoints' => []
    ];
    
    $baseUrl = rtrim($serverConfig['url'] ?? '', '/');
    if (!$baseUrl) {
        $result['details'][] = "No license server URL configured";
        return $result;
    }
    
    // Test discovery endpoint
    $discovery = fetchUrl("{$baseUrl}/.well-known/openid-configuration");
    if ($discovery['success']) {
        $config = json_decode($discovery['content'], true);
        if ($config) {
            $result['endpoints']['discovery'] = $config;
            $result['details'][] = "OpenID Discovery endpoint found";
            $result['score'] += 30;
        }
    } else {
        // Try OAuth metadata
        $oauth = fetchUrl("{$baseUrl}/.well-known/oauth-authorization-server");
        if ($oauth['success']) {
            $config = json_decode($oauth['content'], true);
            if ($config) {
                $result['endpoints']['oauth_metadata'] = $config;
                $result['details'][] = "OAuth metadata endpoint found";
                $result['score'] += 30;
            }
        }
    }
    
    // Test token endpoint (dry run - just check if it responds)
    $tokenEndpoint = $result['endpoints']['discovery']['token_endpoint'] 
        ?? $result['endpoints']['oauth_metadata']['token_endpoint']
        ?? "{$baseUrl}/token";
    
    $tokenTest = fetchUrl($tokenEndpoint, false, 'POST', ['grant_type' => 'client_credentials'], false);
    if ($tokenTest['http_code'] !== 0) {
        $result['details'][] = "Token endpoint responds (HTTP {$tokenTest['http_code']})";
        $result['endpoints']['token'] = $tokenEndpoint;
        $result['score'] += 30;
    }
    
    // Test registration endpoint if available
    $regEndpoint = $result['endpoints']['discovery']['registration_endpoint']
        ?? $result['endpoints']['oauth_metadata']['registration_endpoint']
        ?? "{$baseUrl}/register";
    
    $regTest = fetchUrl($regEndpoint, false, 'OPTIONS');
    if ($regTest['http_code'] !== 0 && $regTest['http_code'] !== 404) {
        $result['details'][] = "Registration endpoint available";
        $result['endpoints']['registration'] = $regEndpoint;
        $result['score'] += 20;
    }
    
    // Determine status
    if ($result['score'] >= 60) {
        $result['status'] = 'operational';
    } elseif ($result['score'] >= 30) {
        $result['status'] = 'partial';
    } else {
        $result['status'] = 'not_found';
    }
    
    return $result;
}

/**
 * Validate RSL document structure
 */
function validateRslDocumentStructure(array $document): array {
    $result = [
        'name' => 'Document Validation',
        'status' => 'invalid',
        'score' => 0,
        'details' => [],
        'errors' => [],
        'warnings' => []
    ];
    
    // Required fields per RSL spec
    $requiredFields = ['version'];
    $recommendedFields = ['content_owner', 'permissions', 'license_server'];
    
    foreach ($requiredFields as $field) {
        if (!isset($document[$field])) {
            $result['errors'][] = "Missing required field: {$field}";
        } else {
            $result['score'] += 20;
        }
    }
    
    foreach ($recommendedFields as $field) {
        if (!isset($document[$field])) {
            $result['warnings'][] = "Missing recommended field: {$field}";
        } else {
            $result['score'] += 15;
        }
    }
    
    // Validate version format
    if (isset($document['version'])) {
        if (!preg_match('/^\d+\.\d+(\.\d+)?$/', $document['version'])) {
            $result['warnings'][] = "Version should be in semver format";
            $result['score'] -= 5;
        }
    }
    
    // Validate permissions structure
    if (isset($document['permissions'])) {
        $perms = $document['permissions'];
        $validPermTypes = ['allow', 'deny', 'require_license', 'conditional'];
        
        if (isset($perms['default']) && !in_array($perms['default'], $validPermTypes)) {
            $result['warnings'][] = "Unknown default permission type: {$perms['default']}";
        }
        
        if (isset($perms['ai_training'])) {
            $result['details'][] = "AI training permissions specified";
        }
        if (isset($perms['scraping'])) {
            $result['details'][] = "Scraping permissions specified";
        }
    }
    
    // Validate license server config
    if (isset($document['license_server'])) {
        $ls = $document['license_server'];
        if (!isset($ls['url'])) {
            $result['errors'][] = "License server missing URL";
            $result['score'] -= 10;
        }
    }
    
    // Calculate final status
    $result['score'] = max(0, min(100, $result['score']));
    if (count($result['errors']) === 0) {
        $result['status'] = count($result['warnings']) === 0 ? 'valid' : 'valid_with_warnings';
    } else {
        $result['status'] = 'invalid';
    }
    
    return $result;
}

/**
 * Scan a site for all RSL documents
 */
function scanSiteForRsl(PDO $db): void {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $url = $data['url'] ?? null;
    $depth = min($data['depth'] ?? 2, 5); // Max 5 levels
    
    if (!$url) {
        sendResponse(['error' => 'URL is required'], 400);
    }
    
    $parsed = parse_url($url);
    $baseUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
    
    $results = [
        'base_url' => $baseUrl,
        'scanned_at' => date('c'),
        'documents_found' => [],
        'pages_scanned' => 0
    ];
    
    // Check common RSL locations
    $commonLocations = [
        '/.well-known/rsl.json',
        '/rsl.json',
        '/.well-known/ai.txt',
        '/ai.txt',
        '/robots.txt',
        '/.well-known/security.txt'
    ];
    
    foreach ($commonLocations as $location) {
        $checkUrl = $baseUrl . $location;
        $response = fetchUrl($checkUrl);
        
        if ($response['success']) {
            $docType = determineDocumentType($location, $response['content']);
            if ($docType !== 'unknown') {
                $results['documents_found'][] = [
                    'url' => $checkUrl,
                    'type' => $docType,
                    'content' => $response['content']
                ];
            }
        }
        $results['pages_scanned']++;
    }
    
    sendResponse($results);
}

/**
 * Import RSL document from external URL
 */
function importRslDocument(PDO $db): void {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $url = $data['url'] ?? null;
    $targetDomain = $data['target_domain'] ?? null; // Optional: apply to specific domain
    
    if (!$url) {
        sendResponse(['error' => 'URL is required'], 400);
    }
    
    $response = fetchUrl($url);
    if (!$response['success']) {
        sendResponse(['error' => 'Failed to fetch document', 'details' => $response['error']], 400);
    }
    
    $document = json_decode($response['content'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendResponse(['error' => 'Invalid JSON document', 'details' => json_last_error_msg()], 400);
    }
    
    // Validate structure
    $validation = validateRslDocumentStructure($document);
    if ($validation['status'] === 'invalid') {
        sendResponse(['error' => 'Document validation failed', 'validation' => $validation], 400);
    }
    
    // Store imported document
    $stmt = $db->prepare("
        INSERT INTO rsl_imported_documents (
            source_url, document_json, imported_at, target_domain, validation_status
        ) VALUES (?, ?, NOW(), ?, ?)
        ON DUPLICATE KEY UPDATE 
            document_json = VALUES(document_json),
            imported_at = NOW(),
            validation_status = VALUES(validation_status)
    ");
    
    try {
        $stmt->execute([
            $url,
            json_encode($document),
            $targetDomain,
            $validation['status']
        ]);
        
        sendResponse([
            'success' => true,
            'message' => 'Document imported successfully',
            'validation' => $validation,
            'document' => $document,
            'source_url' => $url
        ]);
    } catch (PDOException $e) {
        // Table might not exist, create it
        $db->exec("
            CREATE TABLE IF NOT EXISTS rsl_imported_documents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source_url VARCHAR(500) UNIQUE,
                document_json LONGTEXT,
                imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                target_domain VARCHAR(255),
                validation_status VARCHAR(50),
                INDEX idx_source (source_url),
                INDEX idx_domain (target_domain)
            )
        ");
        
        $stmt->execute([
            $url,
            json_encode($document),
            $targetDomain,
            $validation['status']
        ]);
        
        sendResponse([
            'success' => true,
            'message' => 'Document imported successfully',
            'validation' => $validation,
            'document' => $document,
            'source_url' => $url
        ]);
    }
}

/**
 * Compare two RSL implementations
 */
function compareRslImplementations(PDO $db): void {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $url1 = $data['url1'] ?? null;
    $url2 = $data['url2'] ?? null;
    
    if (!$url1 || !$url2) {
        sendResponse(['error' => 'Both url1 and url2 are required'], 400);
    }
    
    // Fetch both documents
    $doc1 = fetchRslDocument($url1);
    $doc2 = fetchRslDocument($url2);
    
    $comparison = [
        'url1' => $url1,
        'url2' => $url2,
        'compared_at' => date('c'),
        'differences' => [],
        'similarities' => [],
        'compatibility_score' => 100
    ];
    
    if (!$doc1['document'] || !$doc2['document']) {
        $comparison['error'] = 'Could not fetch one or both documents';
        $comparison['compatibility_score'] = 0;
        sendResponse($comparison);
        return;
    }
    
    $d1 = $doc1['document'];
    $d2 = $doc2['document'];
    
    // Compare versions
    if (($d1['version'] ?? '') !== ($d2['version'] ?? '')) {
        $comparison['differences'][] = [
            'field' => 'version',
            'url1' => $d1['version'] ?? 'not set',
            'url2' => $d2['version'] ?? 'not set'
        ];
    } else {
        $comparison['similarities'][] = 'version';
    }
    
    // Compare permissions
    $p1 = $d1['permissions'] ?? [];
    $p2 = $d2['permissions'] ?? [];
    
    $allPermKeys = array_unique(array_merge(array_keys($p1), array_keys($p2)));
    foreach ($allPermKeys as $key) {
        $v1 = $p1[$key] ?? 'not set';
        $v2 = $p2[$key] ?? 'not set';
        
        if ($v1 !== $v2) {
            $comparison['differences'][] = [
                'field' => "permissions.{$key}",
                'url1' => $v1,
                'url2' => $v2
            ];
            $comparison['compatibility_score'] -= 10;
        } else {
            $comparison['similarities'][] = "permissions.{$key}";
        }
    }
    
    // Compare license server
    $ls1 = $d1['license_server'] ?? [];
    $ls2 = $d2['license_server'] ?? [];
    
    if (($ls1['url'] ?? '') !== ($ls2['url'] ?? '')) {
        $comparison['differences'][] = [
            'field' => 'license_server.url',
            'url1' => $ls1['url'] ?? 'not set',
            'url2' => $ls2['url'] ?? 'not set'
        ];
    }
    
    $comparison['compatibility_score'] = max(0, $comparison['compatibility_score']);
    
    sendResponse($comparison);
}

/**
 * Get test history
 */
function getRslTestHistory(PDO $db): void {
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);
    
    try {
        $stmt = $db->prepare("
            SELECT * FROM rsl_test_history 
            ORDER BY tested_at DESC 
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($history as &$item) {
            $item['results'] = json_decode($item['results_json'] ?? '{}', true);
            unset($item['results_json']);
        }
        
        sendResponse(['history' => $history, 'limit' => $limit, 'offset' => $offset]);
    } catch (PDOException $e) {
        sendResponse(['history' => [], 'message' => 'No test history available']);
    }
}

/**
 * Get RSL registry (known implementations)
 */
function getRslRegistry(PDO $db): void {
    try {
        $stmt = $db->query("SELECT * FROM rsl_registry ORDER BY name");
        $registry = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendResponse(['registry' => $registry]);
    } catch (PDOException $e) {
        // Return some well-known RSL implementations as defaults
        sendResponse(['registry' => [
            ['name' => 'OpenAI', 'url' => 'https://openai.com', 'status' => 'reference'],
            ['name' => 'Anthropic', 'url' => 'https://anthropic.com', 'status' => 'reference'],
            ['name' => 'Google AI', 'url' => 'https://ai.google', 'status' => 'reference'],
            ['name' => 'RSL Spec', 'url' => 'https://rsl-spec.org', 'status' => 'specification']
        ]]);
    }
}

/**
 * Add to RSL registry
 */
function addToRslRegistry(PDO $db): void {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = $data['name'] ?? null;
    $url = $data['url'] ?? null;
    $description = $data['description'] ?? '';
    
    if (!$name || !$url) {
        sendResponse(['error' => 'Name and URL are required'], 400);
    }
    
    try {
        // Create table if not exists
        $db->exec("
            CREATE TABLE IF NOT EXISTS rsl_registry (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                url VARCHAR(500) NOT NULL,
                description TEXT,
                status VARCHAR(50) DEFAULT 'user_added',
                last_checked TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_url (url)
            )
        ");
        
        $stmt = $db->prepare("
            INSERT INTO rsl_registry (name, url, description) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)
        ");
        $stmt->execute([$name, $url, $description]);
        
        sendResponse(['success' => true, 'message' => 'Added to registry']);
    } catch (PDOException $e) {
        sendResponse(['error' => 'Failed to add to registry', 'details' => $e->getMessage()], 500);
    }
}

/**
 * Validate RSL document (direct input)
 */
function validateRslDocument(PDO $db): void {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $document = $data['document'] ?? $data;
    
    if (empty($document)) {
        sendResponse(['error' => 'Document is required'], 400);
    }
    
    // If it's a string (raw JSON), try to parse it
    if (is_string($document)) {
        $document = json_decode($document, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            sendResponse([
                'status' => 'invalid',
                'error' => 'Invalid JSON',
                'details' => json_last_error_msg()
            ], 400);
        }
    }
    
    $validation = validateRslDocumentStructure($document);
    sendResponse($validation);
}

/**
 * Generate sample RSL document
 */
function generateSampleRsl(string $type): void {
    $samples = [
        'basic' => [
            'version' => '1.0',
            'content_owner' => [
                'name' => 'Example Organization',
                'contact' => 'legal@example.com'
            ],
            'permissions' => [
                'default' => 'allow',
                'ai_training' => 'require_license',
                'scraping' => 'deny'
            ]
        ],
        'strict' => [
            'version' => '1.0',
            'content_owner' => [
                'name' => 'Strict Corp',
                'contact' => 'dmca@strict.com',
                'legal_entity' => 'Strict Corporation LLC'
            ],
            'permissions' => [
                'default' => 'deny',
                'ai_training' => 'deny',
                'scraping' => 'deny',
                'caching' => 'allow',
                'indexing' => 'allow'
            ],
            'enforcement' => [
                'dmca_agent' => 'dmca@strict.com',
                'takedown_url' => 'https://strict.com/takedown'
            ]
        ],
        'licensed' => [
            'version' => '1.0',
            'content_owner' => [
                'name' => 'Licensed Content Inc',
                'contact' => 'licensing@example.com'
            ],
            'permissions' => [
                'default' => 'require_license',
                'ai_training' => 'require_license',
                'scraping' => 'require_license',
                'academic_research' => 'allow'
            ],
            'license_server' => [
                'url' => 'https://license.example.com',
                'protocol' => 'olp',
                'version' => '1.0',
                'registration_required' => true,
                'pricing_url' => 'https://example.com/pricing'
            ],
            'scopes' => [
                'ai:training' => 'AI model training',
                'ai:inference' => 'AI inference/RAG',
                'data:export' => 'Bulk data export'
            ]
        ],
        'conditional' => [
            'version' => '1.0',
            'content_owner' => [
                'name' => 'Conditional Access Site'
            ],
            'permissions' => [
                'default' => 'conditional',
                'ai_training' => [
                    'type' => 'conditional',
                    'condition' => 'attribution_required',
                    'attribution_format' => 'Source: {content_owner.name}'
                ],
                'scraping' => [
                    'type' => 'conditional',
                    'rate_limit' => '100/hour',
                    'require_user_agent' => true
                ]
            ]
        ]
    ];
    
    if (!isset($samples[$type])) {
        sendResponse(['error' => 'Unknown sample type', 'available' => array_keys($samples)], 400);
    }
    
    sendResponse([
        'type' => $type,
        'document' => $samples[$type],
        'description' => "Sample {$type} RSL document"
    ]);
}

// ============ Helper Functions ============

function fetchUrl(string $url, bool $includeHeaders = false, string $method = 'GET', array $postData = [], bool $expectJson = true): array {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'CatWAF RSL Tester/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADER => $includeHeaders
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    } elseif ($method === 'OPTIONS') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'OPTIONS');
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    $result = [
        'success' => $httpCode >= 200 && $httpCode < 400,
        'http_code' => $httpCode,
        'error' => $error ?: null
    ];
    
    if ($includeHeaders && $response) {
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE) ?: strpos($response, "\r\n\r\n");
        $result['headers'] = explode("\r\n", substr($response, 0, $headerSize));
        $result['content'] = substr($response, $headerSize);
    } else {
        $result['content'] = $response;
    }
    
    return $result;
}

function fetchRslDocument(string $url): array {
    $parsed = parse_url($url);
    $baseUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
    
    // Try .well-known first
    $response = fetchUrl("{$baseUrl}/.well-known/rsl.json");
    if ($response['success']) {
        $doc = json_decode($response['content'], true);
        if ($doc) {
            return ['success' => true, 'document' => $doc, 'source' => 'well-known'];
        }
    }
    
    // Try root rsl.json
    $response = fetchUrl("{$baseUrl}/rsl.json");
    if ($response['success']) {
        $doc = json_decode($response['content'], true);
        if ($doc) {
            return ['success' => true, 'document' => $doc, 'source' => 'root'];
        }
    }
    
    return ['success' => false, 'document' => null, 'error' => 'No RSL document found'];
}

function determineDocumentType(string $path, string $content): string {
    if (strpos($path, 'rsl.json') !== false) {
        $json = json_decode($content, true);
        if ($json && isset($json['version'])) {
            return 'rsl';
        }
    }
    if (strpos($path, 'ai.txt') !== false) {
        return 'ai_txt';
    }
    if (strpos($path, 'robots.txt') !== false) {
        if (stripos($content, 'rsl') !== false) {
            return 'robots_with_rsl';
        }
    }
    return 'unknown';
}

function saveTestHistory(PDO $db, string $url, array $results): void {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS rsl_test_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                url VARCHAR(500),
                results_json LONGTEXT,
                overall_score INT,
                status VARCHAR(50),
                tested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_url (url),
                INDEX idx_tested (tested_at)
            )
        ");
        
        $stmt = $db->prepare("
            INSERT INTO rsl_test_history (url, results_json, overall_score, status)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $url,
            json_encode($results),
            $results['overall_score'],
            $results['status']
        ]);
    } catch (PDOException $e) {
        error_log("Failed to save test history: " . $e->getMessage());
    }
}
