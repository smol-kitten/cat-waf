-- Migration: Fix Facebook bot classification
-- Facebook crawlers should be flagged, not automatically allowed

UPDATE bot_whitelist 
SET action = 'flag', 
    description = 'Facebook Link Preview (flagged for review)',
    updated_at = CURRENT_TIMESTAMP
WHERE pattern = '~*facebookexternalhit';

UPDATE bot_whitelist 
SET action = 'flag',
    description = 'Facebook Crawler (flagged for review)',
    updated_at = CURRENT_TIMESTAMP
WHERE pattern = '~*facebot';

-- Add separate block rule for facebook if needed
INSERT IGNORE INTO bot_whitelist (pattern, action, description, priority) VALUES
('~*facebookexternal', 'flag', 'Facebook External Hit (flagged)', 20);
