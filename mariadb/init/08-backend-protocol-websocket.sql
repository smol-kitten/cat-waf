-- Migration: Add backend protocol and WebSocket support
-- Created: 2025-10-19

-- Add columns for backend protocol configuration
ALTER TABLE `sites` 
ADD COLUMN IF NOT EXISTS `backend_protocol` VARCHAR(10) DEFAULT 'http' COMMENT 'Backend protocol: http or https',
ADD COLUMN IF NOT EXISTS `websocket_enabled` TINYINT(1) DEFAULT 0 COMMENT 'Enable WebSocket proxying',
ADD COLUMN IF NOT EXISTS `websocket_protocol` VARCHAR(10) DEFAULT 'ws' COMMENT 'WebSocket protocol: ws or wss',
ADD COLUMN IF NOT EXISTS `websocket_port` INT(11) DEFAULT NULL COMMENT 'WebSocket port (null = same as backend)',
ADD COLUMN IF NOT EXISTS `websocket_path` VARCHAR(255) DEFAULT '/' COMMENT 'WebSocket path pattern';

-- Record migration
INSERT IGNORE INTO `migration_logs` (`migration_name`) VALUES ('08-backend-protocol-websocket');
