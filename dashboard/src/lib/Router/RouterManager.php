<?php
/**
 * Router Manager
 * Factory and orchestration for router adapters
 */

namespace CatWAF\Router;

require_once __DIR__ . '/RouterAdapterInterface.php';
require_once __DIR__ . '/MikroTikAdapter.php';

class RouterManager {
    private \PDO $pdo;
    private array $adapters = [];
    
    public function __construct(\PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Get adapter for a specific router
     */
    public function getAdapter(int $routerId): ?RouterAdapterInterface {
        if (isset($this->adapters[$routerId])) {
            return $this->adapters[$routerId];
        }
        
        $stmt = $this->pdo->prepare("SELECT * FROM router_configs WHERE id = ? AND enabled = 1");
        $stmt->execute([$routerId]);
        $config = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$config) {
            return null;
        }
        
        $adapter = $this->createAdapter($config);
        $this->adapters[$routerId] = $adapter;
        
        return $adapter;
    }
    
    /**
     * Get all enabled routers
     */
    public function getAllAdapters(): array {
        $stmt = $this->pdo->query("SELECT * FROM router_configs WHERE enabled = 1");
        $configs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $adapters = [];
        foreach ($configs as $config) {
            $adapters[$config['id']] = $this->createAdapter($config);
        }
        
        return $adapters;
    }
    
    /**
     * Create adapter based on router type
     */
    private function createAdapter(array $config): RouterAdapterInterface {
        switch ($config['router_type']) {
            case 'mikrotik':
                return new MikroTikAdapter($config, $this->pdo);
            // Future adapters:
            // case 'opnsense':
            //     return new OPNsenseAdapter($config, $this->pdo);
            // case 'pfsense':
            //     return new PfSenseAdapter($config, $this->pdo);
            default:
                throw new \Exception("Unsupported router type: {$config['router_type']}");
        }
    }
    
    /**
     * Add DROP rule to all enabled routers
     */
    public function addDropRuleToAll(string $ip, ?int $duration = null, string $comment = ''): array {
        $results = [];
        
        foreach ($this->getAllAdapters() as $routerId => $adapter) {
            $results[$routerId] = $adapter->addDropRule($ip, $duration, $comment);
        }
        
        return $results;
    }
    
    /**
     * Remove DROP rule from all enabled routers
     */
    public function removeDropRuleFromAll(string $ip): array {
        $results = [];
        
        foreach ($this->getAllAdapters() as $routerId => $adapter) {
            $results[$routerId] = $adapter->removeDropRule($ip);
        }
        
        return $results;
    }
    
    /**
     * Sync all routers with current ban list
     */
    public function syncAll(): array {
        // Get all currently banned IPs
        $stmt = $this->pdo->query("
            SELECT DISTINCT ip_address 
            FROM banned_ips 
            WHERE (expires_at IS NULL OR expires_at > NOW())
        ");
        $bannedIps = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        $results = [];
        
        foreach ($this->getAllAdapters() as $routerId => $adapter) {
            $results[$routerId] = $adapter->syncRules($bannedIps);
        }
        
        return $results;
    }
    
    /**
     * Test connection to a router
     */
    public function testConnection(int $routerId): array {
        $adapter = $this->getAdapter($routerId);
        
        if (!$adapter) {
            return ['success' => false, 'message' => 'Router not found or disabled'];
        }
        
        $result = $adapter->testConnection();
        
        // Update test status in database
        $stmt = $this->pdo->prepare("
            UPDATE router_configs 
            SET test_status = ?, last_test = NOW(), last_error = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $result['success'] ? 'success' : 'failed',
            $result['success'] ? null : $result['message'],
            $routerId
        ]);
        
        return $result;
    }
    
    /**
     * Encrypt password for storage
     */
    public function encryptPassword(string $password): string {
        $key = $this->getOrCreateEncryptionKey();
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($password, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $ciphertext);
    }
    
    /**
     * Get or create encryption key
     */
    private function getOrCreateEncryptionKey(): string {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'router_encryption_key'");
        $stmt->execute();
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['setting_value'])) {
            return $result['setting_value'];
        }
        
        // Generate new key
        $key = bin2hex(random_bytes(32));
        
        $stmt = $this->pdo->prepare("
            INSERT INTO settings (setting_key, setting_value, description) 
            VALUES ('router_encryption_key', ?, 'AES-256 encryption key for router passwords')
            ON DUPLICATE KEY UPDATE setting_value = ?
        ");
        $stmt->execute([$key, $key]);
        
        return $key;
    }
}
