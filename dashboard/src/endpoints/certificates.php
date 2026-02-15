<?php
/**
 * CatWAF Certificate Management API — Refactored
 *
 * Architecture
 * ════════════════════════════════════════════════════════════
 * The `waf-certs` Docker volume is mounted in BOTH containers:
 *   nginx  → /etc/nginx/certs   (reads certs)
 *   acme   → /acme.sh           (writes certs)
 *
 * Both mount points map to the SAME underlying filesystem.
 * This means /acme.sh/domain_ecc/fullchain.cer is the exact same
 * file as /etc/nginx/certs/domain_ecc/fullchain.cer.
 *
 * Certificate types & storage:
 *   ACME (LE/ZeroSSL) → acme.sh writes to {domain}_ecc/
 *     We create symlinks: {domain}/fullchain.pem → ../{domain}_ecc/fullchain.cer
 *   Custom (uploaded)  → written directly to {domain}/fullchain.pem via docker exec
 *   Snakeoil (self-signed) → generated directly in {domain}/ as fallback
 *
 * nginx config always references:
 *   ssl_certificate     /etc/nginx/certs/{domain}/fullchain.pem;
 *   ssl_certificate_key /etc/nginx/certs/{domain}/key.pem;
 *
 * Safety rules:
 *   • Never reissue if cert has >30 days remaining
 *   • Never force-renew if cert was issued <7 days ago
 *   • Symlinks only — no cross-container copies
 * ════════════════════════════════════════════════════════════
 *
 * Endpoints:
 *   GET    /api/certificates/{domain}          Get certificate info
 *   POST   /api/certificates/{domain}          Issue new certificate
 *   POST   /api/certificates/{domain}/renew    Renew certificate
 *   POST   /api/certificates/renew-all         Renew all certificates
 *   POST   /api/certificates/rescan            Rescan & relink all
 *   POST   /api/certificates/rescan/{domain}   Rescan & relink one
 *   DELETE /api/certificates/{domain}          Remove certificate
 *   (Upload handled by certificate-upload.php via /api/certificates/upload)
 */

require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');


// ════════════════════════════════════════════════════════════
// ROUTING
// ════════════════════════════════════════════════════════════

$method     = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];

// renew-all
if (preg_match('#/certificates/renew-all$#', $requestUri)) {
    if ($method === 'POST') { renewAllCertificates(); }
    else { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); }
    exit;
}

