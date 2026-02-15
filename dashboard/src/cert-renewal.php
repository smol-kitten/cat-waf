<?php
/**
 * Auto-renewal script for ACME certificates.
 * Run daily via cron (task-scheduler.php).
 *
 * 1. Runs acme.sh --cron which auto-renews expiring certs in the shared volume.
 * 2. Re-links all ACME certs to nginx paths (symlinks within shared waf-certs volume).
 * 3. Reloads nginx to pick up any renewed certs.
 */

require_once __DIR__ . '/config.php';

error_log("=== Certificate Auto-Renewal Started ===");

// Step 1: Run acme.sh --cron (renews certs that are within 30 days of expiry)
$command = "docker exec waf-acme sh -c 'acme.sh --cron --home /acme.sh' 2>&1";
exec($command, $output, $returnCode);
$outputStr = implode("\n", $output);

if ($returnCode !== 0) {
    error_log("WARNING: acme.sh --cron exited with code {$returnCode}: {$outputStr}");
} else {
    error_log("acme.sh --cron completed: {$outputStr}");
}

// Step 2: Re-link all ACME certs to nginx paths
// This ensures any renewed certs have valid symlinks in the nginx cert directory.
// Uses the refactored certificates.php which creates symlinks within the shared volume.
error_log("Re-linking all certificates...");

try {
    require_once __DIR__ . '/endpoints/certificates.php';
    $db = getDB();

    $stmt = $db->query(
        "SELECT domain, ssl_challenge_type FROM sites
         WHERE ssl_enabled = 1 AND domain != '_'
         ORDER BY domain"
    );
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $linkedCount = 0;
    $processedBase = [];

    foreach ($sites as $site) {
        $domain     = sanitizeDomain($site['domain']);
        $baseDomain = extractRootDomain($domain);

        // Skip non-ACME certs
        $type = $site['ssl_challenge_type'] ?? 'dns-01';
        if ($type === 'custom' || $type === 'snakeoil') {
            continue;
        }

        // Only look up ACME paths once per base domain
        if (!isset($processedBase[$baseDomain])) {
            $processedBase[$baseDomain] = findAcmeCertPaths($baseDomain);
        }

        $acmePaths = $processedBase[$baseDomain];
        if ($acmePaths) {
            if (linkAcmeCertToNginx($domain, $acmePaths)) {
                $linkedCount++;
            }
        } else {
            // No ACME cert found — ensure at least a snakeoil fallback
            ensureCertExists($domain);
        }
    }

    error_log("Re-linked {$linkedCount} certificate(s)");
} catch (Exception $e) {
    error_log("ERROR during cert re-link: " . $e->getMessage());
}

// Step 3: Always reload nginx (cheap operation, ensures latest certs are served)
exec("docker exec waf-nginx nginx -s reload 2>&1", $reloadOutput, $reloadRc);
if ($reloadRc === 0) {
    error_log("nginx reloaded successfully");
} else {
    error_log("WARNING: nginx reload failed: " . implode("\n", $reloadOutput));
}

error_log("=== Certificate Auto-Renewal Completed ===");
