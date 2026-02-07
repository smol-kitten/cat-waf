<?php
/**
 * Router Adapter Interface
 * Base interface for all router integrations
 */

namespace CatWAF\Router;

interface RouterAdapterInterface {
    /**
     * Test connection to router
     * @return array ['success' => bool, 'message' => string, 'details' => array]
     */
    public function testConnection(): array;
    
    /**
     * Add DROP rule for an IP
     * @param string $ip IP address to block
     * @param int|null $duration Duration in seconds (null = permanent)
     * @param string $comment Rule comment
     * @return array ['success' => bool, 'rule_id' => string|null, 'message' => string]
     */
    public function addDropRule(string $ip, ?int $duration = null, string $comment = ''): array;
    
    /**
     * Remove DROP rule for an IP
     * @param string $ip IP address to unblock
     * @return array ['success' => bool, 'message' => string]
     */
    public function removeDropRule(string $ip): array;
    
    /**
     * List all DROP rules managed by CatWAF
     * @return array ['success' => bool, 'rules' => array, 'message' => string]
     */
    public function listDropRules(): array;
    
    /**
     * Sync rules from local database to router
     * @param array $ips Array of IPs that should be blocked
     * @return array ['success' => bool, 'added' => int, 'removed' => int, 'message' => string]
     */
    public function syncRules(array $ips): array;
    
    /**
     * Get router info/stats
     * @return array Router information
     */
    public function getInfo(): array;
}

/**
 * Abstract base class with common functionality
 */
abstract class BaseRouterAdapter implements RouterAdapterInterface {
    protected array $config;
    protected \PDO $pdo;
    protected bool $dryRun = false;
    protected array $whitelist = [];
    
    public function __construct(array $config, \PDO $pdo) {
        $this->config = $config;
        $this->pdo = $pdo;
        $this->dryRun = $this->getSetting('router_dry_run') === '1';
        $this->whitelist = $this->parseWhitelist($config['whitelist_subnets'] ?? '');
    }
    
    protected function getSetting(string $key): ?string {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ? $result['setting_value'] : null;
    }
    
    protected function parseWhitelist(?string $json): array {
        if (!$json) return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
    
    protected function isWhitelisted(string $ip): bool {
        // Check against whitelist subnets
        foreach ($this->whitelist as $subnet) {
            if ($this->ipInSubnet($ip, $subnet)) {
                return true;
            }
        }
        
        // Never block private/local IPs
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        
        return false;
    }
    
    protected function ipInSubnet(string $ip, string $subnet): bool {
        if (strpos($subnet, '/') === false) {
            return $ip === $subnet;
        }
        
        list($network, $mask) = explode('/', $subnet);
        $ipLong = ip2long($ip);
        $networkLong = ip2long($network);
        $maskLong = ~((1 << (32 - (int)$mask)) - 1);
        
        return ($ipLong & $maskLong) === ($networkLong & $maskLong);
    }
    
    protected function logAction(string $action, ?string $ip, string $status, ?string $error = null, ?int $durationMs = null, ?string $ruleId = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO router_rule_log (router_id, action, ip_address, rule_id, status, error_message, duration_ms, triggered_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'auto')
        ");
        $stmt->execute([
            $this->config['id'],
            $action,
            $ip,
            $ruleId,
            $status,
            $error,
            $durationMs
        ]);
    }
    
    protected function formatComment(string $reason): string {
        $prefix = $this->config['rule_comment_prefix'] ?? '[CatWAF]';
        $timestamp = date('Y-m-d H:i');
        return "{$prefix} {$reason} @ {$timestamp}";
    }
}
