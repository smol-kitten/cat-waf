-- Router Integration for DROP rules
-- Created: 2026-02-07

-- Router configurations
CREATE TABLE IF NOT EXISTS router_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    router_type ENUM('mikrotik', 'opnsense', 'pfsense', 'iptables', 'nftables', 'custom') NOT NULL,
    host VARCHAR(255) NOT NULL,
    port INT DEFAULT 8728 COMMENT 'API port (8728 for MikroTik)',
    username VARCHAR(100),
    password_encrypted TEXT COMMENT 'AES-256 encrypted password',
    api_key TEXT COMMENT 'For routers using API keys',
    ssl_enabled TINYINT(1) DEFAULT 0,
    ssl_verify TINYINT(1) DEFAULT 1,
    enabled TINYINT(1) DEFAULT 1,
    test_status ENUM('untested', 'success', 'failed') DEFAULT 'untested',
    last_test TIMESTAMP NULL,
    last_error TEXT,
    whitelist_subnets TEXT COMMENT 'JSON array of subnets to never block',
    address_list_name VARCHAR(50) DEFAULT 'catwaf-banned' COMMENT 'MikroTik address list name',
    rule_chain VARCHAR(50) DEFAULT 'forward' COMMENT 'Firewall chain for rules',
    rule_comment_prefix VARCHAR(50) DEFAULT '[CatWAF]' COMMENT 'Prefix for rule comments',
    sync_on_ban TINYINT(1) DEFAULT 1 COMMENT 'Auto-sync when new ban added',
    sync_on_unban TINYINT(1) DEFAULT 1 COMMENT 'Auto-sync when ban removed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_router_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Router rule sync history
CREATE TABLE IF NOT EXISTS router_rule_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    router_id INT NOT NULL,
    action ENUM('add', 'remove', 'bulk_sync', 'test') NOT NULL,
    ip_address VARCHAR(45),
    rule_id VARCHAR(50) COMMENT 'Router-side rule ID if available',
    status ENUM('pending', 'success', 'failed') DEFAULT 'pending',
    error_message TEXT,
    duration_ms INT,
    triggered_by ENUM('auto', 'manual', 'fail2ban', 'scanner', 'api') DEFAULT 'auto',
    ban_id INT COMMENT 'Reference to banned_ips.id',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (router_id) REFERENCES router_configs(id) ON DELETE CASCADE,
    INDEX idx_router_created (router_id, created_at DESC),
    INDEX idx_ip_action (ip_address, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Current router rules (cached state)
CREATE TABLE IF NOT EXISTS router_rules_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    router_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    rule_id VARCHAR(50) COMMENT 'Router-side rule ID',
    comment TEXT,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (router_id) REFERENCES router_configs(id) ON DELETE CASCADE,
    UNIQUE KEY unique_router_ip (router_id, ip_address),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Settings for router integration
INSERT INTO settings (setting_key, setting_value, description) VALUES
('router_integration_enabled', '0', 'Enable router DROP rule integration'),
('router_sync_interval', '60', 'Seconds between sync checks'),
('router_dry_run', '1', 'Log actions but dont execute (for testing)'),
('router_default_ban_duration', '3600', 'Default ban duration in seconds'),
('router_encryption_key', '', 'AES-256 key for password encryption (auto-generated)')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
