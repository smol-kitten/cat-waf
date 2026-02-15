-- Performance Optimization Migration
-- Adds composite indices, stats cache tables, and views for dashboard performance

USE waf_db;

-- =============================================
-- PART 1: COMPOSITE INDICES FOR COMMON QUERIES
-- =============================================

-- access_logs: Composite indices for stats queries (timestamp + status_code/blocked/domain)
-- These prevent full table scans when filtering by time ranges
CREATE INDEX IF NOT EXISTS idx_access_timestamp_status 
    ON access_logs (timestamp, status_code);

CREATE INDEX IF NOT EXISTS idx_access_timestamp_blocked 
    ON access_logs (timestamp, blocked);

CREATE INDEX IF NOT EXISTS idx_access_timestamp_domain 
    ON access_logs (timestamp, domain);

CREATE INDEX IF NOT EXISTS idx_access_timestamp_ip 
    ON access_logs (timestamp, ip_address);

-- Covering index for the most common stats query pattern
CREATE INDEX IF NOT EXISTS idx_access_stats_covering 
    ON access_logs (timestamp, status_code, blocked, domain, ip_address);

-- modsec_events: Composite indices for dashboard queries
CREATE INDEX IF NOT EXISTS idx_modsec_timestamp_action 
    ON modsec_events (timestamp, action);

CREATE INDEX IF NOT EXISTS idx_modsec_timestamp_severity 
    ON modsec_events (timestamp, severity);

CREATE INDEX IF NOT EXISTS idx_modsec_timestamp_rule 
    ON modsec_events (timestamp, rule_id);

-- request_telemetry: Composite indices
CREATE INDEX IF NOT EXISTS idx_telemetry_timestamp_status 
    ON request_telemetry (timestamp, status_code);

CREATE INDEX IF NOT EXISTS idx_telemetry_timestamp_domain 
    ON request_telemetry (timestamp, domain);

-- bot_detections: Composite indices
CREATE INDEX IF NOT EXISTS idx_bots_timestamp_action 
    ON bot_detections (timestamp, action);

CREATE INDEX IF NOT EXISTS idx_bots_timestamp_type 
    ON bot_detections (timestamp, bot_type);

-- =============================================
-- PART 2: STATS CACHE TABLE
-- =============================================

