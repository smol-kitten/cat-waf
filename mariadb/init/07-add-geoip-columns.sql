-- Migration: Add GeoIP columns to access_logs if missing
-- This ensures proper UTF-8 encoding for country names

USE waf_db;

-- Check if country column exists (as TEXT for full names)
SET @col_exists = (SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'waf_db' 
    AND TABLE_NAME = 'access_logs' 
    AND COLUMN_NAME = 'country');

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE access_logs ADD COLUMN country VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER country_code',
    'SELECT "Column country already exists" AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if city column exists
SET @col_exists = (SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'waf_db' 
    AND TABLE_NAME = 'access_logs' 
    AND COLUMN_NAME = 'city');

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE access_logs ADD COLUMN city VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER country',
    'SELECT "Column city already exists" AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index on country for faster filtering
SET @idx_exists = (SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = 'waf_db' 
    AND TABLE_NAME = 'access_logs' 
    AND INDEX_NAME = 'idx_country');

SET @sql = IF(@idx_exists = 0, 
    'ALTER TABLE access_logs ADD INDEX idx_country (country)',
    'SELECT "Index idx_country already exists" AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Log migration
INSERT INTO migration_logs (migration_name, applied_at) 
VALUES ('07-add-geoip-columns', NOW())
ON DUPLICATE KEY UPDATE applied_at = NOW();

SELECT 'Migration 07: GeoIP columns added/verified with proper UTF-8 encoding' AS status;