// rescan (all or single domain)
if (preg_match('#/certificates/rescan(?:/([^/]+))?$#', $requestUri, $m)) {
    if ($method === 'POST') {
        !empty($m[1]) ? rescanCertificate($m[1]) : rescanAllCertificates();
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    exit;
}

// upload is routed via index.php → certificate-upload.php

// domain-specific routes
preg_match('#/certificates/([^/]+)(?:/(renew))?$#', $requestUri, $matches);
$domain = $matches[1] ?? null;
$action = $matches[2] ?? null;

if (!$domain) {
    http_response_code(400);
    echo json_encode(['error' => 'Domain parameter required']);
    exit;
}
if (!preg_match('/^[a-zA-Z0-9._-]+$/', $domain)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid domain format']);
    exit;
}
if ($domain === '_') {
    echo json_encode(['exists' => false, 'domain' => '_', 'message' => 'Default server – no certificate needed']);
    exit;
}

switch ($method) {
    case 'GET':    getCertificateInfo($domain); break;
    case 'POST':   $action === 'renew' ? renewCertificate($domain) : issueCertificate($domain); break;
    case 'DELETE':  removeCertificate($domain); break;
    default:       http_response_code(405); echo json_encode(['error' => 'Method not allowed']);
}


// ════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ════════════════════════════════════════════════════════════

/**
 * Extract registrable base domain from any subdomain.
 * sub.example.com → example.com,  sub.example.co.uk → example.co.uk
 */
if (!function_exists('extractRootDomain')) {
function extractRootDomain(string $domain): string {
    $domain = preg_replace('/^\*\./', '', $domain);
    $parts  = explode('.', $domain);
    $n      = count($parts);

    $ccSLDs = [
        'co.uk','com.au','co.nz','co.za','com.br','com.mx',
        'co.jp','co.in','co.kr','ac.uk','gov.uk','org.uk',
    ];
    if ($n >= 3 && in_array($parts[$n-2].'.'.$parts[$n-1], $ccSLDs)) {
        return implode('.', array_slice($parts, -3));
    }
    return $n >= 2 ? implode('.', array_slice($parts, -2)) : $domain;
}
}

/** Sanitize domain for shell/path use. */
if (!function_exists('sanitizeDomain')) {
function sanitizeDomain(string $domain): string {
    return preg_replace('/[^a-z0-9._-]/i', '', $domain);
}
}

/** Run a command inside a container. Returns [output, exitCode]. */
if (!function_exists('dockerExec')) {
function dockerExec(string $container, string $cmd): array {
    $full = sprintf("docker exec %s sh -c %s 2>&1",
        escapeshellarg($container),
        escapeshellarg($cmd)
    );
    exec($full, $output, $rc);
    return [implode("\n", $output), $rc];
}
}

/** Run a command with env vars via docker exec -e. Returns [output, exitCode]. */
if (!function_exists('dockerExecEnv')) {
function dockerExecEnv(string $container, array $env, string $cmd): array {
    $envStr = '';
    foreach ($env as $k => $v) {
        if ($v !== '' && $v !== null) {
            $envStr .= ' -e ' . escapeshellarg("{$k}={$v}");
        }
    }
    $full = sprintf("docker exec%s %s sh -c %s 2>&1",
        $envStr,
        escapeshellarg($container),
        escapeshellarg($cmd)
    );
    exec($full, $output, $rc);
    return [implode("\n", $output), $rc];
}
}

/**
 * Find where acme.sh stored a certificate for a domain.
 * Checks ECC and RSA directories, .cer and .pem extensions.
 *
 * Returns ['cert' => '/acme.sh/...', 'key' => '/acme.sh/...'] or null.
 */
if (!function_exists('findAcmeCertPaths')) {
function findAcmeCertPaths(string $baseDomain): ?array {
    $d = sanitizeDomain($baseDomain);

    // acme.sh stores ECC in {domain}_ecc/, RSA in {domain}/
    // Modern acme.sh defaults to ECC (ECDSA)
    $candidates = [
        ['cert' => "/acme.sh/{$d}_ecc/fullchain.cer", 'key' => "/acme.sh/{$d}_ecc/{$d}.key"],
        ['cert' => "/acme.sh/{$d}_ecc/fullchain.pem", 'key' => "/acme.sh/{$d}_ecc/key.pem"],
        ['cert' => "/acme.sh/{$d}/fullchain.cer",     'key' => "/acme.sh/{$d}/{$d}.key"],
        ['cert' => "/acme.sh/{$d}/fullchain.pem",     'key' => "/acme.sh/{$d}/key.pem"],
    ];

    foreach ($candidates as $c) {
        [$out,] = dockerExec('waf-acme',
            "test -s '{$c['cert']}' && test -s '{$c['key']}' && echo found"
        );
        if (trim($out) === 'found') {
            return $c;
        }
    }
    return null;
}
}

/**
 * Read and parse an X.509 certificate from a container.
 * Returns parsed info array or null.
 */
if (!function_exists('readCertInfo')) {
function readCertInfo(string $container, string $path): ?array {
    [$certPEM,] = dockerExec($container, "cat '{$path}'");
    if (empty($certPEM) || strpos($certPEM, 'BEGIN CERTIFICATE') === false) {
        return null;
    }

    $cert = @openssl_x509_parse($certPEM);
    if (!$cert) return null;

    $validTo   = $cert['validTo_time_t'];
    $validFrom = $cert['validFrom_time_t'];
    $now       = time();

    $issuer  = $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? 'Unknown';
    $subject = $cert['subject']['CN'] ?? '';

    // Classify cert type
    $type = 'custom';
    if ($subject === $issuer || stripos($issuer, 'snakeoil') !== false) {
        $type = 'snakeoil';
    } elseif (preg_match("/Let's Encrypt|R3|R10|R11|E1|E5|E6|E7/i", $issuer)) {
        $type = 'letsencrypt';
    } elseif (stripos($issuer, 'ZeroSSL') !== false) {
        $type = 'zerossl';
    }

    // Extract SANs
    $sans = [];
    if (isset($cert['extensions']['subjectAltName'])) {
        preg_match_all('/DNS:([^,\s]+)/', $cert['extensions']['subjectAltName'], $m);
        $sans = $m[1] ?? [];
    }

    return [
        'valid'           => ($validTo - $now) > 0,
        'type'            => $type,
        'issuer'          => $issuer,
        'subject'         => $subject,
        'sans'            => $sans,
        'validFrom'       => date('Y-m-d H:i:s', $validFrom),
        'expiryDate'      => date('Y-m-d H:i:s', $validTo),
        'daysUntilExpiry' => (int)floor(($validTo - $now) / 86400),
        'daysSinceIssued' => (int)floor(($now - $validFrom) / 86400),
    ];
}
}

/**
 * Get Cloudflare credentials for a site (falls back to env vars).
 */
if (!function_exists('getCfCredentials')) {
function getCfCredentials(array $site): array {
    $token  = $site['cf_api_token']
        ?: getenv('CLOUDFLARE_API_TOKEN')
        ?: getenv('CLOUDFLARE_API_KEY')
        ?: '';
    $zoneId = $site['cf_zone_id'] ?? '';
    return [$token, $zoneId];
}
}

/**
 * Create symlinks so nginx reads the ACME cert at the standard path.
 *
 * Since both containers share waf-certs volume:
 *   acme  writes: /acme.sh/{domain}_ecc/fullchain.cer
 *   nginx reads:  /etc/nginx/certs/{domain}_ecc/fullchain.cer  (same file!)
 *
 * We symlink:
 *   /etc/nginx/certs/{domain}/fullchain.pem  →  ../{domain}_ecc/fullchain.cer
 *   /etc/nginx/certs/{domain}/key.pem        →  ../{domain}_ecc/{domain}.key
 */
if (!function_exists('linkAcmeCertToNginx')) {
function linkAcmeCertToNginx(string $domain, array $acmePaths): bool {
    $domain = sanitizeDomain($domain);

    // Convert /acme.sh/catboy.farm_ecc/fullchain.cer → catboy.farm_ecc/fullchain.cer
    $certRel = preg_replace('#^/acme\.sh/#', '', $acmePaths['cert']);
    $keyRel  = preg_replace('#^/acme\.sh/#', '', $acmePaths['key']);

    // Relative symlinks within the shared volume
    $cmd = implode(' && ', [
        "mkdir -p '/etc/nginx/certs/{$domain}'",
        "rm -f '/etc/nginx/certs/{$domain}/fullchain.pem' '/etc/nginx/certs/{$domain}/key.pem'",
        "ln -s '../{$certRel}' '/etc/nginx/certs/{$domain}/fullchain.pem'",
        "ln -s '../{$keyRel}' '/etc/nginx/certs/{$domain}/key.pem'",
    ]);

    [$out, $rc] = dockerExec('waf-nginx', $cmd);
    if ($rc !== 0) {
        error_log("linkAcmeCertToNginx failed for {$domain}: {$out}");
        return false;
    }

    error_log("Linked ACME cert: {$domain} → {$acmePaths['cert']}");
    return true;
}
}

/**
 * Generate a self-signed (snakeoil) certificate for a domain.
 * Uses ECC key for speed & modern compatibility.
 */
if (!function_exists('generateSnakeoil')) {
function generateSnakeoil(string $domain): bool {
    $domain = sanitizeDomain($domain);
    $cmd = implode(' && ', [
        "mkdir -p '/etc/nginx/certs/{$domain}'",
        "rm -f '/etc/nginx/certs/{$domain}/fullchain.pem' '/etc/nginx/certs/{$domain}/key.pem'",
        "openssl req -x509 -nodes -days 3650"
        . " -newkey ec -pkeyopt ec_paramgen_curve:prime256v1"
        . " -keyout '/etc/nginx/certs/{$domain}/key.pem'"
        . " -out '/etc/nginx/certs/{$domain}/fullchain.pem'"
        . " -subj '/CN={$domain}/O=CatWAF Snakeoil'",
    ]);

    [$out, $rc] = dockerExec('waf-nginx', $cmd);
    if ($rc !== 0) {
        error_log("generateSnakeoil failed for {$domain}: {$out}");
        return false;
    }
    error_log("Generated snakeoil certificate for {$domain}");
    return true;
}
}

/**
 * Ensure a domain has a valid certificate at the standard nginx path.
 * Tries: existing → ACME symlink → snakeoil fallback.
 */
if (!function_exists('ensureCertExists')) {
function ensureCertExists(string $domain): bool {
    $domain = sanitizeDomain($domain);

    // Already have a readable cert? (works for files AND valid symlinks)
    [$out,] = dockerExec('waf-nginx',
        "test -e '/etc/nginx/certs/{$domain}/fullchain.pem'"
        . " && test -e '/etc/nginx/certs/{$domain}/key.pem'"
        . " && echo ok"
    );
    if (trim($out) === 'ok') return true;

    // Try linking from ACME
    $baseDomain = extractRootDomain($domain);
    $acme = findAcmeCertPaths($baseDomain);
    if ($acme && linkAcmeCertToNginx($domain, $acme)) {
        return true;
    }

    // Fallback to snakeoil
    return generateSnakeoil($domain);
}
}

/**
 * Link all subdomains of a base domain to the base domain's ACME cert.
 */
if (!function_exists('linkSubdomainsToBaseCert')) {
function linkSubdomainsToBaseCert(string $baseDomain, array $acmePaths, PDO $db): void {
    $stmt = $db->prepare(
        "SELECT domain FROM sites WHERE domain LIKE ? AND domain != ? AND ssl_enabled = 1"
    );
    $stmt->execute(["%.$baseDomain", $baseDomain]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        linkAcmeCertToNginx(sanitizeDomain($row['domain']), $acmePaths);
    }
}
}

/**
 * Regenerate nginx config for a domain.
 */
if (!function_exists('regenerateNginxConfig')) {
function regenerateNginxConfig(string $domain, PDO $db): void {
    require_once __DIR__ . '/sites.php';
    $stmt = $db->prepare("SELECT * FROM sites WHERE domain = ?");
    $stmt->execute([$domain]);
    $site = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($site) {
        generateSiteConfig($site['id'], $site);
    }
}
}

/** Reload nginx configuration. */
if (!function_exists('reloadNginx')) {
function reloadNginx(): void {
    dockerExec('waf-nginx', 'nginx -s reload');
}
}


// ════════════════════════════════════════════════════════════
// GET  /api/certificates/{domain}
// ════════════════════════════════════════════════════════════

function getCertificateInfo(string $domain): void {
    $domain     = sanitizeDomain($domain);
    $baseDomain = extractRootDomain($domain);

    // Check nginx cert (the path nginx actually uses)
    $info = readCertInfo('waf-nginx', "/etc/nginx/certs/{$domain}/fullchain.pem");

    // If no cert at domain-specific path, check base domain
    if (!$info && $domain !== $baseDomain) {
        $info = readCertInfo('waf-nginx', "/etc/nginx/certs/{$baseDomain}/fullchain.pem");
    }

    if (!$info) {
        // Check if cert exists in acme but not linked
        $acme = findAcmeCertPaths($baseDomain);
        if ($acme) {
            echo json_encode([
                'exists'      => false,
                'domain'      => $domain,
                'base_domain' => $baseDomain,
                'in_acme'     => true,
                'message'     => 'Certificate found in acme.sh but not linked to nginx. Click Rescan to fix.',
            ]);
            return;
        }

        http_response_code(404);
        echo json_encode([
            'exists'      => false,
            'domain'      => $domain,
            'base_domain' => $baseDomain,
            'message'     => 'No certificate found',
        ]);
        return;
    }

    // Check if it's a symlink (= ACME-managed)
    [$linkTarget,] = dockerExec('waf-nginx',
        "readlink '/etc/nginx/certs/{$domain}/fullchain.pem' 2>/dev/null || echo none"
    );
    $isSymlink = trim($linkTarget) !== 'none';

    echo json_encode([
        'exists'          => true,
        'domain'          => $domain,
        'base_domain'     => $baseDomain,
        'cert_type'       => $info['type'],
        'managed'         => $isSymlink ? 'acme' : ($info['type'] === 'snakeoil' ? 'snakeoil' : 'custom'),
        'cert_path'       => "/etc/nginx/certs/{$domain}/fullchain.pem",
        'issuer'          => $info['issuer'],
        'subject'         => $info['subject'],
        'sans'            => $info['sans'],
        'expiryDate'      => $info['expiryDate'],
        'daysUntilExpiry' => $info['daysUntilExpiry'],
        'validFrom'       => $info['validFrom'],
    ]);
}


// ════════════════════════════════════════════════════════════
// POST  /api/certificates/{domain}   — Issue
// ════════════════════════════════════════════════════════════

function issueCertificate(string $domain): void {
    $db         = getDB();
    $domain     = sanitizeDomain($domain);
    $baseDomain = extractRootDomain($domain);

    // Get site config
    $stmt = $db->prepare(
        "SELECT ssl_challenge_type, cf_api_token, cf_zone_id, acme_provider FROM sites WHERE domain = ?"
    );
    $stmt->execute([$domain]);
    $site = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$site) {
        http_response_code(404);
        echo json_encode(['error' => 'Site not found']);
        return;
    }

    $challengeType = $site['ssl_challenge_type'] ?? 'dns-01';

    // ── Snakeoil ──
    if ($challengeType === 'snakeoil') {
        generateSnakeoil($domain);
        $db->prepare("UPDATE sites SET ssl_enabled = 1 WHERE domain = ?")->execute([$domain]);
        regenerateNginxConfig($domain, $db);
        reloadNginx();
        echo json_encode([
            'success'   => true,
            'message'   => "Snakeoil certificate generated for {$domain}",
            'cert_type' => 'snakeoil',
        ]);
        return;
    }

    // ── Custom ──
    if ($challengeType === 'custom') {
        http_response_code(400);
        echo json_encode([
            'error' => 'This site uses custom certificates. Upload via /api/certificates/upload.',
        ]);
        return;
    }

    // ── ACME: check if valid cert already exists ──
    $acmePaths = findAcmeCertPaths($baseDomain);
    if ($acmePaths) {
        $existing = readCertInfo('waf-acme', $acmePaths['cert']);

        if ($existing && $existing['valid'] && $existing['daysUntilExpiry'] > 30) {
            // Valid cert exists — just link + enable
            linkAcmeCertToNginx($domain, $acmePaths);
            if ($domain !== $baseDomain) {
                linkAcmeCertToNginx($baseDomain, $acmePaths);
            }
            linkSubdomainsToBaseCert($baseDomain, $acmePaths, $db);
            $db->prepare("UPDATE sites SET ssl_enabled = 1 WHERE domain = ?")->execute([$domain]);
            regenerateNginxConfig($domain, $db);
            reloadNginx();

            echo json_encode([
                'success'          => true,
                'message'          => "Valid certificate already exists ({$existing['daysUntilExpiry']} days remaining). Linked to nginx.",
                'base_domain'      => $baseDomain,
                'daysUntilExpiry'  => $existing['daysUntilExpiry'],
                'expiryDate'       => $existing['expiryDate'],
                'skipped_issuance' => true,
            ]);
            return;
        }
    }

    // ── DNS-01 via Cloudflare (required for wildcard) ──
    if ($challengeType !== 'dns-01') {
        http_response_code(400);
        echo json_encode([
            'error' => "Challenge type '{$challengeType}' not supported. Use dns-01 for wildcard certificates.",
        ]);
        return;
    }

    [$cfToken, $cfZoneId] = getCfCredentials($site);
    if (empty($cfToken)) {
        http_response_code(400);
        echo json_encode(['error' => 'Cloudflare API token not configured. Required for DNS-01 challenge.']);
        return;
    }

    $acmeProvider = $site['acme_provider'] ?? getenv('ACME_PROVIDER') ?: 'letsencrypt';
    $server       = $acmeProvider === 'zerossl' ? 'zerossl' : 'letsencrypt';

    $env = ['CF_Token' => $cfToken];
    if ($cfZoneId) $env['CF_Zone_ID'] = $cfZoneId;

    $domains = sprintf("-d %s -d %s",
        escapeshellarg($baseDomain),
        escapeshellarg("*.{$baseDomain}")
    );
    $acmeCmd = "acme.sh --issue --dns dns_cf {$domains} --server {$server} --home /acme.sh --force";

    [$output, $rc] = dockerExecEnv('waf-acme', $env, $acmeCmd);

    if ($rc !== 0 && strpos($output, 'Cert success') === false) {
        http_response_code(500);
        echo json_encode([
            'error'  => 'Failed to issue certificate',
            'output' => $output,
        ]);
        return;
    }

    // Find the issued cert and link it
    $acmePaths = findAcmeCertPaths($baseDomain);
    if (!$acmePaths) {
        http_response_code(500);
        echo json_encode([
            'error'  => 'Certificate was issued but could not be located in acme.sh storage',
            'output' => $output,
        ]);
        return;
    }

    linkAcmeCertToNginx($baseDomain, $acmePaths);
    if ($domain !== $baseDomain) {
        linkAcmeCertToNginx($domain, $acmePaths);
    }
    linkSubdomainsToBaseCert($baseDomain, $acmePaths, $db);

    $db->prepare("UPDATE sites SET ssl_enabled = 1 WHERE domain = ?")->execute([$domain]);
    regenerateNginxConfig($domain, $db);
    reloadNginx();

    echo json_encode([
        'success'            => true,
        'message'            => "Certificate issued successfully for {$baseDomain}",
        'base_domain'        => $baseDomain,
        'certificate_covers' => [$baseDomain, "*.{$baseDomain}"],
        'output'             => $output,
        'challenge_method'   => 'dns-01',
    ]);
}


