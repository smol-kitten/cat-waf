-- Migration: Add HTTP redirect control and Cloudflare rate limit bypass
-- Date: 2025-10-17
-- This migration adds columns for new features introduced after initial schema
--
-- IMPORTANT: This file uses ALTER TABLE ... IF NOT EXISTS which is MariaDB 10.0.2+
-- For existing databases, these columns will be added safely
-- For fresh installs, the columns are already in 01-complete-schema.sql

USE waf_db;

-- Add disable_http_redirect to prevent NGINX from redirecting HTTP->HTTPS
-- This fixes infinite redirect loops when backend also redirects
ALTER TABLE sites ADD COLUMN IF NOT EXISTS disable_http_redirect TINYINT(1) DEFAULT 0 
    COMMENT 'Disable NGINX HTTP->HTTPS redirect when backend handles it';

-- Add Cloudflare rate limit bypass options
ALTER TABLE sites ADD COLUMN IF NOT EXISTS cf_bypass_ratelimit TINYINT(1) DEFAULT 0
    COMMENT 'Bypass rate limiting for Cloudflare IPs';

ALTER TABLE sites ADD COLUMN IF NOT EXISTS cf_custom_rate_limit INT(11) DEFAULT 100
    COMMENT 'Custom rate limit for Cloudflare IPs (requests per second)';

ALTER TABLE sites ADD COLUMN IF NOT EXISTS cf_rate_limit_burst INT(11) DEFAULT 200
    COMMENT 'Burst limit for Cloudflare rate limiting';

-- Verification query (uncomment to check)
-- SELECT COUNT(*) as has_new_columns FROM information_schema.COLUMNS 
-- WHERE TABLE_SCHEMA = 'waf_db' AND TABLE_NAME = 'sites' 
-- AND COLUMN_NAME IN ('disable_http_redirect', 'cf_bypass_ratelimit', 'cf_custom_rate_limit', 'cf_rate_limit_burst');
