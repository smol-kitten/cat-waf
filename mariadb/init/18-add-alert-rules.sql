-- Add alert rules configuration system
USE waf_db;

-- Create alert_rules table
CREATE TABLE IF NOT EXISTS `alert_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rule_name` varchar(100) NOT NULL,
  `rule_type` varchar(50) NOT NULL COMMENT 'delay, cert_expiry, server_down, rate_limit_breach, error_rate',
  `enabled` tinyint(1) DEFAULT 1,
  `site_id` int(11) DEFAULT NULL COMMENT 'NULL for global rules',
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rule_type` (`rule_type`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_site_id` (`site_id`),
  FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create alert_history table to track fired alerts
CREATE TABLE IF NOT EXISTS `alert_history` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `alert_rule_id` int(11) NOT NULL,
  `fired_at` timestamp NULL DEFAULT current_timestamp(),
  `alert_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`alert_data`)),
  `acknowledged` tinyint(1) DEFAULT 0,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledged_by` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fired_at` (`fired_at`),
  KEY `idx_rule_id` (`alert_rule_id`),
  KEY `idx_acknowledged` (`acknowledged`),
  FOREIGN KEY (`alert_rule_id`) REFERENCES `alert_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default alert rules
INSERT IGNORE INTO alert_rules (rule_name, rule_type, enabled, config) VALUES
('High Response Time', 'delay', 1, '{"threshold_ms": 3000, "duration_minutes": 5, "min_requests": 10}'),
('Certificate Expiring Soon', 'cert_expiry', 1, '{"warning_days": 30, "critical_days": 7}'),
('Backend Server Down', 'server_down', 1, '{"check_interval_seconds": 300}'),
('High Error Rate', 'error_rate', 1, '{"threshold_percent": 10, "duration_minutes": 5, "min_requests": 20}'),
('Rate Limit Breach', 'rate_limit_breach', 1, '{"threshold_blocks": 100, "duration_minutes": 5}');

-- Log migration
INSERT IGNORE INTO migration_logs (migration_name) VALUES ('18-add-alert-rules');