// ════════════════════════════════════════════════════════════
// POST  /api/certificates/{domain}/renew
// ════════════════════════════════════════════════════════════

function renewCertificate(string $domain): void {
    $db         = getDB();
    $domain     = sanitizeDomain($domain);
    $baseDomain = extractRootDomain($domain);

    $stmt = $db->prepare(
        "SELECT ssl_challenge_type, cf_api_token, cf_zone_id FROM sites WHERE domain = ?"
    );
    $stmt->execute([$domain]);
    $site = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$site) {
        http_response_code(404);
        echo json_encode(['error' => 'Site not found']);
        return;
    }

    $challengeType = $site['ssl_challenge_type'] ?? 'dns-01';

    if ($challengeType === 'snakeoil' || $challengeType === 'custom') {
        http_response_code(400);
        echo json_encode(['error' => "Cannot renew {$challengeType} certificates via ACME."]);
        return;
    }
    if ($challengeType !== 'dns-01') {
        http_response_code(400);
        echo json_encode(['error' => 'Only dns-01 (Cloudflare) challenge is supported for renewal.']);
        return;
    }

    // Check existing cert
    $acmePaths = findAcmeCertPaths($baseDomain);
    if ($acmePaths) {
        $existing = readCertInfo('waf-acme', $acmePaths['cert']);

        if ($existing) {
            // Don't renew if still valid for >30 days
            if ($existing['daysUntilExpiry'] > 30) {
                linkAcmeCertToNginx($domain, $acmePaths);
                if ($domain !== $baseDomain) linkAcmeCertToNginx($baseDomain, $acmePaths);
                reloadNginx();

                echo json_encode([
                    'success'         => true,
                    'message'         => "Certificate still valid ({$existing['daysUntilExpiry']} days remaining). No renewal needed. Links verified.",
                    'daysUntilExpiry' => $existing['daysUntilExpiry'],
                    'expiryDate'      => $existing['expiryDate'],
                    'skipped_renewal' => true,
                ]);
                return;
            }

            // Don't renew if issued <7 days ago
            if ($existing['daysSinceIssued'] < 7) {
                echo json_encode([
                    'success'         => true,
                    'message'         => "Certificate was issued {$existing['daysSinceIssued']} day(s) ago. Too recent to renew.",
                    'daysSinceIssued' => $existing['daysSinceIssued'],
                    'daysUntilExpiry' => $existing['daysUntilExpiry'],
                    'skipped_renewal' => true,
                ]);
                return;
            }
        }
    }

    // Renew via ACME
    [$cfToken, $cfZoneId] = getCfCredentials($site);
    if (empty($cfToken)) {
        http_response_code(400);
        echo json_encode(['error' => 'Cloudflare API token not configured.']);
        return;
    }

    $env = ['CF_Token' => $cfToken];
    if ($cfZoneId) $env['CF_Zone_ID'] = $cfZoneId;

    $domains = sprintf("-d %s -d %s",
        escapeshellarg($baseDomain),
        escapeshellarg("*.{$baseDomain}")
    );
    $acmeCmd = "acme.sh --issue --dns dns_cf {$domains} --server letsencrypt --home /acme.sh --force";

    [$output, $rc] = dockerExecEnv('waf-acme', $env, $acmeCmd);

    if ($rc !== 0 && strpos($output, 'Cert success') === false) {
        http_response_code(500);
        echo json_encode([
            'error'  => 'Failed to renew certificate',
            'output' => $output,
        ]);
        return;
    }

    // Re-find and re-link
    $acmePaths = findAcmeCertPaths($baseDomain);
    if ($acmePaths) {
        linkAcmeCertToNginx($baseDomain, $acmePaths);
        if ($domain !== $baseDomain) {
            linkAcmeCertToNginx($domain, $acmePaths);
        }
        linkSubdomainsToBaseCert($baseDomain, $acmePaths, $db);
    }

    reloadNginx();

    echo json_encode([
        'success'            => true,
        'message'            => "Certificate renewed for {$baseDomain}",
        'base_domain'        => $baseDomain,
        'certificate_covers' => [$baseDomain, "*.{$baseDomain}"],
        'output'             => $output,
        'challenge_method'   => 'dns-01',
    ]);
}


