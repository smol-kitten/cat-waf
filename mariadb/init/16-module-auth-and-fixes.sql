-- Update Telemetry Module System with API Key Authentication
-- Migration: 16-module-auth-and-fixes.sql
-- Adds API key authentication and fixes module hierarchy

-- Add authentication columns to telemetry_modules if they don't exist
ALTER TABLE telemetry_modules 
ADD COLUMN IF NOT EXISTS requires_auth TINYINT(1) DEFAULT 0 COMMENT 'Requires Bearer token for submission',
ADD COLUMN IF NOT EXISTS api_key VARCHAR(64) DEFAULT NULL COMMENT 'SHA256 hash of API key for auth';

-- Add index for auth lookups
ALTER TABLE telemetry_modules 
ADD INDEX IF NOT EXISTS idx_requires_auth (requires_auth);

-- Update module definitions: Core is primary, CatWAF is optional
-- This will update existing modules or insert if they don't exist
INSERT INTO telemetry_modules (module_name, display_name, description, dns_prefix, enabled, is_core, requires_auth) VALUES
('core', 'Core', 'Essential telemetry: system health, basic metrics, uptime', 'core', 1, 1, 0),
('catwaf', 'CatWAF', 'WAF-specific metrics: request stats, security events, performance', 'catwaf', 1, 0, 0),
('general', 'General Services', 'Flexible JSON schema for custom service metrics', 'general', 1, 0, 0)
ON DUPLICATE KEY UPDATE 
    display_name = VALUES(display_name),
    description = VALUES(description),
    is_core = VALUES(is_core);

-- Mark migration as applied
INSERT INTO migration_logs (migration_name, applied_at) 
VALUES ('16-module-auth-and-fixes.sql', NOW())
ON DUPLICATE KEY UPDATE applied_at = NOW();
