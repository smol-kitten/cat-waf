<?php
/**
 * Cloudflare Origin Certificates API Endpoint
 * Manages fallback certificates for domains
 */

require_once __DIR__ . '/../lib/CloudflareOriginManager.php';

function handleCfOriginRequest(string $method, array $path, PDO $pdo): void
{
    $manager = new CloudflareOriginManager($pdo);
    $action = $path[1] ?? 'list';

    switch ($action) {
        case 'domains':
            handleDomainsWithCerts($method, $path, $pdo, $manager);
            break;
        case 'upload':
            handleUpload($method, $pdo, $manager);
            break;
        case 'certificate':
            handleCertificate($method, $path, $pdo, $manager);
            break;
        case 'fallback':
            handleFallback($method, $path, $pdo, $manager);
            break;
        case 'check':
            handleCheck($method, $path, $pdo, $manager);
            break;
        case 'history':
            handleHistory($method, $path, $pdo, $manager);
            break;
        case 'settings':
            handleSettings($method, $pdo, $manager);
            break;
        default:
            sendResponse(['error' => 'Unknown action'], 404);
    }
}

/**
 * List domains with their CF origin cert status
 */
function handleDomainsWithCerts(string $method, array $path, PDO $pdo, CloudflareOriginManager $manager): void
{
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $domainId = $path[2] ?? null;

    if ($domainId) {
        // Get specific domain's certificates
        $certs = $manager->listCertificates((int)$domainId);
        $isUsingFallback = $manager->isUsingFallback((int)$domainId);
        $activeCert = $manager->getCertificateInfo((int)$domainId);

        sendResponse([
            'certificates' => $certs,
            'is_using_fallback' => $isUsingFallback,
            'active_certificate' => $activeCert
        ]);
        return;
    }

    // List all domains with their CF origin cert status
    $stmt = $pdo->query("
        SELECT 
            d.id,
            d.domain,
            d.ssl_enabled,
            (SELECT COUNT(*) FROM cf_origin_certificates WHERE domain_id = d.id AND is_active = 1) as has_cf_cert,
            (SELECT expires_at FROM cf_origin_certificates WHERE domain_id = d.id AND is_active = 1 ORDER BY expires_at DESC LIMIT 1) as cf_cert_expires,
            (SELECT COUNT(*) FROM cert_fallback_log WHERE domain_id = d.id AND fallback_ended IS NULL) as using_fallback
        FROM domains d
        WHERE d.ssl_enabled = 1
        ORDER BY d.domain
    ");

    sendResponse(['domains' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

/**
 * Upload a new CF origin certificate
 */
function handleUpload(string $method, PDO $pdo, CloudflareOriginManager $manager): void
{
    if ($method !== 'POST') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    $domainId = $input['domain_id'] ?? null;
    $certificate = $input['certificate'] ?? null;
    $privateKey = $input['private_key'] ?? null;
    $notes = $input['notes'] ?? null;

    if (!$domainId || !$certificate || !$privateKey) {
        sendResponse(['error' => 'domain_id, certificate, and private_key are required'], 400);
        return;
    }

    // Verify domain exists
    $stmt = $pdo->prepare("SELECT id FROM domains WHERE id = ?");
    $stmt->execute([$domainId]);
    if (!$stmt->fetch()) {
        sendResponse(['error' => 'Domain not found'], 404);
        return;
    }

    $result = $manager->uploadCertificate((int)$domainId, $certificate, $privateKey, $notes);
    
    if ($result['success']) {
        sendResponse($result, 201);
    } else {
        sendResponse($result, 400);
    }
}

/**
 * Manage specific certificate
 */
function handleCertificate(string $method, array $path, PDO $pdo, CloudflareOriginManager $manager): void
{
    $certId = $path[2] ?? null;

    if (!$certId) {
        sendResponse(['error' => 'Certificate ID required'], 400);
        return;
    }

    switch ($method) {
        case 'GET':
            // Get certificate details
            $stmt = $pdo->prepare("
                SELECT c.*, d.domain 
                FROM cf_origin_certificates c
                JOIN domains d ON c.domain_id = d.id
                WHERE c.id = ?
            ");
            $stmt->execute([$certId]);
            $cert = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cert) {
                sendResponse(['error' => 'Certificate not found'], 404);
                return;
            }

            // Parse for additional info
            $certInfo = openssl_x509_parse($cert['certificate']);
            unset($cert['certificate'], $cert['private_key_encrypted']);
            
            $cert['subject'] = $certInfo['subject']['CN'] ?? 'Unknown';
            $cert['issuer'] = $certInfo['issuer']['CN'] ?? 'Unknown';
            $cert['san'] = $certInfo['extensions']['subjectAltName'] ?? null;
            $cert['days_until_expiry'] = (int)((strtotime($cert['expires_at']) - time()) / 86400);

            sendResponse(['certificate' => $cert]);
            break;

        case 'DELETE':
            if ($manager->deleteCertificate((int)$certId)) {
                sendResponse(['success' => true]);
            } else {
                sendResponse(['error' => 'Failed to delete certificate'], 500);
            }
            break;

        case 'PUT':
            // Activate/deactivate certificate
            $input = json_decode(file_get_contents('php://input'), true);
            $isActive = $input['is_active'] ?? null;

            if ($isActive !== null) {
                // Get domain_id first
                $stmt = $pdo->prepare("SELECT domain_id FROM cf_origin_certificates WHERE id = ?");
                $stmt->execute([$certId]);
                $domainId = $stmt->fetchColumn();

                if ($isActive) {
                    // Deactivate others first
                    $stmt = $pdo->prepare("UPDATE cf_origin_certificates SET is_active = 0 WHERE domain_id = ?");
                    $stmt->execute([$domainId]);
                }

                $stmt = $pdo->prepare("UPDATE cf_origin_certificates SET is_active = ? WHERE id = ?");
                $stmt->execute([$isActive ? 1 : 0, $certId]);
                sendResponse(['success' => true]);
            } else {
                sendResponse(['error' => 'No updates provided'], 400);
            }
            break;

        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Manage fallback state
 */
function handleFallback(string $method, array $path, PDO $pdo, CloudflareOriginManager $manager): void
{
    $domainId = $path[2] ?? null;

    if (!$domainId) {
        sendResponse(['error' => 'Domain ID required'], 400);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    switch ($method) {
        case 'GET':
            // Check fallback status
            sendResponse([
                'is_using_fallback' => $manager->isUsingFallback((int)$domainId),
                'certificate' => $manager->getCertificateInfo((int)$domainId)
            ]);
            break;

        case 'POST':
            // Activate fallback
            $reason = $input['reason'] ?? 'Manual activation';
            $result = $manager->activateFallback((int)$domainId, $reason);
            sendResponse($result, $result['success'] ? 200 : 400);
            break;

        case 'DELETE':
            // Deactivate fallback (restore primary)
            $result = $manager->deactivateFallback((int)$domainId);
            sendResponse($result);
            break;

        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Check certificate health and auto-fallback
 */
function handleCheck(string $method, array $path, PDO $pdo, CloudflareOriginManager $manager): void
{
    if ($method !== 'POST') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $domainId = $path[2] ?? null;

    if ($domainId) {
        // Check specific domain
        $result = $manager->checkAndFallback((int)$domainId);
        sendResponse($result);
        return;
    }

    // Check all domains
    $stmt = $pdo->query("SELECT id FROM domains WHERE ssl_enabled = 1");
    $results = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $results[$row['id']] = $manager->checkAndFallback((int)$row['id']);
    }

    sendResponse(['results' => $results]);
}

/**
 * Get fallback history
 */
function handleHistory(string $method, array $path, PDO $pdo, CloudflareOriginManager $manager): void
{
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $domainId = $path[2] ?? null;
    $limit = (int)($_GET['limit'] ?? 50);

    if ($domainId) {
        $history = $manager->getFallbackHistory((int)$domainId, $limit);
        sendResponse(['history' => $history]);
        return;
    }

    // Get history for all domains
    $stmt = $pdo->prepare("
        SELECT f.*, d.domain 
        FROM cert_fallback_log f
        JOIN domains d ON f.domain_id = d.id
        ORDER BY f.fallback_started DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    sendResponse(['history' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

/**
 * Manage settings
 */
function handleSettings(string $method, PDO $pdo, CloudflareOriginManager $manager): void
{
    switch ($method) {
        case 'GET':
            sendResponse(['settings' => $manager->getSettings()]);
            break;

        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            $updated = $manager->updateSettings($input);
            sendResponse(['success' => true, 'updated' => $updated]);
            break;

        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}
