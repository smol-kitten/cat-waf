<?php

/**
 * Extract root domain from a subdomain
 * Examples: subdomain.example.com -> example.com, example.co.uk -> example.co.uk
 */
if (!function_exists('extractRootDomain')) {
    function extractRootDomain($domain) {
        // Remove wildcard prefix
        $domain = preg_replace('/^\*\./', '', $domain);
        
        // Split by dots
        $parts = explode('.', $domain);
        $count = count($parts);
        
        // Handle special TLDs (co.uk, com.au, etc.)
        if ($count >= 3 && in_array($parts[$count - 2] . '.' . $parts[$count - 1], [
            'co.uk', 'com.au', 'co.nz', 'co.za', 'com.br', 'com.mx',
            'co.jp', 'co.in', 'co.kr', 'ac.uk', 'gov.uk', 'org.uk'
        ])) {
            return $parts[$count - 3] . '.' . $parts[$count - 2] . '.' . $parts[$count - 1];
        }
        
        // Return last two parts (domain.tld)
        if ($count >= 2) {
            return $parts[$count - 2] . '.' . $parts[$count - 1];
        }
        
        return $domain;
    }
}

// Generate APR1-MD5 hash for htpasswd (Apache compatible)
function generateApr1Hash($plainpasswd) {
    $salt = substr(str_shuffle("abcdefghijklmnopqrstuvwxyz0123456789"), 0, 8);
    $len = strlen($plainpasswd);
    $text = $plainpasswd.'$apr1$'.$salt;
    $bin = pack("H32", md5($plainpasswd.$salt.$plainpasswd));
    for($i = $len; $i > 0; $i -= 16) { $text .= substr($bin, 0, min(16, $i)); }
    for($i = $len; $i > 0; $i >>= 1) { $text .= ($i & 1) ? chr(0) : $plainpasswd[0]; }
    $bin = pack("H32", md5($text));
    for($i = 0; $i < 1000; $i++) {
        $new = ($i & 1) ? $plainpasswd : $bin;
        if ($i % 3) $new .= $salt;
        if ($i % 7) $new .= $plainpasswd;
        $new .= ($i & 1) ? $bin : $plainpasswd;
        $bin = pack("H32", md5($new));
    }
    $tmp = "";
    for ($i = 0; $i < 5; $i++) {
        $k = $i + 6;
        $j = $i + 12;
        if ($j == 16) $j = 5;
        $tmp = $bin[$i].$bin[$k].$bin[$j].$tmp;
    }
    $tmp = chr(0).chr(0).$bin[11].$tmp;
    $tmp = strtr(strrev(substr(base64_encode($tmp), 2)),
        "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/",
        "./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz");
    return "$"."apr1$".$salt."$".$tmp;
}

