-- Add more nginx config options to sites table
-- These are commonly requested features

-- Add client_max_body_size for upload limits
-- Note: Application layer should validate format (e.g., 100M, 1G, 500K)
ALTER TABLE `sites` ADD COLUMN IF NOT EXISTS `client_max_body_size` VARCHAR(20) DEFAULT '100M' 
    COMMENT 'Max upload size - must match nginx format (e.g., 100M, 1G). Validated by application.';

-- Add proxy timeout settings
ALTER TABLE `sites` ADD COLUMN IF NOT EXISTS `proxy_read_timeout` INT DEFAULT 60 
    COMMENT 'Proxy read timeout in seconds';

ALTER TABLE `sites` ADD COLUMN IF NOT EXISTS `proxy_connect_timeout` INT DEFAULT 60 
    COMMENT 'Proxy connect timeout in seconds';

ALTER TABLE `sites` ADD COLUMN IF NOT EXISTS `proxy_send_timeout` INT DEFAULT 60 
    COMMENT 'Proxy send timeout in seconds';

-- Add websocket support toggle
ALTER TABLE `sites` ADD COLUMN IF NOT EXISTS `enable_websocket` TINYINT(1) DEFAULT 0 
    COMMENT 'Enable WebSocket proxying with upgraded connections';

-- Add HSTS settings
ALTER TABLE `sites` ADD COLUMN IF NOT EXISTS `enable_hsts` TINYINT(1) DEFAULT 0 
    COMMENT 'Enable HTTP Strict Transport Security';

ALTER TABLE `sites` ADD COLUMN IF NOT EXISTS `hsts_max_age` INT DEFAULT 31536000 
    COMMENT 'HSTS max-age in seconds (default 1 year)';

ALTER TABLE `sites` ADD COLUMN IF NOT EXISTS `hsts_include_subdomains` TINYINT(1) DEFAULT 0 
    COMMENT 'Include subdomains in HSTS policy';

-- Add custom nginx directives
ALTER TABLE `sites` ADD COLUMN IF NOT EXISTS `custom_nginx_directives` TEXT DEFAULT NULL 
    COMMENT 'Custom nginx directives to inject into server block';

-- Add proxy buffering control
ALTER TABLE `sites` ADD COLUMN IF NOT EXISTS `proxy_buffering` VARCHAR(10) DEFAULT 'on' 
    COMMENT 'Enable/disable proxy buffering (on/off)';

-- Add keepalive settings
ALTER TABLE `sites` ADD COLUMN IF NOT EXISTS `keepalive_timeout` INT DEFAULT 75 
    COMMENT 'Keepalive timeout in seconds';

-- Add access log control
ALTER TABLE `sites` ADD COLUMN IF NOT EXISTS `disable_access_log` TINYINT(1) DEFAULT 0 
    COMMENT 'Disable access logging for this site (performance)';
