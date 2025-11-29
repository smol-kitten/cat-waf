-- Add telemetry configuration to existing WAF database
-- Migration: 14-telemetry-integration.sql

-- Telemetry Configuration Table
CREATE TABLE IF NOT EXISTS telemetry_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    system_uuid VARCHAR(36) UNIQUE NOT NULL,
    
    -- Opt-in Settings
    opt_in_enabled TINYINT(1) DEFAULT 0,
    opt_in_date TIMESTAMP NULL,
    
    -- Collection Intervals
    collection_interval ENUM('off', 'manual', 'daily', 'weekly', 'monthly') DEFAULT 'off',
    last_collection TIMESTAMP NULL,
    
    -- Privacy Controls (DNS-based categories)
    collect_usage TINYINT(1) DEFAULT 0,      -- usage.telemetry.domain.tld
    collect_settings TINYINT(1) DEFAULT 0,    -- settings.telemetry.domain.tld
    collect_system TINYINT(1) DEFAULT 0,      -- system.telemetry.domain.tld
    collect_security TINYINT(1) DEFAULT 0,    -- security.telemetry.domain.tld
    
    -- Telemetry Endpoint
    telemetry_endpoint VARCHAR(255) DEFAULT 'https://catwaf.telemetry.catboy.systems',
    api_key VARCHAR(64) DEFAULT NULL,
    
    -- 404 Collection for Honeypot
    collect_404_paths TINYINT(1) DEFAULT 0,
    min_404_hits INT DEFAULT 5,  -- Minimum hits before sending
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_uuid (system_uuid),
    INDEX idx_opt_in (opt_in_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Site Telemetry UUIDs
CREATE TABLE IF NOT EXISTS site_telemetry_uuids (
    id INT PRIMARY KEY AUTO_INCREMENT,
    site_id INT NOT NULL,
    site_uuid VARCHAR(36) UNIQUE NOT NULL,
    metrics_enabled TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    UNIQUE KEY unique_site (site_id),
    INDEX idx_uuid (site_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Telemetry Submission Log (track what was sent)
CREATE TABLE IF NOT EXISTS telemetry_submissions (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    category ENUM('usage', 'settings', 'system', 'security') NOT NULL,
    status ENUM('success', 'failed') DEFAULT 'success',
    response_code INT DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    data_hash VARCHAR(64) DEFAULT NULL,  -- To prevent duplicate submissions
    
    INDEX idx_submitted (submitted_at),
    INDEX idx_category (category),
    INDEX idx_hash (data_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generate system UUID if not exists
INSERT IGNORE INTO telemetry_config (system_uuid, opt_in_enabled, collection_interval)
SELECT 
    UUID() as system_uuid,
    0 as opt_in_enabled,
    'off' as collection_interval
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM telemetry_config LIMIT 1);
