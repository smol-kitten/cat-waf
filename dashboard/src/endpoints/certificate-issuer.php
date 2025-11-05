<?php
// Background certificate issuer
// Called asynchronously to issue certificates without blocking API responses

/**
 * Extract base domain from subdomain or domain
 * Examples: subdomain.example.com -> example.com, example.co.uk -> example.co.uk
 */
function extractRootDomain($domain) {
    // Remove wildcard prefix
    $domain = preg_replace('/^\*\./', '', $domain);
    
    // Split by dots
    $parts = explode('.', $domain);
    $count = count($parts);
    
    // Handle special TLDs (co.uk, com.au, etc.)
    if ($count >= 3 && in_array($parts[$count - 2] . '.' . $parts[$count - 1], [
        'co.uk', 'com.au', 'co.nz', 'co.za', 'com.br', 'com.mx',
        'co.jp', 'co.in', 'co.kr', 'ac.uk', 'gov.uk', 'org.uk'
    ])) {
        return $parts[$count - 3] . '.' . $parts[$count - 2] . '.' . $parts[$count - 1];
    }
    
    // Return last two parts (domain.tld)
    if ($count >= 2) {
        return $parts[$count - 2] . '.' . $parts[$count - 1];
    }
    
    return $domain;
}

if ($argc < 3) {
    error_log("Usage: php certificate-issuer.php <domain> <challenge_type> [cf_api_token] [cf_zone_id]");
    exit(1);
}

$domain = $argv[1];
$challengeType = $argv[2];
$cfApiToken = $argv[3] ?? '';
$cfZoneId = $argv[4] ?? '';

// Extract base domain - all certificates are issued for base + wildcard
$baseDomain = extractRootDomain($domain);

error_log("Starting certificate issuance for {$domain} (base: {$baseDomain}) using {$challengeType}");

// Wait a bit for NGINX config to be written and reloaded
sleep(5);

// Build acme.sh command based on challenge type
if ($challengeType === 'dns-01') {
    // DNS Challenge with Cloudflare (REQUIRED for wildcard certificates)
    if (empty($cfApiToken)) {
        $cfApiToken = getenv('CF_API_KEY');
    }
    
    if (empty($cfApiToken)) {
        error_log("ERROR: Cloudflare API token not configured for {$domain}");
        exit(1);
    }
    
    // ALWAYS issue with base domain + wildcard to prevent certificate proliferation
    $domains = sprintf("%s -d %s", escapeshellarg($baseDomain), escapeshellarg("*.{$baseDomain}"));
    
    $command = sprintf(
        "docker exec waf-acme sh -c 'export CF_Token=%s CF_Zone_ID=%s; /root/.acme.sh/acme.sh --issue --dns dns_cf -d %s --server letsencrypt --cert-home /acme.sh/%s --key-file /acme.sh/%s/key.pem --fullchain-file /acme.sh/%s/fullchain.pem' 2>&1",
        escapeshellarg($cfApiToken),
        escapeshellarg($cfZoneId),
        $domains,
        escapeshellarg($baseDomain),
        escapeshellarg($baseDomain),
        escapeshellarg($baseDomain)
    );
} elseif ($challengeType === 'snakeoil') {
    // Generate self-signed certificate (no wildcard needed for testing)
    $certDir = "/etc/nginx/certs/{$domain}";
    $command = sprintf(
        "docker exec waf-nginx sh -c 'mkdir -p %s && openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout %s/key.pem -out %s/fullchain.pem -subj \"/CN=%s\"' 2>&1",
        escapeshellarg($certDir),
        escapeshellarg($certDir),
        escapeshellarg($certDir),
        escapeshellarg($domain)
    );
} else {
    // HTTP-01 Challenge NOT SUPPORTED for wildcard certificates
    error_log("ERROR: HTTP-01 challenge not supported for wildcard certificates. Please use dns-01 (Cloudflare) for {$domain}");
    exit(1);
}

error_log("Executing: {$command}");
exec($command, $output, $returnCode);

if ($returnCode !== 0 && $challengeType !== 'snakeoil') {
    error_log("ERROR: Failed to issue certificate for {$domain}: " . implode("\n", $output));
    exit(1);
}

error_log("Certificate issued successfully for {$domain} (base: {$baseDomain})");

// Create symlinks in nginx expected location (if not snakeoil)
if ($challengeType !== 'snakeoil') {
    // Certificate is stored at base domain path (e.g., /acme.sh/example.com/)
    // This covers both example.com and *.example.com
    
    if ($domain !== $baseDomain) {
        // This is a subdomain, create symlink to base domain certificate
        $linkCommand = sprintf(
            "docker exec waf-nginx sh -c 'mkdir -p /etc/nginx/certs/%s && ln -sf /acme.sh/%s/fullchain.pem /etc/nginx/certs/%s/fullchain.pem && ln -sf /acme.sh/%s/key.pem /etc/nginx/certs/%s/key.pem' 2>&1",
            escapeshellarg($domain),
            escapeshellarg($baseDomain),  // Point to base domain cert
            escapeshellarg($domain),
            escapeshellarg($baseDomain),  // Point to base domain cert
            escapeshellarg($domain)
        );
        exec($linkCommand, $linkOutput);
        error_log("Created symlink for subdomain {$domain} -> {$baseDomain} certificate");
    } else {
        // This is the base domain, create normal symlink
        $linkCommand = sprintf(
            "docker exec waf-nginx sh -c 'mkdir -p /etc/nginx/certs/%s && ln -sf /acme.sh/%s/fullchain.pem /etc/nginx/certs/%s/fullchain.pem && ln -sf /acme.sh/%s/key.pem /etc/nginx/certs/%s/key.pem' 2>&1",
            escapeshellarg($domain),
            escapeshellarg($baseDomain),
            escapeshellarg($domain),
            escapeshellarg($baseDomain),
            escapeshellarg($domain)
        );
        exec($linkCommand, $linkOutput);
        error_log("Created certificate symlink for base domain {$baseDomain}");
    }
}

// Reload nginx to use new certificate
exec("docker exec waf-nginx nginx -s reload 2>&1", $reloadOutput);
error_log("Reloaded NGINX for {$domain}");

error_log("Certificate issuance complete for {$domain} (covers: {$baseDomain} and *.{$baseDomain})");
exit(0);
