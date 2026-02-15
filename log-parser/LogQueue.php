<?php
/**
 * Log Queue Manager
 * Provides buffered/queued log ingestion for high-volume scenarios
 */

class LogQueue {
    private \PDO $pdo;
    private array $buffer = [];
    private int $maxBufferSize;
    private int $flushInterval;
    private int $lastFlush;
    private string $diskQueueDir;
    private array $stats = [
        'buffered' => 0,
        'flushed' => 0,
        'disk_queued' => 0,
        'disk_recovered' => 0,
        'errors' => 0
    ];
    
    // Separate buffers for different tables
    private array $accessLogsBuffer = [];
    private array $telemetryBuffer = [];
    private array $botDetectionsBuffer = [];
    private array $scannerBuffer = [];
    
    public function __construct(\PDO $pdo, int $maxBufferSize = 500, int $flushIntervalSeconds = 5) {
        $this->pdo = $pdo;
        $this->maxBufferSize = $maxBufferSize;
        $this->flushInterval = $flushIntervalSeconds;
        $this->lastFlush = time();
        $this->diskQueueDir = '/tmp/log-queue';
        
        // Ensure disk queue directory exists
        if (!is_dir($this->diskQueueDir)) {
            mkdir($this->diskQueueDir, 0755, true);
        }
        
        // Process any leftover disk queue from previous runs
        $this->processDiskQueue();
    }
    
    /**
     * Add access log entry to buffer
     */
    public function addAccessLog(array $data): void {
        $this->accessLogsBuffer[] = $data;
        $this->stats['buffered']++;
        $this->checkFlush();
    }
    
    /**
     * Add telemetry entry to buffer
     */
    public function addTelemetry(array $data): void {
        $this->telemetryBuffer[] = $data;
        $this->stats['buffered']++;
        $this->checkFlush();
    }
    
    /**
     * Add bot detection to buffer
     */
    public function addBotDetection(array $data): void {
        $this->botDetectionsBuffer[] = $data;
        $this->stats['buffered']++;
        $this->checkFlush();
    }
    
    /**
     * Add scanner request to buffer
     */
    public function addScannerRequest(array $data): void {
        $this->scannerBuffer[] = $data;
        $this->stats['buffered']++;
        $this->checkFlush();
    }
    
    /**
     * Check if we should flush based on size or time
     */
    private function checkFlush(): void {
        $totalSize = count($this->accessLogsBuffer) + count($this->telemetryBuffer) + 
                     count($this->botDetectionsBuffer) + count($this->scannerBuffer);
        
        $timeSinceFlush = time() - $this->lastFlush;
        
        if ($totalSize >= $this->maxBufferSize || $timeSinceFlush >= $this->flushInterval) {
            $this->flush();
        }
    }
    
    /**
     * Force flush all buffers to database
     */
    public function flush(): bool {
        $success = true;
        
        try {
            // Flush access logs
            if (!empty($this->accessLogsBuffer)) {
                $this->batchInsertAccessLogs();
                $this->accessLogsBuffer = [];
            }
            
            // Flush telemetry
            if (!empty($this->telemetryBuffer)) {
                $this->batchInsertTelemetry();
                $this->telemetryBuffer = [];
            }
            
            // Flush bot detections
            if (!empty($this->botDetectionsBuffer)) {
                $this->batchInsertBotDetections();
                $this->botDetectionsBuffer = [];
            }
            
            // Flush scanner requests
            if (!empty($this->scannerBuffer)) {
                $this->batchInsertScannerRequests();
                $this->scannerBuffer = [];
            }
            
            $this->lastFlush = time();
            
        } catch (\PDOException $e) {
            // Database unavailable - write to disk queue
            echo "[QUEUE] Database error, writing to disk queue: " . $e->getMessage() . "\n";
            $this->writeToDiskQueue();
            $success = false;
            $this->stats['errors']++;
        }
        
        return $success;
    }
    
    /**
     * Batch insert access logs
     */
    private function batchInsertAccessLogs(): void {
        if (empty($this->accessLogsBuffer)) return;
        
        $placeholders = [];
        $values = [];
        
        foreach ($this->accessLogsBuffer as $log) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $values = array_merge($values, [
                $log['domain'],
                $log['ip_address'],
                $log['request_uri'],
                $log['method'],
                $log['status_code'],
                $log['bytes_sent'],
                $log['user_agent'],
                $log['referer'],
                $log['response_time'] ?? null,
                $log['blocked'] ?? 0,
                $log['blocked_reason'] ?? null,
                $log['timestamp']
            ]);
        }
        
