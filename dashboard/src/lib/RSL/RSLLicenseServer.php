<?php
/**
 * RSL License Server (OLP - Open License Protocol)
 * Implements OAuth 2.0 based license token issuance
 */

namespace CatWAF\RSL;

require_once __DIR__ . '/Webhook.php';

use RSL\Webhook;

class RSLLicenseServer {
    private \PDO $db;
    private string $serverUrl;
    private ?Webhook $webhook = null;
    
    public function __construct(\PDO $db, string $serverUrl) {
        $this->db = $db;
        $this->serverUrl = rtrim($serverUrl, '/');
        $this->webhook = new Webhook($db);
    }
    
    /**
     * Handle /token endpoint (OLP Token Request)
     * Uses OAuth 2.0 client_credentials grant
     */
    public function handleTokenRequest(array $request): array {
        // Validate required parameters
        $grantType = $request['grant_type'] ?? null;
        $scope = $request['scope'] ?? null;
        $contentUrl = $request['content_url'] ?? null;
        
        // Validate grant type
        if ($grantType !== 'client_credentials') {
            return $this->errorResponse('unsupported_grant_type', 'Only client_credentials grant is supported');
        }
        
        // Get client credentials from Authorization header
        $clientId = $request['client_id'] ?? null;
        $clientSecret = $request['client_secret'] ?? null;
        
        if (!$clientId || !$clientSecret) {
            return $this->errorResponse('invalid_client', 'Client credentials required');
        }
        
        // Validate client
        $client = $this->validateClient($clientId, $clientSecret);
        if (!$client) {
            $this->logAccess(null, null, null, $request, 'denied', 'Invalid client credentials');
            return $this->errorResponse('invalid_client', 'Invalid client credentials');
        }
        
        // Check if client is approved
        if (!$client['approved']) {
            $this->logAccess(null, $client['id'], null, $request, 'denied', 'Client not approved');
            return $this->errorResponse('access_denied', 'Client registration pending approval');
        }
        
        // Validate scope against client's allowed scopes
        $requestedScopes = $scope ? explode(' ', $scope) : [];
        $allowedScopes = json_decode($client['allowed_scopes'] ?? '[]', true) ?: [];
        
        if (!empty($allowedScopes) && !empty($requestedScopes)) {
            foreach ($requestedScopes as $s) {
                if (!in_array($s, $allowedScopes)) {
                    return $this->errorResponse('invalid_scope', "Scope '$s' not authorized for this client");
                }
            }
        }
        
        // Find matching license for content URL
        $license = $this->findLicenseForContent($contentUrl, $requestedScopes);
        
        if (!$license && !$client['auto_approve']) {
            return $this->errorResponse('access_denied', 'No license available for requested content');
        }
        
        // Generate license token
        $token = $this->generateToken();
        $tokenHash = hash('sha256', $token);
        
        // Calculate expiry (0 = non-expiring per spec, but we use 24h default)
        $expiresIn = 86400; // 24 hours
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        
        // Store token
        $stmt = $this->db->prepare("
            INSERT INTO rsl_tokens (
                token, token_hash, client_id, license_id, scope, content_url, expires_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $token,
            $tokenHash,
            $client['id'],
            $license ? $license['id'] : null,
            $scope,
            $contentUrl,
            $expiresAt
        ]);
        
        $tokenId = $this->db->lastInsertId();
        
        // Update client stats
        $stmt = $this->db->prepare("
            UPDATE rsl_clients SET 
                last_used = NOW(), 
                total_tokens_issued = total_tokens_issued + 1 
            WHERE id = ?
        ");
        $stmt->execute([$client['id']]);
        
        // Log successful token issuance
        $this->logAccess($tokenId, $client['id'], $license ? $license['id'] : null, $request, 'allowed', 'Token issued');
        
        // Send webhook notification
        if ($this->webhook) {
            $this->webhook->notifyTokenIssued([
                'id' => $tokenId,
                'scope' => $scope,
                'expires_at' => $expiresAt
            ], [
                'client_id' => $client['client_id'],
                'name' => $client['client_name'] ?? null
            ]);
        }
        
        // Return token response per OLP spec
        return [
            'access_token' => $token,
            'token_type' => 'License',
            'expires_in' => $expiresIn,
            'scope' => $scope
        ];
    }
    
    /**
     * Handle /introspect endpoint (Token Introspection)
     */
    public function handleIntrospectRequest(array $request): array {
        $token = $request['token'] ?? null;
        
        if (!$token) {
            return ['active' => false];
        }
        
        $tokenHash = hash('sha256', $token);
        
        $stmt = $this->db->prepare("
            SELECT t.*, c.client_name, c.client_id as client_identifier
            FROM rsl_tokens t
            JOIN rsl_clients c ON t.client_id = c.id
            WHERE t.token_hash = ?
        ");
        $stmt->execute([$tokenHash]);
        $tokenData = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$tokenData) {
            return ['active' => false];
        }
        
        // Check if revoked
        if ($tokenData['revoked']) {
            return ['active' => false];
        }
        
        // Check if expired
        if ($tokenData['expires_at'] && strtotime($tokenData['expires_at']) < time()) {
            return ['active' => false];
        }
        
        return [
            'active' => true,
            'scope' => $tokenData['scope'],
            'client_id' => $tokenData['client_identifier'],
            'exp' => $tokenData['expires_at'] ? strtotime($tokenData['expires_at']) : null,
            'iat' => strtotime($tokenData['created_at']),
            'content_url' => $tokenData['content_url'],
            'token_type' => 'License'
        ];
    }
    
    /**
     * Handle /key endpoint (EMS Key Request)
     */
    public function handleKeyRequest(array $request, string $authToken): array {
        $contentUrl = $request['content_url'] ?? null;
        
        if (!$contentUrl) {
            return $this->errorResponse('invalid_request', 'content_url required');
        }
        
        // Validate the license token
        $tokenData = $this->validateToken($authToken);
        if (!$tokenData) {
            return $this->errorResponse('invalid_token', 'Invalid or expired license token');
        }
        
        // Find encryption key for content
        $stmt = $this->db->prepare("
            SELECT k.*, l.permits, l.prohibits
            FROM rsl_encryption_keys k
            JOIN rsl_licenses l ON k.license_id = l.id
            WHERE k.content_url = ? OR k.content_url IS NULL
            ORDER BY k.content_url IS NULL ASC
            LIMIT 1
        ");
        $stmt->execute([$contentUrl]);
        $keyData = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$keyData) {
            return $this->errorResponse('key_not_found', 'No encryption key available for this content');
        }
        
        // Check if key has expired
        if ($keyData['expires_at'] && strtotime($keyData['expires_at']) < time()) {
            return $this->errorResponse('key_expired', 'Encryption key has expired');
        }
        
        // Return decryption key
        return [
            'key_id' => $keyData['key_id'],
            'key' => $this->decryptKey($keyData['encrypted_key']),
            'algorithm' => $keyData['algorithm'],
            'iv' => $keyData['iv']
        ];
    }
    
    /**
     * Validate client credentials
     */
    private function validateClient(string $clientId, string $clientSecret): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM rsl_clients WHERE client_id = ? AND enabled = 1
        ");
        $stmt->execute([$clientId]);
        $client = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$client) {
            return null;
        }
        