-- Pre-computed hourly stats to avoid real-time aggregation
CREATE TABLE IF NOT EXISTS stats_cache_hourly (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    hour_bucket DATETIME NOT NULL COMMENT 'Hour start time (e.g., 2025-01-15 14:00:00)',
    domain VARCHAR(255) DEFAULT '_all_' COMMENT 'Domain or _all_ for aggregated',
    
    -- Request counts
    total_requests INT DEFAULT 0,
    blocked_requests INT DEFAULT 0,
    unique_ips INT DEFAULT 0,
    
    -- Status code breakdown
    status_2xx INT DEFAULT 0,
    status_3xx INT DEFAULT 0,
    status_4xx INT DEFAULT 0,
    status_5xx INT DEFAULT 0,
    
    -- Security metrics
    modsec_blocks INT DEFAULT 0,
    modsec_warnings INT DEFAULT 0,
    bot_detections INT DEFAULT 0,
    
    -- Performance metrics
    avg_response_time FLOAT DEFAULT 0,
    total_bytes_sent BIGINT DEFAULT 0,
    
    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY idx_hour_domain (hour_bucket, domain),
    KEY idx_hour_bucket (hour_bucket),
    KEY idx_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily aggregated stats (rolled up from hourly)
CREATE TABLE IF NOT EXISTS stats_cache_daily (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    day_bucket DATE NOT NULL,
    domain VARCHAR(255) DEFAULT '_all_',
    
    total_requests INT DEFAULT 0,
    blocked_requests INT DEFAULT 0,
    unique_ips INT DEFAULT 0,
    
    status_2xx INT DEFAULT 0,
    status_3xx INT DEFAULT 0,
    status_4xx INT DEFAULT 0,
    status_5xx INT DEFAULT 0,
    
    modsec_blocks INT DEFAULT 0,
    modsec_warnings INT DEFAULT 0,
    bot_detections INT DEFAULT 0,
    
    avg_response_time FLOAT DEFAULT 0,
    total_bytes_sent BIGINT DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY idx_day_domain (day_bucket, domain),
    KEY idx_day_bucket (day_bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Top IPs cache (refreshed periodically)
CREATE TABLE IF NOT EXISTS stats_top_ips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    period VARCHAR(10) NOT NULL COMMENT '1h, 24h, 7d, 30d',
    ip_address VARCHAR(45) NOT NULL,
    request_count INT DEFAULT 0,
    blocked_count INT DEFAULT 0,
    last_seen TIMESTAMP NULL,
    country_code VARCHAR(2) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY idx_period_ip (period, ip_address),
    KEY idx_period (period),
    KEY idx_request_count (request_count DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Top domains cache
CREATE TABLE IF NOT EXISTS stats_top_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    period VARCHAR(10) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    request_count INT DEFAULT 0,
    blocked_count INT DEFAULT 0,
    avg_response_time FLOAT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY idx_period_domain (period, domain),
    KEY idx_period (period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- PART 3: VIEWS FOR COMMON QUERIES
-- =============================================

-- View for quick dashboard stats (uses cache when available)
CREATE OR REPLACE VIEW v_dashboard_stats_24h AS
SELECT 
    COALESCE(SUM(total_requests), 0) AS total_requests,
    COALESCE(SUM(blocked_requests), 0) AS blocked_requests,
    COALESCE(SUM(unique_ips), 0) AS unique_ips,
    COALESCE(SUM(status_2xx), 0) AS status_2xx,
    COALESCE(SUM(status_3xx), 0) AS status_3xx,
    COALESCE(SUM(status_4xx), 0) AS status_4xx,
    COALESCE(SUM(status_5xx), 0) AS status_5xx,
    COALESCE(SUM(modsec_blocks), 0) AS modsec_blocks,
    COALESCE(AVG(avg_response_time), 0) AS avg_response_time
FROM stats_cache_hourly
WHERE domain = '_all_'
  AND hour_bucket > DATE_SUB(NOW(), INTERVAL 24 HOUR);

-- View for active bans count
CREATE OR REPLACE VIEW v_active_bans AS
SELECT COUNT(*) AS count
FROM banned_ips 
WHERE (expires_at IS NULL OR expires_at > NOW()) 
  AND is_permanent = 0;

-- View for traffic over time (last 24h, hourly)
CREATE OR REPLACE VIEW v_traffic_hourly_24h AS
SELECT 
    hour_bucket,
    total_requests,
    status_2xx,
    status_3xx,
    status_4xx,
    status_5xx
FROM stats_cache_hourly
WHERE domain = '_all_'
  AND hour_bucket > DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY hour_bucket;

-- View for ModSecurity recent events
CREATE OR REPLACE VIEW v_modsec_recent AS
SELECT 
    id,
    timestamp,
    ip_address,
    domain,
    uri,
    method,
    rule_id,
    severity,
    message,
    action
FROM modsec_events
ORDER BY timestamp DESC
LIMIT 100;

-- =============================================
-- PART 4: STORED PROCEDURE TO REFRESH STATS
-- =============================================

DELIMITER //

-- Procedure to refresh hourly stats cache
CREATE OR REPLACE PROCEDURE refresh_hourly_stats()
BEGIN
    DECLARE current_hour DATETIME;
    SET current_hour = DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00');
    
    -- Insert or update current hour stats for all domains combined
    INSERT INTO stats_cache_hourly (
        hour_bucket, domain, total_requests, blocked_requests, unique_ips,
        status_2xx, status_3xx, status_4xx, status_5xx,
        avg_response_time, total_bytes_sent
    )
    SELECT 
        current_hour,
        '_all_',
        COUNT(*),
        SUM(CASE WHEN blocked = 1 THEN 1 ELSE 0 END),
        COUNT(DISTINCT ip_address),
        SUM(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 ELSE 0 END),
        SUM(CASE WHEN status_code >= 300 AND status_code < 400 THEN 1 ELSE 0 END),
        SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END),
        SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END),
        AVG(response_time),
        SUM(bytes_sent)
    FROM access_logs
    WHERE timestamp >= current_hour
      AND timestamp < DATE_ADD(current_hour, INTERVAL 1 HOUR)
    ON DUPLICATE KEY UPDATE
        total_requests = VALUES(total_requests),
        blocked_requests = VALUES(blocked_requests),
        unique_ips = VALUES(unique_ips),
        status_2xx = VALUES(status_2xx),
        status_3xx = VALUES(status_3xx),
        status_4xx = VALUES(status_4xx),
        status_5xx = VALUES(status_5xx),
        avg_response_time = VALUES(avg_response_time),
        total_bytes_sent = VALUES(total_bytes_sent),
        updated_at = NOW();
    
    -- Update ModSecurity stats for current hour
    UPDATE stats_cache_hourly h
    SET 
        modsec_blocks = (
            SELECT COUNT(*) FROM modsec_events 
            WHERE action = 'BLOCKED' 
              AND timestamp >= current_hour 
              AND timestamp < DATE_ADD(current_hour, INTERVAL 1 HOUR)
        ),
        modsec_warnings = (
            SELECT COUNT(*) FROM modsec_events 
            WHERE severity = 'WARNING' 
              AND timestamp >= current_hour 
              AND timestamp < DATE_ADD(current_hour, INTERVAL 1 HOUR)
        )
    WHERE h.hour_bucket = current_hour AND h.domain = '_all_';
    
END //

-- Procedure to refresh top IPs cache
CREATE OR REPLACE PROCEDURE refresh_top_ips(IN p_period VARCHAR(10))
BEGIN
    DECLARE interval_val VARCHAR(50);
    
    SET interval_val = CASE p_period
        WHEN '1h' THEN 'INTERVAL 1 HOUR'
        WHEN '24h' THEN 'INTERVAL 24 HOUR'
        WHEN '7d' THEN 'INTERVAL 7 DAY'
        WHEN '30d' THEN 'INTERVAL 30 DAY'
        ELSE 'INTERVAL 24 HOUR'
    END;
    
    -- Clear old data for this period
    DELETE FROM stats_top_ips WHERE period = p_period;
    
    -- Set dynamic interval query
    SET @sql = CONCAT('
        INSERT INTO stats_top_ips (period, ip_address, request_count, blocked_count, last_seen, country_code)
        SELECT 
            ''', p_period, ''',
            ip_address,
            COUNT(*) as request_count,
            SUM(CASE WHEN blocked = 1 THEN 1 ELSE 0 END) as blocked_count,
            MAX(timestamp) as last_seen,
            MAX(country_code) as country_code
        FROM access_logs 
        WHERE timestamp > DATE_SUB(NOW(), ', interval_val, ')
        GROUP BY ip_address
        ORDER BY request_count DESC
        LIMIT 100
    ');
    
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END //

-- Procedure to refresh top domains cache  
CREATE OR REPLACE PROCEDURE refresh_top_domains(IN p_period VARCHAR(10))
BEGIN
    DECLARE interval_val VARCHAR(50);
    
    SET interval_val = CASE p_period
        WHEN '1h' THEN 'INTERVAL 1 HOUR'
        WHEN '24h' THEN 'INTERVAL 24 HOUR'
        WHEN '7d' THEN 'INTERVAL 7 DAY'
        WHEN '30d' THEN 'INTERVAL 30 DAY'
        ELSE 'INTERVAL 24 HOUR'
    END;
    
    DELETE FROM stats_top_domains WHERE period = p_period;
    
    SET @sql = CONCAT('
        INSERT INTO stats_top_domains (period, domain, request_count, blocked_count, avg_response_time)
        SELECT 
            ''', p_period, ''',
            domain,
            COUNT(*) as request_count,
            SUM(CASE WHEN blocked = 1 THEN 1 ELSE 0 END) as blocked_count,
            AVG(response_time) as avg_response_time
        FROM access_logs 
        WHERE timestamp > DATE_SUB(NOW(), ', interval_val, ')
          AND domain IS NOT NULL
        GROUP BY domain
        ORDER BY request_count DESC
        LIMIT 50
    ');
    
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END //

-- Master refresh procedure (call this every minute from task scheduler)
CREATE OR REPLACE PROCEDURE refresh_all_stats()
BEGIN
    -- Refresh current hour stats
    CALL refresh_hourly_stats();
    
    -- Refresh top IPs for 24h (most commonly used)
    CALL refresh_top_ips('24h');
    
    -- Refresh top domains for 24h
    CALL refresh_top_domains('24h');
END //

DELIMITER ;

-- =============================================
-- PART 5: INITIAL DATA POPULATION
-- =============================================

-- Populate last 24 hours of hourly stats
INSERT IGNORE INTO stats_cache_hourly (
    hour_bucket, domain, total_requests, blocked_requests, unique_ips,
    status_2xx, status_3xx, status_4xx, status_5xx,
    avg_response_time, total_bytes_sent
)
SELECT 
    DATE_FORMAT(timestamp, '%Y-%m-%d %H:00:00') as hour_bucket,
    '_all_',
    COUNT(*),
    SUM(CASE WHEN blocked = 1 THEN 1 ELSE 0 END),
    COUNT(DISTINCT ip_address),
    SUM(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 ELSE 0 END),
    SUM(CASE WHEN status_code >= 300 AND status_code < 400 THEN 1 ELSE 0 END),
    SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END),
    SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END),
    AVG(response_time),
    SUM(bytes_sent)
FROM access_logs
WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY hour_bucket;

-- Initial top IPs population
CALL refresh_top_ips('24h');
CALL refresh_top_domains('24h');

-- =============================================
-- PART 6: REGISTER SCHEDULED TASK FOR STATS REFRESH
-- =============================================

-- Add stats refresh task (runs every minute)
INSERT INTO scheduled_tasks (task_name, task_type, description, schedule, php_handler, enabled, timeout)
VALUES (
    'stats-refresh',
    'system',
    'Refresh dashboard stats cache for fast loading',
    '* * * * *',
    'tasks/stats-refresh.php',
    1,
    120
)
ON DUPLICATE KEY UPDATE 
    description = VALUES(description),
    schedule = VALUES(schedule),
    php_handler = VALUES(php_handler);

-- =============================================
-- PART 7: REGISTER WITH MIGRATION SYSTEM
-- =============================================

INSERT INTO migration_logs (migration_name, applied_at) 
VALUES ('33-performance-optimization.sql', NOW())
ON DUPLICATE KEY UPDATE applied_at = NOW();
