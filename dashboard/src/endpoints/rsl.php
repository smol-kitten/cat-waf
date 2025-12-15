<?php
/**
 * RSL API Endpoint
 * Handles RSL license management and OLP endpoints
 */

require_once __DIR__ . '/../lib/RSL/RSLDocument.php';
require_once __DIR__ . '/../lib/RSL/RSLLicenseServer.php';
require_once __DIR__ . '/../lib/RSL/RSLMiddleware.php';

use CatWAF\RSL\RSLDocument;
use CatWAF\RSL\RSLLicenseServer;
use CatWAF\RSL\RSLMiddleware;

function handleRSL($method, $params, $db) {
    $action = $params[0] ?? '';
    $subAction = $params[1] ?? null;
    $extraAction = $params[2] ?? null;
    
    // Get server URL from settings or construct it
    $serverUrl = getSetting($db, 'rsl_license_server');
    if (!$serverUrl) {
        $serverUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api/rsl/olp';
    }
    
    $licenseServer = new RSLLicenseServer($db, $serverUrl);
    
    switch ($action) {
        // ==============================================
        // OLP (Open License Protocol) Endpoints
        // ==============================================
        case 'olp':
            handleOLP($method, $subAction, $db, $licenseServer);
            break;
            
        // ==============================================
        // License Management API
        // ==============================================
        case 'licenses':
            handleLicenses($method, $subAction, $extraAction, $db);
            break;
            
        case 'clients':
            handleClients($method, $subAction, $extraAction, $db);
            break;
            
        case 'tokens':
            handleTokens($method, $subAction, $extraAction, $db);
            break;
            
        case 'discovery':
            handleDiscovery($method, $subAction, $db);
            break;
            
        // ==============================================
        // Public Tools
        // ==============================================
        case 'generate':
            handleGenerate($method, $db);
            break;
            
        case 'validate':
            handleValidate($method, $db);
            break;
            
        case 'settings':
            handleSettings($method, $db);
            break;
            
        case 'stats':
            handleRSLStats($method, $db);
            break;
            
        default:
            sendResponse(['error' => 'Unknown RSL action: ' . $action], 404);
    }
}

/**
 * Handle OLP (Open License Protocol) endpoints
 */
function handleOLP($method, $endpoint, $db, $licenseServer) {
    switch ($endpoint) {
        case 'token':
            if ($method !== 'POST') {
                sendResponse(['error' => 'Method not allowed'], 405);
            }
            
            // Parse request body
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            
            // Also accept form-encoded data (OAuth 2.0 standard)
            if (empty($input)) {
                $input = $_POST;
            }
            
            // Extract client credentials from Authorization header
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/^Basic\s+(.+)$/i', $authHeader, $matches)) {
                $decoded = base64_decode($matches[1]);
                list($clientId, $clientSecret) = explode(':', $decoded, 2);
                $input['client_id'] = $clientId;
                $input['client_secret'] = $clientSecret;
            }
            
            $response = $licenseServer->handleTokenRequest($input);
            
            if (isset($response['error'])) {
                sendResponse($response, 400);
            } else {
                sendResponse($response);
            }
            break;
            
        case 'introspect':
            if ($method !== 'POST') {
                sendResponse(['error' => 'Method not allowed'], 405);
            }
            
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $response = $licenseServer->handleIntrospectRequest($input);
            sendResponse($response);
            break;
            
        case 'key':
            if ($method !== 'POST') {
                sendResponse(['error' => 'Method not allowed'], 405);
            }
            
            // Require License authorization
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (!preg_match('/^License\s+(.+)$/i', $authHeader, $matches)) {
                header('WWW-Authenticate: License error="invalid_token"');
                sendResponse(['error' => 'License token required'], 401);
            }
            
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $response = $licenseServer->handleKeyRequest($input, $matches[1]);
            
            if (isset($response['error'])) {
                sendResponse($response, isset($response['error']) && $response['error'] === 'invalid_token' ? 401 : 400);
            } else {
                sendResponse($response);
            }
            break;
            
        case 'register':
            if ($method !== 'POST') {
                sendResponse(['error' => 'Method not allowed'], 405);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                sendResponse(['error' => 'Invalid JSON body'], 400);
            }
            
            $response = $licenseServer->registerClient($input);
            sendResponse($response, 201);
            break;
            
        case '.well-known':
        case 'well-known':
            sendResponse($licenseServer->getServerMetadata());
            break;
            
        default:
            sendResponse(['error' => 'Unknown OLP endpoint'], 404);
    }
}

