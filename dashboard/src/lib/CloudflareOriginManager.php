<?php
/**
 * Cloudflare Origin Certificate Manager
 * Handles fallback certificates for when Let's Encrypt/ZeroSSL fails
 */

class CloudflareOriginManager
{
    private PDO $pdo;
    private string $certsPath = '/etc/nginx/ssl/cf-origin';
    private ?string $encryptionKey = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->loadEncryptionKey();
        
        if (!is_dir($this->certsPath)) {
            mkdir($this->certsPath, 0700, true);
        }
    }

    private function loadEncryptionKey(): void
    {
        $stmt = $this->pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'cf_origin_encryption_key'");
        $key = $stmt->fetchColumn();
        
        if (empty($key)) {
            // Generate a new encryption key
            $key = base64_encode(random_bytes(32));
            $stmt = $this->pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'cf_origin_encryption_key'");
            $stmt->execute([$key]);
        }
        
        $this->encryptionKey = base64_decode($key);
    }

    /**
     * Encrypt private key for storage
     */
    private function encryptPrivateKey(string $privateKey): string
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($privateKey, 'aes-256-cbc', $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt private key from storage
     */
    private function decryptPrivateKey(string $encrypted): string
    {
        $data = base64_decode($encrypted);
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        return openssl_decrypt($ciphertext, 'aes-256-cbc', $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * Upload a new Cloudflare Origin Certificate
     */
    public function uploadCertificate(int $domainId, string $certificate, string $privateKey, ?string $notes = null): array
    {
        // Validate certificate
        $certInfo = openssl_x509_parse($certificate);
        if (!$certInfo) {
            return ['success' => false, 'error' => 'Invalid certificate format'];
        }

        // Check if it's a Cloudflare Origin certificate
        $issuer = $certInfo['issuer']['O'] ?? '';
        if (strpos($issuer, 'CloudFlare') === false && strpos($issuer, 'Cloudflare') === false) {
            return ['success' => false, 'error' => 'Certificate does not appear to be a Cloudflare Origin certificate'];
        }

        // Validate private key matches certificate
        $keyResource = openssl_pkey_get_private($privateKey);
        if (!$keyResource) {
            return ['success' => false, 'error' => 'Invalid private key format'];
        }

        $certResource = openssl_x509_read($certificate);
        if (!openssl_x509_check_private_key($certResource, $keyResource)) {
            return ['success' => false, 'error' => 'Private key does not match certificate'];
        }

        // Get expiration date
        $expiresAt = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);

        // Check if already expired
        if ($certInfo['validTo_time_t'] < time()) {
            return ['success' => false, 'error' => 'Certificate has already expired'];
        }

        // Encrypt private key
        $encryptedKey = $this->encryptPrivateKey($privateKey);

        // Deactivate any existing active certificates for this domain
        $stmt = $this->pdo->prepare("UPDATE cf_origin_certificates SET is_active = 0 WHERE domain_id = ?");
        $stmt->execute([$domainId]);

        // Store the certificate
        $stmt = $this->pdo->prepare("
            INSERT INTO cf_origin_certificates 
            (domain_id, certificate, private_key_encrypted, expires_at, is_active, notes)
            VALUES (?, ?, ?, ?, 1, ?)
        ");
        $stmt->execute([$domainId, $certificate, $encryptedKey, $expiresAt, $notes]);

        $certId = $this->pdo->lastInsertId();

        return [
            'success' => true,
            'id' => $certId,
            'expires_at' => $expiresAt,
            'subject' => $certInfo['subject']['CN'] ?? 'Unknown'
        ];
    }

    /**
     * Get certificate info for a domain
     */
    public function getCertificateInfo(int $domainId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, domain_id, certificate, expires_at, is_active, uploaded_at, last_used, notes
            FROM cf_origin_certificates 
            WHERE domain_id = ? AND is_active = 1
            ORDER BY uploaded_at DESC
            LIMIT 1
        ");
        $stmt->execute([$domainId]);
        $cert = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cert) {
            return null;
        }

        // Parse certificate for additional info
        $certInfo = openssl_x509_parse($cert['certificate']);
        
        return [
            'id' => $cert['id'],
            'domain_id' => $cert['domain_id'],
            'subject' => $certInfo['subject']['CN'] ?? 'Unknown',
            'issuer' => $certInfo['issuer']['CN'] ?? 'Unknown',
            'expires_at' => $cert['expires_at'],
            'days_until_expiry' => (int) ((strtotime($cert['expires_at']) - time()) / 86400),
            'is_active' => (bool) $cert['is_active'],
            'uploaded_at' => $cert['uploaded_at'],
            'last_used' => $cert['last_used'],
            'notes' => $cert['notes'],
            'san' => $certInfo['extensions']['subjectAltName'] ?? null
        ];
    }

    /**
     * List all certificates for a domain
     */
    public function listCertificates(int $domainId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, domain_id, certificate, expires_at, is_active, uploaded_at, last_used, notes
            FROM cf_origin_certificates 
            WHERE domain_id = ?
            ORDER BY is_active DESC, uploaded_at DESC
        ");
        $stmt->execute([$domainId]);
        $certs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($cert) {
            $certInfo = openssl_x509_parse($cert['certificate']);
            return [
                'id' => $cert['id'],
                'subject' => $certInfo['subject']['CN'] ?? 'Unknown',
                'expires_at' => $cert['expires_at'],
                'days_until_expiry' => (int) ((strtotime($cert['expires_at']) - time()) / 86400),
                'is_active' => (bool) $cert['is_active'],
                'uploaded_at' => $cert['uploaded_at'],
                'last_used' => $cert['last_used']
            ];
        }, $certs);
    }

    /**
     * Delete a certificate
     */
    public function deleteCertificate(int $certId): bool
    {
        // Get domain to clean up files
        $stmt = $this->pdo->prepare("SELECT domain_id FROM cf_origin_certificates WHERE id = ?");
        $stmt->execute([$certId]);
        $domainId = $stmt->fetchColumn();

        if ($domainId) {
            $this->removeDeployedCertificate($domainId);
        }

        $stmt = $this->pdo->prepare("DELETE FROM cf_origin_certificates WHERE id = ?");
        return $stmt->execute([$certId]);
    }

    /**
     * Activate fallback mode for a domain
     */
    public function activateFallback(int $domainId, string $reason = ''): array
    {
        $cert = $this->getCertificateForDeployment($domainId);
        if (!$cert) {
            return ['success' => false, 'error' => 'No Cloudflare Origin certificate available for this domain'];
        }

        // Deploy the certificate
        $deployed = $this->deployCertificate($domainId, $cert['certificate'], $cert['private_key']);
        if (!$deployed['success']) {
            return $deployed;
        }

        // Log the fallback
        $stmt = $this->pdo->prepare("
            INSERT INTO cert_fallback_log (domain_id, primary_cert_error)
            VALUES (?, ?)
        ");
        $stmt->execute([$domainId, $reason]);

        // Update last_used
        $stmt = $this->pdo->prepare("UPDATE cf_origin_certificates SET last_used = NOW() WHERE id = ?");
        $stmt->execute([$cert['id']]);

        // Send alert if configured
        $this->sendFallbackAlert($domainId, $reason);

        return ['success' => true, 'message' => 'Cloudflare Origin certificate activated'];
    }

    /**
     * Deactivate fallback mode (restore primary certificate)
     */
    public function deactivateFallback(int $domainId): array
    {
        // Mark fallback as ended
        $stmt = $this->pdo->prepare("
            UPDATE cert_fallback_log 
            SET fallback_ended = NOW(), auto_recovered = 1
            WHERE domain_id = ? AND fallback_ended IS NULL
        ");
        $stmt->execute([$domainId]);

        // Remove deployed CF origin cert (will trigger nginx to use primary)
        $this->removeDeployedCertificate($domainId);

        return ['success' => true, 'message' => 'Primary certificate restored'];
    }

    /**
     * Get certificate and key for deployment
     */
    private function getCertificateForDeployment(int $domainId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, certificate, private_key_encrypted 
            FROM cf_origin_certificates 
            WHERE domain_id = ? AND is_active = 1 AND expires_at > NOW()
            ORDER BY expires_at DESC
            LIMIT 1
        ");
        $stmt->execute([$domainId]);
        $cert = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cert) {
            return null;
        }

        return [
            'id' => $cert['id'],
            'certificate' => $cert['certificate'],
            'private_key' => $this->decryptPrivateKey($cert['private_key_encrypted'])
        ];
    }

    /**
     * Deploy certificate files for nginx
     */
    private function deployCertificate(int $domainId, string $certificate, string $privateKey): array
    {
        // Get domain name
        $stmt = $this->pdo->prepare("SELECT domain FROM domains WHERE id = ?");
        $stmt->execute([$domainId]);
        $domain = $stmt->fetchColumn();

        if (!$domain) {
            return ['success' => false, 'error' => 'Domain not found'];
        }

        $certFile = "{$this->certsPath}/{$domain}.crt";
        $keyFile = "{$this->certsPath}/{$domain}.key";

        // Write certificate files
        if (file_put_contents($certFile, $certificate) === false) {
            return ['success' => false, 'error' => 'Failed to write certificate file'];
        }

        if (file_put_contents($keyFile, $privateKey) === false) {
            unlink($certFile);
            return ['success' => false, 'error' => 'Failed to write key file'];
        }

        chmod($keyFile, 0600);

        // Trigger nginx reload
        exec('nginx -t 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            // Rollback
            unlink($certFile);
            unlink($keyFile);
            return ['success' => false, 'error' => 'Nginx config test failed: ' . implode("\n", $output)];
        }

        exec('nginx -s reload');

        return ['success' => true];
    }

    /**
     * Remove deployed CF origin certificate
     */
    private function removeDeployedCertificate(int $domainId): void
    {
        $stmt = $this->pdo->prepare("SELECT domain FROM domains WHERE id = ?");
        $stmt->execute([$domainId]);
        $domain = $stmt->fetchColumn();

        if ($domain) {
            $certFile = "{$this->certsPath}/{$domain}.crt";
            $keyFile = "{$this->certsPath}/{$domain}.key";

            if (file_exists($certFile)) unlink($certFile);
            if (file_exists($keyFile)) unlink($keyFile);
        }
    }

    /**
     * Check if a domain is currently using fallback
     */
    public function isUsingFallback(int $domainId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM cert_fallback_log 
            WHERE domain_id = ? AND fallback_ended IS NULL
        ");
        $stmt->execute([$domainId]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Get fallback history for a domain
     */
    public function getFallbackHistory(int $domainId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM cert_fallback_log 
            WHERE domain_id = ?
            ORDER BY fallback_started DESC
            LIMIT ?
        ");
        $stmt->execute([$domainId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Send fallback alert
     */
    private function sendFallbackAlert(int $domainId, string $reason): void
    {
        $stmt = $this->pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'cf_origin_alert_on_fallback'");
        $shouldAlert = $stmt->fetchColumn() === 'true';

        if (!$shouldAlert) {
            return;
        }

        // Get domain name
        $stmt = $this->pdo->prepare("SELECT domain FROM domains WHERE id = ?");
        $stmt->execute([$domainId]);
        $domain = $stmt->fetchColumn();

        // Use existing notification system if available
        if (class_exists('WebhookNotifier')) {
            $notifier = new WebhookNotifier($this->pdo);
            $notifier->send('certificate_fallback', [
                'domain' => $domain,
                'reason' => $reason,
                'message' => "Domain {$domain} is now using Cloudflare Origin certificate as fallback. Reason: {$reason}"
            ]);
        }
    }

    /**
     * Check primary certificate health and auto-fallback if needed
     */
    public function checkAndFallback(int $domainId): array
    {
        // Get domain
        $stmt = $this->pdo->prepare("SELECT domain, ssl_enabled FROM domains WHERE id = ?");
        $stmt->execute([$domainId]);
        $domain = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$domain || !$domain['ssl_enabled']) {
            return ['success' => true, 'status' => 'ssl_disabled'];
        }

        // Check if auto-fallback is enabled
        $stmt = $this->pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'cf_origin_auto_fallback'");
        $autoFallback = $stmt->fetchColumn() === 'true';

        if (!$autoFallback) {
            return ['success' => true, 'status' => 'auto_fallback_disabled'];
        }

        // Check primary certificate
        $primaryCertPath = "/etc/letsencrypt/live/{$domain['domain']}/fullchain.pem";
        $primaryCertValid = $this->isPrimaryCertValid($primaryCertPath);

        if ($primaryCertValid) {
            // If we were using fallback, deactivate it
            if ($this->isUsingFallback($domainId)) {
                $this->deactivateFallback($domainId);
                return ['success' => true, 'status' => 'restored_primary'];
            }
            return ['success' => true, 'status' => 'primary_valid'];
        }

        // Primary cert is invalid, activate fallback if available
        if (!$this->isUsingFallback($domainId)) {
            $result = $this->activateFallback($domainId, 'Primary certificate invalid or expired');
            if ($result['success']) {
                return ['success' => true, 'status' => 'fallback_activated'];
            }
            return ['success' => false, 'status' => 'fallback_failed', 'error' => $result['error']];
        }

        return ['success' => true, 'status' => 'already_using_fallback'];
    }

    /**
     * Check if primary certificate is valid
     */
    private function isPrimaryCertValid(string $certPath): bool
    {
        if (!file_exists($certPath)) {
            return false;
        }

        $cert = file_get_contents($certPath);
        $certInfo = openssl_x509_parse($cert);

        if (!$certInfo) {
            return false;
        }

        // Check expiration (with 1 day buffer)
        $expiresIn = $certInfo['validTo_time_t'] - time();
        if ($expiresIn < 86400) {
            return false;
        }

        return true;
    }

    /**
     * Get settings
     */
    public function getSettings(): array
    {
        $stmt = $this->pdo->query("
            SELECT setting_key, setting_value 
            FROM settings 
            WHERE setting_key LIKE 'cf_origin_%' AND setting_key != 'cf_origin_encryption_key'
        ");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    /**
     * Update settings
     */
    public function updateSettings(array $settings): int
    {
        $updated = 0;
        foreach ($settings as $key => $value) {
            if (strpos($key, 'cf_origin_') === 0 && $key !== 'cf_origin_encryption_key') {
                $stmt = $this->pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                if ($stmt->execute([$value, $key])) {
                    $updated++;
                }
            }
        }
        return $updated;
    }
}