function handleSites($method, $params, $db) {
    error_log("handleSites called: method=$method, params=" . json_encode($params));
    
    // Check if this is a backends sub-route: /sites/:id/backends
    if (isset($params[1]) && $params[1] === 'backends') {
        require_once __DIR__ . '/backends.php';
        return;
    }
    // Check if request wants raw generated config: /sites/:id/config
    if (isset($params[1]) && $params[1] === 'config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        error_log("Config endpoint matched! Site ID: " . $params[0]);
        // Ensure site exists
        $stmt = $db->prepare("SELECT * FROM sites WHERE id = ?");
        $stmt->execute([$params[0]]);
        $site = $stmt->fetch();
        if (!$site) {
            sendResponse(['error' => 'Site not found'], 404);
        }

        // Generate config (pass returnString=true to get config instead of writing)
        $config = generateSiteConfig($params[0], $site, true);
        if ($config === false) {
            sendResponse(['error' => 'Failed to generate config'], 500);
        }

        header('Content-Type: text/plain');
        echo $config;
        exit; // Important: exit to prevent sendResponse wrapper
    }
    
    switch ($method) {
        case 'GET':
            if (empty($params[0])) {
                // List all sites
                $stmt = $db->query("SELECT * FROM sites ORDER BY domain");
                sendResponse(['sites' => $stmt->fetchAll()]);
            } else {
                // Get specific site
                $stmt = $db->prepare("SELECT * FROM sites WHERE id = ? OR domain = ?");
                $stmt->execute([$params[0], $params[0]]);
                $site = $stmt->fetch();
                if ($site) {
                    sendResponse(['site' => $site]);
                } else {
                    sendResponse(['error' => 'Site not found'], 404);
                }
            }
            break;
            
        case 'POST':
            // Create new site
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['domain']) || empty($data['backend_url'])) {
                sendResponse(['error' => 'Missing required fields'], 400);
            }
            
            // Validate backend URL format
            $backend = preg_replace('#^https?://#', '', $data['backend_url']);
            if (!preg_match('/^[a-zA-Z0-9.-]+:[0-9]+$/', $backend) && !preg_match('/^[0-9.]+:[0-9]+$/', $backend)) {
                sendResponse(['error' => 'Invalid backend URL format. Use: hostname:port or IP:port'], 400);
            }
            
            $stmt = $db->prepare("
                INSERT INTO sites (domain, backend_url, enabled, rate_limit_zone, 
                    rate_limit_burst, enable_modsecurity, enable_bot_protection,
                    enable_rate_limit, custom_rate_limit, enable_geoip_blocking,
                    blocked_countries, allowed_countries, custom_config, ssl_enabled,
                    ssl_challenge_type, cf_api_token, cf_zone_id,
                    enable_gzip, enable_brotli, compression_level, compression_types,                     
                    enable_image_optimization, image_quality, image_max_width,
                    enable_waf_headers, enable_telemetry, custom_headers, ip_whitelist, local_only,
                    wildcard_subdomains, disable_http_redirect, cf_bypass_ratelimit,
                    cf_custom_rate_limit, cf_rate_limit_burst)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['domain'],
                $data['backend_url'],
                $data['enabled'] ?? 1,
                $data['rate_limit_zone'] ?? 'general',
                $data['rate_limit_burst'] ?? 20,
                $data['enable_modsecurity'] ?? 1,
                $data['enable_bot_protection'] ?? 1,
                $data['enable_rate_limit'] ?? 1,
                $data['custom_rate_limit'] ?? null,
                $data['enable_geoip_blocking'] ?? 0,
                $data['blocked_countries'] ?? null,
                $data['allowed_countries'] ?? null,
                $data['custom_config'] ?? null,
                $data['ssl_enabled'] ?? 0,
                $data['ssl_challenge_type'] ?? 'http-01',
                $data['cf_api_token'] ?? null,
                $data['cf_zone_id'] ?? null,
                $data['enable_gzip'] ?? 1,
                $data['enable_brotli'] ?? 0,
                $data['compression_level'] ?? 6,
                $data['compression_types'] ?? 'text/html text/css text/javascript application/json application/xml',
                $data['enable_image_optimization'] ?? 0,
                $data['image_quality'] ?? 85,
                $data['image_max_width'] ?? 1920,
                $data['enable_waf_headers'] ?? 1,
                $data['enable_telemetry'] ?? 1,
                $data['custom_headers'] ?? null,
                $data['ip_whitelist'] ?? null,
                $data['local_only'] ?? 0,
                $data['wildcard_subdomains'] ?? 0,
                $data['disable_http_redirect'] ?? 0,
                $data['cf_bypass_ratelimit'] ?? 0,
                $data['cf_custom_rate_limit'] ?? 100,
                $data['cf_rate_limit_burst'] ?? 200
            ]);
            
            $siteId = $db->lastInsertId();
            
            // Generate NGINX config
            generateSiteConfig($siteId, $data);
            
            // Auto-issue certificate if SSL is enabled
            if (!empty($data['ssl_enabled']) && !empty($data['domain'])) {
                triggerCertificateIssuance($data['domain'], $data['ssl_challenge_type'] ?? 'http-01', 
                                          $data['cf_api_token'] ?? null, $data['cf_zone_id'] ?? null);
            }
            
            sendResponse(['success' => true, 'id' => $siteId, 'message' => 'Site created'], 201);
            break;
            
        case 'PATCH':
            // Partial update (live updates)
            if (empty($params[0])) {
                sendResponse(['error' => 'Site ID required'], 400);
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data)) {
                sendResponse(['error' => 'No data provided'], 400);
            }
            
            // Use same logic as PUT but with fewer fields
            $fields = [];
            $values = [];
            
            $updatableFields = [
                'domain', 'backend_url', 'backend_protocol', 'enabled', 'rate_limit_zone', 'rate_limit_burst', 
                'enable_modsecurity', 'enable_geoip_blocking', 'blocked_countries', 
                'allowed_countries', 'custom_config', 'ssl_enabled', 'ssl_cert_path', 
                'ssl_key_path', 'ssl_redirect', 'enable_gzip', 'enable_brotli', 
                'compression_level', 'compression_types', 'enable_image_optimization', 
                'image_quality', 'image_max_width', 'image_webp_conversion', 'enable_waf_headers', 
                'enable_telemetry', 'enable_bot_protection', 'bot_protection_level', 'custom_headers', 
                'ip_whitelist', 'backends', 'lb_method', 'health_check_enabled', 
                'health_check_interval', 'health_check_path', 'wildcard_subdomains', 
                'custom_rate_limit', 'enable_rate_limit', 'hash_key', 'challenge_enabled', 
                'challenge_difficulty', 'challenge_duration', 'challenge_bypass_cf', 
                'ssl_challenge_type', 'cf_api_token', 'cf_zone_id', 'error_page_404', 
                'error_page_403', 'error_page_429', 'error_page_500', 'security_txt',
                'enable_basic_auth', 'basic_auth_username', 'basic_auth_password',
                'disable_http_redirect', 'cf_bypass_ratelimit', 'cf_custom_rate_limit', 'cf_rate_limit_burst',
                'websocket_enabled', 'websocket_protocol', 'websocket_port', 'websocket_path',
                'custom_error_pages_enabled', 'custom_403_html', 'custom_404_html', 'custom_429_html',
                'custom_500_html', 'custom_502_html', 'custom_503_html',
                'robots_txt', 'security_txt', 'humans_txt', 'ads_txt', 'wellknown_enabled'
            ];
            
            foreach ($updatableFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = ?";
                    $values[] = $data[$field];
                }
            }
            
            if (empty($fields)) {
                sendResponse(['error' => 'No valid fields to update'], 400);
            }
            
            // If backends are being updated, sync backend_url from first backend for compatibility
            if (array_key_exists('backends', $data)) {
                $backends = json_decode($data['backends'], true);
                if (!empty($backends) && is_array($backends)) {
                    // Set backend_url to first backend's address
                    $firstBackend = $backends[0];
                    $backendUrl = $firstBackend['address'] ?? '';
                    
                    // Add backend_url to update if not already present
                    if (!array_key_exists('backend_url', $data)) {
                        $fields[] = "backend_url = ?";
                        $values[] = $backendUrl;
                    }
                }
            }
            
            $values[] = $params[0];
            $stmt = $db->prepare("UPDATE sites SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($values);
            
            // Regenerate NGINX config
            $stmt = $db->prepare("SELECT * FROM sites WHERE id = ?");
            $stmt->execute([$params[0]]);
            $site = $stmt->fetch();
            
            if ($site) {
                generateSiteConfig($params[0], $site);
            }
            
            sendResponse(['success' => true, 'message' => 'Site updated live', 'updated_fields' => array_keys($data)]);
            break;
            
        case 'PUT':
            // Update site (bulk update)
            if (empty($params[0])) {
                sendResponse(['error' => 'Site ID required'], 400);
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $fields = [];
            $values = [];
            
            // All updatable fields
            $updatableFields = [
                'domain', 'backend_url', 'backend_protocol', 'enabled', 'rate_limit_zone', 'rate_limit_burst', 
                'enable_modsecurity', 'enable_geoip_blocking', 'blocked_countries', 
                'allowed_countries', 'custom_config', 'ssl_enabled', 'ssl_cert_path', 
                'ssl_key_path', 'ssl_redirect', 'enable_gzip', 'enable_brotli', 
                'compression_level', 'compression_types', 'enable_image_optimization', 'image_quality', 
                'image_max_width', 'enable_waf_headers', 'enable_telemetry', 
                'enable_bot_protection', 'custom_headers', 'ip_whitelist', 'local_only', 'backends',
                'lb_method', 'health_check_enabled', 'health_check_interval', 'health_check_path',
                'wildcard_subdomains', 'custom_rate_limit', 'enable_rate_limit', 'hash_key',
                'challenge_enabled', 'challenge_difficulty', 'challenge_duration', 
                'challenge_bypass_cf', 'ssl_challenge_type', 'cf_api_token', 'cf_zone_id',
                'disable_http_redirect', 'cf_bypass_ratelimit', 'cf_custom_rate_limit', 'cf_rate_limit_burst',
                'websocket_enabled', 'websocket_protocol', 'websocket_port', 'websocket_path'
            ];
            
            foreach ($updatableFields as $field) {
                if (isset($data[$field])) {
                    $fields[] = "$field = ?";
                    $values[] = $data[$field];
                }
            }
            
            if (empty($fields)) {
                sendResponse(['error' => 'No fields to update'], 400);
            }
            
            // Get old site data for comparison BEFORE updating
            $stmt = $db->prepare("SELECT ssl_enabled FROM sites WHERE id = ?");
            $stmt->execute([$params[0]]);
            $oldSite = $stmt->fetch();
            
            $values[] = $params[0];
            $stmt = $db->prepare("UPDATE sites SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($values);
            
            // Regenerate NGINX config
            $stmt = $db->prepare("SELECT * FROM sites WHERE id = ?");
            $stmt->execute([$params[0]]);
            $site = $stmt->fetch();
            
            if ($site) {
                generateSiteConfig($params[0], $site);
                
                // Auto-issue certificate if SSL was just enabled
                if (!empty($data['ssl_enabled']) && empty($oldSite['ssl_enabled'])) {
                    triggerCertificateIssuance(
                        $site['domain'],
                        $site['ssl_challenge_type'] ?? 'http-01',
                        $site['cf_api_token'] ?? null,
                        $site['cf_zone_id'] ?? null
                    );
                }
            }
            
            sendResponse(['success' => true, 'message' => 'Site updated']);
            break;
            
        case 'DELETE':
            // Delete site
            if (empty($params[0])) {
                sendResponse(['error' => 'Site ID required'], 400);
            }
            
            $stmt = $db->prepare("DELETE FROM sites WHERE id = ?");
            $stmt->execute([$params[0]]);
            
            // Remove NGINX config
            removeSiteConfig($params[0]);
            
            // Trigger reload
            touch("/etc/nginx/sites-enabled/.reload_needed");
            
            sendResponse(['success' => true, 'message' => 'Site deleted']);
            break;
            
        case 'COPY':
            // Copy site (duplicate database entry)
            if (empty($params[0])) {
                sendResponse(['error' => 'Site ID required'], 400);
            }
            
            // Get original site
            $stmt = $db->prepare("SELECT * FROM sites WHERE id = ?");
            $stmt->execute([$params[0]]);
            $originalSite = $stmt->fetch();
            
            if (!$originalSite) {
                sendResponse(['error' => 'Site not found'], 404);
            }
            
            // Create copy with .copy suffix
            $newDomain = $originalSite['domain'] . '.copy';
            
            // Check if domain already exists, add number if needed
            $counter = 1;
            while (true) {
                $checkStmt = $db->prepare("SELECT COUNT(*) FROM sites WHERE domain = ?");
                $checkStmt->execute([$newDomain]);
                if ($checkStmt->fetchColumn() == 0) break;
                $newDomain = $originalSite['domain'] . '.copy' . $counter;
                $counter++;
            }
            
            // Get all columns except id, created_at, updated_at
            $columns = array_keys($originalSite);
            $columns = array_filter($columns, function($col) {
                return !in_array($col, ['id', 'created_at', 'updated_at']);
            });
            
            $columnsList = implode(', ', $columns);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            
            // Prepare values
            $values = [];
            foreach ($columns as $col) {
                if ($col === 'domain') {
                    $values[] = $newDomain;
                } else {
                    $values[] = $originalSite[$col];
                }
            }
            
            // Insert copy
            $insertStmt = $db->prepare("INSERT INTO sites ($columnsList) VALUES ($placeholders)");
            $insertStmt->execute($values);
            
            $newSiteId = $db->lastInsertId();
            
            // Generate NGINX config for copy
            $newSiteData = $originalSite;
            $newSiteData['domain'] = $newDomain;
            $newSiteData['id'] = $newSiteId;
            generateSiteConfig($newSiteId, $newSiteData);
            
            sendResponse(['success' => true, 'id' => $newSiteId, 'domain' => $newDomain, 'message' => 'Site copied successfully']);
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

function generateSiteConfig($siteId, $siteData, $returnString = false) {
    global $db;
    
    // Fetch full site data if only ID provided
    if (is_numeric($siteId) && empty($siteData['domain'])) {
        $stmt = $db->prepare("SELECT * FROM sites WHERE id = ?");
        $stmt->execute([$siteId]);
        $siteData = $stmt->fetch();
        if (!$siteData) {
            error_log("Site ID $siteId not found");
            return false;
        }
    }
    
    $domain = $siteData['domain'];
    $backend_raw = $siteData['backend_url'];
    
    // Strip http:// or https:// from backend URL for upstream
    $backend = preg_replace('#^https?://#', '', $backend_raw);
    // Remove trailing slash if present
    $backend = rtrim($backend, '/');
    
    // Allow per-site override to disable HTTP->HTTPS redirect
    $disable_http_redirect = $siteData['disable_http_redirect'] ?? 0;
    $ssl_enabled = $siteData['ssl_enabled'] ?? 0;
    $modsec_enabled = $siteData['enable_modsecurity'] ?? 1;
    $geoip_enabled = $siteData['enable_geoip_blocking'] ?? 0;
    $blocked_countries = $siteData['blocked_countries'] ?? '';
    $rate_limit_zone = $siteData['rate_limit_zone'] ?? 'general';
    $custom_config = json_decode($siteData['custom_config'] ?? '{}', true);
    
    // New features for redirect and CF rate limiting
    $disable_http_redirect = $siteData['disable_http_redirect'] ?? 0;
    $cf_bypass_ratelimit = $siteData['cf_bypass_ratelimit'] ?? 0;
    $cf_custom_rate_limit = $siteData['cf_custom_rate_limit'] ?? 100;
    $cf_rate_limit_burst = $siteData['cf_rate_limit_burst'] ?? 200;
    
    // Advanced features
    $enable_gzip = $siteData['enable_gzip'] ?? 1;
    $enable_brotli = $siteData['enable_brotli'] ?? 1;
    $compression_level = $siteData['compression_level'] ?? 6;
    $enable_image_opt = $siteData['enable_image_optimization'] ?? 0;
    $image_quality = $siteData['image_quality'] ?? 85;
    $enable_waf_headers = $siteData['enable_waf_headers'] ?? 1;
    $enable_telemetry = $siteData['enable_telemetry'] ?? 1;
    $enable_bot_protection = $siteData['enable_bot_protection'] ?? 1;
    $custom_headers = $siteData['custom_headers'] ?? '';
    
    // Basic authentication
    $enable_basic_auth = $siteData['enable_basic_auth'] ?? 0;
    $basic_auth_username = $siteData['basic_auth_username'] ?? '';
    $basic_auth_password = $siteData['basic_auth_password'] ?? '';

    
    // Challenge mode configuration
    $challenge_enabled = $siteData['challenge_enabled'] ?? 0;
    $challenge_difficulty = $siteData['challenge_difficulty'] ?? 18;
    $challenge_duration = $siteData['challenge_duration'] ?? 1;
    $challenge_bypass_cf = $siteData['challenge_bypass_cf'] ?? 0;
    
    // Rate limiting configuration
    $enable_rate_limit = $siteData['enable_rate_limit'] ?? 1;
    $rate_limit_burst = $siteData['rate_limit_burst'] ?? 20;
    $custom_rate_limit = $siteData['custom_rate_limit'] ?? 10;
    
    // Error pages configuration
    $error_page_mode = $siteData['error_page_mode'] ?? 'template'; // 'template' or 'custom'
    $error_page_403 = $siteData['error_page_403'] ?? '/errors/403.html';
    $error_page_404 = $siteData['error_page_404'] ?? '/errors/404.html';
    $error_page_429 = $siteData['error_page_429'] ?? '/errors/429.html';
    $error_page_500 = $siteData['error_page_500'] ?? '/errors/500.html';
    
    // Load balancing configuration
    $backends = $siteData['backends'] ? json_decode($siteData['backends'], true) : null;
    $lb_method = $siteData['lb_method'] ?? 'round_robin';
    $health_check_enabled = $siteData['health_check_enabled'] ?? 0;
    $health_check_interval = $siteData['health_check_interval'] ?? 30;
    $health_check_path = $siteData['health_check_path'] ?? '/';
    
    // Wildcard subdomain support
    $wildcard_subdomains = $siteData['wildcard_subdomains'] ?? 0;
    
    // Build server_name directive
    if ($wildcard_subdomains && $domain !== '_') {
        $server_name = "*.{$domain} {$domain}";
    } else {
        $server_name = $domain;
    }
    
    // Create upstream name (sanitize domain)
    $upstream_name = preg_replace('/[^a-z0-9_]/', '_', strtolower($domain)) . '_backend';
    
    // Get backend protocol configuration from database
    $backend_protocol = $siteData['backend_protocol'] ?? 'http';
    $backend_speaks_https = ($backend_protocol === 'https');
    
    // Check if any backend in the array specifies https protocol
    if ($backends && is_array($backends) && count($backends) > 0) {
        foreach ($backends as $backend_config) {
            $backend_proto = $backend_config['protocol'] ?? '';
            if ($backend_proto === 'https') {
                $backend_speaks_https = true;
                break;
            }
        }
    }
    
    // Record upstream https usage in a global map so generateLocationBlock can use it
    if (!isset($GLOBALS['upstream_https'])) $GLOBALS['upstream_https'] = [];
    $GLOBALS['upstream_https'][$upstream_name] = $backend_speaks_https;
    
    // WebSocket configuration
    $websocket_enabled = $siteData['websocket_enabled'] ?? 0;
    $websocket_protocol = $siteData['websocket_protocol'] ?? 'ws';
    $websocket_port = $siteData['websocket_port'] ?? null;
    $websocket_path = $siteData['websocket_path'] ?? '/';
    
    error_log("Backend protocol for {$domain}: {$backend_protocol}, WebSocket: " . ($websocket_enabled ? "enabled ({$websocket_protocol})" : "disabled"));
    
    // Build NGINX config
    $config = "# Auto-generated config for {$domain}\n";
    $config .= "# Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Upstream definition with load balancing
    $config .= "upstream {$upstream_name} {\n";
    
    // Add load balancing method (if not round_robin which is default)
    if ($lb_method !== 'round_robin') {
        $config .= "    {$lb_method};\n";
    }
    
    // Add backend servers
    if ($backends && is_array($backends) && count($backends) > 0) {
        foreach ($backends as $backend_config) {
            $server = $backend_config['address'] ?? '';
            $protocol = $backend_config['protocol'] ?? $backend_protocol; // Use backend's protocol or site's default
            $port = $backend_config['port'] ?? ($protocol === 'https' ? 443 : 80);
            $weight = $backend_config['weight'] ?? 1;
            $max_fails = $backend_config['max_fails'] ?? 3;
            $fail_timeout = $backend_config['fail_timeout'] ?? 30;
            $backup = ($backend_config['backup'] ?? false) ? ' backup' : '';
            $down = ($backend_config['down'] ?? false) ? ' down' : '';
            
            if (!empty($server)) {
                // Build complete server address with protocol and port
                // Format: address:port (e.g., 10.10.0.1:443)
                $config .= "    server {$server}:{$port}";
                if ($weight != 1) $config .= " weight={$weight}";
                $config .= " max_fails={$max_fails} fail_timeout={$fail_timeout}s";
                $config .= $backup . $down;
                $config .= ";\n";
            }
        }
    } else {
        // Fallback to single backend_url
        $config .= "    server {$backend};\n";
    }
    
    $config .= "    keepalive 32;\n";
    $config .= "}\n\n";
    
    // Special handling for default catch-all server
    $isDefaultCatchall = ($domain === '_');
    
    if ($isDefaultCatchall) {
        // Default server for IP access and unknown hosts - show default_backend
        $config .= "server {\n";
        $config .= "    listen 80 default_server;\n";
        $config .= "    listen [::]:80 default_server;\n";
        $config .= "    server_name _;\n\n";
        $config .= "    # Check if IP is banned\n";
        $config .= "    if (\$ban) {\n";
        $config .= "        return 403;\n";
        $config .= "    }\n\n";
        $config .= "    # Rate limiting\n";
        $config .= "    limit_req zone=general burst=20 nodelay;\n";
        $config .= "    limit_conn addr 10;\n\n";
        $config .= "    location / {\n";
        $config .= "        proxy_pass http://default_backend;\n";
        $config .= "        proxy_set_header Host \$host;\n";
        $config .= "        proxy_set_header X-Real-IP \$remote_addr;\n";
        $config .= "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n";
        $config .= "        proxy_set_header X-Forwarded-Proto \$scheme;\n";
        $config .= "    }\n\n";
        $config .= "    # ACME challenge for Let's Encrypt\n";
        $config .= "    location ^~ /.well-known/acme-challenge/ {\n";
        $config .= "        root /var/www/certbot;\n";
        $config .= "        default_type \"text/plain\";\n";
        $config .= "    }\n";
        $config .= "}\n\n";
        
        // No HTTPS server for default catch-all
        // Skip the rest of the config generation and jump to file writing
    } else {
        // HTTP server (redirect to HTTPS or serve directly)
        $config .= "server {\n";
        $config .= "    listen 80;\n";
        $config .= "    listen [::]:80;\n";
        $config .= "    server_name {$server_name};\n\n";
    
    // Error pages (use helper function)
    $config .= generateCustomErrorPages($siteData);
    
    // Well-known files (robots.txt, security.txt, humans.txt, ads.txt)
    $config .= generateWellKnownFiles($siteData);
    
    // ACME challenge for Let's Encrypt
    $config .= "    location ^~ /.well-known/acme-challenge/ {\n";
    $config .= "        root /var/www/certbot;\n";
    $config .= "        default_type \"text/plain\";\n";
    $config .= "    }\n\n";
    
    if ($ssl_enabled) {
        // Check if HTTP->HTTPS redirect is disabled (to prevent infinite loops when backend also redirects)
        // Only disable nginx redirect if backend is actively redirecting to HTTPS (not just using port 80)
        if ($disable_http_redirect) {
            // Serve HTTP directly even with SSL enabled (backend handles redirect)
            $config .= generateLocationBlock($upstream_name, $domain, $modsec_enabled, $geoip_enabled, 
                                               $blocked_countries, $rate_limit_zone, $custom_config,                                               
                                               $enable_waf_headers, $enable_telemetry, $custom_headers,
                                               $enable_basic_auth, $basic_auth_username, $basic_auth_password,
                                               $enable_image_opt, $image_quality, $enable_bot_protection,
                                               false, false, false, false, // Disable challenge on HTTP when backend redirects
                                               $cf_bypass_ratelimit, $cf_custom_rate_limit, $cf_rate_limit_burst,
                                               $enable_rate_limit, $rate_limit_burst, $custom_rate_limit);
        } else {
            // Redirect to HTTPS
            $config .= "    location / {\n";
            $config .= "        return 301 https://\$server_name\$request_uri;\n";
            $config .= "    }\n";
        }
    } else {
        // Serve HTTP directly
        $config .= generateLocationBlock($upstream_name, $domain, $modsec_enabled, $geoip_enabled, 
                                           $blocked_countries, $rate_limit_zone, $custom_config,
                                           $enable_waf_headers, $enable_telemetry, $custom_headers,
                                           $enable_basic_auth, $basic_auth_username, $basic_auth_password,
                                           $enable_image_opt, $image_quality, $enable_bot_protection,
                                           false, false, false, false, // No challenge on plain HTTP
                                           $cf_bypass_ratelimit, $cf_custom_rate_limit, $cf_rate_limit_burst,
                                           $enable_rate_limit, $rate_limit_burst, $custom_rate_limit);
    }
    
    // Add WebSocket location to HTTP block if protocol is ws (not wss)
    if ($websocket_enabled && strtolower($websocket_protocol) === 'ws') {
        $config .= generateWebSocketLocation($upstream_name, $websocket_path, $websocket_protocol, $websocket_port, $backend);
    }
    
    $config .= "}\n\n";
    
    // HTTPS server (if SSL enabled)
    if ($ssl_enabled) {
        $config .= "server {\n";
        $config .= "    listen 443 ssl;\n";
        $config .= "    listen [::]:443 ssl;\n";
        $config .= "    listen 443 quic;\n";  // HTTP/3 QUIC (reuseport removed - causes conflicts with multiple sites)
        $config .= "    listen [::]:443 quic;\n";  // HTTP/3 QUIC IPv6
        $config .= "    http2 on;\n";
        $config .= "    http3 on;\n";
        $config .= "    server_name {$server_name};\n\n";
        
        // HTTP/3 optimizations
        $config .= "    # HTTP/3 configuration\n";
        $config .= "    quic_retry on;\n";  // Enable 0-RTT resume
        $config .= "    ssl_early_data on;\n";  // Enable 0-RTT
        $config .= "    quic_gso on;\n";  // Enable GSO for better performance
        $config .= "    add_header Alt-Svc 'h3=\":443\"; ma=86400' always;\n\n";
        
        // Error pages (use helper function - supports both custom HTML and templates)
        $config .= generateCustomErrorPages($siteData);
        
        // Well-known files (robots.txt, security.txt, humans.txt, ads.txt)
        $config .= generateWellKnownFiles($siteData);
        
        // JavaScript Challenge page
        if ($challenge_enabled) {
            $config .= "    # JavaScript Challenge page\n";
            $config .= "    location = /challenge.html {\n";
            $config .= "        root /usr/share/nginx/error-pages;\n";
            $config .= "        add_header Cache-Control \"no-cache, no-store, must-revalidate\";\n";
            $config .= "    }\n\n";
        }
        
        // SSL configuration
        $ssl_challenge_type = $siteData['ssl_challenge_type'] ?? 'http-01';
        
        // Try to copy certificates from acme.sh if they exist and are not snakeoil
        if ($ssl_challenge_type !== 'snakeoil') {
            // Check if acme.sh has a certificate for this domain
            $acmeCertPath = "/acme.sh/{$domain}/{$domain}_ecc/fullchain.cer";
            $acmeKeyPath = "/acme.sh/{$domain}/{$domain}_ecc/{$domain}.key";
            
            $nginxCertDir = "/etc/nginx/certs/{$domain}";
            $nginxCertPath = "{$nginxCertDir}/fullchain.pem";
            $nginxKeyPath = "{$nginxCertDir}/key.pem";
            
            // Check if acme.sh has certificates
            $checkCmd = "docker exec waf-acme test -f {$acmeCertPath} && echo 'exists' || echo 'missing' 2>&1";
            $acmeExists = trim(shell_exec($checkCmd));
            
            if ($acmeExists === 'exists') {
                // Check if it's a real Let's Encrypt cert or snakeoil
                $issuerCmd = "docker exec waf-acme openssl x509 -in {$acmeCertPath} -noout -issuer 2>&1";
                $issuer = shell_exec($issuerCmd);
                
                // If not self-signed (doesn't have CN=domain as issuer), copy to nginx
                if (strpos($issuer, "CN={$domain}") === false) {
                    error_log("Found Let's Encrypt certificate for {$domain}, copying to nginx...");
                    
                    // Create cert directory in nginx container and remove any old symlinks
                    $mkdirCmd = "docker exec waf-nginx sh -c 'mkdir -p {$nginxCertDir} && rm -f {$nginxCertPath} {$nginxKeyPath}' 2>&1";
                    shell_exec($mkdirCmd);
                    
                    // Copy cert: read from acme, write to nginx
                    $copyCertCmd = "docker exec waf-acme cat {$acmeCertPath} | docker exec -i waf-nginx sh -c 'cat > {$nginxCertPath}' 2>&1";
                    $certResult = shell_exec($copyCertCmd);
                    
                    // Copy key: read from acme, write to nginx
                    $copyKeyCmd = "docker exec waf-acme cat {$acmeKeyPath} | docker exec -i waf-nginx sh -c 'cat > {$nginxKeyPath}' 2>&1";
                    $keyResult = shell_exec($copyKeyCmd);
                    
                    // Verify files exist in nginx
                    $verifyCmd = "docker exec waf-nginx test -f {$nginxCertPath} && docker exec waf-nginx test -f {$nginxKeyPath} && echo 'success' || echo 'failed' 2>&1";
                    $verifyResult = trim(shell_exec($verifyCmd));
                    
                    if ($verifyResult === 'success') {
                        error_log("Successfully copied Let's Encrypt certificate for {$domain} to nginx");
                    } else {
                        error_log("Failed to verify copied certificate for {$domain}");
                    }
                }
            }
        }
        
        // Check if certificate files exist, generate snakeoil if missing
        $certPath = "/etc/nginx/certs/{$domain}/fullchain.pem";
        $keyPath = "/etc/nginx/certs/{$domain}/key.pem";
        
        // Check if certs exist in nginx container (check if it's a regular file, not a symlink)
        $certExistsCmd = "docker exec waf-nginx sh -c '[ -f {$certPath} ] && [ ! -L {$certPath} ] && echo exists || echo missing' 2>&1";
        $certExists = trim(shell_exec($certExistsCmd));
        
        if ($certExists !== 'exists') {
            // Generate snakeoil certificate if missing
            error_log("Certificate missing for {$domain}, generating snakeoil...");
            $certDir = "/etc/nginx/certs/{$domain}";
            $snakeoilCmd = sprintf(
                "docker exec waf-nginx sh -c 'mkdir -p %s && openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout %s/key.pem -out %s/fullchain.pem -subj \"/CN=%s\"' 2>&1",
                escapeshellarg($certDir),
                escapeshellarg($certDir),
                escapeshellarg($certDir),
                escapeshellarg($domain)
            );
            exec($snakeoilCmd, $snakeoilOutput, $snakeoilReturn);
            if ($snakeoilReturn !== 0) {
                error_log("Failed to generate snakeoil cert for {$domain}: " . implode("\n", $snakeoilOutput));
            }
        }
        
        // Determine certificate path - use base domain for wildcard coverage
        $baseDomain = extractRootDomain($domain);
        $certDomain = $baseDomain; // Always use base domain cert (covers wildcards)
        
        if ($ssl_challenge_type === 'snakeoil') {
            // Use self-signed snakeoil certificate (domain-specific)
            $config .= "    ssl_certificate /etc/nginx/certs/{$domain}/fullchain.pem;\n";
            $config .= "    ssl_certificate_key /etc/nginx/certs/{$domain}/key.pem;\n";
        } else {
            // Use Let's Encrypt certificate from base domain (wildcard support)
            $config .= "    ssl_certificate /etc/nginx/certs/{$certDomain}/fullchain.pem;\n";
            $config .= "    ssl_certificate_key /etc/nginx/certs/{$certDomain}/key.pem;\n";
        }
        $config .= "    ssl_protocols TLSv1.2 TLSv1.3;\n";
        $config .= "    ssl_ciphers HIGH:!aNULL:!MD5;\n";
        $config .= "    ssl_prefer_server_ciphers on;\n";
        $config .= "    ssl_session_cache shared:SSL:10m;\n";
        $config .= "    ssl_session_timeout 10m;\n\n";
        
        // HSTS
        $config .= "    add_header Strict-Transport-Security \"max-age=31536000; includeSubDomains\" always;\n\n";
        
        // Client body size (support for large file uploads like Bitwarden attachments)
        $config .= "    # Client settings\n";
        $config .= "    client_max_body_size 256M;\n";
        $config .= "    client_body_buffer_size 128k;\n\n";
        
        // Compression
        if ($enable_gzip) {
            $config .= "    # Gzip compression\n";
            $config .= "    gzip on;\n";
            $config .= "    gzip_vary on;\n";
            $config .= "    gzip_comp_level {$compression_level};\n";
            $config .= "    gzip_types text/plain text/css application/json application/javascript text/xml application/xml text/javascript;\n\n";
        }
        
        if ($enable_brotli) {
            $config .= "    # Brotli compression\n";
            $config .= "    brotli on;\n";
            $config .= "    brotli_comp_level {$compression_level};\n";
            $config .= "    brotli_types text/plain text/css application/json application/javascript text/xml application/xml text/javascript image/svg+xml;\n\n";
        }
        
        $config .= generateLocationBlock($upstream_name, $domain, $modsec_enabled, $geoip_enabled,
                                           $blocked_countries, $rate_limit_zone, $custom_config,
                                           $enable_waf_headers, $enable_telemetry, $custom_headers,
                                           $enable_basic_auth, $basic_auth_username, $basic_auth_password,
                                           $enable_image_opt, $image_quality, $enable_bot_protection,
                                           $challenge_enabled, $challenge_difficulty, $challenge_duration, $challenge_bypass_cf,
                                           $cf_bypass_ratelimit, $cf_custom_rate_limit, $cf_rate_limit_burst,
                                           $enable_rate_limit, $rate_limit_burst, $custom_rate_limit);
        
        // Add WebSocket location block to HTTPS if protocol is wss (not ws)
        if ($websocket_enabled && strtolower($websocket_protocol) === 'wss') {
            $config .= generateWebSocketLocation($upstream_name, $websocket_path, $websocket_protocol, $websocket_port, $backend);
        }
        
        $config .= "}\n";
    }
    
    } // end of if (!$isDefaultCatchall)
    
    // If returnString mode, just return the config without writing
    if ($returnString) {
        return $config;
    }
    
    // Write config file
    $config_path = "/etc/nginx/sites-enabled/{$domain}.conf";
    
    // Delete old file if it exists (may be owned by root)
    if (file_exists($config_path)) {
        @unlink($config_path);
    }
    
    $result = file_put_contents($config_path, $config);
    
    if ($result === false) {
        error_log("Failed to write config for {$domain}");
        return false;
    }
    
    error_log("Generated config for {$domain} at {$config_path}");
    
    // Write reload signal file
    touch("/etc/nginx/sites-enabled/.reload_needed");
    
    // Note: NGINX reload must be done manually or via external script
    // Run: docker exec waf-nginx nginx -s reload
    
    return true;
}

function generateLocationBlock($upstream, $domain, $modsec, $geoip, $blocked_countries, $rate_limit, $custom_config,
                               $enable_waf_headers = true, $enable_telemetry = true, $custom_headers = '',
                               $enable_basic_auth = false, $basic_auth_username = '', $basic_auth_password = '',
                               $enable_image_opt = false, $image_quality = 85, $enable_bot_protection = true,
                               $challenge_enabled = false, $challenge_difficulty = 18, $challenge_duration = 1, $challenge_bypass_cf = false,
                               $cf_bypass_ratelimit = false, $cf_custom_rate_limit = 100, $cf_rate_limit_burst = 200,
                               $enable_rate_limit = 1, $rate_limit_burst = 20, $custom_rate_limit = 10) {
    $block = "";
    
    // Ban list check
    $block .= "    if (\$ban) {\n";
    $block .= "        return 403;\n";
    $block .= "    }\n\n";
    
    // Bot protection
    if ($enable_bot_protection) {
        $block .= "    # Bot protection\n";
        $block .= "    if (\$bot_detected) {\n";
        $block .= "        return 403;\n";
        $block .= "    }\n\n";
    }
    
    // GeoIP blocking - DISABLED (GeoIP module requires legacy .dat files)
    // TODO: Install MaxMind GeoIP .dat database or implement GeoIP2
    /*
    if ($geoip && !empty($blocked_countries)) {
        $countries = str_replace(',', '|', trim($blocked_countries));
        if (!empty($countries)) {
            $block .= "    # GeoIP blocking\n";
            $block .= "    if (\$geoip_country_code ~ ^({$countries})\$) {\n";
            $block .= "        return 403;\n";
            $block .= "    }\n\n";
        }
    }
    */
    
    // Local-only access restriction (takes precedence over IP whitelist)
    if (!empty($local_only)) {
        $block .= "    # Local network only access\n";
        $block .= "    allow 127.0.0.0/8;     # Localhost\n";
        $block .= "    allow 10.0.0.0/8;      # Private Class A\n";
        $block .= "    allow 172.16.0.0/12;   # Private Class B\n";
        $block .= "    allow 192.168.0.0/16;  # Private Class C\n";
        $block .= "    deny all;\n\n";
    }
    // IP whitelist (only if local_only is not enabled)
    else if (isset($custom_config['ip_whitelist']) && !empty($custom_config['ip_whitelist'])) {
        $block .= "    # IP whitelist\n";
        $block .= "    allow " . str_replace(',', ";\n    allow ", $custom_config['ip_whitelist']) . ";\n";
        $block .= "    deny all;\n\n";
    }
    
    // Basic auth - now from database columns
    if (!empty($enable_basic_auth) && !empty($basic_auth_username) && !empty($basic_auth_password)) {
        $block .= "    # Basic authentication\n";
        $block .= "    auth_basic \"Restricted Access\";\n";
        $block .= "    auth_basic_user_file /etc/nginx/htpasswd/{$domain};\n\n";
        
        // Generate htpasswd file with APR1-MD5 hashing (Apache compatible)
        $htpasswd_path = "/etc/nginx/htpasswd/{$domain}";
        $hashed_password = generateApr1Hash($basic_auth_password);
        @mkdir(dirname($htpasswd_path), 0755, true);
        file_put_contents($htpasswd_path, "{$basic_auth_username}:{$hashed_password}\n");
    }
    
    // Rate limiting with Cloudflare bypass support
    // Check if rate limiting is actually enabled for this site
    if ($enable_rate_limit == 1 || $enable_rate_limit === true || $enable_rate_limit === '1') {
        $burst = intval($rate_limit_burst) > 0 ? intval($rate_limit_burst) : 20;
        $rate_value = intval($custom_rate_limit) > 0 ? intval($custom_rate_limit) : 10;
        
        // If burst is extremely high (>10000), treat as disabled (user wants unlimited)
        if ($burst > 10000) {
            $block .= "    # Rate limiting: DISABLED (burst value too high, treating as unlimited)\n\n";
        } else {
            $block .= "    # Rate limiting: ENABLED (rate={$rate_value}r/s, burst={$burst})\n";
            
            if ($cf_bypass_ratelimit) {
            // Use geo module to detect Cloudflare IPs and apply different rate limits
            $block .= "    # Cloudflare IP bypass - use relaxed rate limits\n";
            $block .= "    set \$is_cf 0;\n";
            $block .= "    # Check if request is from Cloudflare (via CF-Connecting-IP header)\n";
            $block .= "    if (\$http_cf_connecting_ip != \"\") {\n";
            $block .= "        set \$is_cf 1;\n";
            $block .= "    }\n\n";
            
            $block .= "    # Apply different rate limits for CF vs direct access\n";
            $block .= "    if (\$is_cf = 0) {\n";
            $block .= "        limit_req zone={$rate_limit} burst={$burst} nodelay;\n";
            $block .= "    }\n";
            $block .= "    # Cloudflare gets higher limits (defined globally in nginx.conf)\n";
            $block .= "    if (\$is_cf = 1) {\n";
            $cf_burst = intval($cf_rate_limit_burst) > 0 ? intval($cf_rate_limit_burst) : 200;
            $block .= "        limit_req zone=cloudflare burst={$cf_burst} nodelay;\n";
            $block .= "    }\n";
            } else {
                // Standard rate limiting
                $block .= "    limit_req zone={$rate_limit} burst={$burst} nodelay;\n";
            }
            $conn_limit = 20; // Default connection limit
            $block .= "    limit_conn addr {$conn_limit};\n\n";
        }
    } else {
        $block .= "    # Rate limiting: DISABLED\n\n";
    }
    
    // ModSecurity
    $block .= "    # ModSecurity WAF\n";
    $block .= "    modsecurity " . ($modsec ? "on" : "off") . ";\n\n";
    
    // Logging
    $block .= "    # Logging\n";
    $block .= "    access_log /var/log/nginx/{$domain}-access.log waf;\n";
    $block .= "    error_log /var/log/nginx/{$domain}-error.log;\n\n";
    
    // WAF and Security Headers (X-Frame-Options, X-Content-Type-Options, X-XSS-Protection added globally in nginx.conf)
    if ($enable_waf_headers) {
        $block .= "    # WAF identification headers\n";
        $block .= "    add_header X-Protected-By \"CatWAF v1.0\" always;\n";
        $block .= "    add_header X-WAF-Status \"active\" always;\n";
        // Duplicate headers removed - already in nginx.conf global config
        // $block .= "    add_header X-Frame-Options \"SAMEORIGIN\" always;\n";
        // $block .= "    add_header X-Content-Type-Options \"nosniff\" always;\n";
        // $block .= "    add_header X-XSS-Protection \"1; mode=block\" always;\n";
        $block .= "    add_header Referrer-Policy \"strict-origin-when-cross-origin\" always;\n\n";
    }
    
    // Telemetry headers
    if ($enable_telemetry) {
        $block .= "    # Telemetry\n";
        $block .= "    add_header X-Request-ID \$request_id always;\n";
        $block .= "    add_header X-Response-Time \$request_time always;\n";
        // X-Backend-Server removed - exposes internal backend topology to end users (security risk)
        // $block .= "    add_header X-Backend-Server \$upstream_addr always;\n";
        $block .= "\n";
    }
    
    // Development mode headers - check if enabled in settings
    global $db;
    if (!isset($db)) $db = getDB();
    $devModeStmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'dev_mode_headers'");
    $devModeStmt->execute();
    $devModeResult = $devModeStmt->fetch(PDO::FETCH_ASSOC);
    $devModeEnabled = $devModeResult && $devModeResult['setting_value'] === '1';
    
    if ($devModeEnabled) {
        $block .= "    # Development Mode Headers (Debug Info)\n";
        $block .= "    add_header X-Dev-Backend-Addr \$upstream_addr always;\n";
        $block .= "    add_header X-Dev-Backend-Status \$upstream_status always;\n";
        $block .= "    add_header X-Dev-Backend-Response-Time \$upstream_response_time always;\n";
        $block .= "    add_header X-Dev-Backend-Connect-Time \$upstream_connect_time always;\n";
        $block .= "    add_header X-Dev-Backend-Header-Time \$upstream_header_time always;\n";
        $block .= "    add_header X-Dev-Proxy-Host \$proxy_host always;\n";
        $block .= "    add_header X-Dev-Upstream-Name \"{$upstream}\" always;\n";
        $block .= "    add_header X-Dev-Request-Uri \$request_uri always;\n";
        $block .= "    add_header X-Dev-Server-Name \$server_name always;\n";
        $block .= "\n";
    }
    
    // Custom headers
    if (!empty($custom_headers)) {
        $block .= "    # Custom headers\n";
        $headers_array = explode('\n', $custom_headers);
        foreach ($headers_array as $header) {
            $header = trim($header);
            if (!empty($header)) {
                $block .= "    add_header {$header} always;\n";
            }
        }
        $block .= "\n";
    }
    
      
    // Image optimization proxy (if enabled)
    if ($enable_image_opt) {
        $block .= "    # Image optimization\n";
        $block .= "    location ~* \\.(jpg|jpeg|png)$ {\n";
        
        // Add JavaScript Challenge check for images too
        if ($challenge_enabled) {
            $block .= "        # JavaScript Challenge Mode\n";
            $block .= "        set \$challenge_passed 0;\n";
            $block .= "        \n";
            $block .= "        if (\$cookie_waf_challenge) {\n";
            $block .= "            set \$challenge_passed 1;\n";
            $block .= "        }\n";
            $block .= "        if (\$cookie_waf_difficulty != '{$challenge_difficulty}') {\n";
            $block .= "            set \$challenge_passed 0;\n";
            $block .= "        }\n\n";
            
            // Bypass for Cloudflare if enabled
            if ($challenge_bypass_cf) {
                $block .= "        # Bypass challenge for Cloudflare\n";
                $block .= "        # Check CF-Connecting-IP header instead of CF-Visitor to preserve Host header\n";
                $block .= "        if (\$http_cf_connecting_ip != \"\") {\n";
                $block .= "            set \$challenge_passed 1;\n";
                $block .= "        }\n\n";
            }
            
            $block .= "        # Redirect to challenge if not verified\n";
            $block .= "        if (\$challenge_passed = 0) {\n";
            $block .= "            return 302 /challenge.html?difficulty={$challenge_difficulty}&duration={$challenge_duration}&redirect=\$scheme://\$host\$request_uri;\n";
            $block .= "        }\n\n";
        }
        
        $block .= "        proxy_pass http://{$upstream};\n";
        $block .= "        image_filter resize 1920 -;\n";
        $block .= "        image_filter_jpeg_quality {$image_quality};\n";
        $block .= "        image_filter_buffer 20M;\n";
        $block .= "    }\n\n";
    }
    
    // Proxy configuration
    $block .= "    location / {\n";
    
    // JavaScript Challenge Mode - INSIDE location block
    if ($challenge_enabled) {
        $block .= "        # JavaScript Challenge Mode\n";
        $block .= "        set \$challenge_passed 0;\n";
        $block .= "        \n";
        $block .= "        if (\$cookie_waf_challenge) {\n";
        $block .= "            set \$challenge_passed 1;\n";
        $block .= "        }\n";
        $block .= "        if (\$cookie_waf_difficulty != '{$challenge_difficulty}') {\n";
        $block .= "            set \$challenge_passed 0;\n";
        $block .= "        }\n\n";
        
        // Bypass for Cloudflare if enabled
        if ($challenge_bypass_cf) {
            $block .= "        # Bypass challenge for Cloudflare\n";
            $block .= "        # Check CF-Connecting-IP header instead of CF-Visitor to preserve Host header\n";
            $block .= "        if (\$http_cf_connecting_ip != \"\") {\n";
            $block .= "            set \$challenge_passed 1;\n";
            $block .= "        }\n\n";
        }
        
        $block .= "        # Redirect to challenge if not verified\n";
        $block .= "        if (\$challenge_passed = 0) {\n";
        $block .= "            return 302 /challenge.html?difficulty={$challenge_difficulty}&duration={$challenge_duration}&redirect=\$scheme://\$host\$request_uri;\n";
        $block .= "        }\n\n";
    }

    // If upstream backend expects HTTPS, use https:// scheme and enable proxy_ssl
    $use_https = isset($GLOBALS['upstream_https'][$upstream]) && $GLOBALS['upstream_https'][$upstream];
    $proxy_scheme = $use_https ? 'https' : 'http';
    $block .= "        proxy_pass {$proxy_scheme}://{$upstream};\n";
    if ($use_https) {
        $block .= "        proxy_ssl_server_name on;\n";
        $block .= "        proxy_ssl_name \$host;\n";
        // Do not verify upstream cert by default (internal network)
        $block .= "        proxy_ssl_verify off;\n";
    }
    $block .= "        proxy_http_version 1.1;\n\n";
    
    $block .= "        # Proxy headers\n";
    $block .= "        # Use \$http_host to preserve the original Host header from client\n";
    $block .= "        # This ensures backend vhosts receive the correct domain name\n";
    $block .= "        proxy_set_header Host \$http_host;\n";
    $block .= "        proxy_set_header X-Real-IP \$remote_addr;\n";
    $block .= "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n";
    $block .= "        proxy_set_header X-Forwarded-Proto \$scheme;\n";
    $block .= "        proxy_set_header X-Forwarded-Host \$host;\n";  // Bitwarden compatibility
    $block .= "        proxy_set_header Upgrade \$http_upgrade;\n";
    $block .= "        proxy_set_header Connection \$connection_upgrade;\n";  // Use map for dynamic Connection header
    $block .= "\n";
    
    $block .= "        # Proxy settings\n";
    $block .= "        proxy_redirect off;\n";  // Let backend control redirects
    $block .= "        proxy_buffering off;\n";  // Better for streaming/WebSocket
    
    $block .= "        # Timeouts\n";
    $block .= "        proxy_connect_timeout 90s;\n";  // Increased for Bitwarden
    $block .= "        proxy_send_timeout 90s;\n";
    $block .= "        proxy_read_timeout 90s;\n";
    $block .= "    }\n\n";
    
    return $block;
}

function removeSiteConfig($siteId) {
    global $db;
    
    // Get domain name
    $stmt = $db->prepare("SELECT domain FROM sites WHERE id = ?");
    $stmt->execute([$siteId]);
    $site = $stmt->fetch();
    
    if ($site) {
        $domain = $site['domain'];
        $config_path = "/etc/nginx/sites-enabled/{$domain}.conf";
        
        // Remove config from nginx container using docker exec
        $removeCmd = "docker exec waf-nginx rm -f {$config_path} 2>&1";
        exec($removeCmd, $removeOutput, $removeReturn);
        
        if ($removeReturn === 0) {
            error_log("Removed config for {$domain}");
        } else {
            error_log("Failed to remove config for {$domain}: " . implode("\n", $removeOutput));
        }
        
        // Reload NGINX
        exec("docker exec waf-nginx nginx -s reload 2>&1");
        
        // Remove htpasswd if exists
        $htpasswd_path = "/etc/nginx/htpasswd/{$domain}";
        $htpasswdCmd = "docker exec waf-nginx rm -f {$htpasswd_path} 2>&1";
        exec($htpasswdCmd);
    }
}

/**
 * Clean up orphaned NGINX config files that don't have a corresponding site in the database
 * Returns the number of configs removed
 */
function cleanupOrphanedConfigs($db) {
    $removed = 0;
    
    // Get all domains from database
    $stmt = $db->query("SELECT domain FROM sites");
    $dbDomains = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'domain');
    
    // Get all config files from nginx container
    $listCmd = "docker exec waf-nginx find /etc/nginx/sites-enabled -name '*.conf' -type f 2>&1";
    exec($listCmd, $configFiles, $returnCode);
    
    if ($returnCode !== 0) {
        error_log("Failed to list config files: " . implode("\n", $configFiles));
        return 0;
    }
    
    foreach ($configFiles as $configPath) {
        // Extract domain from filename (e.g., /etc/nginx/sites-enabled/example.com.conf -> example.com)
        $filename = basename($configPath);
        $domain = str_replace('.conf', '', $filename);
        
        // Skip special files
        if (empty($domain) || $domain[0] === '.') {
            continue;
        }
        
        // Check if domain exists in database
        if (!in_array($domain, $dbDomains)) {
            error_log("Found orphaned config: {$domain}.conf");
            
            // Remove orphaned config
            $removeCmd = "docker exec waf-nginx rm -f {$configPath} 2>&1";
            exec($removeCmd, $removeOutput, $removeReturn);
            
            if ($removeReturn === 0) {
                error_log("Removed orphaned config: {$domain}.conf");
                $removed++;
                
                // Also remove htpasswd if exists
                $htpasswd_path = "/etc/nginx/htpasswd/{$domain}";
                $htpasswdCmd = "docker exec waf-nginx rm -f {$htpasswd_path} 2>&1";
                exec($htpasswdCmd);
            } else {
                error_log("Failed to remove orphaned config {$domain}.conf: " . implode("\n", $removeOutput));
            }
        }
    }
    
    if ($removed > 0) {
        // Trigger reload if we removed any configs
        touch("/etc/nginx/sites-enabled/.reload_needed");
    }
    
    return $removed;
}

/**
 * Generate WebSocket location block
 */
function generateWebSocketLocation($upstream_name, $ws_path, $ws_protocol, $ws_port, $backend_address) {
    $block = "\n    # WebSocket support\n";
    $block .= "    location {$ws_path} {\n";
    
    // Determine WebSocket target
    $ws_target = $upstream_name;
    if ($ws_port) {
        // If specific WebSocket port is defined, create direct proxy target
        // Strip port from backend if present
        $backend_host = preg_replace('/:[0-9]+$/', '', $backend_address);
        $ws_target_url = ($ws_protocol === 'wss' ? 'https' : 'http') . "://{$backend_host}:{$ws_port}";
        $block .= "        proxy_pass {$ws_target_url};\n";
    } else {
        // Use same upstream with WebSocket protocol
        $proxy_scheme = ($ws_protocol === 'wss') ? 'https' : 'http';
        $block .= "        proxy_pass {$proxy_scheme}://{$ws_target};\n";
    }
    
    // WebSocket headers
    $block .= "        proxy_http_version 1.1;\n";
    $block .= "        proxy_set_header Upgrade \$http_upgrade;\n";
    $block .= "        proxy_set_header Connection \"upgrade\";\n";
    $block .= "        proxy_set_header Host \$http_host;\n";
    $block .= "        proxy_set_header X-Real-IP \$remote_addr;\n";
    $block .= "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n";
    $block .= "        proxy_set_header X-Forwarded-Proto \$scheme;\n";
    $block .= "        proxy_set_header X-Forwarded-Host \$host;\n";
    $block .= "        proxy_set_header X-Forwarded-Port \$server_port;\n";
    
    // Proxy settings
    $block .= "        proxy_redirect off;\n";
    $block .= "        proxy_buffering off;\n";
    
    // WebSocket timeouts
    $block .= "        proxy_connect_timeout 90s;\n";
    $block .= "        proxy_send_timeout 3600s;\n";
    $block .= "        proxy_read_timeout 3600s;\n";
    
    $block .= "    }\n";
    
    return $block;
}

// Generate well-known files location blocks
function generateWellKnownFiles($siteData) {
    $db = getDB();
    $config = "";
    
    // Get global defaults
    $stmt = $db->query("SELECT robots_txt, security_txt, humans_txt, ads_txt FROM wellknown_global WHERE id = 1");
    $global = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $wellKnownFiles = [
        'robots.txt' => [
            'site' => $siteData['robots_txt'] ?? null,
            'global' => $global['robots_txt'] ?? null
        ],
        'security.txt' => [
            'site' => $siteData['security_txt'] ?? null,
            'global' => $global['security_txt'] ?? null
        ],
        'humans.txt' => [
            'site' => $siteData['humans_txt'] ?? null,
            'global' => $global['humans_txt'] ?? null
        ],
        'ads.txt' => [
            'site' => $siteData['ads_txt'] ?? null,
            'global' => $global['ads_txt'] ?? null
        ]
    ];
    
    $config .= "    # Well-known files\n";
    
    foreach ($wellKnownFiles as $filename => $content) {
        $siteContent = $content['site'];
        $globalContent = $content['global'];
        $finalContent = $siteContent ?: $globalContent;
        
        if ($finalContent) {
            // Escape content for NGINX return directive
            $escaped = addslashes($finalContent);
            $escaped = str_replace("\n", "\\n", $escaped);
            $escaped = str_replace("\r", "", $escaped);
            
            $path = ($filename === 'security.txt') ? '/.well-known/security.txt' : "/{$filename}";
            
            $config .= "    location = {$path} {\n";
            $config .= "        default_type text/plain;\n";
            $config .= "        add_header Cache-Control \"public, max-age=3600\";\n";
            $config .= "        return 200 \"{$escaped}\";\n";
            $config .= "    }\n";
        }
    }
    
    $config .= "\n";
    return $config;
}

// Generate custom error pages or use templates
function generateCustomErrorPages($siteData) {
    $config = "";
    $customEnabled = $siteData['custom_error_pages_enabled'] ?? false;
    
    if ($customEnabled) {
        $db = getDB();
        $config .= "    # Custom error pages (inline HTML)\n";
        
        $errorPages = [
            '403' => $siteData['custom_403_html'] ?? null,
            '404' => $siteData['custom_404_html'] ?? null,
            '429' => $siteData['custom_429_html'] ?? null,
            '500' => $siteData['custom_500_html'] ?? null,
            '502' => $siteData['custom_502_html'] ?? null,
            '503' => $siteData['custom_503_html'] ?? null
        ];
        
        // Get default template if custom not set
        $stmt = $db->query("SELECT html_403, html_404, html_429, html_500, html_502, html_503 FROM error_page_templates WHERE is_default = 1 LIMIT 1");
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        foreach ($errorPages as $code => $html) {
            if (!$html && $template) {
                $html = $template["html_{$code}"] ?? null;
            }
            
            if ($html) {
                // Escape HTML for NGINX return directive
                $escaped = addslashes($html);
                $escaped = str_replace("\n", "\\n", $escaped);
                $escaped = str_replace("\r", "", $escaped);
                
                $statusMap = [
                    '500' => '500 502 503 504',
                    '502' => null, // Handled by 500
                    '503' => null, // Handled by 500
                    '403' => '403',
                    '404' => '404',
                    '429' => '429'
                ];
                
                $statusCodes = $statusMap[$code];
                if ($statusCodes) {
                    $config .= "    error_page {$statusCodes} @error_{$code};\n";
                    $config .= "    location @error_{$code} {\n";
                    $config .= "        internal;\n";
                    $config .= "        default_type text/html;\n";
                    $config .= "        return {$code} \"{$escaped}\";\n";
                    $config .= "    }\n";
                }
            }
        }
        $config .= "\n";
    } else {
        // Use template mode - default error pages
        $config .= "    # Template error pages\n";
        $config .= "    error_page 429 /errors/429.html;\n";
        $config .= "    error_page 403 /errors/403.html;\n";
        $config .= "    error_page 404 /errors/404.html;\n";
        $config .= "    error_page 500 502 503 504 /errors/500.html;\n";
        $config .= "    location ^~ /errors/ {\n";
        $config .= "        alias /usr/share/nginx/error-pages/;\n";
        $config .= "        internal;\n";
        $config .= "    }\n\n";
    }
    
    return $config;
}

// Trigger background certificate issuance
function triggerCertificateIssuance($domain, $challengeType = 'http-01', $cfApiToken = null, $cfZoneId = null) {
    // Issue certificate in background to avoid blocking the response
    $command = sprintf(
        "php %s/certificate-issuer.php %s %s %s %s > /dev/null 2>&1 &",
        escapeshellarg(__DIR__),
        escapeshellarg($domain),
        escapeshellarg($challengeType),
        escapeshellarg($cfApiToken ?: ''),
        escapeshellarg($cfZoneId ?: '')
    );
    exec($command);
    error_log("Triggered background certificate issuance for {$domain}");
}