/**
 * Handle license CRUD
 */
function handleLicenses($method, $id, $extraAction, $db) {
    // Handle sub-actions like /licenses/1/xml
    if ($id && $extraAction === 'xml') {
        return handleLicenseXML($method, $id, $db);
    }
    
    switch ($method) {
        case 'GET':
            if ($id) {
                // Get single license
                $stmt = $db->prepare("SELECT * FROM rsl_licenses WHERE id = ?");
                $stmt->execute([$id]);
                $license = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$license) {
                    sendResponse(['error' => 'License not found'], 404);
                }
                
                // Parse JSON fields
                $license['permits'] = json_decode($license['permits'] ?? '{}', true);
                $license['prohibits'] = json_decode($license['prohibits'] ?? '{}', true);
                
                sendResponse(['success' => true, 'license' => $license]);
            } else {
                // List all licenses
                $siteId = $_GET['site_id'] ?? null;
                
                if ($siteId) {
                    $stmt = $db->prepare("SELECT * FROM rsl_licenses WHERE site_id = ? ORDER BY priority DESC, name");
                    $stmt->execute([$siteId]);
                } else {
                    // MariaDB doesn't support NULLS FIRST, use ISNULL() workaround
                    $stmt = $db->query("SELECT * FROM rsl_licenses ORDER BY ISNULL(site_id) DESC, site_id, priority DESC, name");
                }
                
                $licenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Parse JSON fields
                foreach ($licenses as &$license) {
                    $license['permits'] = json_decode($license['permits'] ?? '{}', true);
                    $license['prohibits'] = json_decode($license['prohibits'] ?? '{}', true);
                }
                
                sendResponse(['success' => true, 'licenses' => $licenses]);
            }
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                sendResponse(['error' => 'Invalid JSON body'], 400);
            }
            
            $stmt = $db->prepare("
                INSERT INTO rsl_licenses (
                    uuid, name, description, content_url_pattern, license_server, encrypted,
                    permits, prohibits, payment_type, payment_amount, payment_currency,
                    payment_standard, payment_custom, payment_accepts,
                    legal_terms, legal_warranty, legal_disclaimer, legal_contact, legal_proof, legal_attestation,
                    copyright_holder, copyright_year, copyright_license,
                    site_id, is_default, enabled, priority
                ) VALUES (
                    UUID(), ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?
                )
            ");
            
            $stmt->execute([
                $input['name'] ?? 'Unnamed License',
                $input['description'] ?? null,
                $input['content_url_pattern'] ?? '*',
                $input['license_server'] ?? null,
                $input['encrypted'] ?? 0,
                json_encode($input['permits'] ?? []),
                json_encode($input['prohibits'] ?? []),
                $input['payment_type'] ?? 'free',
                $input['payment_amount'] ?? 0,
                $input['payment_currency'] ?? 'USD',
                $input['payment_standard'] ?? null,
                $input['payment_custom'] ?? null,
                $input['payment_accepts'] ?? null,
                $input['legal_terms'] ?? null,
                $input['legal_warranty'] ?? null,
                $input['legal_disclaimer'] ?? null,
                $input['legal_contact'] ?? null,
                $input['legal_proof'] ?? null,
                $input['legal_attestation'] ?? null,
                $input['copyright_holder'] ?? null,
                $input['copyright_year'] ?? null,
                $input['copyright_license'] ?? null,
                $input['site_id'] ?? null,
                $input['is_default'] ?? 0,
                $input['enabled'] ?? 1,
                $input['priority'] ?? 0
            ]);
            
            $id = $db->lastInsertId();
            sendResponse(['success' => true, 'id' => $id, 'message' => 'License created'], 201);
            break;
            
        case 'PUT':
        case 'PATCH':
            if (!$id) {
                sendResponse(['error' => 'License ID required'], 400);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                sendResponse(['error' => 'Invalid JSON body'], 400);
            }
            
            // Build dynamic update query
            $fields = [];
            $values = [];
            
            $allowedFields = [
                'name', 'description', 'content_url_pattern', 'license_server', 'encrypted',
                'payment_type', 'payment_amount', 'payment_currency', 'payment_standard', 'payment_custom', 'payment_accepts',
                'legal_terms', 'legal_warranty', 'legal_disclaimer', 'legal_contact', 'legal_proof', 'legal_attestation',
                'copyright_holder', 'copyright_year', 'copyright_license',
                'site_id', 'is_default', 'enabled', 'priority'
            ];
            
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $input)) {
                    $fields[] = "$field = ?";
                    $values[] = $input[$field];
                }
            }
            
            // Handle JSON fields separately
            if (array_key_exists('permits', $input)) {
                $fields[] = "permits = ?";
                $values[] = json_encode($input['permits']);
            }
            if (array_key_exists('prohibits', $input)) {
                $fields[] = "prohibits = ?";
                $values[] = json_encode($input['prohibits']);
            }
            
            if (empty($fields)) {
                sendResponse(['error' => 'No fields to update'], 400);
            }
            
            $values[] = $id;
            $stmt = $db->prepare("UPDATE rsl_licenses SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($values);
            
            sendResponse(['success' => true, 'message' => 'License updated']);
            break;
            
        case 'DELETE':
            if (!$id) {
                sendResponse(['error' => 'License ID required'], 400);
            }
            
            $stmt = $db->prepare("DELETE FROM rsl_licenses WHERE id = ?");
            $stmt->execute([$id]);
            
            sendResponse(['success' => true, 'message' => 'License deleted']);
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Generate RSL XML for a specific license
 */
function handleLicenseXML($method, $id, $db) {
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    // Get the license
    $stmt = $db->prepare("SELECT * FROM rsl_licenses WHERE id = ?");
    $stmt->execute([$id]);
    $license = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$license) {
        sendResponse(['error' => 'License not found'], 404);
    }
    
    // Build RSL document from license data
    $rsl = new RSLDocument();
    
    // Set content URL pattern
    if ($license['content_url_pattern']) {
        $rsl->setContentUrl($license['content_url_pattern']);
    }
    
    // Set license server
    if ($license['license_server']) {
        $rsl->setLicenseServer($license['license_server']);
    }
    
    // Add permits
    $permits = json_decode($license['permits'] ?? '{}', true);
    if (!empty($permits['usage'])) {
        $rsl->addPermit('usage', $permits['usage']);
    }
    if (!empty($permits['user'])) {
        $rsl->addPermit('user', $permits['user']);
    }
    
    // Add prohibits
    $prohibits = json_decode($license['prohibits'] ?? '{}', true);
    if (!empty($prohibits['usage'])) {
        $rsl->addProhibit('usage', $prohibits['usage']);
    }
    if (!empty($prohibits['user'])) {
        $rsl->addProhibit('user', $prohibits['user']);
    }
    
    // Set payment info
    if ($license['payment_type'] && $license['payment_type'] !== 'free') {
        $rsl->setPayment(
            $license['payment_type'],
            $license['payment_amount'] ? (float)$license['payment_amount'] : null,
            $license['payment_currency'] ?? 'USD'
        );
        if ($license['payment_standard']) {
            $rsl->setPaymentStandard($license['payment_standard']);
        }
        if ($license['payment_custom']) {
            $rsl->setPaymentCustom($license['payment_custom']);
        }
        if ($license['payment_accepts']) {
            $accepts = is_string($license['payment_accepts']) 
                ? json_decode($license['payment_accepts'], true) 
                : $license['payment_accepts'];
            if ($accepts) {
                $rsl->setPaymentAccepts($accepts);
            }
        }
    }
    
    // Set legal info
    if ($license['legal_terms']) {
        $rsl->setTerms($license['legal_terms']);
    }
    if ($license['legal_warranty']) {
        $rsl->addLegal('warranty', $license['legal_warranty']);
    }
    if ($license['legal_disclaimer']) {
        $rsl->addLegal('disclaimer', $license['legal_disclaimer']);
    }
    if ($license['legal_contact']) {
        $rsl->addLegal('contact', $license['legal_contact']);
    }
    if ($license['legal_proof']) {
        $rsl->addLegal('proof', $license['legal_proof']);
    }
    if ($license['legal_attestation']) {
        $rsl->addLegal('attestation', $license['legal_attestation']);
    }
    
    // Set copyright info
    if ($license['copyright_holder'] || $license['copyright_year'] || $license['copyright_license']) {
        $rsl->setCopyright(
            $license['copyright_holder'],
            $license['copyright_year'],
            $license['copyright_license']
        );
    }
    
    // Set encrypted flag
    if ($license['encrypted']) {
        $rsl->setEncrypted(true);
    }
    
    // Generate XML
    $xml = $rsl->toXML();
    
    sendResponse([
        'success' => true,
        'xml' => $xml,
        'license_id' => $id,
        'license_name' => $license['name']
    ]);
}

