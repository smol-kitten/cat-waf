-- Custom Block Rules Table
-- Migration 07: Adding path-based blocking rules

USE waf_db;

CREATE TABLE IF NOT EXISTS `custom_block_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL COMMENT 'Friendly name for the rule',
  `pattern` varchar(500) NOT NULL COMMENT 'Path pattern to block (e.g., /.git/config, /.env, /admin/*)',
  `pattern_type` varchar(20) DEFAULT 'exact' COMMENT 'Match type: exact, prefix, suffix, contains, regex',
  `enabled` tinyint(1) DEFAULT 1,
  `block_message` varchar(255) DEFAULT 'Access to this path is forbidden',
  `severity` varchar(20) DEFAULT 'CRITICAL' COMMENT 'CRITICAL, WARNING, NOTICE',
  `rule_id` int(11) DEFAULT NULL COMMENT 'ModSecurity rule ID (10000-19999 range for custom)',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` varchar(100) DEFAULT 'admin',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_pattern` (`pattern`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_severity` (`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Custom path blocking rules for sensitive files/directories';

-- Insert default security rules (use INSERT IGNORE to avoid duplicates on reruns)
INSERT IGNORE INTO `custom_block_rules` (`name`, `pattern`, `pattern_type`, `block_message`, `severity`, `rule_id`, `enabled`) VALUES
('Block .git directory', '/.git/', 'prefix', 'Git directory access forbidden', 'CRITICAL', 10001, 1),
('Block .git/config', '/.git/config', 'exact', 'Git config access forbidden', 'CRITICAL', 10002, 1),
('Block .env file', '/.env', 'exact', 'Environment file access forbidden', 'CRITICAL', 10003, 1),
('Block .env.local', '/.env.local', 'exact', 'Environment file access forbidden', 'CRITICAL', 10004, 1),
('Block .env.production', '/.env.production', 'exact', 'Environment file access forbidden', 'CRITICAL', 10005, 1),
('Block wp-config.php', '/wp-config.php', 'exact', 'WordPress config access forbidden', 'CRITICAL', 10006, 1),
('Block composer.json', '/composer.json', 'exact', 'Composer config access forbidden', 'WARNING', 10007, 1),
('Block package.json', '/package.json', 'exact', 'Package config access forbidden', 'WARNING', 10008, 1),
('Block .htaccess', '/.htaccess', 'exact', 'Apache config access forbidden', 'CRITICAL', 10009, 1),
('Block .htpasswd', '/.htpasswd', 'exact', 'Password file access forbidden', 'CRITICAL', 10010, 1),
('Block backup files', '.sql', 'suffix', 'Database backup access forbidden', 'CRITICAL', 10011, 1),
('Block database dumps', '.dump', 'suffix', 'Database dump access forbidden', 'CRITICAL', 10012, 1),
('Block .DS_Store', '/.DS_Store', 'exact', 'macOS metadata access forbidden', 'WARNING', 10013, 1),
('Block Thumbs.db', '/Thumbs.db', 'exact', 'Windows metadata access forbidden', 'WARNING', 10014, 1),
('Block .svn directory', '/.svn/', 'prefix', 'SVN directory access forbidden', 'CRITICAL', 10015, 1),
('Block .hg directory', '/.hg/', 'prefix', 'Mercurial directory access forbidden', 'CRITICAL', 10016, 1);

-- Create indexes for performance (IF NOT EXISTS to avoid errors on reruns)
CREATE INDEX IF NOT EXISTS `idx_pattern_type` ON `custom_block_rules` (`pattern_type`);
CREATE INDEX IF NOT EXISTS `idx_rule_id` ON `custom_block_rules` (`rule_id`);

-- Mark this migration as applied
INSERT INTO migration_logs (migration_name, applied_at) 
VALUES ('04-custom-block-rules.sql', NOW())
ON DUPLICATE KEY UPDATE applied_at = NOW();
