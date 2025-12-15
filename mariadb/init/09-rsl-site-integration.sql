-- ============================================
-- RSL Site Integration Migration
-- Adds RSL/OLP settings to sites table
-- ============================================

USE waf_db;

-- Add RSL columns to sites table if they don't exist
ALTER TABLE `sites` 
    ADD COLUMN IF NOT EXISTS `enable_rsl` TINYINT(1) DEFAULT 0 COMMENT 'Enable RSL for this site',
    ADD COLUMN IF NOT EXISTS `rsl_inject_olp` TINYINT(1) DEFAULT 0 COMMENT 'Inject OLP endpoints at /cat-waf/rsl/olp/',
    ADD COLUMN IF NOT EXISTS `rsl_license_id` INT(11) DEFAULT NULL COMMENT 'Default RSL license for this site';

-- Add webhook and server mode settings to global settings
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
    ('rsl_webhook_url', '', 'Webhook URL for RSL events (client registration, token issuance, etc.)'),
    ('rsl_webhook_secret', '', 'HMAC secret for webhook signature verification'),
    ('rsl_webhook_events', '["client.registered","client.approved","token.issued","token.revoked"]', 'Events to send to webhook'),
    ('rsl_server_mode', 'inject', 'License server mode: inject (per-site) or external (custom URL)')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- Create webhook logs table
CREATE TABLE IF NOT EXISTS `rsl_webhook_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `event_type` VARCHAR(50) NOT NULL,
    `payload` JSON NOT NULL,
    `response_status` INT(11) DEFAULT NULL,
    `response_body` TEXT DEFAULT NULL,
    `success` TINYINT(1) DEFAULT 0,
    `error_message` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    KEY `idx_event_type` (`event_type`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_success` (`success`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track migration
INSERT INTO `migration_logs` (`migration_name`) VALUES ('09-rsl-site-integration')
ON DUPLICATE KEY UPDATE migration_name = migration_name;
