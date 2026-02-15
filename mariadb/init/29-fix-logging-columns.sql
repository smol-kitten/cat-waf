-- Migration: Fix logging columns for robust log ingestion
-- Ensures access_logs and request_telemetry have all needed columns

-- request_telemetry: add referer column (was missing, queried by telemetry.php)
-- user_agent column already exists in schema

ALTER TABLE `request_telemetry`
  ADD COLUMN IF NOT EXISTS `referer` TEXT DEFAULT NULL AFTER `user_agent`;

-- access_logs: ensure all columns exist (should be present from 01-complete-schema.sql)
-- Adding IF NOT EXISTS guards for safety in case of partial migrations

ALTER TABLE `access_logs`
  ADD COLUMN IF NOT EXISTS `response_time` FLOAT DEFAULT NULL AFTER `referer`,
  ADD COLUMN IF NOT EXISTS `blocked` TINYINT(1) DEFAULT 0 AFTER `response_time`,
  ADD COLUMN IF NOT EXISTS `blocked_reason` VARCHAR(255) DEFAULT NULL AFTER `blocked`;

-- Add index on blocked column for efficient filtered queries
CREATE INDEX IF NOT EXISTS `idx_access_logs_blocked` ON `access_logs` (`blocked`);

-- Mark migration as applied
INSERT INTO migration_logs (migration_name, applied_at)
VALUES ('29-fix-logging-columns.sql', NOW())
ON DUPLICATE KEY UPDATE applied_at = NOW();
