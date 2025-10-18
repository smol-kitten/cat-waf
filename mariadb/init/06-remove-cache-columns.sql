-- Remove all caching-related columns from sites table
-- Cache functionality has been removed from the WAF

ALTER TABLE sites 
    DROP COLUMN IF EXISTS enable_caching,
    DROP COLUMN IF EXISTS cache_duration,
    DROP COLUMN IF EXISTS cache_static_files,
    DROP COLUMN IF EXISTS cache_max_size,
    DROP COLUMN IF EXISTS cache_path;

-- Log this migration
INSERT INTO migration_logs (migration_name, applied_at) 
VALUES ('06-remove-cache-columns', NOW())
ON DUPLICATE KEY UPDATE applied_at = NOW();
