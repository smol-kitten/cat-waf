-- WAF Database Complete Schema
-- Generated from running database on 2025-10-16
-- MariaDB 11.8.2

CREATE DATABASE IF NOT EXISTS waf_db;
USE waf_db;

--
-- Table structure for table `sites`
--

DROP TABLE IF EXISTS `sites`;
CREATE TABLE `sites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) NOT NULL,
  `wildcard_subdomains` tinyint(1) DEFAULT 0,
  `backend_url` varchar(255) NOT NULL,
  `backends` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`backends`)),
  `lb_method` varchar(50) DEFAULT 'round_robin',
  `hash_key` varchar(100) DEFAULT '$request_uri',
  `health_check_enabled` tinyint(1) DEFAULT 0,
  `health_check_interval` int(11) DEFAULT 30,
  `health_check_path` varchar(255) DEFAULT '/',
  `enabled` tinyint(1) DEFAULT 1,
  `rate_limit_zone` varchar(50) DEFAULT 'general',
  `custom_rate_limit` int(11) DEFAULT 10,
  `rate_limit_burst` int(11) DEFAULT 20,
  `enable_modsecurity` tinyint(1) DEFAULT 1,
  `enable_geoip_blocking` tinyint(1) DEFAULT 0,
  `blocked_countries` text DEFAULT NULL,
  `allowed_countries` text DEFAULT NULL,
  `custom_config` text DEFAULT NULL,
  `ssl_enabled` tinyint(1) DEFAULT 0,
  `ssl_cert_path` varchar(255) DEFAULT NULL,
  `ssl_key_path` varchar(255) DEFAULT NULL,
  `enable_gzip` tinyint(1) DEFAULT 1,
  `enable_brotli` tinyint(1) DEFAULT 1,
  `compression_level` int(11) DEFAULT 6,
  `compression_types` TEXT DEFAULT 'text/html text/css text/javascript application/json application/xml',
  `enable_caching` tinyint(1) DEFAULT 1,
  `cache_duration` int(11) DEFAULT 3600,
  `cache_static_files` tinyint(1) DEFAULT 1,
  `enable_image_optimization` tinyint(1) DEFAULT 0,
  `image_quality` int(11) DEFAULT 85,
  `image_max_width` int(11) DEFAULT 1920,
  `image_webp_conversion` tinyint(1) DEFAULT 0,
  `enable_waf_headers` tinyint(1) DEFAULT 1,
  `enable_telemetry` tinyint(1) DEFAULT 1,
  `custom_headers` text DEFAULT NULL,
  `ip_whitelist` text DEFAULT NULL,
  `enable_basic_auth` tinyint(1) DEFAULT 0,
  `basic_auth_username` varchar(255) DEFAULT NULL,
  `basic_auth_password` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `enable_bot_protection` tinyint(1) DEFAULT 1,
  `enable_rate_limit` tinyint(1) DEFAULT 1,
  `bot_protection_level` varchar(20) DEFAULT 'medium',
  `error_page_404` text DEFAULT NULL,
  `error_page_403` text DEFAULT NULL,
  `error_page_429` text DEFAULT NULL,
  `error_page_500` text DEFAULT NULL,
  `security_txt` text DEFAULT NULL,
  `cache_max_size` varchar(20) DEFAULT '100m',
  `cache_path` varchar(255) DEFAULT '/var/cache/nginx',
  `challenge_enabled` tinyint(1) DEFAULT 0,
  `challenge_difficulty` int(11) DEFAULT 18,
  `challenge_duration` float DEFAULT 1,
  `challenge_bypass_cf` tinyint(1) DEFAULT 0,
  `ssl_challenge_type` varchar(20) DEFAULT 'http-01',
  `cf_api_token` varchar(255) DEFAULT NULL,
  `cf_zone_id` varchar(100) DEFAULT NULL,
  `error_page_mode` varchar(20) DEFAULT 'template',
  `disable_http_redirect` tinyint(1) DEFAULT 0 COMMENT 'Disable NGINX HTTP->HTTPS redirect when backend handles it',
  `cf_bypass_ratelimit` tinyint(1) DEFAULT 0 COMMENT 'Bypass rate limiting for Cloudflare IPs',
  `cf_custom_rate_limit` int(11) DEFAULT 100 COMMENT 'Custom rate limit for Cloudflare IPs (requests per second)',
  `cf_rate_limit_burst` int(11) DEFAULT 200 COMMENT 'Burst limit for Cloudflare rate limiting',
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain` (`domain`),
  KEY `idx_domain` (`domain`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `banned_ips`
--

DROP TABLE IF EXISTS `banned_ips`;
CREATE TABLE `banned_ips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `jail` varchar(100) DEFAULT NULL,
  `banned_at` timestamp NULL DEFAULT current_timestamp(),
  `ban_duration` int(11) DEFAULT 3600,
  `expires_at` timestamp NULL DEFAULT NULL,
  `is_permanent` tinyint(1) DEFAULT 0,
  `unban_requested` tinyint(1) DEFAULT 0,
  `banned_by` varchar(50) DEFAULT 'manual',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip` (`ip_address`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_jail` (`jail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `access_logs`
--

DROP TABLE IF EXISTS `access_logs`;
CREATE TABLE `access_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `domain` varchar(255) DEFAULT NULL,
  `request_uri` text DEFAULT NULL,
  `method` varchar(10) DEFAULT NULL,
  `status_code` int(11) DEFAULT NULL,
  `bytes_sent` bigint(20) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `referer` text DEFAULT NULL,
  `response_time` float DEFAULT NULL,
  `country_code` varchar(2) DEFAULT NULL,
  `blocked` tinyint(1) DEFAULT 0,
  `blocked_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_timestamp` (`timestamp`),
  KEY `idx_ip` (`ip_address`),
  KEY `idx_domain` (`domain`),
  KEY `idx_status` (`status_code`),
  KEY `idx_blocked` (`blocked`),
  KEY `idx_method` (`method`),
  KEY `idx_response_time` (`response_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `modsec_events`
--

DROP TABLE IF EXISTS `modsec_events`;
CREATE TABLE `modsec_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `unique_id` varchar(64) DEFAULT NULL,
  `timestamp` timestamp NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `domain` varchar(255) DEFAULT NULL,
  `uri` text DEFAULT NULL,
  `method` varchar(10) DEFAULT NULL,
  `rule_id` varchar(50) DEFAULT NULL,
  `severity` varchar(20) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `action` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_timestamp` (`timestamp`),
  KEY `idx_ip` (`ip_address`),
  KEY `idx_severity` (`severity`),
  KEY `idx_rule_id` (`rule_id`),
  KEY `idx_unique_id` (`unique_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `api_tokens`
--

DROP TABLE IF EXISTS `api_tokens`;
CREATE TABLE `api_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `permissions` text DEFAULT NULL,
  `enabled` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `last_used` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_token` (`token`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `idx_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `bot_detections`
--

DROP TABLE IF EXISTS `bot_detections`;
CREATE TABLE `bot_detections` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `bot_type` varchar(50) DEFAULT NULL,
  `confidence` decimal(5,2) DEFAULT NULL,
  `action` varchar(20) DEFAULT NULL,
  `domain` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_timestamp` (`timestamp`),
  KEY `idx_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Table structure for table `request_telemetry`
--

DROP TABLE IF EXISTS `request_telemetry`;
CREATE TABLE `request_telemetry` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NULL DEFAULT current_timestamp(),
  `request_id` varchar(64) DEFAULT NULL,
  `domain` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `method` varchar(10) DEFAULT NULL,
  `uri` text DEFAULT NULL,
  `status_code` int(11) DEFAULT NULL,
  `response_time` float DEFAULT NULL,
  `backend_server` varchar(255) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `bytes_sent` bigint(20) DEFAULT NULL,
  `cache_status` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_timestamp` (`timestamp`),
  KEY `idx_request_id` (`request_id`),
  KEY `idx_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
