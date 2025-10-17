<?php
// Certificate Management API
// GET /api/certificates/:domain - Get certificate info
// POST /api/certificates/:domain - Issue new certificate
// POST /api/certificates/:domain/renew - Renew certificate
// DELETE /api/certificates/:domain - Revoke certificate

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];

// Parse domain from URI
preg_match('#/certificates/([^/]+)(?:/(renew))?$#', $requestUri, $matches);
$domain = $matches[1] ?? null;
$action = $matches[2] ?? null;

if (!$domain) {
    http_response_code(400);
    echo json_encode(['error' => 'Domain parameter required']);
    exit;
}

// Validate domain format
if (!preg_match('/^[a-zA-Z0-9.-]+$/', $domain)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid domain format']);
    exit;
}

switch ($method) {
    case 'GET':
        getCertificateInfo($domain);
        break;
    
    case 'POST':
        if ($action === 'renew') {
            renewCertificate($domain);
        } else {
            issueCertificate($domain);
        }
        break;
    
    case 'DELETE':
        revokeCertificate($domain);
        break;
    
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

function getCertificateInfo($domain) {
    $certPath = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
    
    if (!file_exists($certPath)) {
        http_response_code(404);
        echo json_encode([
            'exists' => false,
            'domain' => $domain,
            'message' => 'Certificate not found'
        ]);
        return;
    }
    
    // Read certificate and extract info
    $certData = file_get_contents($certPath);
    $cert = openssl_x509_parse($certData);
    
    if (!$cert) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to parse certificate']);
        return;
    }
    
    $validTo = $cert['validTo_time_t'];
    $now = time();
    $daysUntilExpiry = floor(($validTo - $now) / 86400);
    
    $issuer = $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? 'Unknown';
    
    echo json_encode([
        'exists' => true,
        'domain' => $domain,
        'issuer' => $issuer,
        'expiryDate' => date('Y-m-d H:i:s', $validTo),
        'daysUntilExpiry' => $daysUntilExpiry,
        'validFrom' => date('Y-m-d H:i:s', $cert['validFrom_time_t']),
        'subject' => $cert['subject']['CN'] ?? $domain
    ]);
}

function issueCertificate($domain) {
    global $db;
    
    // Get ACME settings
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key IN ('acme_email', 'acme_server')");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $email = $settings['acme_email'] ?? 'admin@localhost';
    $server = $settings['acme_server'] ?? 'https://acme-v02.api.letsencrypt.org/directory';
    
    // Check for Cloudflare DNS credentials from environment
    $cfApiKey = getenv('CF_API_KEY');
    $cfEmail = getenv('CF_EMAIL');
    
    // Determine if we should use DNS challenge (Cloudflare) or HTTP challenge
    $useDnsChallenge = !empty($cfApiKey) && !empty($cfEmail);
    
    if ($useDnsChallenge) {
        // Create Cloudflare credentials file for certbot
        $credentialsPath = '/tmp/cloudflare.ini';
        $credentials = "# Cloudflare API credentials\n";
        $credentials .= "dns_cloudflare_email = $cfEmail\n";
        $credentials .= "dns_cloudflare_api_key = $cfApiKey\n";
        file_put_contents($credentialsPath, $credentials);
        chmod($credentialsPath, 0600);
        
        // Use DNS challenge with Cloudflare plugin
        $command = sprintf(
            "docker run --rm --name certbot -v /etc/letsencrypt:/etc/letsencrypt -v %s:/tmp/cloudflare.ini:ro certbot/dns-cloudflare certonly --dns-cloudflare --dns-cloudflare-credentials /tmp/cloudflare.ini --email %s --agree-tos --no-eff-email --server %s -d %s 2>&1",
            escapeshellarg($credentialsPath),
            escapeshellarg($email),
            escapeshellarg($server),
            escapeshellarg($domain)
        );
    } else {
        // Use HTTP webroot challenge (default)
        $command = sprintf(
            "docker run --rm --name certbot -v /etc/letsencrypt:/etc/letsencrypt -v /var/www/certbot:/var/www/certbot certbot/certbot certonly --webroot --webroot-path /var/www/certbot --email %s --agree-tos --no-eff-email --server %s -d %s 2>&1",
            escapeshellarg($email),
            escapeshellarg($server),
            escapeshellarg($domain)
        );
    }
    
    exec($command, $output, $returnCode);
    
    // Clean up credentials file if it was created
    if ($useDnsChallenge && file_exists($credentialsPath)) {
        unlink($credentialsPath);
    }
    
    if ($returnCode !== 0) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to issue certificate',
            'output' => implode("\n", $output),
            'challenge_method' => $useDnsChallenge ? 'dns-cloudflare' : 'http-webroot'
        ]);
        return;
    }
    
    // Update site to enable SSL
    $stmt = $db->prepare("UPDATE sites SET ssl_enabled = 1 WHERE domain = ?");
    $stmt->execute([$domain]);
    
    // Regenerate nginx config
    require_once __DIR__ . '/sites.php';
    $stmt = $db->prepare("SELECT id FROM sites WHERE domain = ?");
    $stmt->execute([$domain]);
    $siteId = $stmt->fetchColumn();
    
    if ($siteId) {
        generateNginxConfig($siteId);
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Certificate issued successfully for {$domain}",
        'output' => implode("\n", $output),
        'challenge_method' => $useDnsChallenge ? 'dns-cloudflare' : 'http-webroot'
    ]);
}

function renewCertificate($domain) {
    // Use certbot to renew
    $command = sprintf(
        "docker run --rm --name certbot -v /etc/letsencrypt:/etc/letsencrypt -v /var/www/certbot:/var/www/certbot certbot/certbot renew --cert-name %s 2>&1",
        escapeshellarg($domain)
    );
    
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to renew certificate',
            'output' => implode("\n", $output)
        ]);
        return;
    }
    
    // Reload nginx
    exec("docker exec waf-nginx nginx -s reload 2>&1");
    
    echo json_encode([
        'success' => true,
        'message' => "Certificate renewed successfully for {$domain}",
        'output' => implode("\n", $output)
    ]);
}

function revokeCertificate($domain) {
    global $db;
    
    $command = sprintf(
        "docker run --rm --name certbot -v /etc/letsencrypt:/etc/letsencrypt certbot/certbot revoke --cert-name %s --delete-after-revoke 2>&1",
        escapeshellarg($domain)
    );
    
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to revoke certificate',
            'output' => implode("\n", $output)
        ]);
        return;
    }
    
    // Disable SSL on site
    $stmt = $db->prepare("UPDATE sites SET ssl_enabled = 0 WHERE domain = ?");
    $stmt->execute([$domain]);
    
    // Regenerate nginx config
    require_once __DIR__ . '/sites.php';
    $stmt = $db->prepare("SELECT id FROM sites WHERE domain = ?");
    $stmt->execute([$domain]);
    $siteId = $stmt->fetchColumn();
    
    if ($siteId) {
        generateNginxConfig($siteId);
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Certificate revoked for {$domain}",
        'output' => implode("\n", $output)
    ]);
}
