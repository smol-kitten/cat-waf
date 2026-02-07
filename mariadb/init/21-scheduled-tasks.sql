-- Scheduled Tasks / Cron Management
-- Created: 2026-02-07

-- Main scheduled tasks table
CREATE TABLE IF NOT EXISTS scheduled_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_name VARCHAR(100) NOT NULL,
    task_type ENUM('ssl_renewal', 'log_cleanup', 'backup', 'health_check', 'ban_sync', 'geoip_update', 'custom') NOT NULL,
    description TEXT,
    schedule VARCHAR(50) NOT NULL COMMENT 'Cron expression (minute hour day month weekday)',
    command TEXT COMMENT 'For custom tasks: shell command to execute',
    php_handler VARCHAR(255) COMMENT 'PHP file/function to call',
    enabled TINYINT(1) DEFAULT 1,
    last_run TIMESTAMP NULL,
    last_status ENUM('success', 'failed', 'running', 'skipped') DEFAULT NULL,
    last_output TEXT,
    last_duration INT DEFAULT NULL COMMENT 'Execution time in seconds',
    next_run TIMESTAMP NULL,
    run_count INT DEFAULT 0,
    fail_count INT DEFAULT 0,
    timeout INT DEFAULT 300 COMMENT 'Max execution time in seconds',
    retry_on_fail TINYINT(1) DEFAULT 0,
    retry_delay INT DEFAULT 60 COMMENT 'Seconds to wait before retry',
    max_retries INT DEFAULT 3,
    notify_on_fail TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_task_name (task_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Task execution history
CREATE TABLE IF NOT EXISTS task_executions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL,
    duration INT DEFAULT NULL COMMENT 'Execution time in seconds',
    status ENUM('running', 'success', 'failed', 'timeout', 'skipped') DEFAULT 'running',
    output TEXT,
    error_message TEXT,
    triggered_by ENUM('scheduler', 'manual', 'api') DEFAULT 'scheduler',
    FOREIGN KEY (task_id) REFERENCES scheduled_tasks(id) ON DELETE CASCADE,
    INDEX idx_task_started (task_id, started_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default system tasks
INSERT INTO scheduled_tasks (task_name, task_type, description, schedule, php_handler, enabled, next_run) VALUES
('SSL Certificate Renewal', 'ssl_renewal', 'Check and renew SSL certificates via ACME', '0 3 * * *', 'cert-renewal.php', 1, DATE_ADD(CURDATE(), INTERVAL 1 DAY) + INTERVAL 3 HOUR),
('Log Cleanup (7 days)', 'log_cleanup', 'Remove access logs older than 7 days', '0 4 * * *', 'tasks/log-cleanup.php', 1, DATE_ADD(CURDATE(), INTERVAL 1 DAY) + INTERVAL 4 HOUR),
('GeoIP Database Update', 'geoip_update', 'Update MaxMind GeoIP database', '0 5 * * 0', 'tasks/geoip-update.php', 1, NULL),
('Health Check', 'health_check', 'Check all backend services and send alerts', '*/5 * * * *', 'tasks/health-check.php', 1, NULL),
('Ban Expiry Cleanup', 'ban_sync', 'Remove expired bans from banlist', '*/10 * * * *', 'tasks/ban-cleanup.php', 1, NULL),
('Request Telemetry Cleanup', 'log_cleanup', 'Archive old request telemetry data', '0 2 * * *', 'tasks/telemetry-cleanup.php', 1, DATE_ADD(CURDATE(), INTERVAL 1 DAY) + INTERVAL 2 HOUR);

-- Settings for task scheduler
INSERT INTO settings (setting_key, setting_value, description) VALUES
('task_scheduler_enabled', '1', 'Enable/disable the task scheduler'),
('task_default_timeout', '300', 'Default task timeout in seconds'),
('task_log_retention_days', '30', 'Days to keep task execution history')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