// ════════════════════════════════════════════════════════════
// DELETE  /api/certificates/{domain}
// ════════════════════════════════════════════════════════════

function removeCertificate(string $domain): void {
    $db     = getDB();
    $domain = sanitizeDomain($domain);

    // Remove cert files/symlinks
    dockerExec('waf-nginx', "rm -rf '/etc/nginx/certs/{$domain}'");

    // Disable SSL in DB
    $db->prepare("UPDATE sites SET ssl_enabled = 0 WHERE domain = ?")->execute([$domain]);

    regenerateNginxConfig($domain, $db);
    reloadNginx();

    echo json_encode([
        'success' => true,
        'message' => "Certificate removed and SSL disabled for {$domain}",
    ]);
}


// ════════════════════════════════════════════════════════════
// POST  /api/certificates/renew-all
// ════════════════════════════════════════════════════════════

function renewAllCertificates(): void {
    $db = getDB();
    set_time_limit(300);

    $stmt = $db->query(
        "SELECT domain, ssl_challenge_type, cf_api_token, cf_zone_id
         FROM sites
         WHERE ssl_enabled = 1
           AND ssl_challenge_type = 'dns-01'
           AND domain != '_'
         ORDER BY domain"
    );
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($sites)) {
        echo json_encode(['success' => true, 'message' => 'No ACME-managed sites found', 'renewed' => 0]);
        return;
    }

    $results      = [];
    $processed    = [];
    $renewedCount = 0;
    $skipCount    = 0;
    $failCount    = 0;

    foreach ($sites as $site) {
        $domain     = sanitizeDomain($site['domain']);
        $baseDomain = extractRootDomain($domain);

        // Skip if we already processed this base domain
        if (isset($processed[$baseDomain])) {
            $results[] = [
                'domain' => $domain,
                'status' => 'skipped',
                'reason' => "Covered by {$baseDomain} wildcard",
            ];
            continue;
        }
        $processed[$baseDomain] = true;

        // Check existing cert
        $acmePaths = findAcmeCertPaths($baseDomain);
        if ($acmePaths) {
            $info = readCertInfo('waf-acme', $acmePaths['cert']);

            if ($info && $info['daysUntilExpiry'] > 30) {
                // Still valid — just verify links
                linkAcmeCertToNginx($domain, $acmePaths);
                linkSubdomainsToBaseCert($baseDomain, $acmePaths, $db);
                $results[] = [
                    'domain'          => $domain,
                    'base_domain'     => $baseDomain,
                    'status'          => 'valid',
                    'daysUntilExpiry' => $info['daysUntilExpiry'],
                    'action'          => 'links_verified',
                ];
                $skipCount++;
                continue;
            }
        }

        // Need to renew
        [$cfToken, $cfZoneId] = getCfCredentials($site);
        if (empty($cfToken)) {
            $results[] = ['domain' => $domain, 'base_domain' => $baseDomain, 'status' => 'failed', 'error' => 'No CF token'];
            $failCount++;
            continue;
        }

        $env = ['CF_Token' => $cfToken];
        if ($cfZoneId) $env['CF_Zone_ID'] = $cfZoneId;

        $domains = sprintf("-d %s -d %s",
            escapeshellarg($baseDomain),
            escapeshellarg("*.{$baseDomain}")
        );
        $acmeCmd = "acme.sh --issue --dns dns_cf {$domains} --server letsencrypt --home /acme.sh --force";

        [$output, $rc] = dockerExecEnv('waf-acme', $env, $acmeCmd);
        $ok = ($rc === 0) || strpos($output, 'Cert success') !== false;

        if ($ok) {
            $newPaths = findAcmeCertPaths($baseDomain);
            if ($newPaths) {
                linkAcmeCertToNginx($baseDomain, $newPaths);
                linkSubdomainsToBaseCert($baseDomain, $newPaths, $db);
            }
            $results[] = ['domain' => $domain, 'base_domain' => $baseDomain, 'status' => 'renewed'];
            $renewedCount++;
        } else {
            $results[] = [
                'domain'      => $domain,
                'base_domain' => $baseDomain,
                'status'      => 'failed',
                'error'       => substr($output, -300),
            ];
            $failCount++;
        }
    }

    reloadNginx();

    echo json_encode([
        'success' => true,
        'message' => "Renewed: {$renewedCount}, Valid: {$skipCount}, Failed: {$failCount}",
        'renewed' => $renewedCount,
        'skipped' => $skipCount,
        'failed'  => $failCount,
        'results' => $results,
    ]);
}


