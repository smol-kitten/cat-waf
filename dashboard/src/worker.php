#!/usr/bin/env php
<?php
/**
 * Background Job Worker
 * Processes jobs from the queue
 */

require_once __DIR__ . '/config.php';

$running = true;
$sleepTime = 5; // seconds between checks

echo "Job worker started. Waiting for jobs...\n";

// Parse ModSecurity audit logs on startup and periodically
parseModSecurityAuditLog();

while ($running) {
    
    try {
        $db = getDB();
        
        // Get next pending job with highest priority
        $stmt = $db->query("
            SELECT * FROM jobs 
            WHERE status = 'pending' 
            AND attempts < max_attempts
            ORDER BY priority DESC, created_at ASC 
            LIMIT 1
        ");
        
        $job = $stmt->fetch();
        
        if ($job) {
            processJob($db, $job);
        } else {
            // No jobs, sleep
            sleep($sleepTime);
        }
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        sleep($sleepTime);
    }
}

echo "Job worker stopped.\n";

function processJob($db, $job) {
    echo "Processing job {$job['id']}: {$job['type']}\n";
    
    // Mark as running
    $stmt = $db->prepare("UPDATE jobs SET status = 'running', started_at = NOW() WHERE id = ?");
    $stmt->execute([$job['id']]);
    
    try {
        switch ($job['type']) {
            case 'parse_logs':
                parseModSecurityAuditLog();
                break;
            default:
                echo "Unknown job type: {$job['type']}\n";
        }
        
        // Mark as completed
        $stmt = $db->prepare("UPDATE jobs SET status = 'completed', completed_at = NOW() WHERE id = ?");
        $stmt->execute([$job['id']]);
        
    } catch (Exception $e) {
        echo "Job {$job['id']} failed: {$e->getMessage()}\n";
        
        // Mark as failed
        $stmt = $db->prepare("UPDATE jobs SET status = 'failed', error = ?, attempts = attempts + 1 WHERE id = ?");
        $stmt->execute([$e->getMessage(), $job['id']]);
    }
}

function parseModSecurityAuditLog() {
    global $db;
    
    $logFile = '/var/log/modsec/modsec_audit.log';
    if (!file_exists($logFile)) {
        return;
    }
    
    // Read last 1000 lines
    $output = shell_exec("tail -1000 {$logFile}");
    if (!$output) return;
    
    // Parse audit log entries
    $entries = explode("\n---", $output);
    
    foreach ($entries as $entry) {
        // Match blocked requests with anomaly scores
        if (preg_match('/Inbound Anomaly Score Exceeded \(Total Score: (\d+)\)/', $entry, $scoreMatch) &&
            preg_match('/\[hostname "([^"]+)"\]/', $entry, $hostMatch) &&
            preg_match('/\[uri "([^"]+)"\]/', $entry, $uriMatch) &&
            preg_match('/\[unique_id "([^"]+)"\]/', $entry, $idMatch)) {
            
            $uniqueId = $idMatch[1];
            $hostname = $hostMatch[1];
            $uri = $uriMatch[1];
            $score = intval($scoreMatch[1]);
            
            // Extract IP from section B
            $ip = '0.0.0.0';
            if (preg_match('/(\d+\.\d+\.\d+\.\d+) \d+ \d+\.\d+\.\d+\.\d+ \d+/', $entry, $ipMatch)) {
                $ip = $ipMatch[1];
            }
            
            // Check if already exists
            $stmt = $db->prepare("SELECT COUNT(*) as c FROM modsec_events WHERE unique_id = ?");
            $stmt->execute([$uniqueId]);
            if ($stmt->fetch()['c'] > 0) continue;
            
            // Extract triggered rules
            $rules = [];
            if (preg_match_all('/\[id "(\d+)"\].*?\[msg "([^"]+)"\].*?\[severity "(\d+)"\]/', $entry, $ruleMatches, PREG_SET_ORDER)) {
                foreach ($ruleMatches as $match) {
                    if ($match[1] == '949110') continue; // Skip blocking evaluation rule
                    $rules[] = [
                        'id' => $match[1],
                        'msg' => $match[2],
                        'severity' => $match[3]
                    ];
                }
            }
            
            // Insert main event
            $stmt = $db->prepare("
                INSERT INTO modsec_events (unique_id, timestamp, ip_address, hostname, uri, anomaly_score, action, rules_triggered)
                VALUES (?, NOW(), ?, ?, ?, ?, 'BLOCKED', ?)
            ");
            $stmt->execute([$uniqueId, $ip, $hostname, $uri, $score, json_encode($rules)]);
            
            echo "Logged ModSecurity event: {$hostname}{$uri} (Score: {$score}, Rules: " . count($rules) . ")\n";
        }
    }
}

function provisionCertificate($payload) {
    $domain = $payload['domain'] ?? '';
    $email = $payload['email'] ?? getenv('ACME_EMAIL') ?: 'admin@example.com';
    
    if (!$domain) {
        throw new Exception('Domain is required for certificate provisioning');
    }
    
    echo "Provisioning certificate for $domain...\n";
    
    // Check for Cloudflare credentials
    $cfApiKey = getenv('CF_API_KEY');
    $cfEmail = getenv('CF_EMAIL');
    $useDnsChallenge = !empty($cfApiKey) && !empty($cfEmail);
    
    if ($useDnsChallenge) {
        // Use DNS challenge with Cloudflare
        $credsFile = '/tmp/cloudflare_' . md5($domain) . '.ini';
        file_put_contents($credsFile, "dns_cloudflare_api_key = $cfApiKey\n");
        
        $cmd = "docker run --rm " .
               "-v /tmp/acme-certs:/etc/letsencrypt " .
               "-v $credsFile:/tmp/cloudflare.ini " .
               "certbot/dns-cloudflare certbot certonly " .
               "--dns-cloudflare " .
               "--dns-cloudflare-credentials /tmp/cloudflare.ini " .
               "--email $email " .
               "--agree-tos " .
               "--non-interactive " .
               "-d $domain";
        
        exec($cmd, $output, $returnCode);
        unlink($credsFile);
    } else {
        // Use HTTP challenge
        $cmd = "docker run --rm " .
               "-v /tmp/acme-certs:/etc/letsencrypt " .
               "-v /var/www/acme-challenge:/var/www/acme-challenge " .
               "certbot/certbot certonly " .
               "--webroot " .
               "--webroot-path /var/www/acme-challenge " .
               "--email $email " .
               "--agree-tos " .
               "--non-interactive " .
               "-d $domain";
        
        exec($cmd, $output, $returnCode);
    }
    
    if ($returnCode !== 0) {
        throw new Exception("Certificate provisioning failed: " . implode("\n", $output));
    }
    
    // Reload NGINX
    exec("docker exec nginx-waf nginx -s reload", $output, $returnCode);
    
    echo "Certificate provisioned successfully for $domain\n";
}

function regenerateConfig($payload) {
    echo "Regenerating NGINX configuration...\n";
    
    // Trigger config regeneration via API
    $ch = curl_init('http://localhost/api/sites/reload');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("Config regeneration failed: HTTP $httpCode");
    }
    
    echo "Configuration regenerated successfully\n";
}
