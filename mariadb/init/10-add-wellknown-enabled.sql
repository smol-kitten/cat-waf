-- Migration: Add wellknown_enabled column
-- Created: 2025-11-06

-- Add column to enable/disable site-specific well-known files
ALTER TABLE sites 
ADD COLUMN IF NOT EXISTS `wellknown_enabled` tinyint(1) DEFAULT 0 COMMENT 'Enable site-specific well-known files (0=use global, 1=use site-specific)';
