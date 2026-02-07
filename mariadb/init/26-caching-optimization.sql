-- Migration: Caching and Image Optimization
-- Created: 2026-02-07

-- Cache configuration per domain
CREATE TABLE IF NOT EXISTS cache_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    cache_enabled TINYINT(1) DEFAULT 1,
    cache_static_ttl INT DEFAULT 86400 COMMENT 'TTL for static assets in seconds',
    cache_dynamic_ttl INT DEFAULT 3600 COMMENT 'TTL for dynamic content in seconds',
    cache_bypass_cookies TEXT COMMENT 'JSON array of cookie names to bypass cache',
    cache_bypass_args TEXT COMMENT 'JSON array of query args to bypass cache',
    cache_key_includes TEXT COMMENT 'JSON: host, uri, args, cookies to include in key',
    stale_while_revalidate INT DEFAULT 60,
    stale_if_error INT DEFAULT 3600,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_domain (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Image optimization settings per domain
CREATE TABLE IF NOT EXISTS image_optimization_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    enabled TINYINT(1) DEFAULT 0,
    webp_enabled TINYINT(1) DEFAULT 1,
    avif_enabled TINYINT(1) DEFAULT 0,
    lazy_loading TINYINT(1) DEFAULT 1,
    quality_jpeg INT DEFAULT 80,
    quality_webp INT DEFAULT 80,
    quality_avif INT DEFAULT 70,
    max_width INT DEFAULT 2048,
    max_height INT DEFAULT 2048,
    strip_metadata TINYINT(1) DEFAULT 1,
    preserve_animation TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_domain (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cache purge history
CREATE TABLE IF NOT EXISTS cache_purge_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT,
    purge_type ENUM('all', 'url', 'pattern', 'tag') NOT NULL,
    purge_target TEXT COMMENT 'URL, pattern, or tag that was purged',
    purged_by VARCHAR(100),
    purged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success TINYINT(1) DEFAULT 1,
    error_message TEXT,
    INDEX idx_domain_time (domain_id, purged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cache warming queue
CREATE TABLE IF NOT EXISTS cache_warm_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    url VARCHAR(2048) NOT NULL,
    priority INT DEFAULT 5,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    last_attempt TIMESTAMP NULL,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    INDEX idx_status_priority (status, priority DESC),
    INDEX idx_domain (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Global cache settings
INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES
('cache_enabled', 'true', 'Enable proxy caching globally'),
('cache_path', '/var/cache/nginx', 'Path for nginx cache storage'),
('cache_max_size', '10g', 'Maximum cache size'),
('cache_inactive', '7d', 'Remove items not accessed in this period'),
('image_optimization_enabled', 'false', 'Enable image optimization globally'),
('image_processor', 'libvips', 'Image processor: libvips or imagemagick'),
('cache_warm_enabled', 'false', 'Enable cache warming'),
('cache_warm_concurrency', '5', 'Concurrent cache warming requests');
