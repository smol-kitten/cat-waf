-- Migration: Default WAF Settings
-- Adds default values for WAF configuration settings

USE waf_db;

-- Add default WAF settings if they don't exist
INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES
('paranoia_level', '1', 'ModSecurity paranoia level (1-4). Higher levels are more strict but may cause more false positives.'),
('default_rate_limit', '10', 'Default rate limit for requests per second per IP'),
('ban_duration', '3600', 'Default ban duration in seconds (1 hour)'),
('enable_auto_ban', '1', 'Enable automatic banning of IPs that trigger too many blocks'),
('auto_ban_threshold', '5', 'Number of blocks in 5 minutes to trigger auto-ban'),
('exclude_cloudflare', '1', 'Exclude Cloudflare IP ranges from auto-ban'),
('router_auto_sync', '1', 'Automatically sync bans to configured routers'),
('router_sync_interval', '5', 'Interval in minutes between router syncs'),
('router_address_list', 'catwaf-banned', 'Default address list name for banned IPs on routers');

-- Log the migration
INSERT IGNORE INTO migration_logs (migration_name) VALUES ('28-default-waf-settings');