// ════════════════════════════════════════════════════════════
// POST  /api/certificates/rescan/{domain}
// ════════════════════════════════════════════════════════════

function rescanCertificate(string $domain): void {
    $db         = getDB();
    $domain     = sanitizeDomain($domain);
    $baseDomain = extractRootDomain($domain);

    $stmt = $db->prepare("SELECT id, ssl_challenge_type, ssl_enabled FROM sites WHERE domain = ?");
    $stmt->execute([$domain]);
    $site = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$site) {
        http_response_code(404);
        echo json_encode(['error' => 'Site not found']);
        return;
    }

    $result        = ['domain' => $domain, 'base_domain' => $baseDomain, 'action' => 'none'];
    $challengeType = $site['ssl_challenge_type'] ?? 'dns-01';

    if ($challengeType === 'custom') {
        [$out,] = dockerExec('waf-nginx',
            "test -e '/etc/nginx/certs/{$domain}/fullchain.pem' && echo ok"
        );
        $result['action'] = trim($out) === 'ok' ? 'custom_cert_valid' : 'custom_cert_missing';
    } elseif ($challengeType === 'snakeoil') {
        ensureCertExists($domain);
        $result['action'] = 'snakeoil_ensured';
    } else {
        // ACME — try to link
        $acmePaths = findAcmeCertPaths($baseDomain);
        if ($acmePaths) {
            linkAcmeCertToNginx($domain, $acmePaths);
            if ($domain !== $baseDomain) {
                linkAcmeCertToNginx($baseDomain, $acmePaths);
            }
            $result['action']      = 'linked';
            $result['cert_source'] = $acmePaths['cert'];
        } else {
            ensureCertExists($domain);
            $result['action'] = 'snakeoil_fallback';
            $result['note']   = 'No ACME cert found. Snakeoil installed as fallback. Issue a certificate via SSL settings.';
        }
    }

    regenerateNginxConfig($domain, $db);
    reloadNginx();

    echo json_encode(['success' => true, 'result' => $result]);
}


