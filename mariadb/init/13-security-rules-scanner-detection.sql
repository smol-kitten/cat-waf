-- Migration: Security rules and scanner detection system
-- This allows configurable security policies per site and tracks scanning behavior

-- Security rules configuration table
CREATE TABLE IF NOT EXISTS `security_rules` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `site_id` INT(11) DEFAULT NULL COMMENT 'NULL = global rule, otherwise site-specific',
  `rule_type` ENUM('scanner_detection', 'learning_mode', 'wordpress_block', 'rate_limit', 'path_block') NOT NULL,
  `enabled` TINYINT(1) DEFAULT 1,
  `config` JSON DEFAULT NULL COMMENT 'Rule-specific configuration',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_site_rule` (`site_id`, `rule_type`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Configurable security policies';

-- Scanner activity tracking
CREATE TABLE IF NOT EXISTS `scanner_detections` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(45) NOT NULL,
  `domain` VARCHAR(255) DEFAULT NULL,
  `scan_type` VARCHAR(50) DEFAULT NULL COMMENT 'wordpress, exploit, directory, generic',
  `request_count` INT(11) DEFAULT 1,
  `error_404_count` INT(11) DEFAULT 0,
  `suspicious_paths` TEXT DEFAULT NULL COMMENT 'Comma-separated list of scanned paths',
  `first_seen` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `auto_blocked` TINYINT(1) DEFAULT 0,
  `block_reason` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip` (`ip_address`),
  KEY `idx_domain` (`domain`),
  KEY `idx_scan_type` (`scan_type`),
  KEY `idx_last_seen` (`last_seen`),
  KEY `idx_auto_blocked` (`auto_blocked`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Track scanning activity for auto-blocking';

-- Insert default global security rules
INSERT INTO security_rules (site_id, rule_type, enabled, config) VALUES
(NULL, 'scanner_detection', 1, JSON_OBJECT(
  'threshold_404', 10,
  'time_window_seconds', 60,
  'auto_block_duration', 3600,
  'wordpress_instant_block', false
)),
(NULL, 'learning_mode', 0, JSON_OBJECT(
  'bot_spike_threshold', 100,
  'ip_spike_threshold', 50,
  'time_window_seconds', 300,
  'auto_block_duration', 1800,
  'whitelist_known_bots', true
)),
(NULL, 'wordpress_block', 0, JSON_OBJECT(
  'instant_block', false,
  'paths', JSON_ARRAY(
    '/wp-admin/',
    '/wp-includes/',
    '/wp-content/',
    '/wp-login.php',
    '/xmlrpc.php',
    '/wp-json/'
  )
))
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- Create index on request_telemetry for spike analysis
CREATE INDEX IF NOT EXISTS `idx_timestamp_status` ON request_telemetry (`timestamp`, `status_code`);
CREATE INDEX IF NOT EXISTS `idx_timestamp_domain_status` ON request_telemetry (`timestamp`, `domain`, `status_code`);
