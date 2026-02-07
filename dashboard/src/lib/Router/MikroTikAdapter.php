<?php
/**
 * MikroTik Router Adapter
 * Communicates with MikroTik RouterOS via API
 */

namespace CatWAF\Router;

require_once __DIR__ . '/RouterAdapterInterface.php';

class MikroTikAdapter extends BaseRouterAdapter {
    private $socket;
    private bool $connected = false;
    
    public function testConnection(): array {
        $start = microtime(true);
        
        try {
            $this->connect();
            $response = $this->command('/system/identity/print');
            $this->disconnect();
            
            $duration = (int)((microtime(true) - $start) * 1000);
            $identity = $response[0]['name'] ?? 'Unknown';
            
            return [
                'success' => true,
                'message' => "Connected to: {$identity}",
                'details' => [
                    'identity' => $identity,
                    'response_time_ms' => $duration
                ]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'details' => []
            ];
        }
    }
    
    public function addDropRule(string $ip, ?int $duration = null, string $comment = ''): array {
        if ($this->isWhitelisted($ip)) {
            return ['success' => false, 'rule_id' => null, 'message' => 'IP is whitelisted'];
        }
        
        $start = microtime(true);
        $comment = $comment ?: $this->formatComment('Auto-banned');
        $addressList = $this->config['address_list_name'] ?? 'catwaf-banned';
        
        if ($this->dryRun) {
            $this->logAction('add', $ip, 'success', 'DRY RUN', 0);
            return ['success' => true, 'rule_id' => 'dry-run', 'message' => '[DRY RUN] Would add rule'];
        }
        
        try {
            $this->connect();
            
            // Check if already exists
            $existing = $this->command('/ip/firewall/address-list/print', [
                '?list' => $addressList,
                '?address' => $ip
            ]);
            
            if (!empty($existing)) {
                $this->disconnect();
                return ['success' => true, 'rule_id' => $existing[0]['.id'] ?? null, 'message' => 'Rule already exists'];
            }
            
            // Add to address list
            $params = [
                'list' => $addressList,
                'address' => $ip,
                'comment' => $comment
            ];
            
            if ($duration) {
                // MikroTik timeout format: 1h30m, 1d, etc.
                $params['timeout'] = $this->secondsToMikroTikTime($duration);
            }
            
            $response = $this->command('/ip/firewall/address-list/add', $params);
            $this->disconnect();
            
            $durationMs = (int)((microtime(true) - $start) * 1000);
            $ruleId = $response['ret'] ?? null;
            
            $this->logAction('add', $ip, 'success', null, $durationMs, $ruleId);
            $this->updateCache($ip, $ruleId, $duration);
            
            return ['success' => true, 'rule_id' => $ruleId, 'message' => 'Rule added successfully'];
            
        } catch (\Exception $e) {
            $durationMs = (int)((microtime(true) - $start) * 1000);
            $this->logAction('add', $ip, 'failed', $e->getMessage(), $durationMs);
            return ['success' => false, 'rule_id' => null, 'message' => $e->getMessage()];
        }
    }
    