// ════════════════════════════════════════════════════════════
// POST  /api/certificates/rescan   (all)
// ════════════════════════════════════════════════════════════

function rescanAllCertificates(): void {
    $db = getDB();
    set_time_limit(300);

    $stmt  = $db->query("SELECT id, domain, ssl_challenge_type, ssl_enabled FROM sites WHERE domain != '_'");
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results       = [];
    $linkedCount   = 0;
    $snakeoilCount = 0;
    $processedBase = [];

    foreach ($sites as $site) {
        $domain        = sanitizeDomain($site['domain']);
        $baseDomain    = extractRootDomain($domain);
        $r             = ['domain' => $domain, 'action' => 'none'];
        $challengeType = $site['ssl_challenge_type'] ?? 'dns-01';

        if ($challengeType === 'custom' || $challengeType === 'snakeoil') {
            ensureCertExists($domain);
            $r['action'] = $challengeType . '_ensured';
        } else {
            // ACME — cache lookup per base domain
            if (!isset($processedBase[$baseDomain])) {
                $processedBase[$baseDomain] = findAcmeCertPaths($baseDomain);
            }
            $acmePaths = $processedBase[$baseDomain];

            if ($acmePaths) {
                linkAcmeCertToNginx($domain, $acmePaths);
                $r['action'] = 'linked';
                $linkedCount++;
            } else {
                ensureCertExists($domain);
                $r['action'] = 'snakeoil_fallback';
                $snakeoilCount++;
            }
        }

        $results[] = $r;
    }

    reloadNginx();

    echo json_encode([
        'success'  => true,
        'total'    => count($sites),
        'linked'   => $linkedCount,
        'snakeoil' => $snakeoilCount,
        'results'  => $results,
    ]);
}
