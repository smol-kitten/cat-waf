-- Add alert suppression to prevent spam
-- Alerts only fire once until the issue is resolved
USE waf_db;

-- Create table to track active issues (alerts that haven't been resolved)
CREATE TABLE IF NOT EXISTS `active_issues` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `issue_type` varchar(100) NOT NULL COMMENT 'Type of issue: backend_down, disk_space, ssl_expiry, etc.',
  `issue_key` varchar(255) NOT NULL COMMENT 'Unique identifier for the specific issue instance',
  `first_detected` timestamp NULL DEFAULT current_timestamp(),
  `last_detected` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  `alert_sent` tinyint(1) DEFAULT 0 COMMENT 'Whether initial alert was sent',
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_issue_type_key` (`issue_type`, `issue_key`),
  KEY `idx_resolved` (`resolved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add health check rule to alert_rules for storing health alerts in history
INSERT IGNORE INTO alert_rules (rule_name, rule_type, enabled, config) VALUES
('Health Check Issues', 'health_check', 1, '{"description": "General health check issues"}');

-- Log migration
INSERT IGNORE INTO migration_logs (migration_name) VALUES ('31-alert-suppression');