    public function removeDropRule(string $ip): array {
        $start = microtime(true);
        $addressList = $this->config['address_list_name'] ?? 'catwaf-banned';
        
        if ($this->dryRun) {
            $this->logAction('remove', $ip, 'success', 'DRY RUN', 0);
            return ['success' => true, 'message' => '[DRY RUN] Would remove rule'];
        }
        
        try {
            $this->connect();
            
            // Find the rule
            $existing = $this->command('/ip/firewall/address-list/print', [
                '?list' => $addressList,
                '?address' => $ip
            ]);
            
            if (empty($existing)) {
                $this->disconnect();
                return ['success' => true, 'message' => 'Rule not found (already removed)'];
            }
            
            // Remove each matching rule
            foreach ($existing as $rule) {
                if (isset($rule['.id'])) {
                    $this->command('/ip/firewall/address-list/remove', ['.id' => $rule['.id']]);
                }
            }
            
            $this->disconnect();
            
            $durationMs = (int)((microtime(true) - $start) * 1000);
            $this->logAction('remove', $ip, 'success', null, $durationMs);
            $this->removeFromCache($ip);
            
            return ['success' => true, 'message' => 'Rule removed successfully'];
            
        } catch (\Exception $e) {
            $durationMs = (int)((microtime(true) - $start) * 1000);
            $this->logAction('remove', $ip, 'failed', $e->getMessage(), $durationMs);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function listDropRules(): array {
        $addressList = $this->config['address_list_name'] ?? 'catwaf-banned';
        
        try {
            $this->connect();
            
            $rules = $this->command('/ip/firewall/address-list/print', [
                '?list' => $addressList
            ]);
            
            $this->disconnect();
            
            $formatted = [];
            foreach ($rules as $rule) {
                $formatted[] = [
                    'id' => $rule['.id'] ?? null,
                    'ip' => $rule['address'] ?? '',
                    'list' => $rule['list'] ?? '',
                    'comment' => $rule['comment'] ?? '',
                    'timeout' => $rule['timeout'] ?? null,
                    'creation_time' => $rule['creation-time'] ?? null,
                    'dynamic' => ($rule['dynamic'] ?? 'false') === 'true'
                ];
            }
            
            return ['success' => true, 'rules' => $formatted, 'message' => 'Retrieved ' . count($formatted) . ' rules'];
            
        } catch (\Exception $e) {
            return ['success' => false, 'rules' => [], 'message' => $e->getMessage()];
        }
    }
    
    public function syncRules(array $shouldBeBlocked): array {
        $start = microtime(true);
        $added = 0;
        $removed = 0;
        $errors = [];
        
        try {
            // Get current rules from router
            $result = $this->listDropRules();
            if (!$result['success']) {
                throw new \Exception($result['message']);
            }
            
            $currentlyBlocked = array_column($result['rules'], 'ip');
            
            // Determine what to add and remove
            $toAdd = array_diff($shouldBeBlocked, $currentlyBlocked);
            $toRemove = array_diff($currentlyBlocked, $shouldBeBlocked);
            
            // Filter whitelisted from additions
            $toAdd = array_filter($toAdd, fn($ip) => !$this->isWhitelisted($ip));
            
            // Add new rules
            foreach ($toAdd as $ip) {
                $addResult = $this->addDropRule($ip, null, 'Sync from CatWAF');
                if ($addResult['success']) {
                    $added++;
                } else {
                    $errors[] = "Add {$ip}: {$addResult['message']}";
                }
            }
            
            // Remove old rules
            foreach ($toRemove as $ip) {
                $removeResult = $this->removeDropRule($ip);
                if ($removeResult['success']) {
                    $removed++;
                } else {
                    $errors[] = "Remove {$ip}: {$removeResult['message']}";
                }
            }
            
            $durationMs = (int)((microtime(true) - $start) * 1000);
            $this->logAction('bulk_sync', null, empty($errors) ? 'success' : 'failed', 
                             empty($errors) ? null : implode('; ', $errors), $durationMs);
            
            return [
                'success' => empty($errors),
                'added' => $added,
                'removed' => $removed,
                'errors' => $errors,
                'message' => "Sync complete: +{$added} -{$removed}" . (empty($errors) ? '' : ' with errors')
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'added' => $added,
                'removed' => $removed,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function getInfo(): array {
        try {
            $this->connect();
            
            $identity = $this->command('/system/identity/print');
            $resource = $this->command('/system/resource/print');
            $routerboard = $this->command('/system/routerboard/print');
            
            $this->disconnect();
            
            return [
                'identity' => $identity[0]['name'] ?? 'Unknown',
                'model' => $routerboard[0]['model'] ?? 'Unknown',
                'version' => $resource[0]['version'] ?? 'Unknown',
                'uptime' => $resource[0]['uptime'] ?? 'Unknown',
                'cpu_load' => $resource[0]['cpu-load'] ?? 0,
                'free_memory' => $resource[0]['free-memory'] ?? 0,
                'total_memory' => $resource[0]['total-memory'] ?? 0
            ];
            
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    // ========== MikroTik API Protocol Implementation ==========
    
    private function connect(): void {
        if ($this->connected) return;
        
        $host = $this->config['host'];
        $port = $this->config['port'] ?? 8728;
        $ssl = $this->config['ssl_enabled'] ?? false;
        
        $context = stream_context_create();
        if ($ssl) {
            $port = $this->config['port'] ?? 8729;
            if (!($this->config['ssl_verify'] ?? true)) {
                stream_context_set_option($context, 'ssl', 'verify_peer', false);
                stream_context_set_option($context, 'ssl', 'verify_peer_name', false);
            }
        }
        
        $protocol = $ssl ? 'ssl' : 'tcp';
        $this->socket = @stream_socket_client(
            "{$protocol}://{$host}:{$port}",
            $errno, $errstr, 10,
            STREAM_CLIENT_CONNECT, $context
        );
        
        if (!$this->socket) {
            throw new \Exception("Connection failed: {$errstr} ({$errno})");
        }
        
        stream_set_timeout($this->socket, 10);
        
        // Login
        $this->login();
        $this->connected = true;
    }
    
    private function disconnect(): void {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
    }
    
    private function login(): void {
        $username = $this->config['username'] ?? 'admin';
        $password = $this->decryptPassword($this->config['password_encrypted'] ?? '');
        
        // Try new (post 6.43) login first
        $response = $this->command('/login', [
            'name' => $username,
            'password' => $password
        ], false);
        
        if (isset($response['!trap'])) {
            throw new \Exception('Login failed: ' . ($response['!trap']['message'] ?? 'Invalid credentials'));
        }
    }
    
    private function command(string $cmd, array $params = [], bool $throwOnTrap = true): array {
        // Send command
        $this->write($cmd);
        foreach ($params as $key => $value) {
            if (strpos($key, '?') === 0 || strpos($key, '.') === 0) {
                $this->write($key . '=' . $value);
            } else {
                $this->write('=' . $key . '=' . $value);
            }
        }
        $this->write('', true); // End of sentence
        
        // Read response
        $response = [];
        $current = [];
        
        while (true) {
            $line = $this->read();
            
            if ($line === '!done') {
                if (!empty($current)) {
                    $response[] = $current;
                }
                break;
            }
            
            if ($line === '!re') {
                if (!empty($current)) {
                    $response[] = $current;
                }
                $current = [];
                continue;
            }
            
            if ($line === '!trap') {
                $current['!trap'] = true;
                continue;
            }
            
            if (preg_match('/^=(.+?)=(.*)$/', $line, $m)) {
                $current[$m[1]] = $m[2];
            }
            
            if (preg_match('/^=ret=(.*)$/', $line, $m)) {
                $response['ret'] = $m[1];
            }
        }
        
        if ($throwOnTrap && !empty($response) && isset($response[0]['!trap'])) {
            throw new \Exception($response[0]['message'] ?? 'API error');
        }
        
        return $response;
    }
    
    private function write(string $word, bool $endSentence = false): void {
        $data = $this->encodeLength(strlen($word)) . $word;
        fwrite($this->socket, $data);
        
        if ($endSentence) {
            fwrite($this->socket, chr(0)); // Empty word terminates sentence
        }
    }
    
    private function read(): string {
        $len = $this->decodeLength();
        if ($len === 0) return '';
        return fread($this->socket, $len);
    }
    
    private function encodeLength(int $len): string {
        if ($len < 0x80) {
            return chr($len);
        } elseif ($len < 0x4000) {
            return chr(($len >> 8) | 0x80) . chr($len & 0xFF);
        } elseif ($len < 0x200000) {
            return chr(($len >> 16) | 0xC0) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        } elseif ($len < 0x10000000) {
            return chr(($len >> 24) | 0xE0) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        } else {
            return chr(0xF0) . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
    }
    
    private function decodeLength(): int {
        $byte = ord(fread($this->socket, 1));
        
        if (($byte & 0x80) === 0) {
            return $byte;
        } elseif (($byte & 0xC0) === 0x80) {
            return (($byte & 0x3F) << 8) + ord(fread($this->socket, 1));
        } elseif (($byte & 0xE0) === 0xC0) {
            $b = fread($this->socket, 2);
            return (($byte & 0x1F) << 16) + (ord($b[0]) << 8) + ord($b[1]);
        } elseif (($byte & 0xF0) === 0xE0) {
            $b = fread($this->socket, 3);
            return (($byte & 0x0F) << 24) + (ord($b[0]) << 16) + (ord($b[1]) << 8) + ord($b[2]);
        } elseif ($byte === 0xF0) {
            $b = fread($this->socket, 4);
            return (ord($b[0]) << 24) + (ord($b[1]) << 16) + (ord($b[2]) << 8) + ord($b[3]);
        }
        
        return 0;
    }
    
    private function decryptPassword(?string $encrypted): string {
        if (!$encrypted) return '';
        
        $key = $this->getSetting('router_encryption_key');
        if (!$key) return $encrypted; // Return as-is if no encryption key
        
        $data = base64_decode($encrypted);
        if (strlen($data) < 16) return '';
        
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        
        $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : '';
    }
    
    private function secondsToMikroTikTime(int $seconds): string {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        $result = '';
        if ($days > 0) $result .= "{$days}d";
        if ($hours > 0) $result .= "{$hours}h";
        if ($minutes > 0) $result .= "{$minutes}m";
        if ($secs > 0 && $days == 0) $result .= "{$secs}s";
        
        return $result ?: '0s';
    }
    
    private function updateCache(string $ip, ?string $ruleId, ?int $duration): void {
        $expiresAt = $duration ? date('Y-m-d H:i:s', time() + $duration) : null;
        
        $stmt = $this->pdo->prepare("
            INSERT INTO router_rules_cache (router_id, ip_address, rule_id, expires_at, synced_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE rule_id = ?, expires_at = ?, synced_at = NOW()
        ");
        $stmt->execute([
            $this->config['id'], $ip, $ruleId, $expiresAt,
            $ruleId, $expiresAt
        ]);
    }
    
    private function removeFromCache(string $ip): void {
        $stmt = $this->pdo->prepare("DELETE FROM router_rules_cache WHERE router_id = ? AND ip_address = ?");
        $stmt->execute([$this->config['id'], $ip]);
    }
}
