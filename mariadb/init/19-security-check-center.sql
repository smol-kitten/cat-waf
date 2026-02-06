-- Security Check Center
-- Centralized security monitoring and health checks

CREATE TABLE IF NOT EXISTS `security_checks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `check_type` varchar(50) NOT NULL COMMENT 'Type of check: ssl_expiry, modsec_status, fail2ban_status, etc.',
  `check_name` varchar(255) NOT NULL,
  `status` varchar(20) DEFAULT 'unknown' COMMENT 'Status: healthy, warning, critical, unknown',
  `severity` varchar(20) DEFAULT 'info' COMMENT 'Severity: info, warning, critical',
  `message` text DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `last_checked` timestamp NULL DEFAULT NULL,
  `next_check` timestamp NULL DEFAULT NULL,
  `check_interval` int(11) DEFAULT 3600 COMMENT 'Check interval in seconds',
  `enabled` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_check_type` (`check_type`),
  KEY `idx_status` (`status`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_next_check` (`next_check`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Security check history for tracking over time
CREATE TABLE IF NOT EXISTS `security_check_history` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `check_id` int(11) NOT NULL,
  `status` varchar(20) NOT NULL,
  `message` text DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `checked_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_check_id` (`check_id`),
  KEY `idx_checked_at` (`checked_at`),
  CONSTRAINT `fk_security_check_history` FOREIGN KEY (`check_id`) REFERENCES `security_checks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default security checks
INSERT INTO `security_checks` (`check_type`, `check_name`, `status`, `severity`, `message`, `check_interval`, `enabled`) VALUES
('ssl_expiry', 'SSL Certificate Expiry Check', 'unknown', 'info', 'Checking SSL certificates for expiration', 3600, 1),
('modsec_status', 'ModSecurity Status', 'unknown', 'info', 'Checking ModSecurity engine status', 300, 1),
('fail2ban_status', 'Fail2ban Status', 'unknown', 'info', 'Checking fail2ban service status', 300, 1),
('disk_space', 'Disk Space Check', 'unknown', 'info', 'Checking available disk space', 1800, 1),
('nginx_status', 'NGINX Status', 'unknown', 'info', 'Checking NGINX service status', 300, 1),
('database_status', 'Database Status', 'unknown', 'info', 'Checking MariaDB database status', 300, 1),
('security_rules', 'Security Rules Check', 'unknown', 'info', 'Checking security rules are loaded', 3600, 1),
('blocked_attacks', 'Recent Attack Activity', 'unknown', 'info', 'Monitoring blocked attack attempts', 300, 1)
ON DUPLICATE KEY UPDATE updated_at = current_timestamp();
