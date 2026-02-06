-- Add ACME provider column to sites table for ZeroSSL support
USE waf_db;

-- Add acme_provider column if it doesn't exist
ALTER TABLE sites ADD COLUMN IF NOT EXISTS acme_provider VARCHAR(20) DEFAULT 'letsencrypt' COMMENT 'ACME provider: letsencrypt or zerossl';

-- Add index for provider
ALTER TABLE sites ADD INDEX IF NOT EXISTS idx_acme_provider (acme_provider);

-- Log migration
INSERT IGNORE INTO migration_logs (migration_name) VALUES ('17-add-acme-provider');
