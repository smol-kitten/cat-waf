-- Path-based routing for sub-page routing
-- Enables routing different paths to different backend servers
-- Example: service.dom.tld -> server1, service.dom.tld/files -> server2

CREATE TABLE IF NOT EXISTS `path_routes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `site_id` int(11) NOT NULL,
  `path` varchar(500) NOT NULL COMMENT 'URL path pattern (e.g., /api, /files, /admin)',
  `backend_url` varchar(255) NOT NULL COMMENT 'Backend server for this path (hostname:port)',
  `backend_protocol` varchar(10) DEFAULT 'http' COMMENT 'Protocol: http or https',
  `priority` int(11) DEFAULT 0 COMMENT 'Higher priority routes are matched first',
  `enabled` tinyint(1) DEFAULT 1,
  `strip_path` tinyint(1) DEFAULT 0 COMMENT 'Remove path prefix when proxying to backend',
  `enable_modsecurity` tinyint(1) DEFAULT 1,
  `enable_rate_limit` tinyint(1) DEFAULT 1,
  `custom_rate_limit` int(11) DEFAULT NULL COMMENT 'Custom rate limit for this path',
  `rate_limit_burst` int(11) DEFAULT 20,
  `custom_headers` text DEFAULT NULL COMMENT 'Custom headers for this path (JSON)',
  `custom_config` text DEFAULT NULL COMMENT 'Additional NGINX config for this location',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_site_id` (`site_id`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_priority` (`priority`),
  CONSTRAINT `fk_path_routes_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add max body size settings to sites table
ALTER TABLE `sites` ADD COLUMN IF NOT EXISTS `max_body_size` varchar(20) DEFAULT '100M' COMMENT 'Maximum request body size (e.g., 10M, 100M, 1G)';
ALTER TABLE `sites` ADD COLUMN IF NOT EXISTS `max_body_size_enabled` tinyint(1) DEFAULT 1 COMMENT 'Enable max body size limit';

-- Add path-based max body size to path_routes
ALTER TABLE `path_routes` ADD COLUMN IF NOT EXISTS `max_body_size` varchar(20) DEFAULT NULL COMMENT 'Override max body size for this path';
