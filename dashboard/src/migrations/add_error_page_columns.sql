-- Add error page configuration columns
-- Migration: add_error_page_columns
-- Date: 2025-10-17

ALTER TABLE sites ADD COLUMN IF NOT EXISTS error_page_mode varchar(20) DEFAULT 'template';

-- Note: error_page_403, error_page_404, error_page_429, error_page_500 already exist in schema
