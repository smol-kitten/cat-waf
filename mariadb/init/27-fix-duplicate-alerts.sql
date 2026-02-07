-- Fix duplicate alerts and add unique constraint
USE waf_db;

-- Remove duplicate alert rules (keep lowest ID)
DELETE ar1 FROM alert_rules ar1
INNER JOIN alert_rules ar2 
WHERE ar1.id > ar2.id 
  AND ar1.rule_name = ar2.rule_name 
  AND ar1.rule_type = ar2.rule_type 
  AND COALESCE(ar1.site_id, 0) = COALESCE(ar2.site_id, 0);

-- Add unique constraint to prevent future duplicates
-- Drop if exists first (for idempotency)
SET @constraint_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = 'waf_db' 
    AND TABLE_NAME = 'alert_rules' 
    AND CONSTRAINT_NAME = 'uk_alert_rule');

SET @sql = IF(@constraint_exists = 0, 
    'ALTER TABLE alert_rules ADD CONSTRAINT uk_alert_rule UNIQUE (rule_name, rule_type, site_id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Log migration
INSERT IGNORE INTO migration_logs (migration_name) VALUES ('27-fix-duplicate-alerts');
