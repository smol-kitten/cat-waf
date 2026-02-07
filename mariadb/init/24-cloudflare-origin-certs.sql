-- Migration: Cloudflare Origin Certificate Support
-- Created: 2026-02-07

-- Store Cloudflare Origin Certificates as fallback for domains
CREATE TABLE IF NOT EXISTS cf_origin_certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    certificate TEXT NOT NULL COMMENT 'PEM-encoded certificate',
    private_key_encrypted TEXT NOT NULL COMMENT 'Encrypted private key',
    expires_at TIMESTAMP NOT NULL,
    is_active TINYINT(1) DEFAULT 0 COMMENT 'Currently in use as fallback',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used TIMESTAMP NULL,
    notes TEXT,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    INDEX idx_domain_active (domain_id, is_active),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Track certificate fallback events
CREATE TABLE IF NOT EXISTS cert_fallback_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    primary_cert_error TEXT COMMENT 'Error that triggered fallback',
    fallback_started TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fallback_ended TIMESTAMP NULL,
    auto_recovered TINYINT(1) DEFAULT 0,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    INDEX idx_domain_time (domain_id, fallback_started)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Settings for CF origin cert behavior
INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES
('cf_origin_auto_fallback', 'true', 'Automatically switch to CF origin cert when primary fails'),
('cf_origin_alert_on_fallback', 'true', 'Send alert when falling back to CF origin cert'),
('cf_origin_check_interval', '300', 'Seconds between primary cert health checks'),
('cf_origin_encryption_key', '', 'Key for encrypting stored private keys');
