-- Scanner Detection and 404 Tracking Schema
-- Detects IPs hitting many 404s (likely scanners) and tracks scanned endpoints

-- Track endpoints that have been scanned (hit with 404s)
CREATE TABLE IF NOT EXISTS scanned_endpoints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    path VARCHAR(500) NOT NULL,
    first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    hit_count INT DEFAULT 1,
    unique_ips INT DEFAULT 1,
    category ENUM('wordpress', 'php', 'config', 'admin', 'api', 'git', 'backup', 'other') DEFAULT 'other',
    is_honeypot TINYINT(1) DEFAULT 0 COMMENT 'Mark as honeypot to auto-block scanners',
    notes TEXT,
    INDEX idx_path (path(255)),
    INDEX idx_category (category),
    INDEX idx_hit_count (hit_count),
    INDEX idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track IPs identified as scanners
CREATE TABLE IF NOT EXISTS scanner_ips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    scan_count INT DEFAULT 1 COMMENT 'Total 404 requests',
    paths_scanned INT DEFAULT 1 COMMENT 'Unique paths scanned',
    successful_requests INT DEFAULT 0 COMMENT 'Non-404 requests (legitimate traffic)',
    status ENUM('monitoring', 'challenged', 'rate_limited', 'blocked', 'whitelisted') DEFAULT 'monitoring',
    action_taken_at TIMESTAMP NULL,
    auto_blocked TINYINT(1) DEFAULT 0,
    country_code VARCHAR(2) NULL,
    user_agent TEXT,
    notes TEXT,
    UNIQUE KEY unique_ip (ip_address),
    INDEX idx_status (status),
    INDEX idx_scan_count (scan_count),
    INDEX idx_last_seen (last_seen),
    INDEX idx_auto_blocked (auto_blocked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Detailed log of scanner requests (for analysis)
CREATE TABLE IF NOT EXISTS scanner_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    scanner_ip_id INT NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    path VARCHAR(500),
    user_agent TEXT,
    status_code INT,
    domain VARCHAR(255),
    INDEX idx_scanner_ip (scanner_ip_id),
    INDEX idx_timestamp (timestamp),
    INDEX idx_path (path(255)),
    FOREIGN KEY (scanner_ip_id) REFERENCES scanner_ips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scanner detection settings
INSERT INTO settings (setting_key, setting_value, description) VALUES
    ('scanner_detection_enabled', '1', 'Enable automatic scanner detection'),
    ('scanner_404_threshold', '10', 'Number of 404s to trigger scanner detection'),
    ('scanner_timeframe', '300', 'Timeframe in seconds for 404 threshold'),
    ('scanner_success_ratio', '0.1', 'Max ratio of success/404 to still consider scanner'),
    ('scanner_auto_block', '0', 'Automatically block detected scanners'),
    ('scanner_auto_block_duration', '86400', 'Auto-block duration in seconds (default 24h)')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- Common scanned paths to pre-populate
INSERT INTO scanned_endpoints (path, category, hit_count, unique_ips) VALUES
    ('/wp-admin', 'wordpress', 0, 0),
    ('/wp-login.php', 'wordpress', 0, 0),
    ('/wp-includes', 'wordpress', 0, 0),
    ('/wp-content', 'wordpress', 0, 0),
    ('/xmlrpc.php', 'wordpress', 0, 0),
    ('/wp-config.php', 'wordpress', 0, 0),
    ('/.env', 'config', 0, 0),
    ('/.git/config', 'git', 0, 0),
    ('/.git/HEAD', 'git', 0, 0),
    ('/phpmyadmin', 'admin', 0, 0),
    ('/phpMyAdmin', 'admin', 0, 0),
    ('/admin', 'admin', 0, 0),
    ('/administrator', 'admin', 0, 0),
    ('/backup.sql', 'backup', 0, 0),
    ('/database.sql', 'backup', 0, 0),
    ('/db.sql', 'backup', 0, 0),
    ('/config.php', 'config', 0, 0),
    ('/configuration.php', 'config', 0, 0),
    ('/phpinfo.php', 'php', 0, 0),
    ('/info.php', 'php', 0, 0),
    ('/shell.php', 'php', 0, 0),
    ('/c99.php', 'php', 0, 0),
    ('/r57.php', 'php', 0, 0),
    ('/.htaccess', 'config', 0, 0),
    ('/.htpasswd', 'config', 0, 0),
    ('/api/v1', 'api', 0, 0),
    ('/api/users', 'api', 0, 0),
    ('/api/admin', 'api', 0, 0)
ON DUPLICATE KEY UPDATE path = path;

-- Mark migration as applied
INSERT INTO migration_logs (migration_name, applied_at) 
VALUES ('20-scanner-detection.sql', NOW())
ON DUPLICATE KEY UPDATE applied_at = NOW();
