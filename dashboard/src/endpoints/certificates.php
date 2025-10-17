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
    $certPath = "/etc/nginx/certs/{$domain}/fullchain.pem";
    
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
    
    // Get site configuration for SSL challenge type
    $stmt = $db->prepare("SELECT ssl_challenge_type, cf_api_token, cf_zone_id FROM sites WHERE domain = ?");
    $stmt->execute([$domain]);
    $site = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$site) {
        http_response_code(404);
        echo json_encode(['error' => 'Site not found']);
        return;
    }
    
    $challengeType = $site['ssl_challenge_type'] ?? 'http-01';
    $email = getenv('ACME_EMAIL') ?: 'admin@localhost';
    
    // Build acme.sh command based on challenge type
    if ($challengeType === 'dns-01') {
        // DNS Challenge with Cloudflare
        $cfApiKey = $site['cf_api_token'] ?: getenv('CF_API_KEY');
        $cfZoneId = $site['cf_zone_id'];
        
        if (empty($cfApiKey)) {
            http_response_code(400);
            echo json_encode(['error' => 'Cloudflare API token not configured']);
            return;
        }
        
        $command = sprintf(
            "docker exec waf-acme sh -c 'export CF_Token=%s CF_Zone_ID=%s; /root/.acme.sh/acme.sh --issue --dns dns_cf -d %s --server letsencrypt --cert-home /acme.sh/%s --key-file /acme.sh/%s/key.pem --fullchain-file /acme.sh/%s/fullchain.pem' 2>&1",
            escapeshellarg($cfApiKey),
            escapeshellarg($cfZoneId),
            escapeshellarg($domain),
            escapeshellarg($domain),
            escapeshellarg($domain),
            escapeshellarg($domain)
        );
    } elseif ($challengeType === 'snakeoil') {
        // Generate self-signed certificate
        $certDir = "/etc/nginx/certs/{$domain}";
        $command = sprintf(
            "docker exec waf-nginx sh -c 'mkdir -p %s && openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout %s/key.pem -out %s/fullchain.pem -subj \"/CN=%s\"' 2>&1",
            escapeshellarg($certDir),
            escapeshellarg($certDir),
            escapeshellarg($certDir),
            escapeshellarg($domain)
        );
    } else {
        // HTTP-01 Challenge (default)
        $command = sprintf(
            "docker exec waf-acme sh -c '/root/.acme.sh/acme.sh --issue -d %s --webroot /var/www/certbot --server letsencrypt --cert-home /acme.sh/%s --key-file /acme.sh/%s/key.pem --fullchain-file /acme.sh/%s/fullchain.pem --force' 2>&1",
            escapeshellarg($domain),
            escapeshellarg($domain),
            escapeshellarg($domain),
            escapeshellarg($domain)
        );
    }
    
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0 && $challengeType !== 'snakeoil') {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to issue certificate',
            'output' => implode("\n", $output),
            'challenge_method' => $challengeType,
            'command' => $command
        ]);
        return;
    }
    
    // Create symlinks in nginx expected location
    if ($challengeType !== 'snakeoil') {
        $linkCommand = sprintf(
            "docker exec waf-nginx sh -c 'mkdir -p /etc/nginx/certs/%s && ln -sf /acme.sh/%s/fullchain.pem /etc/nginx/certs/%s/fullchain.pem && ln -sf /acme.sh/%s/key.pem /etc/nginx/certs/%s/key.pem' 2>&1",
            escapeshellarg($domain),
            escapeshellarg($domain),
            escapeshellarg($domain),
            escapeshellarg($domain),
            escapeshellarg($domain)
        );
        exec($linkCommand);
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
    
    // Reload nginx
    exec("docker exec waf-nginx nginx -s reload 2>&1");
    
    echo json_encode([
        'success' => true,
        'message' => "Certificate issued successfully for {$domain}",
        'output' => implode("\n", $output),
        'challenge_method' => $challengeType
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
