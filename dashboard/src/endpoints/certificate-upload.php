<?php

function handleCertificateUpload($method, $params, $db) {
    if ($method !== 'POST') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    // Check if files were uploaded
    if (!isset($_FILES['certificate']) || !isset($_FILES['private_key'])) {
        sendResponse(['error' => 'Certificate and private key files are required'], 400);
    }
    
    $siteId = $_POST['site_id'] ?? '';
    $domain = $_POST['domain'] ?? '';
    
    if (!$domain) {
        sendResponse(['error' => 'Domain is required'], 400);
    }
    
    $certFile = $_FILES['certificate'];
    $keyFile = $_FILES['private_key'];
    
    // Validate files
    if ($certFile['error'] !== UPLOAD_ERR_OK || $keyFile['error'] !== UPLOAD_ERR_OK) {
        sendResponse(['error' => 'File upload failed'], 400);
    }
    
    // Read file contents
    $certContent = file_get_contents($certFile['tmp_name']);
    $keyContent = file_get_contents($keyFile['tmp_name']);
    
    // Validate certificate format
    $certInfo = openssl_x509_parse($certContent);
    if (!$certInfo) {
        sendResponse(['error' => 'Invalid certificate format'], 400);
    }
    
    // Validate private key
    $keyResource = openssl_pkey_get_private($keyContent);
    if (!$keyResource) {
        sendResponse(['error' => 'Invalid private key format'], 400);
    }
    
    // Check if certificate and key match
    $publicKey = openssl_pkey_get_public($certContent);
    $keyDetails = openssl_pkey_get_details($keyResource);
    $certKeyDetails = openssl_pkey_get_details($publicKey);
    
    if ($keyDetails['key'] !== $certKeyDetails['key']) {
        sendResponse(['error' => 'Certificate and private key do not match'], 400);
    }
    
    // Create certificates directory if it doesn't exist
    $certsDir = '/etc/letsencrypt/live/' . $domain;
    if (!is_dir($certsDir)) {
        mkdir($certsDir, 0755, true);
    }
    
    // Save certificate and key
    $certPath = $certsDir . '/fullchain.pem';
    $keyPath = $certsDir . '/privkey.pem';
    
    if (file_put_contents($certPath, $certContent) === false) {
        sendResponse(['error' => 'Failed to save certificate'], 500);
    }
    
    if (file_put_contents($keyPath, $keyContent) === false) {
        sendResponse(['error' => 'Failed to save private key'], 500);
    }
    
    // Set proper permissions
    chmod($certPath, 0644);
    chmod($keyPath, 0600);
    
    // If site_id provided, update site to enable SSL
    if ($siteId) {
        $stmt = $db->prepare("UPDATE sites SET ssl_enabled = 1 WHERE id = :id");
        $stmt->execute(['id' => $siteId]);
        
        // Regenerate NGINX config
        require_once __DIR__ . '/sites.php';
        $site = $db->prepare("SELECT * FROM sites WHERE id = :id");
        $site->execute(['id' => $siteId]);
        $siteData = $site->fetch(PDO::FETCH_ASSOC);
        
        if ($siteData) {
            generateNginxConfig($siteData);
        }
    }
    
    // Reload NGINX
    exec("docker exec nginx-waf nginx -s reload 2>&1", $output, $returnCode);
    
    if ($returnCode !== 0) {
        sendResponse([
            'success' => true,
            'message' => 'Certificate uploaded but NGINX reload failed',
            'warning' => implode("\n", $output)
        ]);
    }
    
    sendResponse([
        'success' => true,
        'message' => 'Certificate uploaded and NGINX reloaded successfully',
        'cert_info' => [
            'subject' => $certInfo['subject']['CN'] ?? 'Unknown',
            'issuer' => $certInfo['issuer']['CN'] ?? 'Unknown',
            'valid_from' => date('Y-m-d H:i:s', $certInfo['validFrom_time_t']),
            'valid_to' => date('Y-m-d H:i:s', $certInfo['validTo_time_t'])
        ]
    ]);
}