        // Verify secret
        if (!password_verify($clientSecret, $client['client_secret'])) {
            return null;
        }
        
        return $client;
    }
    
    /**
     * Validate a license token
     */
    public function validateToken(string $token): ?array {
        $tokenHash = hash('sha256', $token);
        
        $stmt = $this->db->prepare("
            SELECT t.*, c.enabled as client_enabled, c.client_name
            FROM rsl_tokens t
            JOIN rsl_clients c ON t.client_id = c.id
            WHERE t.token_hash = ?
            AND t.revoked = 0
            AND (t.expires_at IS NULL OR t.expires_at > NOW())
        ");
        $stmt->execute([$tokenHash]);
        $tokenData = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$tokenData || !$tokenData['client_enabled']) {
            return null;
        }
        
        // Update usage stats
        $stmt = $this->db->prepare("
            UPDATE rsl_tokens SET used_count = used_count + 1, last_used = NOW() WHERE token_hash = ?
        ");
        $stmt->execute([$tokenHash]);
        
        return $tokenData;
    }
    
    /**
     * Find a license for given content URL
     */
    private function findLicenseForContent(?string $contentUrl, array $scopes): ?array {
        // First try exact match
        if ($contentUrl) {
            $stmt = $this->db->prepare("
                SELECT * FROM rsl_licenses 
                WHERE enabled = 1 
                AND (content_url_pattern = ? OR content_url_pattern = '*' OR ? LIKE REPLACE(content_url_pattern, '*', '%'))
                ORDER BY 
                    CASE WHEN content_url_pattern = '*' THEN 1 ELSE 0 END,
                    priority DESC
                LIMIT 1
            ");
            $stmt->execute([$contentUrl, $contentUrl]);
            $license = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($license) {
                return $license;
            }
        }
        
        // Fall back to default license
        $stmt = $this->db->query("
            SELECT * FROM rsl_licenses WHERE enabled = 1 AND is_default = 1 LIMIT 1
        ");
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Generate a cryptographically secure token
     */
    private function generateToken(): string {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Decrypt an encryption key using the master key
     */
    private function decryptKey(string $encryptedKey): string {
        // Get master key from settings
        $stmt = $this->db->query("SELECT setting_value FROM settings WHERE setting_key = 'rsl_master_key'");
        $masterKey = $stmt->fetchColumn();
        
        if (!$masterKey) {
            // Generate and store master key if not exists
            $masterKey = bin2hex(random_bytes(32));
            $stmt = $this->db->prepare("
                INSERT INTO settings (setting_key, setting_value, description)
                VALUES ('rsl_master_key', ?, 'RSL encryption master key')
                ON DUPLICATE KEY UPDATE setting_value = ?
            ");
            $stmt->execute([$masterKey, $masterKey]);
        }
        
        // Decrypt the key (simplified - in production use proper envelope encryption)
        $data = base64_decode($encryptedKey);
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        
        return openssl_decrypt($ciphertext, 'aes-256-gcm', hex2bin($masterKey), OPENSSL_RAW_DATA, $iv);
    }
    
    /**
     * Encrypt a key using the master key
     */
    public function encryptKey(string $key): string {
        $stmt = $this->db->query("SELECT setting_value FROM settings WHERE setting_key = 'rsl_master_key'");
        $masterKey = $stmt->fetchColumn();
        
        if (!$masterKey) {
            $masterKey = bin2hex(random_bytes(32));
            $stmt = $this->db->prepare("
                INSERT INTO settings (setting_key, setting_value, description)
                VALUES ('rsl_master_key', ?, 'RSL encryption master key')
                ON DUPLICATE KEY UPDATE setting_value = ?
            ");
            $stmt->execute([$masterKey, $masterKey]);
        }
        
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($key, 'aes-256-gcm', hex2bin($masterKey), OPENSSL_RAW_DATA, $iv);
        
        return base64_encode($iv . $ciphertext);
    }
    
    /**
     * Log access attempt
     */
    private function logAccess(?int $tokenId, ?int $clientId, ?int $licenseId, array $request, string $status, string $reason): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO rsl_access_log (
                    token_id, client_id, license_id, request_url, request_method, request_ip, user_agent, status, status_reason
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $tokenId,
                $clientId,
                $licenseId,
                $request['content_url'] ?? null,
                $request['_method'] ?? 'POST',
                $request['_ip'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
                $request['_user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null,
                $status,
                $reason
            ]);
        } catch (\Exception $e) {
            // Silently fail logging
        }
    }
    
    /**
     * Generate error response per OAuth 2.0 spec
     */
    private function errorResponse(string $error, string $description): array {
        return [
            'error' => $error,
            'error_description' => $description
        ];
    }
    
    /**
     * Register a new client
     */
    public function registerClient(array $data): array {
        $clientId = bin2hex(random_bytes(16));
        $clientSecret = bin2hex(random_bytes(32));
        
        $stmt = $this->db->prepare("
            INSERT INTO rsl_clients (
                client_id, client_secret, client_name, client_type, description,
                contact_email, contact_url, allowed_scopes, rate_limit
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $name = $data['name'] ?? 'Unknown Client';
        $type = $data['type'] ?? 'other';
        $email = $data['email'] ?? null;
        $url = $data['url'] ?? null;
        
        $stmt->execute([
            $clientId,
            password_hash($clientSecret, PASSWORD_DEFAULT),
            $name,
            $type,
            $data['description'] ?? null,
            $email,
            $url,
            json_encode($data['scopes'] ?? []),
            $data['rate_limit'] ?? 1000
        ]);
        
        // Send webhook notification
        if ($this->webhook) {
            $this->webhook->notifyClientRegistered([
                'client_id' => $clientId,
                'name' => $name,
                'client_type' => $type,
                'contact_email' => $email,
                'website' => $url,
                'intended_use' => $data['description'] ?? null
            ]);
        }
        
        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'message' => 'Client registered. Requires admin approval before use.'
        ];
    }
    
    /**
     * Get OLP server metadata (well-known endpoint)
     */
    public function getServerMetadata(): array {
        return [
            'issuer' => $this->serverUrl,
            'token_endpoint' => $this->serverUrl . '/token',
            'introspection_endpoint' => $this->serverUrl . '/introspect',
            'key_endpoint' => $this->serverUrl . '/key',
            'registration_endpoint' => $this->serverUrl . '/register',
            'grant_types_supported' => ['client_credentials'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'response_types_supported' => ['token'],
            'scopes_supported' => [
                'ai-all', 'ai-train', 'ai-input', 'ai-index', 'search',
                'commercial', 'non-commercial', 'education', 'government', 'personal'
            ]
        ];
    }
}
