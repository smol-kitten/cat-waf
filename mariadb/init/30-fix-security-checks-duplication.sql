-- Fix security_checks duplication
-- The original migration lacked a UNIQUE constraint on check_type,
-- causing INSERT ON DUPLICATE KEY UPDATE to always insert new rows.

-- Step 1: Remove duplicates, keeping the row with the lowest id per check_type
DELETE sc FROM security_checks sc
INNER JOIN (
    SELECT check_type, MIN(id) AS min_id
    FROM security_checks
    GROUP BY check_type
    HAVING COUNT(*) > 1
) dups ON sc.check_type = dups.check_type AND sc.id > dups.min_id;

-- Step 2: Add UNIQUE constraint on check_type (if not already present)
-- Use a procedure to safely add the constraint
DELIMITER //
CREATE PROCEDURE fix_security_checks_unique()
BEGIN
    DECLARE idx_exists INT DEFAULT 0;
    
    SELECT COUNT(*) INTO idx_exists
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'security_checks'
      AND CONSTRAINT_NAME = 'uk_check_type';
    
    IF idx_exists = 0 THEN
        ALTER TABLE security_checks ADD UNIQUE KEY `uk_check_type` (`check_type`);
    END IF;
END //
DELIMITER ;

CALL fix_security_checks_unique();
DROP PROCEDURE IF EXISTS fix_security_checks_unique;

-- Step 3: Re-insert default checks (now safe with UNIQUE constraint)
INSERT INTO `security_checks` (`check_type`, `check_name`, `status`, `severity`, `message`, `check_interval`, `enabled`) VALUES
('ssl_expiry', 'SSL Certificate Expiry Check', 'unknown', 'info', 'Checking SSL certificates for expiration', 3600, 1),
('modsec_status', 'ModSecurity Status', 'unknown', 'info', 'Checking ModSecurity engine status', 300, 1),
('fail2ban_status', 'Fail2ban Status', 'unknown', 'info', 'Checking fail2ban service status', 300, 1),
('disk_space', 'Disk Space Check', 'unknown', 'info', 'Checking available disk space', 1800, 1),
('nginx_status', 'NGINX Status', 'unknown', 'info', 'Checking NGINX service status', 300, 1),
('database_status', 'Database Status', 'unknown', 'info', 'Checking MariaDB database status', 300, 1),
('security_rules', 'Security Rules Check', 'unknown', 'info', 'Checking security rules are loaded', 3600, 1),
('blocked_attacks', 'Recent Attack Activity', 'unknown', 'info', 'Monitoring blocked attack attempts', 300, 1)
ON DUPLICATE KEY UPDATE updated_at = current_timestamp();
