-- Migration: Certificate Authority Center
-- Created: 2026-02-07

-- Store Certificate Authority configurations
CREATE TABLE IF NOT EXISTS certificate_authorities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type ENUM('self-signed', 'imported', 'acme') NOT NULL DEFAULT 'self-signed',
    is_root TINYINT(1) DEFAULT 1,
    parent_ca_id INT NULL COMMENT 'For intermediate CAs',
    certificate TEXT NOT NULL COMMENT 'PEM-encoded CA certificate',
    private_key_encrypted TEXT NOT NULL COMMENT 'Encrypted CA private key',
    public_key TEXT,
    subject_cn VARCHAR(255) NOT NULL,
    subject_o VARCHAR(255),
    subject_ou VARCHAR(255),
    subject_c VARCHAR(2),
    subject_st VARCHAR(100),
    subject_l VARCHAR(100),
    key_algorithm ENUM('RSA-2048', 'RSA-4096', 'EC-P256', 'EC-P384') DEFAULT 'RSA-4096',
    valid_from TIMESTAMP NOT NULL,
    valid_until TIMESTAMP NOT NULL,
    serial_number VARCHAR(64) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    can_issue_certs TINYINT(1) DEFAULT 1,
    max_path_length INT DEFAULT 0 COMMENT 'For CA chain depth',
    crl_url VARCHAR(500),
    ocsp_url VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_ca_id) REFERENCES certificate_authorities(id) ON DELETE SET NULL,
    UNIQUE INDEX idx_serial (serial_number),
    INDEX idx_active (is_active),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Issued certificates
CREATE TABLE IF NOT EXISTS issued_certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ca_id INT NOT NULL,
    serial_number VARCHAR(64) NOT NULL,
    subject_cn VARCHAR(255) NOT NULL,
    subject_o VARCHAR(255),
    subject_ou VARCHAR(255),
    cert_type ENUM('server', 'client', 'code-signing', 'email') DEFAULT 'server',
    certificate TEXT NOT NULL COMMENT 'PEM-encoded certificate',
    private_key_encrypted TEXT COMMENT 'Encrypted private key if generated here',
    csr TEXT COMMENT 'Original CSR if provided',
    key_algorithm ENUM('RSA-2048', 'RSA-4096', 'EC-P256', 'EC-P384') DEFAULT 'RSA-2048',
    san TEXT COMMENT 'JSON array of Subject Alternative Names',
    valid_from TIMESTAMP NOT NULL,
    valid_until TIMESTAMP NOT NULL,
    is_revoked TINYINT(1) DEFAULT 0,
    revoked_at TIMESTAMP NULL,
    revocation_reason ENUM('unspecified', 'key_compromise', 'ca_compromise', 'affiliation_changed', 'superseded', 'cessation_of_operation') NULL,
    purpose VARCHAR(255) COMMENT 'Description of what cert is used for',
    issued_to VARCHAR(255) COMMENT 'User/system that requested cert',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ca_id) REFERENCES certificate_authorities(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_ca_serial (ca_id, serial_number),
    INDEX idx_cn (subject_cn),
    INDEX idx_revoked (is_revoked),
    INDEX idx_expires (valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Certificate Revocation List tracking
CREATE TABLE IF NOT EXISTS crl_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ca_id INT NOT NULL,
    cert_id INT NOT NULL,
    serial_number VARCHAR(64) NOT NULL,
    revocation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reason ENUM('unspecified', 'key_compromise', 'ca_compromise', 'affiliation_changed', 'superseded', 'cessation_of_operation') DEFAULT 'unspecified',
    FOREIGN KEY (ca_id) REFERENCES certificate_authorities(id) ON DELETE CASCADE,
    FOREIGN KEY (cert_id) REFERENCES issued_certificates(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_ca_cert (ca_id, cert_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Track next serial number per CA
CREATE TABLE IF NOT EXISTS ca_serial_counters (
    ca_id INT PRIMARY KEY,
    next_serial BIGINT DEFAULT 1,
    FOREIGN KEY (ca_id) REFERENCES certificate_authorities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Settings for CA center
INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES
('ca_center_enabled', 'true', 'Enable Certificate Authority Center'),
('ca_encryption_key', '', 'Key for encrypting CA private keys'),
('ca_default_validity_days', '365', 'Default certificate validity in days'),
('ca_max_validity_days', '3650', 'Maximum certificate validity in days'),
('ca_crl_validity_hours', '24', 'CRL validity period in hours'),
('ca_auto_crl_generation', 'true', 'Automatically regenerate CRL on revocation');
