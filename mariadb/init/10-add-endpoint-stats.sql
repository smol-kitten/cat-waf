-- Add endpoint statistics columns to request_telemetry table
-- This migration adds support for tracking top accessed endpoints and 404s

USE waf_db;

-- Add site_id column for faster per-site queries
ALTER TABLE request_telemetry 
ADD COLUMN IF NOT EXISTS site_id INT(11) DEFAULT NULL,
ADD INDEX idx_site_id (site_id);

-- Mark as applied
INSERT INTO migration_logs (migration_name, applied_at) 
VALUES ('10-add-endpoint-stats.sql', NOW())
ON DUPLICATE KEY UPDATE applied_at = NOW();
