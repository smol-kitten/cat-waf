-- Add automatic log cleanup scheduled task
USE waf_db;

-- Insert log cleanup task (runs daily at 3 AM)
INSERT IGNORE INTO scheduled_tasks (task_name, description, schedule, php_handler, enabled) 
VALUES (
    'Log Cleanup',
    'Automatically delete logs and telemetry data older than retention period',
    '0 3 * * *',
    'tasks/log-cleanup.php',
    1
);

-- Log migration
INSERT IGNORE INTO migration_logs (migration_name) VALUES ('32-auto-cleanup-task');
