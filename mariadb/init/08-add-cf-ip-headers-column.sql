-- Migration: Add Cloudflare IP headers setting
-- This allows backends to receive the real client IP in Cloudflare format headers

ALTER TABLE sites ADD COLUMN IF NOT EXISTS cf_ip_headers TINYINT(1) DEFAULT 0
    COMMENT 'Forward client IP using Cloudflare headers (CF-Connecting-IP, True-Client-IP)';

-- Mark migration as applied
INSERT IGNORE INTO migration_logs (migration_name) VALUES ('08-add-cf-ip-headers-column');
