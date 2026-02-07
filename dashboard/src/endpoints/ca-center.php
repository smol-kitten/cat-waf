<?php
/**
 * Certificate Authority Center API Endpoint
 * Manage CAs, issue certificates, handle revocation
 */

require_once __DIR__ . '/../lib/CertificateAuthorityManager.php';

function handleCaRequest(string $method, array $path, PDO $pdo): void
{
    $manager = new CertificateAuthorityManager($pdo);
    $action = $path[1] ?? 'list';

    switch ($action) {
        case 'list':
        case '':
            handleCAList($method, $pdo, $manager);
            break;
        case 'create':
            handleCreateCA($method, $pdo, $manager);
            break;
        case 'import':
            handleImportCA($method, $pdo, $manager);
            break;
        case 'ca':
            handleCADetails($method, $path, $pdo, $manager);
            break;
        case 'issue':
            handleIssueCert($method, $path, $pdo, $manager);
            break;
        case 'certificate':
        case 'cert':
            handleCertDetails($method, $path, $pdo, $manager);
            break;
        case 'revoke':
            handleRevoke($method, $path, $pdo, $manager);
            break;
        case 'crl':
            handleCRL($method, $path, $pdo, $manager);
            break;
        case 'bundle':
            handleBundle($method, $path, $pdo, $manager);
            break;
        case 'settings':
            handleCASettings($method, $pdo, $manager);
            break;
        default:
            // Check if it's a numeric CA ID
            if (is_numeric($action)) {
                array_splice($path, 1, 0, 'ca');
                handleCADetails($method, $path, $pdo, $manager);
            } else {
                sendResponse(['error' => 'Unknown action'], 404);
            }
    }
}

/**
 * List all Certificate Authorities
 */
function handleCAList(string $method, PDO $pdo, CertificateAuthorityManager $manager): void
{
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $cas = $manager->listCAs();
    sendResponse(['certificate_authorities' => $cas]);
}

/**
 * Create a new self-signed CA
 */
function handleCreateCA(string $method, PDO $pdo, CertificateAuthorityManager $manager): void
{
    if ($method !== 'POST') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['name'])) {
        sendResponse(['error' => 'CA name is required'], 400);
        return;
    }

    $result = $manager->createCA($input);
    sendResponse($result, $result['success'] ? 201 : 400);
}

/**
 * Import an existing CA
 */
function handleImportCA(string $method, PDO $pdo, CertificateAuthorityManager $manager): void
{
    if ($method !== 'POST') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['name']) || empty($input['certificate']) || empty($input['private_key'])) {
        sendResponse(['error' => 'name, certificate, and private_key are required'], 400);
        return;
    }

    $result = $manager->importCA($input['name'], $input['certificate'], $input['private_key']);
    sendResponse($result, $result['success'] ? 201 : 400);
}

/**
 * Get CA details, issued certificates, or delete CA
 */
