<?php
// Log parser - Parses NGINX access logs and populates database with telemetry, bot detection, and ModSec events

require_once __DIR__ . '/LogQueue.php';

$logDir = '/var/log/nginx';
$modsecAuditLog = '/var/log/modsec/modsec_audit.log';
$posFilePrefix = '/tmp/parser_';
$modsecPosFile = '/tmp/parser_modsec.pos';

// Queue configuration
$QUEUE_BUFFER_SIZE = (int)(getenv('QUEUE_BUFFER_SIZE') ?: 500);
$QUEUE_FLUSH_INTERVAL = (int)(getenv('QUEUE_FLUSH_INTERVAL') ?: 5);
$USE_QUEUE = getenv('USE_LOG_QUEUE') !== 'false'; // Enabled by default

// Load bot patterns from bot-protection.conf
$botPatterns = [
    // Bad bots (from bot-protection.conf)
    'bad' => [
        'python-requests', 'wget', 'curl', 'scrapy', 'selenium', 'phantomjs',
        'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'blexbot',
        'masscan', 'nmap', 'nikto', 'sqlmap', 'metasploit', 'w3af',
        'burp', 'zap', 'acunetix', 'nessus', 'openvas', 'qualys',
        'apache-httpclient', 'java/', 'go-http-client', 'okhttp', 'facebookexternalhit',
    ],
    // Good bots (whitelisted)
    'good' => [
        'googlebot', 'bingbot', 'slackbot', 
        'twitterbot', 'linkedinbot', 'discordbot', 'telegrambot',
        'whatsapp', 'applebot', 'duckduckbot', 'baiduspider', 'yandexbot'
    ]
];

// WordPress scanning patterns
$wpPaths = [
    '/wp-admin', '/wp-includes', '/wp-content', '/wp-login.php', 
    '/xmlrpc.php', '/wp-json', '/wp-config.php', '/wp-cron.php'
];

// Common exploit paths
$exploitPaths = [
    '/.env', '/.git', '/phpmyadmin', '/admin', '/administrator',
    '/config.php', '/setup.php', '/.htaccess', '/shell.php',
    '/c99.php', '/r57.php', '/eval.php', '/phpinfo.php'
];

// Tracking for scanner detection (in-memory, per execution)
$scannerTracking = [];

// Database connection
$dbHost = getenv('DB_HOST') ?: 'mariadb';
$dbName = getenv('DB_NAME') ?: 'waf_db';
$dbUser = getenv('DB_USER') ?: 'waf_user';
$dbPass = getenv('DB_PASSWORD') ?: 'changeme';

// Function to create/reconnect database
function connectDatabase($host, $name, $user, $pass) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$name", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
        $pdo->setAttribute(PDO::MYSQL_ATTR_INIT_COMMAND, "SET SESSION wait_timeout=28800");
        echo "[DB] Connected to database\n";
        return $pdo;
    } catch (PDOException $e) {
        error_log("[DB ERROR] Connection failed: " . $e->getMessage());
        return null;
    }
}

