-- Telemetry Module System
-- Migration: 15-telemetry-modules.sql
-- Adds modular telemetry collection with service-specific routing

-- Module definitions table
CREATE TABLE IF NOT EXISTS telemetry_modules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    module_name VARCHAR(50) UNIQUE NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    description TEXT,
    dns_prefix VARCHAR(50), -- e.g., 'catwaf' for catwaf.telemetry.domain.tld
    enabled TINYINT(1) DEFAULT 1,
    is_core TINYINT(1) DEFAULT 0, -- Core modules can't be disabled
    requires_auth TINYINT(1) DEFAULT 0, -- Requires Bearer token for submission
    api_key VARCHAR(64) DEFAULT NULL, -- SHA256 hash of API key for auth
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_module_name (module_name),
    INDEX idx_enabled (enabled),
    INDEX idx_requires_auth (requires_auth)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Site-level module configuration
CREATE TABLE IF NOT EXISTS site_telemetry_modules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    site_id INT NOT NULL,
    module_id INT NOT NULL,
    enabled TINYINT(1) DEFAULT 1,
    custom_config JSON DEFAULT NULL,
    
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY (module_id) REFERENCES telemetry_modules(id) ON DELETE CASCADE,
    UNIQUE KEY unique_site_module (site_id, module_id),
    INDEX idx_site (site_id),
    INDEX idx_module (module_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert core modules (Core is primary, CatWAF is optional extension)
INSERT INTO telemetry_modules (module_name, display_name, description, dns_prefix, enabled, is_core) VALUES
('core', 'Core', 'Essential telemetry: system health, basic metrics, uptime', 'core', 1, 1),
('catwaf', 'CatWAF', 'WAF-specific metrics: request stats, security events, performance', 'catwaf', 1, 0),
('general', 'General Services', 'Flexible JSON schema for custom service metrics', 'general', 1, 0)
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name);

-- Add honeypot blocklist integration to sites
ALTER TABLE sites 
ADD COLUMN IF NOT EXISTS honeypot_blocklist_enabled TINYINT(1) DEFAULT 0 
    COMMENT 'Enable blocklist from 404 honeypot collection',
ADD COLUMN IF NOT EXISTS honeypot_blocklist_mode ENUM('block', 'challenge', 'log') DEFAULT 'block' 
    COMMENT 'Action to take for honeypot-detected paths',
ADD COLUMN IF NOT EXISTS honeypot_min_confidence INT DEFAULT 5 
    COMMENT 'Minimum hit count before blocking path';

-- Create table for site-specific honeypot rules
CREATE TABLE IF NOT EXISTS site_honeypot_rules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    site_id INT NOT NULL,
    path_pattern VARCHAR(500) NOT NULL,
    category VARCHAR(50),
    hit_count INT DEFAULT 0,
    confidence_score INT DEFAULT 0,
    action ENUM('block', 'challenge', 'log') DEFAULT 'block',
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    INDEX idx_site (site_id),
    INDEX idx_pattern (path_pattern(255)),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mark migration as applied
INSERT INTO migration_logs (migration_name, applied_at) 
VALUES ('15-telemetry-modules.sql', NOW())
ON DUPLICATE KEY UPDATE applied_at = NOW();