/**
 * Handle client management
 */
function handleClients($method, $id, $extraAction, $db) {
    // Handle sub-actions like /clients/xxx/approve
    if ($id && $extraAction === 'approve') {
        if ($method !== 'POST') {
            sendResponse(['error' => 'Method not allowed'], 405);
        }
        $stmt = $db->prepare("UPDATE rsl_clients SET approved = 1 WHERE client_id = ?");
        $stmt->execute([$id]);
        sendResponse(['success' => true, 'message' => 'Client approved']);
        return;
    }
    
    switch ($method) {
        case 'GET':
            if ($id) {
                $stmt = $db->prepare("SELECT * FROM rsl_clients WHERE id = ?");
                $stmt->execute([$id]);
                $client = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$client) {
                    sendResponse(['error' => 'Client not found'], 404);
                }
                
                // Don't expose secret
                unset($client['client_secret']);
                $client['allowed_scopes'] = json_decode($client['allowed_scopes'] ?? '[]', true);
                $client['allowed_domains'] = json_decode($client['allowed_domains'] ?? '[]', true);
                
                sendResponse(['success' => true, 'client' => $client]);
            } else {
                $stmt = $db->query("SELECT id, client_id, client_name AS name, client_type, description, contact_email, contact_url AS website, enabled, approved, last_used, total_requests, total_tokens_issued, created_at FROM rsl_clients ORDER BY created_at DESC");
                sendResponse(['success' => true, 'clients' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            }
            break;
            
        case 'PUT':
        case 'PATCH':
            if (!$id) {
                sendResponse(['error' => 'Client ID required'], 400);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            $fields = [];
            $values = [];
            
            $allowedFields = ['client_name', 'description', 'contact_email', 'contact_url', 'rate_limit', 'enabled', 'approved', 'auto_approve'];
            
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $input)) {
                    $fields[] = "$field = ?";
                    $values[] = $input[$field];
                }
            }
            
            if (array_key_exists('allowed_scopes', $input)) {
                $fields[] = "allowed_scopes = ?";
                $values[] = json_encode($input['allowed_scopes']);
            }
            if (array_key_exists('allowed_domains', $input)) {
                $fields[] = "allowed_domains = ?";
                $values[] = json_encode($input['allowed_domains']);
            }
            
            if (empty($fields)) {
                sendResponse(['error' => 'No fields to update'], 400);
            }
            
            $values[] = $id;
            $stmt = $db->prepare("UPDATE rsl_clients SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($values);
            
            sendResponse(['success' => true, 'message' => 'Client updated']);
            break;
            
        case 'DELETE':
            if (!$id) {
                sendResponse(['error' => 'Client ID required'], 400);
            }
            
            // Delete by client_id string (not numeric id)
            $stmt = $db->prepare("DELETE FROM rsl_clients WHERE client_id = ?");
            $stmt->execute([$id]);
            
            sendResponse(['success' => true, 'message' => 'Client deleted']);
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Handle token management
 */
function handleTokens($method, $id, $extraAction, $db) {
    // Handle sub-actions like /tokens/1/revoke
    if ($id && $extraAction === 'revoke') {
        if ($method !== 'POST') {
            sendResponse(['error' => 'Method not allowed'], 405);
        }
        $stmt = $db->prepare("UPDATE rsl_tokens SET revoked = 1 WHERE id = ?");
        $stmt->execute([$id]);
        sendResponse(['success' => true, 'message' => 'Token revoked']);
        return;
    }
    
    switch ($method) {
        case 'GET':
            if ($id) {
                $stmt = $db->prepare("
                    SELECT t.*, c.client_name, l.name as license_name
                    FROM rsl_tokens t
                    LEFT JOIN rsl_clients c ON t.client_id = c.id
                    LEFT JOIN rsl_licenses l ON t.license_id = l.id
                    WHERE t.id = ?
                ");
                $stmt->execute([$id]);
                $token = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$token) {
                    sendResponse(['error' => 'Token not found'], 404);
                }
                
                // Don't expose full token
                $token['token'] = substr($token['token'], 0, 8) . '...';
                sendResponse(['success' => true, 'token' => $token]);
            } else {
                $stmt = $db->query("
                    SELECT t.id, t.token, t.client_id, t.license_id, t.scope, t.content_url, t.used_count, t.last_used, t.revoked, t.created_at AS issued_at, t.expires_at,
                           c.client_name, l.name as license_name
                    FROM rsl_tokens t
                    LEFT JOIN rsl_clients c ON t.client_id = c.client_id
                    LEFT JOIN rsl_licenses l ON t.license_id = l.id
                    ORDER BY t.created_at DESC
                    LIMIT 100
                ");
                sendResponse(['success' => true, 'tokens' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            }
            break;
            
        case 'DELETE':
            if (!$id) {
                sendResponse(['error' => 'Token ID required'], 400);
            }
            
            // Actually delete the token
            $stmt = $db->prepare("DELETE FROM rsl_tokens WHERE id = ?");
            $stmt->execute([$id]);
            
            sendResponse(['success' => true, 'message' => 'Token deleted']);
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Handle discovery configuration
 */
function handleDiscovery($method, $siteId, $db) {
    switch ($method) {
        case 'GET':
            $stmt = $db->prepare("SELECT * FROM rsl_discovery WHERE site_id = ? OR (site_id IS NULL AND ? IS NULL)");
            $stmt->execute([$siteId, $siteId]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            sendResponse($config ?: [
                'enable_robots_txt' => true,
                'enable_http_header' => true,
                'enable_html_link' => false,
                'enable_wellknown' => true,
                'rsl_file_path' => '/rsl.xml'
            ]);
            break;
            
        case 'POST':
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            
            $stmt = $db->prepare("
                INSERT INTO rsl_discovery (site_id, enable_robots_txt, enable_http_header, enable_html_link, enable_wellknown, rsl_file_path)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    enable_robots_txt = VALUES(enable_robots_txt),
                    enable_http_header = VALUES(enable_http_header),
                    enable_html_link = VALUES(enable_html_link),
                    enable_wellknown = VALUES(enable_wellknown),
                    rsl_file_path = VALUES(rsl_file_path)
            ");
            
            $stmt->execute([
                $siteId ?: null,
                $input['enable_robots_txt'] ?? 1,
                $input['enable_http_header'] ?? 1,
                $input['enable_html_link'] ?? 0,
                $input['enable_wellknown'] ?? 1,
                $input['rsl_file_path'] ?? '/rsl.xml'
            ]);
            
            sendResponse(['message' => 'Discovery configuration updated']);
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Generate RSL document from parameters (public tool)
 */
function handleGenerate($method, $db) {
    if ($method !== 'POST') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendResponse(['error' => 'Invalid JSON body'], 400);
    }
    
    $rsl = new RSLDocument($input['content_url'] ?? null);
    
    // Set content attributes
    if (!empty($input['license_server'])) {
        $rsl->setLicenseServer($input['license_server']);
    }
    if (!empty($input['encrypted'])) {
        $rsl->setEncrypted(true);
    }
    
    // Add permits
    if (!empty($input['permits'])) {
        foreach ($input['permits'] as $type => $values) {
            $rsl->addPermit($type, (array)$values);
        }
    }
    
    // Add prohibits
    if (!empty($input['prohibits'])) {
        foreach ($input['prohibits'] as $type => $values) {
            $rsl->addProhibit($type, (array)$values);
        }
    }
    
    // Set payment
    if (!empty($input['payment'])) {
        $payment = $input['payment'];
        $rsl->setPayment(
            $payment['type'] ?? 'free',
            $payment['amount'] ?? null,
            $payment['currency'] ?? 'USD'
        );
        
        if (!empty($payment['standard'])) {
            $rsl->setPaymentStandard($payment['standard']);
        }
        if (!empty($payment['custom'])) {
            $rsl->setPaymentCustom($payment['custom']);
        }
        if (!empty($payment['accepts'])) {
            $rsl->setPaymentAccepts((array)$payment['accepts']);
        }
    }
    
    // Set legal references
    if (!empty($input['legal'])) {
        foreach ($input['legal'] as $type => $url) {
            $rsl->addLegal($type, $url);
        }
    }
    
    // Set copyright
    if (!empty($input['copyright'])) {
        $rsl->setCopyright(
            $input['copyright']['holder'] ?? null,
            $input['copyright']['year'] ?? null,
            $input['copyright']['license'] ?? null
        );
    }
    
    // Set terms
    if (!empty($input['terms'])) {
        $rsl->setTerms($input['terms']);
    }
    
    // Generate output
    $format = $_GET['format'] ?? 'xml';
    
    if ($format === 'json') {
        sendResponse($rsl->toArray());
    } else {
        header('Content-Type: application/rsl+xml');
        echo $rsl->toXML();
        exit;
    }
}

/**
 * Validate RSL document (public tool)
 */
function handleValidate($method, $db) {
    if ($method !== 'POST') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'application/xml') !== false || strpos($contentType, 'application/rsl+xml') !== false) {
        $xml = file_get_contents('php://input');
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $xml = $input['xml'] ?? null;
    }
    
    if (!$xml) {
        sendResponse(['error' => 'No XML content provided'], 400);
    }
    
    try {
        $rsl = RSLDocument::fromXML($xml);
        
        sendResponse([
            'valid' => true,
            'parsed' => $rsl->toArray()
        ]);
    } catch (\Exception $e) {
        sendResponse([
            'valid' => false,
            'error' => $e->getMessage()
        ], 400);
    }
}

/**
 * Handle RSL settings
 */
function handleSettings($method, $db) {
    $rslSettings = ['rsl_enabled', 'rsl_default_permit', 'rsl_default_prohibit', 'rsl_license_server'];
    
    switch ($method) {
        case 'GET':
            $settings = [];
            foreach ($rslSettings as $key) {
                $settings[$key] = getSetting($db, $key);
            }
            sendResponse(['success' => true, 'settings' => $settings]);
            break;
            
        case 'PUT':
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            
            foreach ($rslSettings as $key) {
                if (array_key_exists($key, $input)) {
                    $value = is_array($input[$key]) ? json_encode($input[$key]) : $input[$key];
                    setSetting($db, $key, $value);
                }
            }
            
            sendResponse(['success' => true, 'message' => 'Settings updated']);
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Get RSL statistics
 */
function handleRSLStats($method, $db) {
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    $stats = [];
    
    // License counts
    $stmt = $db->query("SELECT COUNT(*) as total, SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) as enabled FROM rsl_licenses");
    $licenseStats = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_licenses'] = (int)$licenseStats['total'];
    $stats['enabled_licenses'] = (int)$licenseStats['enabled'];
    
    // Client counts
    $stmt = $db->query("SELECT COUNT(*) as total, SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) as enabled, SUM(CASE WHEN approved = 1 THEN 1 ELSE 0 END) as approved FROM rsl_clients");
    $clientStats = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_clients'] = (int)$clientStats['total'];
    $stats['approved_clients'] = (int)$clientStats['approved'];
    
    // Token counts
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN revoked = 0 AND (expires_at IS NULL OR expires_at > NOW()) THEN 1 ELSE 0 END) as active,
            SUM(used_count) as total_uses
        FROM rsl_tokens
    ");
    $tokenStats = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['active_tokens'] = (int)$tokenStats['active'];
    $stats['total_tokens'] = (int)$tokenStats['total'];
    $stats['total_token_uses'] = (int)$tokenStats['total_uses'];
    
    // Access log stats (last 24h)
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'allowed' THEN 1 ELSE 0 END) as allowed,
            SUM(CASE WHEN status = 'denied' THEN 1 ELSE 0 END) as denied
        FROM rsl_access_log
        WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $accessStats = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_requests'] = (int)$accessStats['total'];
    $stats['allowed_requests'] = (int)$accessStats['allowed'];
    $stats['denied_requests'] = (int)$accessStats['denied'];
    
    sendResponse(['success' => true, 'stats' => $stats]);
}

/**
 * Helper function to get setting
 */
function getSetting($db, $key) {
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    return $stmt->fetchColumn();
}

/**
 * Helper function to set setting
 */
function setSetting($db, $key, $value) {
    $stmt = $db->prepare("
        INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$key, $value]);
}
