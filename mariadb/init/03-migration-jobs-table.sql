-- Jobs table for background task management
CREATE TABLE IF NOT EXISTS `jobs` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `type` VARCHAR(50) NOT NULL,
  `payload` TEXT,
  `status` ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `started_at` TIMESTAMP NULL,
  `completed_at` TIMESTAMP NULL,
  `error_message` TEXT,
  INDEX idx_status (status),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mark this migration as applied
INSERT INTO migration_logs (migration_name, applied_at) 
VALUES ('03-migration-jobs-table.sql', NOW())
ON DUPLICATE KEY UPDATE applied_at = NOW();
