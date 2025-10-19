<?php
// Certificate Management API
// GET /api/certificates/:domain - Get certificate info
// POST /api/certificates/:domain - Issue new certificate
// POST /api/certificates/:domain/renew - Renew certificate
// DELETE /api/certificates/:domain - Revoke certificate

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];

error_log("Certificate API: $method $requestUri");

// Check for renew-all endpoint
if (preg_match('#/certificates/renew-all$#', $requestUri)) {
    error_log("Matched renew-all endpoint");
    if ($method === 'POST') {
        renewAllCertificates();
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    exit;
}

// Check for rescan endpoints
if (preg_match('#/certificates/rescan(?:/([^/]+))?$#', $requestUri, $rescanMatches)) {
    error_log("Matched rescan endpoint");
    if ($method === 'POST') {
        $rescanDomain = $rescanMatches[1] ?? null;
        if ($rescanDomain) {
            rescanCertificate($rescanDomain);
        } else {
            rescanAllCertificates();
        }
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    exit;
}

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
if (!preg_match('/^[a-zA-Z0-9._-]+$/', $domain)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid domain format']);
    exit;
}

// Skip special domains that shouldn't have certificates
if ($domain === '_') {
    http_response_code(200);
    echo json_encode([
        'exists' => false,
        'domain' => $domain,
        'message' => 'Default/catch-all domain - no certificate needed'
    ]);
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
    
    // Check if certificate exists in nginx container
    $checkCmd = "docker exec waf-nginx test -f {$certPath} && echo 'exists' || echo 'not_found'";
    $exists = trim(shell_exec($checkCmd));
    
    if ($exists !== 'exists') {
        // Check if cert exists in acme.sh container before giving up
        $acmeCertPath = "/acme.sh/{$domain}/{$domain}_ecc/fullchain.cer";
        $checkAcmeCmd = "docker exec waf-acme test -f {$acmeCertPath} && echo 'exists' || echo 'missing' 2>&1";
        $acmeExists = trim(shell_exec($checkAcmeCmd));
        
        if ($acmeExists === 'exists') {
            // Certificate exists in acme.sh but not in nginx - return that it needs syncing
            http_response_code(200);
            echo json_encode([
                'exists' => false,
                'domain' => $domain,
                'in_acme' => true,
                'message' => 'Certificate found in acme.sh but not in nginx - run rescan to sync'
            ]);
            return;
        }
        
        http_response_code(404);
        echo json_encode([
            'exists' => false,
            'domain' => $domain,
            'message' => 'Certificate not found'
        ]);
        return;
    }
    
    // Read certificate from nginx container and extract info
    $certData = shell_exec("docker exec waf-nginx cat {$certPath}");
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
    $db = getDB();
    
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
        $cfApiKey = $site['cf_api_token'] ?: getenv('CLOUDFLARE_API_TOKEN') ?: getenv('CLOUDFLARE_API_KEY');
        $cfZoneId = $site['cf_zone_id'];
        
        if (empty($cfApiKey)) {
            http_response_code(400);
            echo json_encode(['error' => 'Cloudflare API token not configured']);
            return;
        }
        
        // Build environment variables (only if site has custom token)
        $envVars = '';
        if (!empty($site['cf_api_token'])) {
            $envVars = sprintf('export CF_Token=%s; ', escapeshellarg($site['cf_api_token']));
        }
        if (!empty($cfZoneId)) {
            $envVars .= sprintf('export CF_Zone_ID=%s; ', escapeshellarg($cfZoneId));
        }
        
        // Add wildcard subdomain support if enabled
        $domains = escapeshellarg($domain);
        if (!empty($site['wildcard_subdomains']) && $site['wildcard_subdomains'] == 1) {
            $domains = sprintf("%s -d %s", escapeshellarg($domain), escapeshellarg("*.{$domain}"));
        }
        
        $command = sprintf(
            "docker exec waf-acme sh -c '%s/root/.acme.sh/acme.sh --issue --dns dns_cf -d %s --server letsencrypt --cert-home /acme.sh/%s' 2>&1",
            $envVars,
            $domains,
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
        // Add wildcard subdomain support if enabled (requires DNS-01)
        $domains = escapeshellarg($domain);
        if (!empty($site['wildcard_subdomains']) && $site['wildcard_subdomains'] == 1) {
            // Wildcard requires DNS-01, fall back to DNS if wildcard is enabled
            http_response_code(400);
            echo json_encode([
                'error' => 'Wildcard subdomains require DNS-01 challenge (Cloudflare)',
                'hint' => 'Please set SSL Challenge Type to "dns-01" in site settings'
            ]);
            return;
        }
        
        $command = sprintf(
            "docker exec waf-acme sh -c '/root/.acme.sh/acme.sh --issue -d %s --webroot /var/www/certbot --server letsencrypt --cert-home /acme.sh/%s --force' 2>&1",
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
    
    // Install certificate to target location
    if ($challengeType !== 'snakeoil') {
        // Use acme.sh --install-cert to properly copy files without creating loops
        $installCommand = sprintf(
            "docker exec waf-acme sh -c '/root/.acme.sh/acme.sh --install-cert -d %s --key-file /acme.sh/%s/key.pem --fullchain-file /acme.sh/%s/fullchain.pem' 2>&1",
            escapeshellarg($domain),
            escapeshellarg($domain),
            escapeshellarg($domain)
        );
        exec($installCommand, $installOutput);
        
        // Create symlinks in nginx expected location
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
    
    // Copy certificate from acme.sh to nginx immediately
    copyCertFromAcme($domain);
    
    // Regenerate nginx config
    require_once __DIR__ . '/sites.php';
    $stmt = $db->prepare("SELECT * FROM sites WHERE domain = ?");
    $stmt->execute([$domain]);
    $site = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($site) {
        generateSiteConfig($site['id'], $site);
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
    $db = getDB();
    
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
    
    // Build acme.sh command based on challenge type
    if ($challengeType === 'dns-01') {
        // DNS Challenge with Cloudflare
        $cfApiKey = $site['cf_api_token'] ?: getenv('CLOUDFLARE_API_TOKEN') ?: getenv('CLOUDFLARE_API_KEY');
        $cfZoneId = $site['cf_zone_id'];
        
        if (empty($cfApiKey)) {
            http_response_code(400);
            echo json_encode(['error' => 'Cloudflare API token not configured']);
            return;
        }
        
        // Build environment variables (only if site has custom token)
        $envVars = '';
        if (!empty($site['cf_api_token'])) {
            $envVars = sprintf('export CF_Token=%s; ', escapeshellarg($site['cf_api_token']));
        }
        if (!empty($cfZoneId)) {
            $envVars .= sprintf('export CF_Zone_ID=%s; ', escapeshellarg($cfZoneId));
        }
        
        // Add wildcard subdomain support if enabled
        $domains = escapeshellarg($domain);
        $stmt = $db->prepare("SELECT wildcard_subdomains FROM sites WHERE domain = ?");
        $stmt->execute([$domain]);
        $wildcardEnabled = $stmt->fetchColumn();
        if (!empty($wildcardEnabled) && $wildcardEnabled == 1) {
            $domains = sprintf("%s -d %s", escapeshellarg($domain), escapeshellarg("*.{$domain}"));
        }
        
        $command = sprintf(
            "docker exec waf-acme sh -c '%s/root/.acme.sh/acme.sh --renew --dns dns_cf -d %s --force' 2>&1",
            $envVars,
            $domains
        );
    } else {
        // HTTP-01 Challenge (default)
        $command = sprintf(
            "docker exec waf-acme sh -c '/root/.acme.sh/acme.sh --renew -d %s --webroot /var/www/certbot --force' 2>&1",
            escapeshellarg($domain)
        );
    }
    
    exec($command, $output, $returnCode);
    
    $outputText = implode("\n", $output);

    // If renew failed because the domain was never issued, try issuing it now
    if ($returnCode !== 0) {
        error_log("Certificate renew failed for {$domain}, returnCode: {$returnCode}");
        error_log("Output contains 'is not an issued domain': " . (strpos($outputText, 'is not an issued domain') !== false ? 'YES' : 'NO'));
        error_log("Output: " . $outputText);
        
        if (strpos($outputText, 'is not an issued domain') !== false || strpos($outputText, 'No certificate found for') !== false) {
            // Attempt to issue a new certificate (build issue command similar to renewAllCertificates)
            if ($challengeType === 'dns-01') {
                $cfApiKey = $site['cf_api_token'] ?: getenv('CLOUDFLARE_API_TOKEN') ?: getenv('CLOUDFLARE_API_KEY');
                $cfZoneId = $site['cf_zone_id'];

                // Prefer site-specific token, otherwise use global env token
                $tokenToUse = !empty($site['cf_api_token']) ? $site['cf_api_token'] : (getenv('CLOUDFLARE_API_TOKEN') ?: getenv('CLOUDFLARE_API_KEY'));

                $envVars = '';
                if (!empty($tokenToUse)) {
                    $envVars = sprintf('export CF_Token=%s; ', escapeshellarg($tokenToUse));
                }
                if (!empty($cfZoneId)) {
                    $envVars .= sprintf('export CF_Zone_ID=%s; ', escapeshellarg($cfZoneId));
                }

                $issueCommand = sprintf(
                    "docker exec waf-acme sh -c '%s/root/.acme.sh/acme.sh --issue --dns dns_cf -d %s --server letsencrypt --force' 2>&1",
                    $envVars,
                    escapeshellarg($domain)
                );
            } else {
                // For snakeoil sites or http-01 fallback, try HTTP-01 issuance
                $issueCommand = sprintf(
                    "docker exec waf-acme sh -c '/root/.acme.sh/acme.sh --issue -d %s --webroot /var/www/certbot --server letsencrypt --force' 2>&1",
                    escapeshellarg($domain)
                );
            }

            unset($output);
            exec($issueCommand, $output, $returnCode);
            $issueOutput = implode("\n", $output);

            if ($returnCode !== 0) {
                http_response_code(500);
                echo json_encode([
                    'error' => 'Failed to issue certificate after renew reported missing',
                    'renew_output' => $outputText,
                    'issue_output' => $issueOutput,
                    'challenge_method' => $challengeType
                ]);
                return;
            }

            // Link and reload
            // First, create the intermediate symlinks in the acme.sh directory (to actual _ecc files)
            $createAcmeSymlinks = sprintf(
                "docker exec waf-nginx sh -c 'cd /acme.sh/%s && rm -f fullchain.pem key.pem && ln -s %s_ecc/fullchain.cer fullchain.pem && ln -s %s_ecc/%s.key key.pem' 2>&1",
                escapeshellarg($domain),
                escapeshellarg($domain),
                escapeshellarg($domain),
                escapeshellarg($domain)
            );
            exec($createAcmeSymlinks);
            
            // Then link from nginx certs to acme.sh
            $linkCommand = sprintf(
                "docker exec waf-nginx sh -c 'mkdir -p /etc/nginx/certs/%s && cd /etc/nginx/certs/%s && rm -f fullchain.pem key.pem && ln -sf /acme.sh/%s/fullchain.pem fullchain.pem && ln -sf /acme.sh/%s/key.pem key.pem' 2>&1",
                escapeshellarg($domain),
                escapeshellarg($domain),
                escapeshellarg($domain),
                escapeshellarg($domain)
            );
            exec($linkCommand);
            exec("docker exec waf-nginx nginx -s reload 2>&1");

            echo json_encode([
                'success' => true,
                'message' => "Certificate issued (after missing renew) for {$domain}",
                'output' => $issueOutput,
                'challenge_method' => $challengeType
            ]);
            return;
        }

        // Other failures: return original renew failure
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to renew certificate',
            'output' => $outputText,
            'challenge_method' => $challengeType
        ]);
        return;
    }
    
    // Reload nginx
    exec("docker exec waf-nginx nginx -s reload 2>&1");
    
    echo json_encode([
        'success' => true,
        'message' => "Certificate renewed successfully for {$domain}",
        'output' => implode("\n", $output),
        'challenge_method' => $challengeType
    ]);
}

function revokeCertificate($domain) {
    $db = getDB();
    
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
    $stmt = $db->prepare("SELECT * FROM sites WHERE domain = ?");
    $stmt->execute([$domain]);
    $site = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($site) {
        generateSiteConfig($site['id'], $site);
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Certificate revoked for {$domain}",
        'output' => implode("\n", $output)
    ]);
}

function renewAllCertificates() {
    $db = getDB();
    
    // Get all sites with SSL enabled
    $stmt = $db->query("SELECT domain, ssl_challenge_type, cf_api_token, cf_zone_id FROM sites WHERE ssl_enabled = 1 AND ssl_challenge_type != 'snakeoil'");
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($sites)) {
        echo json_encode([
            'success' => true,
            'message' => 'No sites with certificates to renew',
            'renewed' => 0
        ]);
        return;
    }
    
    $results = [];
    $successCount = 0;
    $failCount = 0;
    
    foreach ($sites as $site) {
        $domain = $site['domain'];
        $challengeType = $site['ssl_challenge_type'] ?? 'http-01';
        
        // Skip wildcard domains and internal domains
        if (strpos($domain, '*') !== false || strpos($domain, '.local') !== false || $domain === '_') {
            $results[] = [
                'domain' => $domain,
                'status' => 'skipped',
                'reason' => 'Wildcard or internal domain'
            ];
            continue;
        }
        
        error_log("Processing certificate for {$domain} using {$challengeType}");
        
        // Build acme.sh command
        if ($challengeType === 'dns-01') {
            // Check if we have CF credentials (either in site config or env)
            $cfApiKey = $site['cf_api_token'] ?: getenv('CLOUDFLARE_API_TOKEN') ?: getenv('CLOUDFLARE_API_KEY');
            $cfZoneId = $site['cf_zone_id'];
            
            if (empty($cfApiKey)) {
                $results[] = [
                    'domain' => $domain,
                    'status' => 'failed',
                    'error' => 'Missing Cloudflare API token'
                ];
                $failCount++;
                continue;
            }
            
            // Build environment variables for acme.sh
            // If site has custom token, use it; otherwise acme container already has CF_Token from docker-compose
            $envVars = '';
            if (!empty($site['cf_api_token'])) {
                $envVars = sprintf('export CF_Token=%s; ', escapeshellarg($site['cf_api_token']));
            }
            if (!empty($cfZoneId)) {
                $envVars .= sprintf('export CF_Zone_ID=%s; ', escapeshellarg($cfZoneId));
            }
            
            // Add wildcard subdomain support if enabled
            $domains = escapeshellarg($domain);
            if (!empty($site['wildcard_subdomains']) && $site['wildcard_subdomains'] == 1) {
                $domains = sprintf("%s -d %s", escapeshellarg($domain), escapeshellarg("*.{$domain}"));
            }
            
            // Try renew first, if fails try issue
            $renewCommand = sprintf(
                "docker exec waf-acme sh -c '%s/root/.acme.sh/acme.sh --renew --dns dns_cf -d %s --force' 2>&1",
                $envVars,
                $domains
            );
            
            $issueCommand = sprintf(
                "docker exec waf-acme sh -c '%s/root/.acme.sh/acme.sh --issue --dns dns_cf -d %s --server letsencrypt --cert-home /acme.sh/%s' 2>&1",
                $envVars,
                $domains,
                escapeshellarg($domain)
            );
        } else {
            // HTTP-01 Challenge
            $renewCommand = sprintf(
                "docker exec waf-acme sh -c '/root/.acme.sh/acme.sh --renew -d %s --webroot /var/www/certbot --force' 2>&1",
                escapeshellarg($domain)
            );
            
            $issueCommand = sprintf(
                "docker exec waf-acme sh -c '/root/.acme.sh/acme.sh --issue -d %s --webroot /var/www/certbot --server letsencrypt --cert-home /acme.sh/%s' 2>&1",
                escapeshellarg($domain),
                escapeshellarg($domain)
            );
        }
        
        // Try to renew first
        exec($renewCommand, $output, $returnCode);
        $outputText = implode("\n", $output);
        
        // Check if cert doesn't exist or if we need to issue
        if ($returnCode !== 0 && strpos($outputText, 'is not an issued domain') !== false) {
            error_log("Certificate not found for {$domain}, issuing new certificate...");
            unset($output);
            exec($issueCommand, $output, $returnCode);
            $outputText = implode("\n", $output);
            $action = 'issued';
        } else {
            $action = 'renewed';
        }
        
        // acme.sh returns success even for "Domains not changed", "Cert success" etc.
        // Check for common success indicators in output
        $isSuccess = ($returnCode === 0) || 
                     (strpos($outputText, 'Domains not changed') !== false) ||
                     (strpos($outputText, 'Cert success') !== false) ||
                     (strpos($outputText, 'already exists') !== false);
        
        if ($isSuccess) {
            $results[] = [
                'domain' => $domain,
                'status' => 'success',
                'method' => $challengeType,
                'action' => $action
            ];
            $successCount++;
        } else {
            $results[] = [
                'domain' => $domain,
                'status' => 'failed',
                'error' => implode("\n", array_slice($output, -3))
            ];
            $failCount++;
        }
        
        unset($output);
    }
    
    // Reload nginx
    exec("docker exec waf-nginx nginx -s reload 2>&1");
    
    echo json_encode([
        'success' => true,
        'message' => "Processed {$successCount} certificates, {$failCount} failed",
        'total' => count($sites),
        'succeeded' => $successCount,
        'failed' => $failCount,
        'results' => $results
    ]);
}

/**
 * Check if a certificate is a real Let's Encrypt cert or snakeoil
 * Returns: 'letencrypt', 'snakeoil', 'custom', or 'missing'
 */
function checkCertificateType($domain) {
    $certPath = "/etc/nginx/certs/{$domain}/fullchain.pem";
    
    // Check if cert exists in nginx
    $checkCmd = "docker exec waf-nginx test -f {$certPath} && echo 'exists' || echo 'missing' 2>&1";
    $exists = trim(shell_exec($checkCmd));
    
    if ($exists !== 'exists') {
        return 'missing';
    }
    
    // Read certificate and check issuer
    $certData = shell_exec("docker exec waf-nginx cat {$certPath} 2>&1");
    if (empty($certData)) {
        return 'missing';
    }
    
    $cert = openssl_x509_parse($certData);
    if (!$cert) {
        return 'missing';
    }
    
    $issuer = $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? '';
    $subject = $cert['subject']['CN'] ?? '';
    
    // Check if it's self-signed (subject == issuer for snakeoil)
    if ($subject === $issuer || strpos($issuer, $domain) !== false) {
        return 'snakeoil';
    }
    
    // Check if it's Let's Encrypt
    if (strpos($issuer, "Let's Encrypt") !== false || strpos($issuer, 'R3') !== false || strpos($issuer, 'E1') !== false) {
        return 'letencrypt';
    }
    
    // Otherwise it's a custom certificate
    return 'custom';
}

/**
 * Rescan a single domain's certificate and update if needed
 */
function rescanCertificate($domain) {
    $db = getDB();
    
    // Get site info
    $stmt = $db->prepare("SELECT id, ssl_challenge_type, ssl_enabled FROM sites WHERE domain = ?");
    $stmt->execute([$domain]);
    $site = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$site) {
        http_response_code(404);
        echo json_encode(['error' => 'Site not found']);
        return;
    }
    
    $certType = checkCertificateType($domain);
    $shouldHaveRealCert = ($site['ssl_challenge_type'] !== 'snakeoil' && $site['ssl_enabled'] == 1);
    
    $result = [
        'domain' => $domain,
        'current_cert_type' => $certType,
        'should_have_real_cert' => $shouldHaveRealCert,
        'action' => 'none'
    ];
    
    // If site should have a real cert but has snakeoil or missing
    if ($shouldHaveRealCert && ($certType === 'snakeoil' || $certType === 'missing')) {
        // Check if cert exists in acme.sh container
        $acmeCertPath = "/acme.sh/{$domain}/{$domain}_ecc/fullchain.cer";
        $checkAcmeCmd = "docker exec waf-acme test -f {$acmeCertPath} && echo 'exists' || echo 'missing' 2>&1";
        $acmeExists = trim(shell_exec($checkAcmeCmd));
        
        if ($acmeExists === 'exists') {
            // Copy from acme.sh to nginx
            $result['action'] = 'copied_from_acme';
            copyCertFromAcme($domain);
        } else {
            // Issue new certificate
            $result['action'] = 'reissued';
            issueCertificate($domain);
            return; // issueCertificate handles the response
        }
    }
    
    // Regenerate nginx config to ensure consistency
    require_once __DIR__ . '/sites.php';
    generateSiteConfig($site['id'], $site);
    
    // Reload nginx
    exec("docker exec waf-nginx nginx -s reload 2>&1");
    
    echo json_encode([
        'success' => true,
        'result' => $result
    ]);
}

/**
 * Rescan all sites and fix certificate issues
 */
function rescanAllCertificates() {
    $db = getDB();
    
    $stmt = $db->query("SELECT id, domain, ssl_challenge_type, ssl_enabled FROM sites WHERE domain != '_'");
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results = [];
    $fixedCount = 0;
    
    foreach ($sites as $site) {
        $domain = $site['domain'];
        $certType = checkCertificateType($domain);
        $shouldHaveRealCert = ($site['ssl_challenge_type'] !== 'snakeoil' && $site['ssl_enabled'] == 1);
        
        $siteResult = [
            'domain' => $domain,
            'current_cert_type' => $certType,
            'should_have_real_cert' => $shouldHaveRealCert,
            'action' => 'none'
        ];
        
        // If site should have a real cert but has snakeoil or missing
        if ($shouldHaveRealCert && ($certType === 'snakeoil' || $certType === 'missing')) {
            // Check if cert exists in acme.sh container
            $acmeCertPath = "/acme.sh/{$domain}/{$domain}_ecc/fullchain.cer";
            $checkAcmeCmd = "docker exec waf-acme test -f {$acmeCertPath} && echo 'exists' || echo 'missing' 2>&1";
            $acmeExists = trim(shell_exec($checkAcmeCmd));
            
            if ($acmeExists === 'exists') {
                // Copy from acme.sh to nginx
                copyCertFromAcme($domain);
                $siteResult['action'] = 'copied_from_acme';
                $fixedCount++;
            } else {
                $siteResult['action'] = 'needs_issuance';
                $siteResult['note'] = 'Certificate needs to be issued manually';
            }
        }
        
        // Regenerate nginx config
        require_once __DIR__ . '/sites.php';
        generateSiteConfig($site['id'], $site);
        
        $results[] = $siteResult;
    }
    
    // Reload nginx
    exec("docker exec waf-nginx nginx -s reload 2>&1");
    
    echo json_encode([
        'success' => true,
        'total' => count($sites),
        'fixed' => $fixedCount,
        'results' => $results
    ]);
}

/**
 * Helper function to copy certificate from acme.sh to nginx
 */
function copyCertFromAcme($domain) {
    $acmeCertPath = "/acme.sh/{$domain}/{$domain}_ecc/fullchain.cer";
    $acmeKeyPath = "/acme.sh/{$domain}/{$domain}_ecc/{$domain}.key";
    
    $nginxCertDir = "/etc/nginx/certs/{$domain}";
    $nginxCertPath = "{$nginxCertDir}/fullchain.pem";
    $nginxKeyPath = "{$nginxCertDir}/key.pem";
    
    // Create cert directory in nginx container and remove any old symlinks
    $mkdirCmd = "docker exec waf-nginx sh -c 'mkdir -p {$nginxCertDir} && rm -f {$nginxCertPath} {$nginxKeyPath}' 2>&1";
    shell_exec($mkdirCmd);
    
    // Copy cert: read from acme, write to nginx
    $copyCertCmd = "docker exec waf-acme cat {$acmeCertPath} | docker exec -i waf-nginx sh -c 'cat > {$nginxCertPath}' 2>&1";
    shell_exec($copyCertCmd);
    
    // Copy key: read from acme, write to nginx
    $copyKeyCmd = "docker exec waf-acme cat {$acmeKeyPath} | docker exec -i waf-nginx sh -c 'cat > {$nginxKeyPath}' 2>&1";
    shell_exec($copyKeyCmd);
    
    error_log("Copied Let's Encrypt certificate from acme.sh to nginx for {$domain}");
}