// Function to check if connection is alive
function isDatabaseAlive($pdo) {
    try {
        $pdo->query("SELECT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

$pdo = connectDatabase($dbHost, $dbName, $dbUser, $dbPass);
if (!$pdo) {
    echo "Initial database connection failed. Retrying in 5 seconds...\n";
    sleep(5);
    $pdo = connectDatabase($dbHost, $dbName, $dbUser, $dbPass);
    if (!$pdo) {
        error_log("Database connection failed after retry. Exiting.");
        exit(1);
    }
}

$lastHealthCheck = time();
$healthCheckInterval = 60; // Check connection every 60 seconds

// Initialize queue if enabled
$logQueue = null;
if ($USE_QUEUE) {
    $logQueue = new LogQueue($pdo, $QUEUE_BUFFER_SIZE, $QUEUE_FLUSH_INTERVAL);
    echo "[QUEUE] Log queue enabled (buffer: {$QUEUE_BUFFER_SIZE}, flush interval: {$QUEUE_FLUSH_INTERVAL}s)\n";
} else {
    echo "[QUEUE] Log queue disabled, using direct inserts\n";
}

echo "Starting log parser...\n";

// Cleanup old data on startup to prevent bloat
echo "Cleaning up old data (older than 90 days)...\n";
try {
    $pdo->exec("DELETE FROM access_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $pdo->exec("DELETE FROM request_telemetry WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $pdo->exec("DELETE FROM modsec_events WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $pdo->exec("DELETE FROM bot_detections WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    echo "Cleanup complete.\n";
} catch (Exception $e) {
    error_log("Cleanup failed: " . $e->getMessage());
}

while (true) {
    // Periodic health check and reconnect if needed
    if (time() - $lastHealthCheck > $healthCheckInterval) {
        if (!isDatabaseAlive($pdo)) {
            echo "[DB WARN] Connection lost (MySQL server has gone away). Reconnecting...\n";
            $pdo = connectDatabase($dbHost, $dbName, $dbUser, $dbPass);
            if (!$pdo) {
                echo "[DB ERROR] Reconnection failed. Retrying in 10 seconds...\n";
                sleep(10);
                continue;
            }
        }
        $lastHealthCheck = time();
    }
    
    // Find all access log files
    $logFiles = glob("$logDir/*-access.log");
    if (empty($logFiles)) {
        $logFiles = ["$logDir/access.log"];
    }
    
    foreach ($logFiles as $logFile) {
        if (!file_exists($logFile)) {
            continue;
        }
        
        // Get position file for this specific log
        $posFile = $posFilePrefix . basename($logFile) . '.pos';
        $lastPos = 0;
        if (file_exists($posFile)) {
            $lastPos = (int)file_get_contents($posFile);
        }

        $handle = fopen($logFile, 'r');
        if (!$handle) {
            error_log("Failed to open log file: $logFile");
            continue;
        }

        // Seek to last position
        fseek($handle, $lastPos);

        while (($line = fgets($handle)) !== false) {
            $lastPos = ftell($handle);
        
            // Parse nginx log line with enhanced format including X-Real-IP
        // Format: $host $http_x_real_ip $remote_addr - [$time_local] "$request" $status $body_bytes_sent "$http_referer" "$http_user_agent" rt=$request_time uct="$upstream_connect_time" uht="$upstream_header_time" urt="$upstream_response_time" cs=$upstream_cache_status ua="$upstream_addr"
        $pattern = '/^(\S+) (\S+) (\S+) - \[([^\]]+)\] "([^"]*)" (\d+) (\d+) "([^"]*)" "([^"]*)"(?:\s+rt=([\d.]+))?(?:\s+uct="([^"]*)")?(?:\s+uht="([^"]*)")?(?:\s+urt="([^"]*)")?(?:\s+cs=(\S+))?(?:\s+ua="([^"]*)")?.*/s';
        
        $matchResult = @preg_match($pattern, $line, $matches);
        
        if ($matchResult === false) {
            // Regex error - log and skip this line
            error_log("Regex error parsing log line: " . substr($line, 0, 100));
            continue;
        }
        
        if ($matchResult === 1) {
            $host = $matches[1];
            $xRealIp = $matches[2];
            $remoteAddr = $matches[3];
            $timeLocal = $matches[4];
            $request = $matches[5];
            $status = (int)$matches[6];
            $bodyBytes = (int)$matches[7];
            $referer = $matches[8];
            $userAgent = $matches[9];
            
            // Use X-Real-IP if available (real client IP), otherwise fall back to remote_addr
            $clientIp = ($xRealIp !== '-' && $xRealIp !== '') ? $xRealIp : $remoteAddr;
            
            // Extract enhanced telemetry fields (if available)
            $requestTime = isset($matches[10]) && $matches[10] !== '' ? (float)$matches[10] : null;
            $upstreamConnectTime = isset($matches[11]) && $matches[11] !== '' && $matches[11] !== '-' ? (float)$matches[11] : null;
            $upstreamHeaderTime = isset($matches[12]) && $matches[12] !== '' && $matches[12] !== '-' ? (float)$matches[12] : null;
            $upstreamResponseTime = isset($matches[13]) && $matches[13] !== '' && $matches[13] !== '-' ? (float)$matches[13] : null;
            $cacheStatus = isset($matches[14]) && $matches[14] !== '' && $matches[14] !== '-' ? $matches[14] : null;
            $upstreamAddr = isset($matches[15]) && $matches[15] !== '' && $matches[15] !== '-' ? $matches[15] : 'unknown';

            // Parse request
            $requestParts = explode(' ', $request);
            $method = $requestParts[0] ?? '';
            $uri = $requestParts[1] ?? '';
            $protocol = $requestParts[2] ?? '';
            
            // Truncate and redact sensitive data from URI
            $uri = sanitizeUri($uri);

            // Parse time - nginx format: 16/Jan/2025:02:00:00 +0200
            $timestamp = strtotime(str_replace('/', ' ', $timeLocal));
            if ($timestamp === false) {
                // Fallback to current time
                $timestamp = time();
            }
            $logTime = date('Y-m-d H:i:s', $timestamp);

            // Skip unknown hosts or default backend
            if ($host === '-' || $host === 'unknown' || $host === 'localhost') {
                continue;
            }

            try {
                // Detect bot type
                $botDetection = detectBot($userAgent, $botPatterns);
                $botType = $botDetection['type'];
                $botName = $botDetection['name'] ?? 'unknown';
                
                // Insert into access_logs (via queue or direct)
                $accessLogData = [
                    'domain' => $host,
                    'ip_address' => $clientIp,
                    'request_uri' => $uri,
                    'method' => $method,
                    'status_code' => $status,
                    'bytes_sent' => $bodyBytes,
                    'user_agent' => $userAgent,
                    'referer' => $referer,
                    'timestamp' => $logTime
                ];
                
                if ($logQueue) {
                    $logQueue->addAccessLog($accessLogData);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO access_logs (
                            domain, ip_address, request_uri, method,
                            status_code, bytes_sent, user_agent, referer, 
                            timestamp
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $host, $clientIp, $uri, $method,
                        $status, $bodyBytes, $userAgent, $referer, $logTime
                    ]);
                }
                
                // Record bot detection if bot identified
                if ($botType !== 'human') {
                    try {
                        // Query bot_whitelist to determine actual action based on priority
                        $actionStmt = $pdo->prepare("
                            SELECT action FROM bot_whitelist 
                            WHERE enabled = 1 AND ? REGEXP REPLACE(pattern, '~*', '(?i)')
                            ORDER BY priority ASC, id ASC
                            LIMIT 1
                        ");
                        $actionStmt->execute([$userAgent]);
                        $whitelistRule = $actionStmt->fetch();
                        
                        // Determine action: check whitelist first, then status code
                        if ($whitelistRule) {
                            $action = $whitelistRule['action'];
                        } else {
                            $action = ($status == 403) ? 'blocked' : 'allowed';
                        }
                        
                        echo "[DEBUG] Bot detected: name=$botName, type=$botType, action=$action, UA=$userAgent, IP=$clientIp\n";
                        
                        // Insert bot detection (via queue or direct)
                        $botData = [
                            'ip_address' => $clientIp,
                            'user_agent' => $userAgent,
                            'bot_name' => $botName,
                            'bot_type' => $botType,
                            'action' => $action,
                            'domain' => $host,
                            'timestamp' => $logTime
                        ];
                        
                        if ($logQueue) {
                            $logQueue->addBotDetection($botData);
                        } else {
                            $botStmt = $pdo->prepare("
                                INSERT INTO bot_detections (
                                    ip_address, user_agent, bot_name, bot_type, action, 
                                    domain, timestamp
                                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            $botStmt->execute([
                                $clientIp, $userAgent, $botName, $botType, $action, $host, $logTime
                            ]);
                        }
                        
                        echo "[BOT] $botName ($botType) detected: $userAgent ($action)\n";
                    } catch (PDOException $e) {
                        // Table might not exist yet, log the error
                        echo "[ERROR] Failed to insert bot detection: " . $e->getMessage() . "\n";
                    }
                }
                
                // Scanner detection
                detectScanner($clientIp, $uri, $status, $host, $pdo, $wpPaths, $exploitPaths, $scannerTracking);
                
                // Record telemetry for ALL requests (including errors)
                try {
                    // Use upstream_response_time if available, otherwise request_time
                    $responseTime = $upstreamResponseTime ?? $requestTime;
                    
                    $telemetryData = [
                        'domain' => $host,
                        'uri' => $uri,
                        'method' => $method,
                        'status_code' => $status,
                        'ip_address' => $clientIp,
                        'bytes_sent' => $bodyBytes,
                        'response_time' => $responseTime,
                        'cache_status' => $cacheStatus,
                        'backend_server' => $upstreamAddr,
                        'timestamp' => $logTime
                    ];
                    
                    if ($logQueue) {
                        $logQueue->addTelemetry($telemetryData);
                    } else {
                        $telemetryStmt = $pdo->prepare("
                            INSERT INTO request_telemetry (
                                domain, uri, method, status_code, ip_address,
                                bytes_sent, response_time,
                                cache_status, backend_server, timestamp
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $telemetryStmt->execute([
                            $host, $uri, $method, $status, $clientIp,
                            $bodyBytes, $responseTime, $cacheStatus, $upstreamAddr, $logTime
                        ]);
                    }
                } catch (PDOException $e) {
                    // Table might not exist yet, silently continue
                }

                echo "[" . date('Y-m-d H:i:s') . "] Logged: $host $method $uri ($status)\n";

            } catch (PDOException $e) {
                // Check if connection was lost
                if (strpos($e->getMessage(), 'MySQL server has gone away') !== false || 
                    strpos($e->getMessage(), 'Lost connection') !== false) {
                    echo "[DB ERROR] Connection lost during query: " . $e->getMessage() . "\n";
                    echo "[DB] Attempting immediate reconnection...\n";
                    $pdo = connectDatabase($dbHost, $dbName, $dbUser, $dbPass);
                    if (!$pdo) {
                        error_log("[DB ERROR] Reconnection failed. Will retry on next iteration.");
                    }
                } else {
                    error_log("Failed to insert log entry: " . $e->getMessage());
                }
            }
        } else {
            // Line didn't match expected format - log once per 100 failures to avoid spam
            static $unmatchedCount = 0;
            $unmatchedCount++;
            if ($unmatchedCount % 100 === 1) {
                error_log("Log line format mismatch (occurrence #$unmatchedCount): " . substr($line, 0, 150));
            }
        }  // Close if (preg_match)
        }  // Close while (($line = fgets))

        // Save position for this log file
        file_put_contents($posFile, $lastPos);

        fclose($handle);
    }
    
    // Flush queue if enabled
    if ($logQueue) {
        $logQueue->flush();
        
        // Periodically check disk queue (every 5 iterations = ~10 seconds)
        static $iterCount = 0;
        $iterCount++;
        if ($iterCount % 5 === 0) {
            $logQueue->processDiskQueue();
        }
        
        // Print stats every 30 iterations (~60 seconds)
        if ($iterCount % 30 === 0) {
            $stats = $logQueue->getStats();
            echo "[QUEUE STATS] Flushed: {$stats['flushed']}, Disk queued: {$stats['disk_queued']}, Recovered: {$stats['disk_recovered']}, Errors: {$stats['errors']}\n";
        }
    }
    
    // Parse ModSecurity audit logs (once per iteration, not per file)
    parseModSecAuditLog();
    
    // Wait before checking for new logs
    sleep(2);
}

/**
 * Sanitize URI by truncating and redacting sensitive parameters
 * @param string $uri The original URI
 * @return string Sanitized URI
 */
function sanitizeUri($uri) {
    try {
        // Parse URI into components - wrap in try-catch to handle malformed URIs
        $parts = @parse_url($uri);
        if (!$parts) {
            // If parse_url fails, just truncate the raw URI
            return substr($uri, 0, 255);
        }
        
        $path = $parts['path'] ?? '/';
        $query = $parts['query'] ?? '';
        
        // Truncate path if too long (keep first 200 chars)
        if (strlen($path) > 200) {
            $path = substr($path, 0, 200) . '...[truncated]';
        }
        
        // Redact sensitive query parameters
        if (!empty($query)) {
            $params = [];
            // Suppress warnings from parse_str for malformed query strings
            @parse_str($query, $params);
            
            if (empty($params)) {
                // If parse_str failed, just truncate the query string
                if (strlen($query) > 200) {
                    $query = substr($query, 0, 200) . '...[truncated]';
                }
            } else {
                $redacted = [];
                
                foreach ($params as $key => $value) {
                    // Skip non-string values
                    if (!is_string($value)) {
                        if (is_array($value) || is_object($value)) {
                            error_log("URI sanitization: Non-string query param value for key '{$key}' - type: " . gettype($value));
                        }
                        $value = '';
                    }
                    
                    // Check if parameter name contains sensitive keywords
                    $keyLower = strtolower($key);
                    if (preg_match('/(token|key|password|secret|auth|api[_-]?key|access[_-]?token|jwt|bearer)/i', $keyLower)) {
                        // Redact the value but keep first/last 4 chars if long enough
                        if (strlen($value) > 12) {
                            $redacted[$key] = substr($value, 0, 4) . '...[REDACTED]...' . substr($value, -4);
                        } else {
                            $redacted[$key] = '[REDACTED]';
                        }
                    } else {
                        // Keep parameter but truncate if too long
                        if (strlen($value) > 100) {
                            $redacted[$key] = substr($value, 0, 100) . '...[truncated]';
                        } else {
                            $redacted[$key] = $value;
                        }
                    }
                }
                
                $query = http_build_query($redacted);
            }
        }
        
        // Reconstruct URI
        $sanitized = $path;
        if (!empty($query)) {
            $sanitized .= '?' . $query;
        }
        
        // Final length check (database column limit)
        if (strlen($sanitized) > 500) {
            $sanitized = substr($sanitized, 0, 500) . '...[truncated]';
        }
        
        return $sanitized;
    } catch (Exception $e) {
        // If anything goes wrong, just return a truncated version of the original URI
        error_log("URI sanitization error: " . $e->getMessage());
        return substr($uri, 0, 255);
    }
}

// Parse ModSecurity audit log
function parseModSecAuditLog() {
    global $pdo, $modsecAuditLog, $modsecPosFile;
    
    if (!file_exists($modsecAuditLog)) {
        echo "[DEBUG] ModSec audit log not found: $modsecAuditLog\n";
        return;
    }
    
    // Get last read position
    $lastPos = 0;
    if (file_exists($modsecPosFile)) {
        $lastPos = (int)file_get_contents($modsecPosFile);
    }
    
    $handle = fopen($modsecAuditLog, 'r');
    if (!$handle) {
        return;
    }
    
    fseek($handle, $lastPos);
    
    $currentEntry = [];
    $inEntry = false;
    $lineCount = 0;
    
    while (($line = fgets($handle)) !== false) {
        $lastPos = ftell($handle);
        $rawLine = $line;
        $line = trim($line);
        $lineCount++;
        
        // Debug: Show first few lines
        if ($lineCount <= 5) {
            echo "[DEBUG] Line $lineCount (raw length: " . strlen($rawLine) . "): " . substr($line, 0, 50) . "\n";
            echo "[DEBUG] First chars: " . bin2hex(substr($line, 0, 20)) . "\n";
        }
        
        // ModSec audit log format: entries start with ---XXXXXXXX---A-- and end with ---XXXXXXXX---Z--
        if (preg_match('/^---([a-zA-Z0-9]{8})---A--$/', $line, $matches)) {
            // Start of new entry
            $inEntry = true;
            $currentEntry = ['id' => $matches[1], 'sections' => []];
            echo "[DEBUG] Started new entry: {$matches[1]}\n";
        } elseif (preg_match('/^---([a-zA-Z0-9]{8})---Z--$/', $line, $matches)) {
            // End of entry - process it BEFORE checking for section marker
            if (!empty($currentEntry)) {
                echo "[DEBUG] Processing ModSec entry: {$currentEntry['id']}\n";
                processModSecEntry($pdo, $currentEntry);
            }
            $inEntry = false;
            $currentEntry = [];
        } elseif (preg_match('/^---([a-zA-Z0-9]{8})---([A-Z])--$/', $line, $matches)) {
            // Section marker (not Z, which is handled above)
            $currentEntry['current_section'] = $matches[2];
            $currentEntry['sections'][$matches[2]] = [];
            echo "[DEBUG] Section marker: {$matches[2]}\n";
        } elseif ($inEntry && isset($currentEntry['current_section'])) {
            // Collect section data
            $section = $currentEntry['current_section'];
            $currentEntry['sections'][$section][] = $line;
        }
    }
    
    // Save position
    file_put_contents($modsecPosFile, $lastPos);
    fclose($handle);
}

function processModSecEntry($pdo, $entry) {
    try {
        // Section A contains timestamp and unique ID
        // Section B contains request headers (extract IP, Host, URI)
        // Section F contains response headers
        // Section H contains audit log trailer (rule IDs, messages, severity)
        
        $timestamp = date('Y-m-d H:i:s');
        $ip = '';
        $domain = '';
        $uri = '';
        $method = '';
        $ruleIds = [];
        $messages = [];
        $severity = 0;
        $action = 'unknown';
        
        // Parse Section A (header with timestamp)
        if (isset($entry['sections']['A'])) {
            foreach ($entry['sections']['A'] as $line) {
                if (preg_match('/^\[([^\]]+)\]/', $line, $matches)) {
                    $timestamp = date('Y-m-d H:i:s', strtotime($matches[1]));
                }
            }
        }
        
        // Parse Section B (request headers)
        if (isset($entry['sections']['B'])) {
            $requestLine = '';
            foreach ($entry['sections']['B'] as $line) {
                if (preg_match('/^(GET|POST|PUT|DELETE|HEAD|OPTIONS|PATCH)\s+(\S+)/', $line, $matches)) {
                    $method = $matches[1];
                    $uri = $matches[2];
                    $requestLine = $line;
                } elseif (preg_match('/^Host:\s*(.+)$/i', $line, $matches)) {
                    $domain = trim($matches[1]);
                } elseif (preg_match('/^X-Forwarded-For:\s*(.+)$/i', $line, $matches)) {
                    $ip = trim(explode(',', $matches[1])[0]);
                }
            }
        }
        
        // Parse Section H (audit log trailer with rule matches)
        if (isset($entry['sections']['H'])) {
            foreach ($entry['sections']['H'] as $line) {
                // Extract rule IDs: [id "123456"]
                if (preg_match_all('/\[id\s+"(\d+)"\]/', $line, $matches)) {
                    $ruleIds = array_merge($ruleIds, $matches[1]);
                }
                
                // Extract messages: [msg "message text"]
                if (preg_match_all('/\[msg\s+"([^"]+)"\]/', $line, $matches)) {
                    $messages = array_merge($messages, $matches[1]);
                }
                
                // Extract severity: [severity "CRITICAL"]
                if (preg_match('/\[severity\s+"(\w+)"\]/', $line, $matches)) {
                    $severityMap = ['CRITICAL' => 5, 'ERROR' => 4, 'WARNING' => 3, 'NOTICE' => 2, 'INFO' => 1];
                    $severity = $severityMap[strtoupper($matches[1])] ?? 0;
                }
                
                // Extract action: [disruptive]
                if (strpos($line, '[disruptive]') !== false || strpos($line, 'Access denied') !== false) {
                    $action = 'blocked';
                } elseif (strpos($line, '[Warning]') !== false) {
                    $action = 'detected';
                }
            }
        }
        
        // Debug extracted data
        echo "[DEBUG] Extracted - Rules: " . count($ruleIds) . ", Messages: " . count($messages) . ", Domain: $domain, IP: $ip\n";
        
        // Only insert if we have meaningful data
        if (!empty($ruleIds) || !empty($messages)) {
            $stmt = $pdo->prepare("
                INSERT INTO modsec_events (
                    unique_id, ip_address, domain, uri, method,
                    rule_id, message, severity, action, timestamp
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $ruleId = !empty($ruleIds) ? implode(',', array_unique($ruleIds)) : null;
            $message = !empty($messages) ? implode(' | ', array_unique($messages)) : 'ModSecurity triggered';
            
            $stmt->execute([
                $entry['id'],
                $ip ?: 'unknown',
                $domain ?: 'unknown',
                $uri ?: '/',
                $method ?: 'GET',
                $ruleId,
                $message,
                $severity,
                $action,
                $timestamp
            ]);
            
            echo "[MODSEC] Event recorded: {$entry['id']} - Rule(s): $ruleId - Action: $action\n";
        }
    } catch (PDOException $e) {
        error_log("Failed to insert ModSec event: " . $e->getMessage());
    }
}

// Helper function to detect bot type from user agent
function detectBot($userAgent, $patterns) {
    $userAgentLower = strtolower($userAgent);
    
    // Check good bots first (whitelist)
    foreach ($patterns['good'] as $botPattern) {
        if (strpos($userAgentLower, strtolower($botPattern)) !== false) {
            return ['type' => 'good', 'name' => $botPattern];
        }
    }
    
    // Check bad bots
    foreach ($patterns['bad'] as $botPattern) {
        if (strpos($userAgentLower, strtolower($botPattern)) !== false) {
            return ['type' => 'bad', 'name' => $botPattern];
        }
    }
    
    // Not a bot
    return ['type' => 'human', 'name' => null];
}

// Scanner detection function
function detectScanner($ip, $uri, $status, $host, $pdo, $wpPaths, $exploitPaths, &$scannerTracking) {
    global $dbHost, $dbName, $dbUser, $dbPass;
    
    // Only track 404s and known scan paths
    if ($status != 404) {
        // Track successful requests for ratio calculation
        updateScannerSuccessCount($pdo, $ip);
        return;
    }
    
    $category = 'other';
    
    // Check for WordPress scanning
    foreach ($wpPaths as $wpPath) {
        if (stripos($uri, $wpPath) !== false) {
            $category = 'wordpress';
            break;
        }
    }
    
    // Check for config/exploit paths
    if ($category === 'other') {
        if (stripos($uri, '.env') !== false || stripos($uri, '.git') !== false) {
            $category = 'config';
        } elseif (stripos($uri, '.sql') !== false || stripos($uri, 'backup') !== false) {
            $category = 'backup';
        } elseif (stripos($uri, 'admin') !== false) {
            $category = 'admin';
        } elseif (stripos($uri, 'api') !== false) {
            $category = 'api';
        } elseif (stripos($uri, '.php') !== false) {
            $category = 'php';
        }
    }
    
    // Track in memory for this execution (for rate limiting)
    $trackKey = $ip;
    if (!isset($scannerTracking[$trackKey])) {
        $scannerTracking[$trackKey] = [
            '404_count' => 0,
            'paths' => [],
            'first_seen' => time()
        ];
    }
    
    $scannerTracking[$trackKey]['404_count']++;
    $scannerTracking[$trackKey]['paths'][] = $uri;
    
    // Update databases
    try {
        // 1. Update scanned_endpoints table
        $stmt = $pdo->prepare("
            INSERT INTO scanned_endpoints (path, category, hit_count, unique_ips, first_seen, last_seen)
            VALUES (?, ?, 1, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                hit_count = hit_count + 1,
                last_seen = NOW()
        ");
        $pathTruncated = substr($uri, 0, 500);
        $stmt->execute([$pathTruncated, $category]);
        
        // 2. Update scanner_ips table
        $stmt = $pdo->prepare("
            INSERT INTO scanner_ips (ip_address, scan_count, paths_scanned, first_seen, last_seen)
            VALUES (?, 1, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                scan_count = scan_count + 1,
                last_seen = NOW()
        ");
        $stmt->execute([$ip]);
        
        // Get scanner ID for request logging
        $stmt = $pdo->prepare("SELECT id FROM scanner_ips WHERE ip_address = ?");
        $stmt->execute([$ip]);
        $scannerId = $stmt->fetchColumn();
        
        // 3. Log the request (only for active scanners to save space)
        if ($scannerId && $scannerTracking[$trackKey]['404_count'] <= 100) {
            $stmt = $pdo->prepare("
                INSERT INTO scanner_requests (scanner_ip_id, path, status_code, domain, timestamp)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$scannerId, $pathTruncated, $status, $host]);
        }
        
        // 4. Check if we should auto-block
        $settings = getScannerSettings($pdo);
        
        if ($settings['detection_enabled'] === '1') {
            $threshold = (int)$settings['404_threshold'];
            $timeframe = (int)$settings['timeframe'];
            
            // Check if threshold exceeded in memory tracking
            if (time() - $scannerTracking[$trackKey]['first_seen'] <= $timeframe) {
                $count404 = $scannerTracking[$trackKey]['404_count'];
                
                if ($count404 >= $threshold && $settings['auto_block'] === '1') {
                    $duration = (int)$settings['auto_block_duration'];
                    $reason = "Scanner auto-blocked: $count404 404s in {$timeframe}s";
                    autoBlockScanner($ip, $reason, $duration, $pdo);
                    
                    // Update scanner status
                    $stmt = $pdo->prepare("
                        UPDATE scanner_ips 
                        SET status = 'blocked', auto_blocked = 1, action_taken_at = NOW() 
                        WHERE ip_address = ?
                    ");
                    $stmt->execute([$ip]);
                    
                    echo "[SCANNER BLOCKED] $ip - $reason\n";
                }
            }
        }
        
    } catch (PDOException $e) {
        // Tables might not exist yet
        if (strpos($e->getMessage(), "doesn't exist") === false) {
            echo "[ERROR] Scanner detection failed: " . $e->getMessage() . "\n";
        }
    }
}

// Update success count for an IP (for ratio calculation)
function updateScannerSuccessCount($pdo, $ip) {
    try {
        $pdo->prepare("
            UPDATE scanner_ips 
            SET successful_requests = successful_requests + 1 
            WHERE ip_address = ?
        ")->execute([$ip]);
    } catch (PDOException $e) {
        // Ignore - IP might not be tracked as scanner yet
    }
}

// Get scanner detection settings
function getScannerSettings($pdo) {
    $defaults = [
        'detection_enabled' => '1',
        '404_threshold' => '10',
        'timeframe' => '300',
        'success_ratio' => '0.1',
        'auto_block' => '0',
        'auto_block_duration' => '86400'
    ];
    
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'scanner_%'");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($rows as $row) {
            $key = str_replace('scanner_', '', $row['setting_key']);
            if (isset($defaults[$key])) {
                $defaults[$key] = $row['setting_value'];
            }
        }
    } catch (PDOException $e) {
        // Use defaults if settings table doesn't exist
    }
    
    return $defaults;
}

// Auto-block scanner IP
function autoBlockScanner($ip, $reason, $duration, $pdo) {
    try {
        // Add to banned_ips table
        $stmt = $pdo->prepare("
            INSERT INTO banned_ips (ip_address, reason, banned_by, ban_duration, expires_at, created_at)
            VALUES (?, ?, 'scanner-detection', ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())
            ON DUPLICATE KEY UPDATE
                reason = VALUES(reason),
                ban_duration = VALUES(ban_duration),
                expires_at = VALUES(expires_at)
        ");
        $stmt->execute([$ip, $reason, $duration, $duration]);
        
        // Trigger nginx banlist regeneration
        touch('/etc/nginx/sites-enabled/.reload_needed');
        
    } catch (PDOException $e) {
        echo "[ERROR] Auto-block failed: " . $e->getMessage() . "\n";
    }
}
