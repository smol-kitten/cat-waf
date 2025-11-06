-- Update error page templates with actual NGINX error pages

-- This migration is intentionally left minimal to avoid SQL escaping issues
-- The actual template updates should be done via the dashboard API endpoint
-- or by loading templates through the UI

-- Just ensure the default template exists
INSERT IGNORE INTO `error_page_templates` (name, description, is_default)
VALUES ('default', 'Default CatWAF error pages from /nginx/error-pages/', 1)
ON DUPLICATE KEY UPDATE description = 'Default CatWAF error pages from /nginx/error-pages/';

-- Note: To update templates with actual NGINX error pages, use the dashboard UI:
-- 1. Go to Settings → Well-Known Files tab
-- 2. Click "Error Pages" tab in site editor
-- 3. Click "Load Template" to load the default styled pages