function handleCADetails(string $method, array $path, PDO $pdo, CertificateAuthorityManager $manager): void
{
    $caId = $path[2] ?? null;
    $subAction = $path[3] ?? null;

    if (!$caId || !is_numeric($caId)) {
        sendResponse(['error' => 'CA ID required'], 400);
        return;
    }

    $caId = (int)$caId;

    switch ($method) {
        case 'GET':
            if ($subAction === 'certificates' || $subAction === 'certs') {
                // List certificates issued by this CA
                $includeRevoked = ($_GET['include_revoked'] ?? 'true') === 'true';
                $certs = $manager->listIssuedCertificates($caId, $includeRevoked);
                sendResponse(['certificates' => $certs]);
            } elseif ($subAction === 'download') {
                // Download CA certificate
                $ca = $manager->getCA($caId, true);
                if (!$ca) {
                    sendResponse(['error' => 'CA not found'], 404);
                    return;
                }
                header('Content-Type: application/x-pem-file');
                header("Content-Disposition: attachment; filename=\"{$ca['name']}.crt\"");
                echo $ca['certificate'];
                exit;
            } else {
                // Get CA details
                $includeCert = ($_GET['include_cert'] ?? 'false') === 'true';
                $ca = $manager->getCA($caId, $includeCert);
                if (!$ca) {
                    sendResponse(['error' => 'CA not found'], 404);
                    return;
                }
                sendResponse(['ca' => $ca]);
            }
            break;

        case 'DELETE':
            $result = $manager->deleteCA($caId);
            sendResponse($result, $result['success'] ? 200 : 400);
            break;

        case 'PUT':
            // Toggle active state
            $input = json_decode(file_get_contents('php://input'), true);
            if (isset($input['is_active'])) {
                $stmt = $pdo->prepare("UPDATE certificate_authorities SET is_active = ? WHERE id = ?");
                $stmt->execute([$input['is_active'] ? 1 : 0, $caId]);
                sendResponse(['success' => true]);
            } elseif (isset($input['can_issue_certs'])) {
                $stmt = $pdo->prepare("UPDATE certificate_authorities SET can_issue_certs = ? WHERE id = ?");
                $stmt->execute([$input['can_issue_certs'] ? 1 : 0, $caId]);
                sendResponse(['success' => true]);
            } else {
                sendResponse(['error' => 'No valid fields to update'], 400);
            }
            break;

        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Issue a new certificate
 */
function handleIssueCert(string $method, array $path, PDO $pdo, CertificateAuthorityManager $manager): void
{
    if ($method !== 'POST') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $caId = $path[2] ?? null;
    if (!$caId || !is_numeric($caId)) {
        sendResponse(['error' => 'CA ID required'], 400);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['cn'])) {
        sendResponse(['error' => 'Common Name (cn) is required'], 400);
        return;
    }

    $result = $manager->issueCertificate((int)$caId, $input);
    sendResponse($result, $result['success'] ? 201 : 400);
}

/**
 * Get certificate details or download
 */
function handleCertDetails(string $method, array $path, PDO $pdo, CertificateAuthorityManager $manager): void
{
    $certId = $path[2] ?? null;
    $subAction = $path[3] ?? null;

    if (!$certId || !is_numeric($certId)) {
        sendResponse(['error' => 'Certificate ID required'], 400);
        return;
    }

    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $includeKey = $subAction === 'download' || ($_GET['include_key'] ?? 'false') === 'true';
    $cert = $manager->getCertificate((int)$certId, $includeKey);

    if (!$cert) {
        sendResponse(['error' => 'Certificate not found'], 404);
        return;
    }

    if ($subAction === 'download') {
        $format = $_GET['format'] ?? 'pem';
        
        switch ($format) {
            case 'pem':
                header('Content-Type: application/x-pem-file');
                header("Content-Disposition: attachment; filename=\"{$cert['subject_cn']}.crt\"");
                echo $cert['certificate'];
                break;
            case 'chain':
                // Certificate + CA chain
                header('Content-Type: application/x-pem-file');
                header("Content-Disposition: attachment; filename=\"{$cert['subject_cn']}-chain.crt\"");
                echo $cert['certificate'] . "\n" . $cert['ca_certificate'];
                break;
            case 'key':
                if (empty($cert['private_key'])) {
                    sendResponse(['error' => 'No private key available (CSR was provided)'], 404);
                    return;
                }
                header('Content-Type: application/x-pem-file');
                header("Content-Disposition: attachment; filename=\"{$cert['subject_cn']}.key\"");
                echo $cert['private_key'];
                break;
            case 'bundle':
                // ZIP with cert, key, and CA chain
                $zip = new ZipArchive();
                $zipFile = tempnam(sys_get_temp_dir(), 'cert');
                $zip->open($zipFile, ZipArchive::CREATE);
                $zip->addFromString("{$cert['subject_cn']}.crt", $cert['certificate']);
                if (!empty($cert['private_key'])) {
                    $zip->addFromString("{$cert['subject_cn']}.key", $cert['private_key']);
                }
                $zip->addFromString("ca-chain.crt", $cert['ca_certificate']);
                $zip->close();
                
                header('Content-Type: application/zip');
                header("Content-Disposition: attachment; filename=\"{$cert['subject_cn']}-bundle.zip\"");
                readfile($zipFile);
                unlink($zipFile);
                break;
            default:
                sendResponse(['error' => 'Unknown format'], 400);
                return;
        }
        exit;
    }

    // Remove sensitive data for normal view
    unset($cert['private_key'], $cert['ca_certificate']);
    sendResponse(['certificate' => $cert]);
}

/**
 * Revoke a certificate
 */
function handleRevoke(string $method, array $path, PDO $pdo, CertificateAuthorityManager $manager): void
{
    if ($method !== 'POST') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $certId = $path[2] ?? null;
    if (!$certId || !is_numeric($certId)) {
        sendResponse(['error' => 'Certificate ID required'], 400);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $reason = $input['reason'] ?? 'unspecified';

    $result = $manager->revokeCertificate((int)$certId, $reason);
    sendResponse($result, $result['success'] ? 200 : 400);
}

/**
 * Generate or download CRL
 */
function handleCRL(string $method, array $path, PDO $pdo, CertificateAuthorityManager $manager): void
{
    $caId = $path[2] ?? null;
    if (!$caId || !is_numeric($caId)) {
        sendResponse(['error' => 'CA ID required'], 400);
        return;
    }

    switch ($method) {
        case 'GET':
            // Download CRL
            $crlFile = "/etc/nginx/ssl/ca/ca-{$caId}.crl";
            if (!file_exists($crlFile)) {
                // Generate if doesn't exist
                $manager->generateCRL((int)$caId);
            }
            
            if (file_exists($crlFile)) {
                header('Content-Type: application/pkix-crl');
                header("Content-Disposition: attachment; filename=\"ca-{$caId}.crl\"");
                readfile($crlFile);
                exit;
            }
            sendResponse(['error' => 'CRL not available'], 404);
            break;

        case 'POST':
            // Force regenerate CRL
            $result = $manager->generateCRL((int)$caId);
            sendResponse($result, $result['success'] ? 200 : 500);
            break;

        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Get CA certificate bundle/chain
 */
function handleBundle(string $method, array $path, PDO $pdo, CertificateAuthorityManager $manager): void
{
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
        return;
    }

    $caId = $path[2] ?? null;
    if (!$caId || !is_numeric($caId)) {
        sendResponse(['error' => 'CA ID required'], 400);
        return;
    }

    $bundle = $manager->exportCABundle((int)$caId);

    if (isset($_GET['download'])) {
        header('Content-Type: application/x-pem-file');
        header("Content-Disposition: attachment; filename=\"ca-bundle-{$caId}.crt\"");
        echo $bundle;
        exit;
    }

    sendResponse(['bundle' => $bundle]);
}

/**
 * CA Center settings
 */
function handleCASettings(string $method, PDO $pdo, CertificateAuthorityManager $manager): void
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
