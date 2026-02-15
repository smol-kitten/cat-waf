<?php
/**
 * Custom Certificate Upload
 *
 * Writes uploaded cert + key to the waf-nginx container at
 * /etc/nginx/certs/{domain}/fullchain.pem + key.pem via docker exec.
 * Sets ssl_challenge_type = 'custom' so the cert system knows not to
 * overwrite it with ACME or snakeoil.
 */

function handleCertificateUpload($method, $params, $db) {
    if ($method !== 'POST') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }

    // ── Validate input ──
    if (!isset($_FILES['certificate']) || !isset($_FILES['private_key'])) {
        sendResponse(['error' => 'Certificate and private key files are required'], 400);
    }

    $siteId = $_POST['site_id'] ?? '';
    $domain = $_POST['domain'] ?? '';

    if (!$domain || !preg_match('/^[a-zA-Z0-9._-]+$/', $domain)) {
        sendResponse(['error' => 'Valid domain is required'], 400);
    }
    $domain = preg_replace('/[^a-z0-9._-]/i', '', $domain);

    $certFile = $_FILES['certificate'];
    $keyFile  = $_FILES['private_key'];

    if ($certFile['error'] !== UPLOAD_ERR_OK || $keyFile['error'] !== UPLOAD_ERR_OK) {
        sendResponse(['error' => 'File upload failed'], 400);
    }

    // ── Read & validate ──
    $certContent = file_get_contents($certFile['tmp_name']);
    $keyContent  = file_get_contents($keyFile['tmp_name']);

    $certInfo = openssl_x509_parse($certContent);
    if (!$certInfo) {
        sendResponse(['error' => 'Invalid certificate format (not a valid X.509 PEM)'], 400);
    }

    $keyResource = openssl_pkey_get_private($keyContent);
    if (!$keyResource) {
        sendResponse(['error' => 'Invalid private key format'], 400);
    }

    // Verify cert and key match
    $publicKey      = openssl_pkey_get_public($certContent);
    $keyDetails     = openssl_pkey_get_details($keyResource);
    $certKeyDetails = openssl_pkey_get_details($publicKey);

    if ($keyDetails['key'] !== $certKeyDetails['key']) {
        sendResponse(['error' => 'Certificate and private key do not match'], 400);
    }

    // ── Write to nginx via docker exec ──
    // Both containers share waf-certs volume so writing in nginx container
    // places the files at /etc/nginx/certs/{domain}/
    $certDir = "/etc/nginx/certs/{$domain}";

    // Create directory, remove old files/symlinks
    $mkdirCmd = sprintf(
        "docker exec waf-nginx sh -c %s 2>&1",
        escapeshellarg("mkdir -p '{$certDir}' && rm -f '{$certDir}/fullchain.pem' '{$certDir}/key.pem'")
    );
    exec($mkdirCmd, $out, $rc);
    if ($rc !== 0) {
        sendResponse(['error' => 'Failed to prepare certificate directory', 'detail' => implode("\n", $out)], 500);
    }

    // Write cert via piped stdin
    $writeCertCmd = sprintf(
        "echo %s | docker exec -i waf-nginx sh -c %s 2>&1",
        escapeshellarg($certContent),
        escapeshellarg("cat > '{$certDir}/fullchain.pem'")
    );
    exec($writeCertCmd, $out, $rc);
    if ($rc !== 0) {
        sendResponse(['error' => 'Failed to write certificate file'], 500);
    }

    // Write key via piped stdin
    $writeKeyCmd = sprintf(
        "echo %s | docker exec -i waf-nginx sh -c %s 2>&1",
        escapeshellarg($keyContent),
        escapeshellarg("cat > '{$certDir}/key.pem' && chmod 600 '{$certDir}/key.pem'")
    );
    exec($writeKeyCmd, $out, $rc);
    if ($rc !== 0) {
        sendResponse(['error' => 'Failed to write private key file'], 500);
    }

    // ── Update DB (enable SSL + set type to custom) ──
    if ($siteId) {
        $stmt = $db->prepare("UPDATE sites SET ssl_enabled = 1, ssl_challenge_type = 'custom' WHERE id = ?");
        $stmt->execute([$siteId]);
    } else {
        // Fallback: match by domain
        $stmt = $db->prepare("UPDATE sites SET ssl_enabled = 1, ssl_challenge_type = 'custom' WHERE domain = ?");
        $stmt->execute([$domain]);
    }

    // Regenerate nginx config
    require_once __DIR__ . '/sites.php';
    $lookupField = $siteId ? 'id' : 'domain';
    $lookupValue = $siteId ?: $domain;
    $stmt = $db->prepare("SELECT * FROM sites WHERE {$lookupField} = ?");
    $stmt->execute([$lookupValue]);
    $siteData = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($siteData) {
        generateSiteConfig($siteData['id'], $siteData);
    }

    // Reload nginx
    exec("docker exec waf-nginx nginx -s reload 2>&1", $reloadOut, $reloadRc);

    sendResponse([
        'success'   => true,
        'message'   => $reloadRc === 0
            ? 'Certificate uploaded and NGINX reloaded successfully'
            : 'Certificate uploaded but NGINX reload failed',
        'warning'   => $reloadRc !== 0 ? implode("\n", $reloadOut) : null,
        'cert_info' => [
            'subject'    => $certInfo['subject']['CN'] ?? 'Unknown',
            'issuer'     => $certInfo['issuer']['CN'] ?? $certInfo['issuer']['O'] ?? 'Unknown',
            'valid_from' => date('Y-m-d H:i:s', $certInfo['validFrom_time_t']),
            'valid_to'   => date('Y-m-d H:i:s', $certInfo['validTo_time_t']),
        ],
    ]);
}
