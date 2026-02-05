<?php
// Auto-renewal script for Let's Encrypt certificates
// Run daily via cron

error_log("=== Certificate Auto-Renewal Started ===");

// Renew all certificates (acme.sh handles checking if renewal is needed)
// NOTE: acme.sh home is /acme.sh (mounted from waf-certs volume), NOT /root/.acme.sh
$command = "docker exec waf-acme sh -c 'acme.sh --cron --home /acme.sh' 2>&1";
exec($command, $output, $returnCode);

if ($returnCode !== 0) {
    error_log("ERROR: Certificate renewal failed: " . implode("\n", $output));
} else {
    error_log("Certificate renewal check completed: " . implode("\n", $output));
    
    // Reload nginx if certificates were renewed
    if (strpos(implode("\n", $output), 'Reload') !== false || 
        strpos(implode("\n", $output), 'renewed') !== false ||
        strpos(implode("\n", $output), 'Cert success') !== false) {
        exec("docker exec waf-nginx nginx -s reload 2>&1");
        error_log("NGINX reloaded after certificate renewal");
    }
}

error_log("=== Certificate Auto-Renewal Completed ===");
