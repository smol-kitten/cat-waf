-- Add local_only column to sites table
-- This allows restricting site access to local/private IP ranges only

ALTER TABLE sites ADD COLUMN IF NOT EXISTS local_only TINYINT(1) DEFAULT 0 COMMENT 'Restrict access to local/private IPs only';

-- Log this migration
INSERT INTO migration_logs (migration_name, applied_at) 
VALUES ('05-add-local-only-column', NOW())
ON DUPLICATE KEY UPDATE applied_at = NOW();
