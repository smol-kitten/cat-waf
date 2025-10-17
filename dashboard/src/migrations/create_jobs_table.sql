-- Background Job Queue Table
CREATE TABLE IF NOT EXISTS jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL COMMENT 'Job type: cert_provision, config_regen, etc',
    payload JSON COMMENT 'Job-specific data',
    status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, running, completed, failed',
    priority INT DEFAULT 0 COMMENT 'Higher priority jobs run first',
    attempts INT DEFAULT 0 COMMENT 'Number of execution attempts',
    max_attempts INT DEFAULT 3 COMMENT 'Maximum retry attempts',
    error TEXT COMMENT 'Error message if failed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    INDEX idx_status (status),
    INDEX idx_type (type),
    INDEX idx_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
