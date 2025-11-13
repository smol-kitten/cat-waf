-- Migration: Add bot_name column to bot_detections table
-- Date: 2025-11-13

-- Add bot_name column if it doesn't exist
ALTER TABLE bot_detections 
ADD COLUMN IF NOT EXISTS `bot_name` varchar(100) DEFAULT 'unknown' AFTER `user_agent`;

-- Add index for bot_name
ALTER TABLE bot_detections 
ADD INDEX IF NOT EXISTS `idx_bot_name` (`bot_name`);

-- Update existing records to extract bot name from user_agent
UPDATE bot_detections SET bot_name = 'googlebot' WHERE user_agent LIKE '%googlebot%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'bingbot' WHERE user_agent LIKE '%bingbot%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'slurp' WHERE user_agent LIKE '%slurp%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'duckduckbot' WHERE user_agent LIKE '%duckduckbot%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'baiduspider' WHERE user_agent LIKE '%baiduspider%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'yandexbot' WHERE user_agent LIKE '%yandexbot%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'facebookexternalhit' WHERE user_agent LIKE '%facebookexternalhit%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'twitterbot' WHERE user_agent LIKE '%twitterbot%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'linkedinbot' WHERE user_agent LIKE '%linkedinbot%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'semrushbot' WHERE user_agent LIKE '%semrushbot%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'ahrefsbot' WHERE user_agent LIKE '%ahrefsbot%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'mj12bot' WHERE user_agent LIKE '%mj12bot%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'dotbot' WHERE user_agent LIKE '%dotbot%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'rogerbot' WHERE user_agent LIKE '%rogerbot%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'screaming frog' WHERE user_agent LIKE '%screaming frog%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'masscan' WHERE user_agent LIKE '%masscan%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'ivre-masscan' WHERE user_agent LIKE '%ivre-masscan%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'zgrab' WHERE user_agent LIKE '%zgrab%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'nmap' WHERE user_agent LIKE '%nmap%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'nikto' WHERE user_agent LIKE '%nikto%' AND bot_name = 'unknown';
UPDATE bot_detections SET bot_name = 'sqlmap' WHERE user_agent LIKE '%sqlmap%' AND bot_name = 'unknown';
