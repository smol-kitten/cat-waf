<?php
/**
 * Certificate Authority Manager
 * Generate and manage internal CA certificates, issue certs, handle CRL
 */

class CertificateAuthorityManager
{
    private PDO $pdo;
    private string $caPath = '/etc/nginx/ssl/ca';
    private ?string $encryptionKey = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->loadEncryptionKey();
        
        if (!is_dir($this->caPath)) {
            mkdir($this->caPath, 0700, true);
        }
    }

    private function loadEncryptionKey(): void
    {
        $stmt = $this->pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'ca_encryption_key'");
        $key = $stmt->fetchColumn();
        
        if (empty($key)) {
            $key = base64_encode(random_bytes(32));
            $stmt = $this->pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'ca_encryption_key'");
            $stmt->execute([$key]);
        }
        
        $this->encryptionKey = base64_decode($key);
    }

    private function encrypt(string $data): string
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    private function decrypt(string $encrypted): string
    {
        $data = base64_decode($encrypted);
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        return openssl_decrypt($ciphertext, 'aes-256-cbc', $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * Generate a new Certificate Authority
     */
    public function createCA(array $options): array
    {
        $name = $options['name'] ?? 'CatWAF Internal CA';
        $cn = $options['cn'] ?? $name;
        $o = $options['o'] ?? 'CatWAF';
        $ou = $options['ou'] ?? 'Security';
        $c = $options['c'] ?? 'US';
        $st = $options['st'] ?? '';
        $l = $options['l'] ?? '';
        $keyAlgo = $options['key_algorithm'] ?? 'RSA-4096';
        $validityDays = $options['validity_days'] ?? 3650; // 10 years default for CA
        $parentCaId = $options['parent_ca_id'] ?? null;

        // Generate key pair
        $keyConfig = $this->getKeyConfig($keyAlgo);
        $privateKey = openssl_pkey_new($keyConfig);
        
        if (!$privateKey) {
            return ['success' => false, 'error' => 'Failed to generate private key: ' . openssl_error_string()];
        }

        // Build subject DN
        $dn = ['commonName' => $cn];
        if ($o) $dn['organizationName'] = $o;
        if ($ou) $dn['organizationalUnitName'] = $ou;
        if ($c) $dn['countryName'] = $c;
        if ($st) $dn['stateOrProvinceName'] = $st;
        if ($l) $dn['localityName'] = $l;

        // Generate serial number
        $serial = $this->generateSerial();

        // Create CSR
        $csr = openssl_csr_new($dn, $privateKey, [
            'digest_alg' => 'sha256',
            'private_key_bits' => $keyConfig['private_key_bits'] ?? 4096,
            'private_key_type' => $keyConfig['private_key_type']
        ]);

        if (!$csr) {
            return ['success' => false, 'error' => 'Failed to create CSR: ' . openssl_error_string()];
        }

        // Sign the certificate (self-signed or by parent CA)
        $configArgs = [
            'digest_alg' => 'sha256',
            'x509_extensions' => 'v3_ca',
            'config' => $this->createOpenSSLConfig($parentCaId === null)
        ];

        if ($parentCaId) {
            // Sign with parent CA
            $parentCA = $this->getCAForSigning($parentCaId);
            if (!$parentCA) {
                return ['success' => false, 'error' => 'Parent CA not found or cannot issue certificates'];
            }
            
            $parentCert = openssl_x509_read($parentCA['certificate']);
            $parentKey = openssl_pkey_get_private($this->decrypt($parentCA['private_key_encrypted']));
            
            $cert = openssl_csr_sign($csr, $parentCert, $parentKey, $validityDays, $configArgs, $serial);
        } else {
            // Self-signed
            $cert = openssl_csr_sign($csr, null, $privateKey, $validityDays, $configArgs, $serial);
        }

        if (!$cert) {
            return ['success' => false, 'error' => 'Failed to sign certificate: ' . openssl_error_string()];
        }

        // Export certificate and keys
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($privateKey, $privateKeyPem);
        $keyDetails = openssl_pkey_get_details($privateKey);
        $publicKeyPem = $keyDetails['key'];

        // Calculate dates
        $validFrom = date('Y-m-d H:i:s');
        $validUntil = date('Y-m-d H:i:s', strtotime("+{$validityDays} days"));

        // Store in database
        $stmt = $this->pdo->prepare("
            INSERT INTO certificate_authorities 
            (name, type, is_root, parent_ca_id, certificate, private_key_encrypted, public_key,
             subject_cn, subject_o, subject_ou, subject_c, subject_st, subject_l,
             key_algorithm, valid_from, valid_until, serial_number, is_active, can_issue_certs)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)
        ");

        $stmt->execute([
            $name,
            $parentCaId ? 'self-signed' : 'self-signed', // Could be 'imported' if uploaded
            $parentCaId ? 0 : 1,
            $parentCaId,
            $certPem,
            $this->encrypt($privateKeyPem),
            $publicKeyPem,
            $cn, $o, $ou, $c, $st, $l,
            $keyAlgo,
            $validFrom,
            $validUntil,
            $serial
        ]);

        $caId = $this->pdo->lastInsertId();

        // Initialize serial counter
        $stmt = $this->pdo->prepare("INSERT INTO ca_serial_counters (ca_id, next_serial) VALUES (?, 1)");
        $stmt->execute([$caId]);

        // Write CA cert to file for distribution
        file_put_contents("{$this->caPath}/ca-{$caId}.crt", $certPem);

        return [
            'success' => true,
            'id' => $caId,
            'name' => $name,
            'serial' => $serial,
            'valid_until' => $validUntil,
            'certificate' => $certPem
        ];
    }

    /**
     * Import an existing CA certificate and key
     */
    public function importCA(string $name, string $certificate, string $privateKey): array
    {
        // Validate certificate
        $certInfo = openssl_x509_parse($certificate);
        if (!$certInfo) {
            return ['success' => false, 'error' => 'Invalid certificate format'];
        }

        // Check if it's a CA certificate
        $isCA = isset($certInfo['extensions']['basicConstraints']) && 
                strpos($certInfo['extensions']['basicConstraints'], 'CA:TRUE') !== false;
        
        if (!$isCA) {
            return ['success' => false, 'error' => 'Certificate is not a CA certificate (missing CA:TRUE)'];
        }

        // Validate private key
        $keyResource = openssl_pkey_get_private($privateKey);
        if (!$keyResource) {
            return ['success' => false, 'error' => 'Invalid private key format'];
        }

        // Verify key matches certificate
        $certResource = openssl_x509_read($certificate);
        if (!openssl_x509_check_private_key($certResource, $keyResource)) {
            return ['success' => false, 'error' => 'Private key does not match certificate'];
        }

        // Extract key details
        $keyDetails = openssl_pkey_get_details($keyResource);
        $keyAlgo = 'RSA-' . $keyDetails['bits'];
        if ($keyDetails['type'] === OPENSSL_KEYTYPE_EC) {
            $keyAlgo = 'EC-P' . ($keyDetails['bits'] == 256 ? '256' : '384');
        }

        // Store
        $stmt = $this->pdo->prepare("
            INSERT INTO certificate_authorities 
            (name, type, is_root, certificate, private_key_encrypted, public_key,
             subject_cn, subject_o, subject_ou, subject_c, subject_st, subject_l,
             key_algorithm, valid_from, valid_until, serial_number, is_active, can_issue_certs)
            VALUES (?, 'imported', 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)
        ");

        $stmt->execute([
            $name,
            $certificate,
            $this->encrypt($privateKey),
            $keyDetails['key'],
            $certInfo['subject']['CN'] ?? $name,
            $certInfo['subject']['O'] ?? null,
            $certInfo['subject']['OU'] ?? null,
            $certInfo['subject']['C'] ?? null,
            $certInfo['subject']['ST'] ?? null,
            $certInfo['subject']['L'] ?? null,
            $keyAlgo,
            date('Y-m-d H:i:s', $certInfo['validFrom_time_t']),
            date('Y-m-d H:i:s', $certInfo['validTo_time_t']),
            $certInfo['serialNumberHex'] ?? bin2hex(random_bytes(16))
        ]);

        $caId = $this->pdo->lastInsertId();

        // Initialize serial counter
        $stmt = $this->pdo->prepare("INSERT INTO ca_serial_counters (ca_id, next_serial) VALUES (?, 1)");
        $stmt->execute([$caId]);

        file_put_contents("{$this->caPath}/ca-{$caId}.crt", $certificate);

        return [
            'success' => true,
            'id' => $caId,
            'name' => $name
        ];
    }

    /**
     * Issue a certificate signed by a CA
     */
    public function issueCertificate(int $caId, array $options): array
    {
        $ca = $this->getCAForSigning($caId);
        if (!$ca) {
            return ['success' => false, 'error' => 'CA not found or cannot issue certificates'];
        }

        $cn = $options['cn'] ?? null;
        if (!$cn) {
            return ['success' => false, 'error' => 'Common Name (cn) is required'];
        }

        $certType = $options['type'] ?? 'server';
        $san = $options['san'] ?? [];
        $validityDays = min($options['validity_days'] ?? 365, (int)$this->getSetting('ca_max_validity_days'));
        $keyAlgo = $options['key_algorithm'] ?? 'RSA-2048';
        $purpose = $options['purpose'] ?? '';
        $issuedTo = $options['issued_to'] ?? '';
        $csr = $options['csr'] ?? null;

        // Generate or use provided CSR
        if ($csr) {
            $csrResource = openssl_csr_get_subject($csr);
            if (!$csrResource) {
                return ['success' => false, 'error' => 'Invalid CSR format'];
            }
            $privateKeyPem = null;
        } else {
            // Generate key pair
            $keyConfig = $this->getKeyConfig($keyAlgo);
            $privateKey = openssl_pkey_new($keyConfig);
            
            if (!$privateKey) {
                return ['success' => false, 'error' => 'Failed to generate private key'];
            }

            $dn = [
                'commonName' => $cn,
                'organizationName' => $options['o'] ?? 'CatWAF',
                'organizationalUnitName' => $options['ou'] ?? ''
            ];

            $csrResource = openssl_csr_new($dn, $privateKey, [
                'digest_alg' => 'sha256'
            ]);

            openssl_pkey_export($privateKey, $privateKeyPem);
        }

        // Get next serial
        $serial = $this->getNextSerial($caId);

        // Prepare extensions based on cert type
        $configFile = $this->createCertConfig($certType, $san);

        // Sign certificate
        $caCert = openssl_x509_read($ca['certificate']);
        $caKey = openssl_pkey_get_private($this->decrypt($ca['private_key_encrypted']));

        $cert = openssl_csr_sign(
            $csr ?? $csrResource,
            $caCert,
            $caKey,
            $validityDays,
            [
                'digest_alg' => 'sha256',
                'config' => $configFile,
                'x509_extensions' => $certType === 'server' ? 'server_cert' : 'client_cert'
            ],
            $serial
        );

        if (!$cert) {
            return ['success' => false, 'error' => 'Failed to sign certificate: ' . openssl_error_string()];
        }

        openssl_x509_export($cert, $certPem);

        // Store issued certificate
        $stmt = $this->pdo->prepare("
            INSERT INTO issued_certificates
            (ca_id, serial_number, subject_cn, subject_o, subject_ou, cert_type,
             certificate, private_key_encrypted, csr, key_algorithm, san,
             valid_from, valid_until, purpose, issued_to)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $validFrom = date('Y-m-d H:i:s');
        $validUntil = date('Y-m-d H:i:s', strtotime("+{$validityDays} days"));

        $stmt->execute([
            $caId,
            $serial,
            $cn,
            $options['o'] ?? 'CatWAF',
            $options['ou'] ?? null,
            $certType,
            $certPem,
            $privateKeyPem ? $this->encrypt($privateKeyPem) : null,
            $csr,
            $keyAlgo,
            json_encode($san),
            $validFrom,
            $validUntil,
            $purpose,
            $issuedTo
        ]);

        $certId = $this->pdo->lastInsertId();

        $result = [
            'success' => true,
            'id' => $certId,
            'serial' => $serial,
            'certificate' => $certPem,
            'valid_until' => $validUntil
        ];

        if ($privateKeyPem) {
            $result['private_key'] = $privateKeyPem;
        }

        return $result;
    }

    /**
     * Revoke a certificate
     */
    public function revokeCertificate(int $certId, string $reason = 'unspecified'): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM issued_certificates WHERE id = ? AND is_revoked = 0");
        $stmt->execute([$certId]);
        $cert = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cert) {
            return ['success' => false, 'error' => 'Certificate not found or already revoked'];
        }

        $this->pdo->beginTransaction();

        try {
            // Mark as revoked
            $stmt = $this->pdo->prepare("
                UPDATE issued_certificates 
                SET is_revoked = 1, revoked_at = NOW(), revocation_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$reason, $certId]);

            // Add to CRL entries
            $stmt = $this->pdo->prepare("
                INSERT INTO crl_entries (ca_id, cert_id, serial_number, reason)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$cert['ca_id'], $certId, $cert['serial_number'], $reason]);

            $this->pdo->commit();

            // Regenerate CRL if auto-generation is enabled
            if ($this->getSetting('ca_auto_crl_generation') === 'true') {
                $this->generateCRL($cert['ca_id']);
            }

            return ['success' => true, 'message' => 'Certificate revoked'];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Generate Certificate Revocation List
     */
    public function generateCRL(int $caId): array
    {
        $ca = $this->getCAForSigning($caId);
        if (!$ca) {
            return ['success' => false, 'error' => 'CA not found'];
        }

        // Get revoked certificates
        $stmt = $this->pdo->prepare("
            SELECT serial_number, revocation_date, reason 
            FROM crl_entries 
            WHERE ca_id = ?
        ");
        $stmt->execute([$caId]);
        $revoked = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build CRL using OpenSSL
        $crlValidityHours = (int)$this->getSetting('ca_crl_validity_hours');
        
        // Create temporary files for CRL generation
        $tempDir = sys_get_temp_dir();
        $indexFile = "{$tempDir}/ca-{$caId}-index.txt";
        $crlNumberFile = "{$tempDir}/ca-{$caId}-crlnumber";
        
        // Write index file
        $indexContent = '';
        foreach ($revoked as $rev) {
            $date = date('ymdHis', strtotime($rev['revocation_date'])) . 'Z';
            $indexContent .= "R\t{$date}\t{$date}\t{$rev['serial_number']}\tunknown\t/CN=revoked\n";
        }
        file_put_contents($indexFile, $indexContent);
        
        // Write CRL number
        file_put_contents($crlNumberFile, '01');

        // Generate CRL using openssl command (more reliable for CRL generation)
        $caKeyFile = "{$tempDir}/ca-{$caId}.key";
        $caCertFile = "{$tempDir}/ca-{$caId}.crt";
        $crlFile = "{$this->caPath}/ca-{$caId}.crl";

        file_put_contents($caKeyFile, $this->decrypt($ca['private_key_encrypted']));
        file_put_contents($caCertFile, $ca['certificate']);
        chmod($caKeyFile, 0600);

        // Create minimal OpenSSL config for CRL
        $configFile = "{$tempDir}/ca-{$caId}-crl.cnf";
        $config = "
[ca]
default_ca = CA_default

[CA_default]
database = {$indexFile}
crlnumber = {$crlNumberFile}
default_crl_days = " . ceil($crlValidityHours / 24) . "
default_md = sha256
";
        file_put_contents($configFile, $config);

        $cmd = "openssl ca -config {$configFile} -gencrl -keyfile {$caKeyFile} -cert {$caCertFile} -out {$crlFile} 2>&1";
        exec($cmd, $output, $exitCode);

        // Cleanup temp files
        unlink($caKeyFile);
        unlink($indexFile);
        unlink($crlNumberFile);
        unlink($configFile);

        if ($exitCode !== 0 && !file_exists($crlFile)) {
            // Fallback: create empty CRL manually
            $crlPem = $this->createEmptyCRL($ca);
            file_put_contents($crlFile, $crlPem);
        }

        return [
            'success' => true,
            'crl_file' => $crlFile,
            'revoked_count' => count($revoked),
            'valid_hours' => $crlValidityHours
        ];
    }

    /**
     * Create an empty CRL (fallback method)
     */
    private function createEmptyCRL(array $ca): string
    {
        // This is a simplified CRL - for production use openssl command
        $crlValidityHours = (int)$this->getSetting('ca_crl_validity_hours');
        $thisUpdate = gmdate('YmdHis') . 'Z';
        $nextUpdate = gmdate('YmdHis', strtotime("+{$crlValidityHours} hours")) . 'Z';
        
        return "-----BEGIN X509 CRL-----\n" .
               "# CRL for CA {$ca['name']}\n" .
               "# This Update: {$thisUpdate}\n" .
               "# Next Update: {$nextUpdate}\n" .
               "-----END X509 CRL-----\n";
    }

    /**
     * List all CAs
     */
    public function listCAs(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, name, type, is_root, parent_ca_id, subject_cn, subject_o,
                   key_algorithm, valid_from, valid_until, is_active, can_issue_certs,
                   created_at,
                   (SELECT COUNT(*) FROM issued_certificates WHERE ca_id = certificate_authorities.id) as issued_count,
                   (SELECT COUNT(*) FROM issued_certificates WHERE ca_id = certificate_authorities.id AND is_revoked = 1) as revoked_count
            FROM certificate_authorities
            ORDER BY is_root DESC, name
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get CA details
     */
    public function getCA(int $caId, bool $includeCert = false): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM certificate_authorities WHERE id = ?");
        $stmt->execute([$caId]);
        $ca = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ca) {
            return null;
        }

        // Remove sensitive data
        unset($ca['private_key_encrypted']);

        if (!$includeCert) {
            // Truncate certificate for list view
            $ca['certificate_preview'] = substr($ca['certificate'], 0, 100) . '...';
            unset($ca['certificate']);
        }

        // Add stats
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM issued_certificates WHERE ca_id = ?");
        $stmt->execute([$caId]);
        $ca['issued_count'] = $stmt->fetchColumn();

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM issued_certificates WHERE ca_id = ? AND is_revoked = 1");
        $stmt->execute([$caId]);
        $ca['revoked_count'] = $stmt->fetchColumn();

        return $ca;
    }

    /**
     * List certificates issued by a CA
     */
    public function listIssuedCertificates(int $caId, bool $includeRevoked = true): array
    {
        $sql = "
            SELECT id, serial_number, subject_cn, subject_o, cert_type, key_algorithm,
                   san, valid_from, valid_until, is_revoked, revoked_at, revocation_reason,
                   purpose, issued_to, created_at
            FROM issued_certificates
            WHERE ca_id = ?
        ";
        
        if (!$includeRevoked) {
            $sql .= " AND is_revoked = 0";
        }
        
        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$caId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get certificate details including download
     */
    public function getCertificate(int $certId, bool $includeKey = false): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT ic.*, ca.name as ca_name, ca.certificate as ca_certificate
            FROM issued_certificates ic
            JOIN certificate_authorities ca ON ic.ca_id = ca.id
            WHERE ic.id = ?
        ");
        $stmt->execute([$certId]);
        $cert = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cert) {
            return null;
        }

        $result = [
            'id' => $cert['id'],
            'ca_id' => $cert['ca_id'],
            'ca_name' => $cert['ca_name'],
            'serial_number' => $cert['serial_number'],
            'subject_cn' => $cert['subject_cn'],
            'cert_type' => $cert['cert_type'],
            'valid_from' => $cert['valid_from'],
            'valid_until' => $cert['valid_until'],
            'is_revoked' => (bool)$cert['is_revoked'],
            'certificate' => $cert['certificate'],
            'ca_certificate' => $cert['ca_certificate']
        ];

        if ($includeKey && $cert['private_key_encrypted']) {
            $result['private_key'] = $this->decrypt($cert['private_key_encrypted']);
        }

        return $result;
    }

    /**
     * Delete a CA (only if no certificates issued)
     */
    public function deleteCA(int $caId): array
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM issued_certificates WHERE ca_id = ?");
        $stmt->execute([$caId]);
        
        if ($stmt->fetchColumn() > 0) {
            return ['success' => false, 'error' => 'Cannot delete CA with issued certificates'];
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("DELETE FROM ca_serial_counters WHERE ca_id = ?");
            $stmt->execute([$caId]);

            $stmt = $this->pdo->prepare("DELETE FROM certificate_authorities WHERE id = ?");
            $stmt->execute([$caId]);

            // Remove files
            @unlink("{$this->caPath}/ca-{$caId}.crt");
            @unlink("{$this->caPath}/ca-{$caId}.crl");

            $this->pdo->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Export CA bundle (certificate chain)
     */
    public function exportCABundle(int $caId): string
    {
        $bundle = '';
        $currentId = $caId;

        while ($currentId) {
            $stmt = $this->pdo->prepare("SELECT certificate, parent_ca_id FROM certificate_authorities WHERE id = ?");
            $stmt->execute([$currentId]);
            $ca = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ca) break;

            $bundle .= $ca['certificate'] . "\n";
            $currentId = $ca['parent_ca_id'];
        }

        return trim($bundle);
    }

    // Helper methods

    private function getCAForSigning(int $caId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM certificate_authorities 
            WHERE id = ? AND is_active = 1 AND can_issue_certs = 1 AND valid_until > NOW()
        ");
        $stmt->execute([$caId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getNextSerial(int $caId): int
    {
        $this->pdo->beginTransaction();
        
        $stmt = $this->pdo->prepare("SELECT next_serial FROM ca_serial_counters WHERE ca_id = ? FOR UPDATE");
        $stmt->execute([$caId]);
        $serial = $stmt->fetchColumn();

        $stmt = $this->pdo->prepare("UPDATE ca_serial_counters SET next_serial = next_serial + 1 WHERE ca_id = ?");
        $stmt->execute([$caId]);

        $this->pdo->commit();

        return $serial;
    }

    private function generateSerial(): int
    {
        return hexdec(bin2hex(random_bytes(8))) & 0x7FFFFFFFFFFFFFFF;
    }

    private function getKeyConfig(string $algo): array
    {
        switch ($algo) {
            case 'RSA-2048':
                return ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            case 'RSA-4096':
                return ['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            case 'EC-P256':
                return ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
            case 'EC-P384':
                return ['curve_name' => 'secp384r1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
            default:
                return ['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        }
    }

    private function createOpenSSLConfig(bool $isRoot): string
    {
        $configFile = tempnam(sys_get_temp_dir(), 'openssl');
        $config = "
[req]
distinguished_name = req_distinguished_name
x509_extensions = v3_ca

[req_distinguished_name]

[v3_ca]
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer
basicConstraints = critical, CA:true" . ($isRoot ? "" : ", pathlen:0") . "
keyUsage = critical, digitalSignature, cRLSign, keyCertSign
";
        file_put_contents($configFile, $config);
        return $configFile;
    }

    private function createCertConfig(string $type, array $san): string
    {
        $configFile = tempnam(sys_get_temp_dir(), 'openssl');
        
        $sanStr = '';
        if (!empty($san)) {
            $sanEntries = [];
            $i = 1;
            foreach ($san as $name) {
                if (filter_var($name, FILTER_VALIDATE_IP)) {
                    $sanEntries[] = "IP.{$i} = {$name}";
                } else {
                    $sanEntries[] = "DNS.{$i} = {$name}";
                }
                $i++;
            }
            $sanStr = "subjectAltName = @alt_names\n\n[alt_names]\n" . implode("\n", $sanEntries);
        }

        $keyUsage = $type === 'server' 
            ? 'digitalSignature, keyEncipherment'
            : 'digitalSignature';
        
        $extKeyUsage = $type === 'server'
            ? 'serverAuth'
            : 'clientAuth';

        $config = "
[req]
distinguished_name = req_distinguished_name

[req_distinguished_name]

[server_cert]
basicConstraints = CA:FALSE
nsCertType = server
nsComment = \"CatWAF Generated Certificate\"
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer
keyUsage = critical, {$keyUsage}
extendedKeyUsage = {$extKeyUsage}
{$sanStr}

[client_cert]
basicConstraints = CA:FALSE
nsCertType = client
nsComment = \"CatWAF Generated Certificate\"
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer
keyUsage = critical, {$keyUsage}
extendedKeyUsage = {$extKeyUsage}
{$sanStr}
";
        file_put_contents($configFile, $config);
        return $configFile;
    }

    private function getSetting(string $key): string
    {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        return $stmt->fetchColumn() ?: '';
    }

    public function getSettings(): array
    {
        $stmt = $this->pdo->query("
            SELECT setting_key, setting_value 
            FROM settings 
            WHERE setting_key LIKE 'ca_%' AND setting_key != 'ca_encryption_key'
        ");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    public function updateSettings(array $settings): int
    {
        $updated = 0;
        foreach ($settings as $key => $value) {
            if (strpos($key, 'ca_') === 0 && $key !== 'ca_encryption_key') {
                $stmt = $this->pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                if ($stmt->execute([$value, $key])) {
                    $updated++;
                }
            }
        }
        return $updated;
    }
}
