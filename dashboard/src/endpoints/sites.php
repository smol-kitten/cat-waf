<?php

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
    // Check if this is a backends sub-route: /sites/:id/backends
    if (isset($params[1]) && $params[1] === 'backends') {
        require_once __DIR__ . '/backends.php';
        return;
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
                    enable_caching, cache_duration, cache_static_files, 
                    enable_image_optimization, image_quality, image_max_width,
                    enable_waf_headers, enable_telemetry, custom_headers, ip_whitelist,
                    wildcard_subdomains, disable_http_redirect, cf_bypass_ratelimit,
                    cf_custom_rate_limit, cf_rate_limit_burst)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                $data['enable_caching'] ?? 1,
                $data['cache_duration'] ?? 3600,
                $data['cache_static_files'] ?? 1,
                $data['enable_image_optimization'] ?? 0,
                $data['image_quality'] ?? 85,
                $data['image_max_width'] ?? 1920,
                $data['enable_waf_headers'] ?? 1,
                $data['enable_telemetry'] ?? 1,
                $data['custom_headers'] ?? null,
                $data['ip_whitelist'] ?? null,
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
                'domain', 'backend_url', 'enabled', 'rate_limit_zone', 'rate_limit_burst', 
                'enable_modsecurity', 'enable_geoip_blocking', 'blocked_countries', 
                'allowed_countries', 'custom_config', 'ssl_enabled', 'ssl_cert_path', 
                'ssl_key_path', 'ssl_redirect', 'enable_gzip', 'enable_brotli', 
                'compression_level', 'compression_types', 'enable_caching', 'cache_duration', 
                'cache_static_files', 'cache_max_size', 'cache_path', 'enable_image_optimization', 
                'image_quality', 'image_max_width', 'image_webp_conversion', 'enable_waf_headers', 
                'enable_telemetry', 'enable_bot_protection', 'bot_protection_level', 'custom_headers', 
                'ip_whitelist', 'backends', 'lb_method', 'health_check_enabled', 
                'health_check_interval', 'health_check_path', 'wildcard_subdomains', 
                'custom_rate_limit', 'enable_rate_limit', 'hash_key', 'challenge_enabled', 
                'challenge_difficulty', 'challenge_duration', 'challenge_bypass_cf', 
                'ssl_challenge_type', 'cf_api_token', 'cf_zone_id', 'error_page_404', 
                'error_page_403', 'error_page_429', 'error_page_500', 'security_txt',
                'enable_basic_auth', 'basic_auth_username', 'basic_auth_password',
                'disable_http_redirect', 'cf_bypass_ratelimit', 'cf_custom_rate_limit', 'cf_rate_limit_burst'
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
                'domain', 'backend_url', 'enabled', 'rate_limit_zone', 'rate_limit_burst', 
                'enable_modsecurity', 'enable_geoip_blocking', 'blocked_countries', 
                'allowed_countries', 'custom_config', 'ssl_enabled', 'ssl_cert_path', 
                'ssl_key_path', 'ssl_redirect', 'enable_gzip', 'enable_brotli', 
                'compression_level', 'compression_types', 'enable_caching', 'cache_duration', 
                'cache_static_files', 'enable_image_optimization', 'image_quality', 
                'image_max_width', 'enable_waf_headers', 'enable_telemetry', 
                'enable_bot_protection', 'custom_headers', 'ip_whitelist', 'backends',
                'lb_method', 'health_check_enabled', 'health_check_interval', 'health_check_path',
                'wildcard_subdomains', 'custom_rate_limit', 'enable_rate_limit', 'hash_key',
                'challenge_enabled', 'challenge_difficulty', 'challenge_duration', 
                'challenge_bypass_cf', 'ssl_challenge_type', 'cf_api_token', 'cf_zone_id',
                'disable_http_redirect', 'cf_bypass_ratelimit', 'cf_custom_rate_limit', 'cf_rate_limit_burst'
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

