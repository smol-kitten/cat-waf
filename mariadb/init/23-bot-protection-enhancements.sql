-- Bot Protection Enhancements
-- Created: 2026-02-07

-- Add category column to bot_whitelist if not exists
ALTER TABLE bot_whitelist ADD COLUMN IF NOT EXISTS category VARCHAR(50) DEFAULT 'custom';

-- Add challenge tracking to bot_detections
ALTER TABLE bot_detections ADD COLUMN IF NOT EXISTS challenge_type VARCHAR(20) DEFAULT NULL;
ALTER TABLE bot_detections ADD COLUMN IF NOT EXISTS challenge_passed TINYINT(1) DEFAULT NULL;
ALTER TABLE bot_detections ADD COLUMN IF NOT EXISTS request_count INT DEFAULT 1;

-- Create index for faster bot lookups
CREATE INDEX IF NOT EXISTS idx_bot_detections_bot_type ON bot_detections(bot_type);
CREATE INDEX IF NOT EXISTS idx_bot_detections_action ON bot_detections(action);
CREATE INDEX IF NOT EXISTS idx_bot_detections_ip_timestamp ON bot_detections(ip_address, timestamp);

-- Bot protection settings
INSERT INTO settings (setting_key, setting_value, description) VALUES
('bot_protection_enabled', '1', 'Enable bot protection'),
('bot_block_empty_ua', '1', 'Block requests with empty user agent'),
('bot_rate_limit_good', '100', 'Requests per minute for good bots'),
('bot_rate_limit_bad', '10', 'Requests per minute for flagged bots before blocking'),
('bot_challenge_mode', 'none', 'Challenge mode: none, js, cookie, captcha'),
('bot_log_all_requests', '0', 'Log all requests including human traffic')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- Update existing bot_whitelist with categories
UPDATE bot_whitelist SET category = 'search' WHERE pattern LIKE '%googlebot%' OR pattern LIKE '%bingbot%' OR pattern LIKE '%duckduckbot%';
UPDATE bot_whitelist SET category = 'social' WHERE pattern LIKE '%facebook%' OR pattern LIKE '%twitter%' OR pattern LIKE '%linkedin%' OR pattern LIKE '%discord%';
UPDATE bot_whitelist SET category = 'seo' WHERE pattern LIKE '%ahrefs%' OR pattern LIKE '%semrush%' OR pattern LIKE '%mj12bot%';
UPDATE bot_whitelist SET category = 'scanner' WHERE pattern LIKE '%nmap%' OR pattern LIKE '%nikto%' OR pattern LIKE '%sqlmap%';
UPDATE bot_whitelist SET category = 'generic' WHERE pattern LIKE '%python%' OR pattern LIKE '%curl%' OR pattern LIKE '%wget%';
