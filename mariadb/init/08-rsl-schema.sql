-- RSL (Really Simple Licensing) Schema
-- Implements the RSL 1.0 Specification for content licensing
-- https://rslstandard.org/spec/1.0/

USE waf_db;

-- ============================================
-- RSL License Definitions
-- Stores the actual license configurations
-- ============================================
CREATE TABLE IF NOT EXISTS `rsl_licenses` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `uuid` VARCHAR(36) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    
    -- Content URL pattern (can be wildcard like /blog/* or specific URLs)
    `content_url_pattern` VARCHAR(500) DEFAULT '*',
    
    -- License server URL (for OLP)
    `license_server` VARCHAR(500) DEFAULT NULL,
    
    -- Encrypted content support
    `encrypted` TINYINT(1) DEFAULT 0,
    `encryption_algorithm` VARCHAR(50) DEFAULT 'aes-256-gcm',
    
    -- Permissions JSON: {"usage": ["ai-all", "search"], "user": ["commercial"], "geo": ["US", "GB"]}
    `permits` JSON DEFAULT NULL,
    
    -- Prohibitions JSON (same structure as permits)
    `prohibits` JSON DEFAULT NULL,
    
    -- Payment configuration
    `payment_type` ENUM('free', 'purchase', 'subscription', 'training', 'crawl', 'use', 'contribution', 'attribution') DEFAULT 'free',
    `payment_amount` DECIMAL(10,2) DEFAULT 0.00,
    `payment_currency` VARCHAR(3) DEFAULT 'USD',
    `payment_standard` VARCHAR(500) DEFAULT NULL,  -- URL to standard license
    `payment_custom` VARCHAR(500) DEFAULT NULL,    -- URL to custom payment endpoint
    `payment_accepts` VARCHAR(255) DEFAULT NULL,   -- e.g., 'credit-card,crypto,invoice'
    
    -- Legal references
    `legal_terms` VARCHAR(500) DEFAULT NULL,
    `legal_warranty` VARCHAR(500) DEFAULT NULL,
    `legal_disclaimer` VARCHAR(500) DEFAULT NULL,
    `legal_contact` VARCHAR(500) DEFAULT NULL,
    `legal_proof` VARCHAR(500) DEFAULT NULL,
    `legal_attestation` VARCHAR(500) DEFAULT NULL,
    
    -- Schema extension support
    `schema_url` VARCHAR(500) DEFAULT NULL,
    `alternate_format` VARCHAR(500) DEFAULT NULL,
    
    -- Copyright notice
    `copyright_holder` VARCHAR(255) DEFAULT NULL,
    `copyright_year` VARCHAR(20) DEFAULT NULL,
    `copyright_license` VARCHAR(255) DEFAULT NULL,
    
    -- Global or site-specific
    `site_id` INT(11) DEFAULT NULL,  -- NULL = global default
    `is_default` TINYINT(1) DEFAULT 0,
    `enabled` TINYINT(1) DEFAULT 1,
    `priority` INT(11) DEFAULT 0,
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    KEY `idx_site_id` (`site_id`),
    KEY `idx_enabled` (`enabled`),
    KEY `idx_is_default` (`is_default`),
    CONSTRAINT `fk_rsl_licenses_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- RSL License Server Clients (OLP)
-- Registered clients that can request license tokens
-- ============================================
CREATE TABLE IF NOT EXISTS `rsl_clients` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `client_id` VARCHAR(64) NOT NULL,
    `client_secret` VARCHAR(255) NOT NULL,  -- Hashed
    `client_name` VARCHAR(255) NOT NULL,
    `client_type` ENUM('crawler', 'ai-agent', 'search-engine', 'application', 'other') DEFAULT 'other',
    `description` TEXT DEFAULT NULL,
    
    -- Contact information
    `contact_email` VARCHAR(255) DEFAULT NULL,
    `contact_url` VARCHAR(500) DEFAULT NULL,
    
    -- Access configuration
    `allowed_scopes` JSON DEFAULT NULL,  -- Which license types this client can request
    `allowed_domains` JSON DEFAULT NULL, -- Which domains this client can access
    `rate_limit` INT(11) DEFAULT 1000,   -- Requests per hour
    
    -- Status
    `enabled` TINYINT(1) DEFAULT 1,
    `approved` TINYINT(1) DEFAULT 0,     -- Requires admin approval
    `auto_approve` TINYINT(1) DEFAULT 0, -- Auto-approve token requests
    
    -- Stats
    `last_used` TIMESTAMP NULL DEFAULT NULL,
    `total_requests` BIGINT(20) DEFAULT 0,
    `total_tokens_issued` INT(11) DEFAULT 0,
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `client_id` (`client_id`),
    KEY `idx_enabled` (`enabled`),
    KEY `idx_approved` (`approved`),
    KEY `idx_client_type` (`client_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- RSL License Tokens
-- Issued tokens for authenticated access
-- ============================================
CREATE TABLE IF NOT EXISTS `rsl_tokens` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `token` VARCHAR(255) NOT NULL,  -- The actual license token
    `token_hash` VARCHAR(64) NOT NULL,  -- SHA-256 hash for lookup
    `client_id` INT(11) NOT NULL,
    `license_id` INT(11) DEFAULT NULL,
    
    -- Token scope
    `scope` VARCHAR(255) DEFAULT NULL,  -- e.g., 'ai-train search'
    `content_url` VARCHAR(500) DEFAULT NULL,  -- Specific content this token grants access to
    
    -- Token metadata
    `token_type` VARCHAR(20) DEFAULT 'License',
    `expires_at` TIMESTAMP NULL DEFAULT NULL,  -- NULL = non-expiring
    
    -- Usage tracking
    `used_count` INT(11) DEFAULT 0,
    `last_used` TIMESTAMP NULL DEFAULT NULL,
    `last_used_ip` VARCHAR(45) DEFAULT NULL,
    
    -- Status
    `revoked` TINYINT(1) DEFAULT 0,
    `revoked_at` TIMESTAMP NULL DEFAULT NULL,
    `revoked_reason` VARCHAR(255) DEFAULT NULL,
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `token_hash` (`token_hash`),
    KEY `idx_client_id` (`client_id`),
    KEY `idx_license_id` (`license_id`),
    KEY `idx_expires_at` (`expires_at`),
    KEY `idx_revoked` (`revoked`),
    CONSTRAINT `fk_rsl_tokens_client` FOREIGN KEY (`client_id`) REFERENCES `rsl_clients`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rsl_tokens_license` FOREIGN KEY (`license_id`) REFERENCES `rsl_licenses`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- RSL Access Log
-- Track all license token usage
-- ============================================
CREATE TABLE IF NOT EXISTS `rsl_access_log` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `token_id` BIGINT(20) DEFAULT NULL,
    `client_id` INT(11) DEFAULT NULL,
    `license_id` INT(11) DEFAULT NULL,
    
    -- Request info
    `request_url` VARCHAR(500) DEFAULT NULL,
    `request_method` VARCHAR(10) DEFAULT NULL,
    `request_ip` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    
    -- Result
    `status` ENUM('allowed', 'denied', 'invalid_token', 'expired', 'revoked', 'rate_limited') NOT NULL,
    `status_reason` VARCHAR(255) DEFAULT NULL,
    
    `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    KEY `idx_token_id` (`token_id`),
    KEY `idx_client_id` (`client_id`),
    KEY `idx_timestamp` (`timestamp`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- RSL Encryption Keys (EMS)
-- Store encryption keys for encrypted content
-- ============================================
CREATE TABLE IF NOT EXISTS `rsl_encryption_keys` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `key_id` VARCHAR(64) NOT NULL,
    `license_id` INT(11) NOT NULL,
    `content_url` VARCHAR(500) DEFAULT NULL,
    
    -- Key data (encrypted with server master key)
    `encrypted_key` TEXT NOT NULL,
    `algorithm` VARCHAR(50) DEFAULT 'aes-256-gcm',
    `iv` VARCHAR(255) DEFAULT NULL,
    
    -- Access control
    `requires_token` TINYINT(1) DEFAULT 1,
    `single_use` TINYINT(1) DEFAULT 0,
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NULL DEFAULT NULL,
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `key_id` (`key_id`),
    KEY `idx_license_id` (`license_id`),
    CONSTRAINT `fk_rsl_keys_license` FOREIGN KEY (`license_id`) REFERENCES `rsl_licenses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- RSL Discovery Configuration
-- Configure how RSL documents are discovered
-- ============================================
CREATE TABLE IF NOT EXISTS `rsl_discovery` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `site_id` INT(11) DEFAULT NULL,
    
    -- Discovery methods
    `enable_robots_txt` TINYINT(1) DEFAULT 1,      -- Add License: directive to robots.txt
    `enable_http_header` TINYINT(1) DEFAULT 1,     -- Add Link header to responses
    `enable_html_link` TINYINT(1) DEFAULT 0,       -- Inject <link> into HTML responses
    `enable_wellknown` TINYINT(1) DEFAULT 1,       -- Serve /.well-known/rsl
    
    -- Custom RSL file path (relative to site root)
    `rsl_file_path` VARCHAR(255) DEFAULT '/rsl.xml',
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `site_id` (`site_id`),
    CONSTRAINT `fk_rsl_discovery_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Default global RSL settings
-- ============================================
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
    ('rsl_enabled', '0', 'Enable RSL (Really Simple Licensing) globally'),
    ('rsl_default_permit', '["search"]', 'Default permitted uses for content'),
    ('rsl_default_prohibit', '["ai-train"]', 'Default prohibited uses for content'),
    ('rsl_license_server', '', 'Default license server URL for OLP'),
    ('rsl_master_key', '', 'Master encryption key for EMS (auto-generated on first use)')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ============================================
-- Insert default "Prohibit AI Training" license
-- ============================================
INSERT INTO `rsl_licenses` (
    `uuid`, `name`, `description`, 
    `permits`, `prohibits`, 
    `payment_type`, `is_default`, `enabled`
) VALUES (
    UUID(),
    'Default - Prohibit AI Training',
    'Allows search indexing but prohibits AI training',
    '{"usage": ["search", "ai-index"]}',
    '{"usage": ["ai-train"]}',
    'free',
    1,
    0
) ON DUPLICATE KEY UPDATE name = name;

-- Migration marker
INSERT INTO migration_logs (migration_name, applied_at) 
VALUES ('08-rsl-schema.sql', NOW())
ON DUPLICATE KEY UPDATE applied_at = NOW();