        $sql = "INSERT INTO access_logs (domain, ip_address, request_uri, method, status_code, bytes_sent, user_agent, referer, response_time, blocked, blocked_reason, timestamp) VALUES " . implode(', ', $placeholders);
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        
        $this->stats['flushed'] += count($this->accessLogsBuffer);
        echo "[QUEUE] Flushed " . count($this->accessLogsBuffer) . " access logs\n";
    }
    
    /**
     * Batch insert telemetry
     */
    private function batchInsertTelemetry(): void {
        if (empty($this->telemetryBuffer)) return;
        
        $placeholders = [];
        $values = [];
        
        foreach ($this->telemetryBuffer as $t) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $values = array_merge($values, [
                $t['domain'],
                $t['uri'],
                $t['method'],
                $t['status_code'],
                $t['ip_address'],
                $t['bytes_sent'],
                $t['response_time'],
                $t['cache_status'],
                $t['backend_server'],
                $t['user_agent'] ?? null,
                $t['timestamp']
            ]);
        }
        
        $sql = "INSERT INTO request_telemetry (domain, uri, method, status_code, ip_address, bytes_sent, response_time, cache_status, backend_server, user_agent, timestamp) VALUES " . implode(', ', $placeholders);
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        
        $this->stats['flushed'] += count($this->telemetryBuffer);
        echo "[QUEUE] Flushed " . count($this->telemetryBuffer) . " telemetry records\n";
    }
    
    /**
     * Batch insert bot detections
     */
    private function batchInsertBotDetections(): void {
        if (empty($this->botDetectionsBuffer)) return;
        
        $placeholders = [];
        $values = [];
        
        foreach ($this->botDetectionsBuffer as $b) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?)';
            $values = array_merge($values, [
                $b['ip_address'],
                $b['user_agent'],
                $b['bot_name'],
                $b['bot_type'],
                $b['action'],
                $b['domain'],
                $b['timestamp']
            ]);
        }
        
        $sql = "INSERT INTO bot_detections (ip_address, user_agent, bot_name, bot_type, action, domain, timestamp) VALUES " . implode(', ', $placeholders);
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        
        $this->stats['flushed'] += count($this->botDetectionsBuffer);
        echo "[QUEUE] Flushed " . count($this->botDetectionsBuffer) . " bot detections\n";
    }
    
    /**
     * Batch insert scanner requests
     */
    private function batchInsertScannerRequests(): void {
        if (empty($this->scannerBuffer)) return;
        
        // Scanner requests are more complex - insert one by one with ON DUPLICATE handling
        foreach ($this->scannerBuffer as $s) {
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO scanner_requests (scanner_ip_id, timestamp, path, user_agent, status_code)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $s['scanner_ip_id'],
                    $s['timestamp'],
                    $s['path'],
                    $s['user_agent'],
                    $s['status_code']
                ]);
            } catch (\PDOException $e) {
                // Log but continue
            }
        }
        
        $this->stats['flushed'] += count($this->scannerBuffer);
    }
    
    /**
     * Write current buffers to disk queue for later processing
     */
    private function writeToDiskQueue(): void {
        $filename = $this->diskQueueDir . '/queue_' . time() . '_' . uniqid() . '.json';
        
        $data = [
            'access_logs' => $this->accessLogsBuffer,
            'telemetry' => $this->telemetryBuffer,
            'bot_detections' => $this->botDetectionsBuffer,
            'scanner' => $this->scannerBuffer,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        $totalItems = count($this->accessLogsBuffer) + count($this->telemetryBuffer) + 
                      count($this->botDetectionsBuffer) + count($this->scannerBuffer);
        
        if (file_put_contents($filename, json_encode($data))) {
            echo "[QUEUE] Wrote {$totalItems} items to disk queue: {$filename}\n";
            $this->stats['disk_queued'] += $totalItems;
            
            // Clear buffers after writing to disk
            $this->accessLogsBuffer = [];
            $this->telemetryBuffer = [];
            $this->botDetectionsBuffer = [];
            $this->scannerBuffer = [];
        } else {
            echo "[QUEUE ERROR] Failed to write disk queue file\n";
        }
    }
    
    /**
     * Process any queued files from disk
     */
    public function processDiskQueue(): void {
        $files = glob($this->diskQueueDir . '/queue_*.json');
        
        if (empty($files)) {
            return;
        }
        
        echo "[QUEUE] Found " . count($files) . " disk queue files to process\n";
        
        foreach ($files as $file) {
            try {
                $data = json_decode(file_get_contents($file), true);
                
                if (!$data) {
                    unlink($file);
                    continue;
                }
                
                // Restore to buffers and flush
                $this->accessLogsBuffer = $data['access_logs'] ?? [];
                $this->telemetryBuffer = $data['telemetry'] ?? [];
                $this->botDetectionsBuffer = $data['bot_detections'] ?? [];
                $this->scannerBuffer = $data['scanner'] ?? [];
                
                $itemCount = count($this->accessLogsBuffer) + count($this->telemetryBuffer) + 
                            count($this->botDetectionsBuffer) + count($this->scannerBuffer);
                
                if ($this->flush()) {
                    unlink($file);
                    echo "[QUEUE] Recovered {$itemCount} items from disk queue\n";
                    $this->stats['disk_recovered'] += $itemCount;
                } else {
                    // Still can't connect - leave file for later
                    echo "[QUEUE] Failed to process disk queue file, will retry later\n";
                    break;
                }
                
            } catch (\Exception $e) {
                echo "[QUEUE ERROR] Error processing disk queue: " . $e->getMessage() . "\n";
                // Move bad file aside
                rename($file, $file . '.error');
            }
        }
    }
    
    /**
     * Get queue statistics
     */
    public function getStats(): array {
        $currentSize = count($this->accessLogsBuffer) + count($this->telemetryBuffer) + 
                       count($this->botDetectionsBuffer) + count($this->scannerBuffer);
        
        return array_merge($this->stats, [
            'current_buffer_size' => $currentSize,
            'max_buffer_size' => $this->maxBufferSize,
            'pending_disk_files' => count(glob($this->diskQueueDir . '/queue_*.json')),
            'last_flush' => date('Y-m-d H:i:s', $this->lastFlush)
        ]);
    }
    
    /**
     * Destructor - flush any remaining data
     */
    public function __destruct() {
        if (!empty($this->accessLogsBuffer) || !empty($this->telemetryBuffer) || 
            !empty($this->botDetectionsBuffer) || !empty($this->scannerBuffer)) {
            echo "[QUEUE] Destructor flushing remaining buffer...\n";
            $this->flush();
        }
    }
}
