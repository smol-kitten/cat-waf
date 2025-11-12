-- Migration: Create bot whitelist management table
-- This allows dynamic bot management from the dashboard interface

CREATE TABLE IF NOT EXISTS `bot_whitelist` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `pattern` VARCHAR(255) NOT NULL COMMENT 'Regex pattern to match bot User-Agent',
  `action` ENUM('allow', 'flag', 'block') NOT NULL DEFAULT 'allow' COMMENT 'allow=0, flag=1, block=2 in nginx map',
  `description` VARCHAR(255) DEFAULT NULL COMMENT 'Human-readable description of the bot',
  `enabled` TINYINT(1) DEFAULT 1 COMMENT 'Whether this rule is active',
  `priority` INT(11) DEFAULT 100 COMMENT 'Lower numbers match first (specific rules should have lower priority)',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert existing bot patterns from bot-protection.conf
INSERT IGNORE INTO bot_whitelist (pattern, action, description, priority) VALUES
-- Good bots (allow - priority 10-50)
('~*googlebot', 'allow', 'Google Search Bot', 10),
('~*bingbot', 'allow', 'Bing Search Bot', 11),
('~*slurp', 'allow', 'Yahoo Search Bot', 12),
('~*duckduckbot', 'allow', 'DuckDuckGo Bot', 13),
('~*baiduspider', 'allow', 'Baidu Search Bot', 14),
('~*yandexbot', 'allow', 'Yandex Search Bot', 15),
('~*sogou', 'allow', 'Sogou Search Bot', 16),
('~*exabot', 'allow', 'Exalead Search Bot', 17),
('~*facebookexternalhit', 'allow', 'Facebook Link Preview', 20),
('~*facebot', 'allow', 'Facebook Crawler', 21),
('~*twitterbot', 'allow', 'Twitter Link Preview', 22),
('~*linkedinbot', 'allow', 'LinkedIn Link Preview', 23),
('~*whatsapp', 'allow', 'WhatsApp Link Preview', 24),
('~*telegrambot', 'allow', 'Telegram Link Preview', 25),
('~*discordbot', 'allow', 'Discord Link Preview', 26),
('~*slackbot', 'allow', 'Slack Link Preview', 27),
('~*applebot', 'allow', 'Apple Search Bot', 30),
('~*ia_archiver', 'allow', 'Internet Archive Bot', 31),

-- Flagged bots (flag - priority 100-150)
('~*bot', 'flag', 'Generic Bot Pattern', 100),
('~*crawl', 'flag', 'Generic Crawler Pattern', 101),
('~*spider', 'flag', 'Generic Spider Pattern', 102),
('~*scan', 'flag', 'Generic Scanner Pattern', 103),

-- Bad bots (block - priority 200-250)
('~*semrush', 'block', 'SEMrush Crawler (aggressive)', 200),
('~*ahrefs', 'block', 'Ahrefs Crawler (aggressive)', 201),
('~*mj12bot', 'block', 'Majestic Crawler (aggressive)', 202),
('~*dotbot', 'block', 'DotBot Crawler (aggressive)', 203),
('~*blexbot', 'block', 'BLEXBot Crawler (aggressive)', 204),
('~*petalbot', 'block', 'PetalBot Crawler (aggressive)', 205);

-- Mark migration as applied
INSERT IGNORE INTO migration_logs (migration_name) VALUES ('09-bot-whitelist-table');