function generateSiteConfig($siteId, $siteData) {
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
    $enable_caching = $siteData['enable_caching'] ?? 1;
    $cache_duration = $siteData['cache_duration'] ?? 3600;
    $cache_static = $siteData['cache_static_files'] ?? 1;
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
    
    // Create upstream name (sanitize domain)
    $upstream_name = preg_replace('/[^a-z0-9_]/', '_', strtolower($domain)) . '_backend';
    
    // Build NGINX config
    $config = "# Auto-generated config for {$domain}\n";
    $config .= "# Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Cache configuration (if enabled)
    if ($enable_caching) {
        $cache_zone_name = preg_replace('/[^a-z0-9_]/', '_', strtolower($domain)) . '_cache';
        $cache_max_size = $siteData['cache_max_size'] ?? '100m';
        $cache_path = $siteData['cache_path'] ?? '/var/cache/nginx';
        $cache_path_full = "{$cache_path}/{$cache_zone_name}";
        
        $config .= "# Proxy cache zone\n";
        $config .= "proxy_cache_path {$cache_path_full} levels=1:2 keys_zone={$cache_zone_name}:10m max_size={$cache_max_size} inactive=60m use_temp_path=off;\n\n";
    }
    
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
            $weight = $backend_config['weight'] ?? 1;
            $max_fails = $backend_config['max_fails'] ?? 3;
            $fail_timeout = $backend_config['fail_timeout'] ?? 30;
            $backup = ($backend_config['backup'] ?? false) ? ' backup' : '';
            $down = ($backend_config['down'] ?? false) ? ' down' : '';
            
            if (!empty($server)) {
                $config .= "    server {$server}";
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
        // Minimal default server - just return 444 (close connection) for unknown hosts/IP access
        $config .= "server {\n";
        $config .= "    listen 80 default_server;\n";
        $config .= "    listen [::]:80 default_server;\n";
        $config .= "    server_name _;\n\n";
        $config .= "    # Return 444 (close connection) for IP/unknown host access\n";
        $config .= "    # This prevents invalid redirects to https://_/ or exposing backend\n";
        $config .= "    return 444;\n";
        $config .= "}\n\n";
        
        // No HTTPS server for default catch-all
        // Skip the rest of the config generation and jump to file writing
    } else {
        // HTTP server (redirect to HTTPS or serve directly)
        $config .= "server {\n";
        $config .= "    listen 80;\n";
        $config .= "    listen [::]:80;\n";
        $config .= "    server_name {$domain};\n\n";
    
    // Error pages
    $config .= "    # Custom error pages\n";
    $config .= "    error_page 429 /errors/429.html;\n";
    $config .= "    error_page 403 /errors/403.html;\n";
    $config .= "    error_page 404 /errors/404.html;\n";
    $config .= "    error_page 500 502 503 504 /errors/500.html;\n";
    $config .= "    location ^~ /errors/ {\n";
    $config .= "        alias /usr/share/nginx/error-pages/;\n";
    $config .= "        internal;\n";
    $config .= "    }\n\n";
    
    // ACME challenge for Let's Encrypt
    $config .= "    location ^~ /.well-known/acme-challenge/ {\n";
    $config .= "        root /var/www/certbot;\n";
    $config .= "        default_type \"text/plain\";\n";
    $config .= "    }\n\n";
    
    if ($ssl_enabled) {
        // Check if HTTP->HTTPS redirect is disabled (to prevent infinite loops when backend also redirects)
        if ($disable_http_redirect) {
            // Serve HTTP directly even with SSL enabled (backend handles redirect)
            $config .= generateLocationBlock($upstream_name, $domain, $modsec_enabled, $geoip_enabled, 
                                               $blocked_countries, $rate_limit_zone, $custom_config,
                                               $enable_caching, $cache_duration, $cache_static,
                                               $enable_waf_headers, $enable_telemetry, $custom_headers,
                                               $enable_basic_auth, $basic_auth_username, $basic_auth_password,
                                               $enable_image_opt, $image_quality, $enable_bot_protection,
                                               false, false, false, false, // Disable challenge on HTTP when backend redirects
                                               $cf_bypass_ratelimit, $cf_custom_rate_limit, $cf_rate_limit_burst);
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
                                           $enable_caching, $cache_duration, $cache_static,
                                           $enable_waf_headers, $enable_telemetry, $custom_headers,
                                           $enable_basic_auth, $basic_auth_username, $basic_auth_password,
                                           $enable_image_opt, $image_quality, $enable_bot_protection,
                                           false, false, false, false, // No challenge on plain HTTP
                                           $cf_bypass_ratelimit, $cf_custom_rate_limit, $cf_rate_limit_burst);
    }
    $config .= "}\n\n";
    
    // HTTPS server (if SSL enabled)
    if ($ssl_enabled) {
        $config .= "server {\n";
        $config .= "    listen 443 ssl;\n";
        $config .= "    listen [::]:443 ssl;\n";
        $config .= "    http2 on;\n";
        $config .= "    server_name {$domain};\n\n";
        
        // Error pages
        $config .= "    # Custom error pages\n";
        if ($error_page_mode === 'custom') {
            // Custom URLs - could be external or internal
            $config .= "    error_page 429 {$error_page_429};\n";
            $config .= "    error_page 403 {$error_page_403};\n";
            $config .= "    error_page 404 {$error_page_404};\n";
            $config .= "    error_page 500 502 503 504 {$error_page_500};\n\n";
        } else {
            // Template mode - use built-in error pages
            $config .= "    error_page 429 /errors/429.html;\n";
            $config .= "    error_page 403 /errors/403.html;\n";
            $config .= "    error_page 404 /errors/404.html;\n";
            $config .= "    error_page 500 502 503 504 /errors/500.html;\n";
            $config .= "    location ^~ /errors/ {\n";
            $config .= "        alias /usr/share/nginx/error-pages/;\n";
            $config .= "        internal;\n";
            $config .= "    }\n\n";
        }
        
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
        
        if ($ssl_challenge_type === 'snakeoil') {
            // Use self-signed snakeoil certificate
            $config .= "    ssl_certificate /etc/nginx/certs/{$domain}/fullchain.pem;\n";
            $config .= "    ssl_certificate_key /etc/nginx/certs/{$domain}/key.pem;\n";
        } else {
            // Use Let's Encrypt certificate (from acme.sh)
            $config .= "    ssl_certificate /etc/nginx/certs/{$domain}/fullchain.pem;\n";
            $config .= "    ssl_certificate_key /etc/nginx/certs/{$domain}/key.pem;\n";
        }
        $config .= "    ssl_protocols TLSv1.2 TLSv1.3;\n";
        $config .= "    ssl_ciphers HIGH:!aNULL:!MD5;\n";
        $config .= "    ssl_prefer_server_ciphers on;\n";
        $config .= "    ssl_session_cache shared:SSL:10m;\n";
        $config .= "    ssl_session_timeout 10m;\n\n";
        
        // HSTS
        $config .= "    add_header Strict-Transport-Security \"max-age=31536000; includeSubDomains\" always;\n\n";
        
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
                                           $enable_caching, $cache_duration, $cache_static,
                                           $enable_waf_headers, $enable_telemetry, $custom_headers,
                                           $enable_basic_auth, $basic_auth_username, $basic_auth_password,
                                           $enable_image_opt, $image_quality, $enable_bot_protection,
                                           $challenge_enabled, $challenge_difficulty, $challenge_duration, $challenge_bypass_cf,
                                           $cf_bypass_ratelimit, $cf_custom_rate_limit, $cf_rate_limit_burst);
        $config .= "}\n";
    }
    
    } // end of if (!$isDefaultCatchall)
    
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
                               $enable_caching = true, $cache_duration = 3600, $cache_static = true,
                               $enable_waf_headers = true, $enable_telemetry = true, $custom_headers = '',
                               $enable_basic_auth = false, $basic_auth_username = '', $basic_auth_password = '',
                               $enable_image_opt = false, $image_quality = 85, $enable_bot_protection = true,
                               $challenge_enabled = false, $challenge_difficulty = 18, $challenge_duration = 1, $challenge_bypass_cf = false,
                               $cf_bypass_ratelimit = false, $cf_custom_rate_limit = 100, $cf_rate_limit_burst = 200) {
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
    
    // IP whitelist
    if (isset($custom_config['ip_whitelist']) && !empty($custom_config['ip_whitelist'])) {
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
    $burst = $custom_config['custom_rate_limit'] ?? 20;
    $block .= "    # Rate limiting\n";
    
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
        $block .= "        limit_req zone=cloudflare burst={$cf_rate_limit_burst} nodelay;\n";
        $block .= "    }\n";
    } else {
        // Standard rate limiting
        $block .= "    limit_req zone={$rate_limit} burst={$burst} nodelay;\n";
    }
    $block .= "    limit_conn addr 20;\n\n";
    
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
    
    // Static file caching
    if ($cache_static && $enable_caching) {
        $cache_zone_name = preg_replace('/[^a-z0-9_]/', '_', strtolower($domain)) . '_cache';
        $static_cache_duration = $cache_duration * 10; // Cache static files 10x longer
        $block .= "    # Static file caching\n";
        $block .= "    location ~* \\.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot|webp|avif|mp4|webm)$ {\n";
        $block .= "        proxy_pass http://{$upstream};\n";
        $block .= "        proxy_cache {$cache_zone_name};\n";
        $block .= "        proxy_cache_valid 200 {$static_cache_duration}s;\n";
        $block .= "        proxy_cache_key \$scheme\$proxy_host\$request_uri;\n";
        $block .= "        proxy_cache_use_stale error timeout updating;\n";
        $block .= "        add_header X-Cache-Status \$upstream_cache_status always;\n";
        $block .= "        expires {$static_cache_duration}s;\n";
        $block .= "        add_header Cache-Control \"public, immutable\";\n";
        $block .= "    }\n\n";
    }
    
    // Image optimization proxy (if enabled)
    if ($enable_image_opt) {
        $block .= "    # Image optimization\n";
        $block .= "    location ~* \\.(jpg|jpeg|png)$ {\n";
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
            $block .= "        if (\$http_cf_visitor) {\n";
            $block .= "            set \$challenge_passed 1;\n";
            $block .= "        }\n\n";
        }
        
        $block .= "        # Redirect to challenge if not verified\n";
        $block .= "        if (\$challenge_passed = 0) {\n";
        $block .= "            return 302 /challenge.html?difficulty={$challenge_difficulty}&duration={$challenge_duration}&redirect=\$scheme://\$host\$request_uri;\n";
        $block .= "        }\n\n";
    }
    
    // Add cache directives if enabled
    if ($enable_caching) {
        $cache_zone_name = preg_replace('/[^a-z0-9_]/', '_', strtolower($domain)) . '_cache';
        $block .= "        # Proxy caching\n";
        $block .= "        proxy_cache {$cache_zone_name};\n";
        $block .= "        proxy_cache_valid 200 302 {$cache_duration}s;\n";
        $block .= "        proxy_cache_valid 404 1m;\n";
        $block .= "        proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;\n";
        $block .= "        proxy_cache_background_update on;\n";
        $block .= "        proxy_cache_lock on;\n";
        $block .= "        proxy_cache_key \$scheme\$proxy_host\$request_uri;\n";
        $block .= "        proxy_cache_bypass \$http_pragma \$http_authorization;\n";
        $block .= "        proxy_no_cache \$http_pragma \$http_authorization;\n";
        $block .= "        add_header X-Cache-Status \$upstream_cache_status always;\n\n";
    }
    
    $block .= "        proxy_pass http://{$upstream};\n";
    $block .= "        proxy_http_version 1.1;\n\n";
    
    $block .= "        # Proxy headers\n";
    $block .= "        # Use \$http_host to preserve the original Host header from client\n";
    $block .= "        # This ensures backend vhosts receive the correct domain name\n";
    $block .= "        proxy_set_header Host \$http_host;\n";
    $block .= "        proxy_set_header X-Real-IP \$remote_addr;\n";
    $block .= "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n";
    $block .= "        proxy_set_header X-Forwarded-Proto \$scheme;\n";
    $block .= "        proxy_set_header Upgrade \$http_upgrade;\n";
    $block .= "        proxy_set_header Connection \"upgrade\";\n\n";
    
    $block .= "        # Timeouts\n";
    $block .= "        proxy_connect_timeout 60s;\n";
    $block .= "        proxy_send_timeout 60s;\n";
    $block .= "        proxy_read_timeout 60s;\n";
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
        
        if (file_exists($config_path)) {
            unlink($config_path);
            error_log("Removed config for {$domain}");
            
            // Reload NGINX
            exec("docker exec waf-nginx nginx -s reload 2>&1");
        }
        
        // Remove htpasswd if exists
        $htpasswd_path = "/etc/nginx/htpasswd/{$domain}";
        if (file_exists($htpasswd_path)) {
            unlink($htpasswd_path);
        }
    }
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
