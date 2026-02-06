-- Create insights configuration and data tables
USE waf_db;

-- Insights configuration table
CREATE TABLE IF NOT EXISTS `insights_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `site_id` int(11) DEFAULT NULL COMMENT 'NULL for global config',
  `level` varchar(20) DEFAULT 'basic' COMMENT 'basic or extended',
  `enabled` tinyint(1) DEFAULT 1,
  `collect_web_vitals` tinyint(1) DEFAULT 0 COMMENT 'Collect LCP, FCP, TTFB (extended level)',
  `collect_user_agent` tinyint(1) DEFAULT 1,
  `collect_referrer` tinyint(1) DEFAULT 1,
  `retention_days` int(11) DEFAULT 30,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_site_id` (`site_id`),
  UNIQUE KEY `unique_site_config` (`site_id`),
  FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Web Vitals data table (extended insights only)
CREATE TABLE IF NOT EXISTS `web_vitals` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NULL DEFAULT current_timestamp(),
  `site_id` int(11) DEFAULT NULL,
  `domain` varchar(255) DEFAULT NULL,
  `path` varchar(500) DEFAULT NULL,
  `lcp` float DEFAULT NULL COMMENT 'Largest Contentful Paint in seconds',
  `fcp` float DEFAULT NULL COMMENT 'First Contentful Paint in seconds',
  `ttfb` float DEFAULT NULL COMMENT 'Time to First Byte in seconds',
  `cls` float DEFAULT NULL COMMENT 'Cumulative Layout Shift',
  `fid` float DEFAULT NULL COMMENT 'First Input Delay in milliseconds',
  `user_agent` text DEFAULT NULL,
  `device_type` varchar(20) DEFAULT NULL COMMENT 'desktop, mobile, tablet',
  PRIMARY KEY (`id`),
  KEY `idx_timestamp` (`timestamp`),
  KEY `idx_site_id` (`site_id`),
  KEY `idx_domain` (`domain`),
  KEY `idx_lcp` (`lcp`),
  KEY `idx_fcp` (`fcp`),
  FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default global insights config (basic level)
INSERT IGNORE INTO insights_config (site_id, level, enabled, collect_web_vitals) 
VALUES (NULL, 'basic', 1, 0);

-- Log migration
INSERT IGNORE INTO migration_logs (migration_name) VALUES ('19-add-insights-system');
