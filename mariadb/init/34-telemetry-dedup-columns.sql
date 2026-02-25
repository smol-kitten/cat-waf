-- Add referer, blocked, and blocked_reason to request_telemetry
-- This eliminates the need for redundant access_logs inserts
-- since request_telemetry now captures all the same data.

ALTER TABLE request_telemetry
  ADD COLUMN IF NOT EXISTS `referer` text DEFAULT NULL AFTER `user_agent`,
  ADD COLUMN IF NOT EXISTS `blocked` tinyint(1) DEFAULT 0 AFTER `referer`,
  ADD COLUMN IF NOT EXISTS `blocked_reason` varchar(255) DEFAULT NULL AFTER `blocked`;

-- Add index on blocked column for efficient filtering
ALTER TABLE request_telemetry
  ADD INDEX IF NOT EXISTS `idx_blocked` (`blocked`);

INSERT INTO migration_logs (migration_name, applied_at)
VALUES ('34-telemetry-dedup-columns.sql', NOW())
ON DUPLICATE KEY UPDATE applied_at = NOW();
