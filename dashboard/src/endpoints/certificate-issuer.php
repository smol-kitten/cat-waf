<?php
// Background certificate issuer
// Called asynchronously to issue certificates without blocking API responses

if ($argc < 3) {
    error_log("Usage: php certificate-issuer.php <domain> <challenge_type> [cf_api_token] [cf_zone_id]");
    exit(1);
}

$domain = $argv[1];
$challengeType = $argv[2];
$cfApiToken = $argv[3] ?? '';
$cfZoneId = $argv[4] ?? '';

error_log("Starting certificate issuance for {$domain} using {$challengeType}");

// Wait a bit for NGINX config to be written and reloaded
sleep(5);

// Build acme.sh command based on challenge type
if ($challengeType === 'dns-01') {
    // DNS Challenge with Cloudflare
    if (empty($cfApiToken)) {
        $cfApiToken = getenv('CF_API_KEY');
    }
    
    if (empty($cfApiToken)) {
        error_log("ERROR: Cloudflare API token not configured for {$domain}");
        exit(1);
    }
    
    $command = sprintf(
        "docker exec waf-acme sh -c 'export CF_Token=%s CF_Zone_ID=%s; /root/.acme.sh/acme.sh --issue --dns dns_cf -d %s --server letsencrypt --cert-home /acme.sh/%s --key-file /acme.sh/%s/key.pem --fullchain-file /acme.sh/%s/fullchain.pem' 2>&1",
        escapeshellarg($cfApiToken),
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

error_log("Executing: {$command}");
exec($command, $output, $returnCode);

if ($returnCode !== 0 && $challengeType !== 'snakeoil') {
    error_log("ERROR: Failed to issue certificate for {$domain}: " . implode("\n", $output));
    exit(1);
}

error_log("Certificate issued successfully for {$domain}");

// Create symlinks in nginx expected location (if not snakeoil)
if ($challengeType !== 'snakeoil') {
    $linkCommand = sprintf(
        "docker exec waf-nginx sh -c 'mkdir -p /etc/nginx/certs/%s && ln -sf /acme.sh/%s/fullchain.pem /etc/nginx/certs/%s/fullchain.pem && ln -sf /acme.sh/%s/key.pem /etc/nginx/certs/%s/key.pem' 2>&1",
        escapeshellarg($domain),
        escapeshellarg($domain),
        escapeshellarg($domain),
        escapeshellarg($domain),
        escapeshellarg($domain)
    );
    exec($linkCommand, $linkOutput);
    error_log("Created symlinks for {$domain}");
}

// Reload nginx to use new certificate
exec("docker exec waf-nginx nginx -s reload 2>&1", $reloadOutput);
error_log("Reloaded NGINX for {$domain}");

error_log("Certificate issuance complete for {$domain}");
exit(0);
