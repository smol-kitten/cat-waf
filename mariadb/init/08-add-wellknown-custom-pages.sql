-- Migration: Add well-known files and enhanced custom page support
-- Created: 2025-11-05

-- Add columns for well-known files (per-site)
ALTER TABLE sites 
ADD COLUMN IF NOT EXISTS `robots_txt` text DEFAULT NULL COMMENT 'Custom robots.txt content',
ADD COLUMN IF NOT EXISTS `ads_txt` text DEFAULT NULL COMMENT 'Custom ads.txt content',
ADD COLUMN IF NOT EXISTS `humans_txt` text DEFAULT NULL COMMENT 'Custom humans.txt content',
ADD COLUMN IF NOT EXISTS `custom_error_pages_enabled` tinyint(1) DEFAULT 0 COMMENT 'Enable custom uploaded error pages',
ADD COLUMN IF NOT EXISTS `custom_403_html` mediumtext DEFAULT NULL COMMENT 'Custom HTML for 403 Forbidden page',
ADD COLUMN IF NOT EXISTS `custom_404_html` mediumtext DEFAULT NULL COMMENT 'Custom HTML for 404 Not Found page',
ADD COLUMN IF NOT EXISTS `custom_429_html` mediumtext DEFAULT NULL COMMENT 'Custom HTML for 429 Rate Limit page',
ADD COLUMN IF NOT EXISTS `custom_500_html` mediumtext DEFAULT NULL COMMENT 'Custom HTML for 500 Server Error page',
ADD COLUMN IF NOT EXISTS `custom_502_html` mediumtext DEFAULT NULL COMMENT 'Custom HTML for 502 Bad Gateway page',
ADD COLUMN IF NOT EXISTS `custom_503_html` mediumtext DEFAULT NULL COMMENT 'Custom HTML for 503 Service Unavailable page';

-- Create global settings table for well-known files (global defaults)
CREATE TABLE IF NOT EXISTS `wellknown_global` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `robots_txt` text DEFAULT NULL COMMENT 'Global default robots.txt',
  `ads_txt` text DEFAULT NULL COMMENT 'Global default ads.txt',
  `humans_txt` text DEFAULT NULL COMMENT 'Global default humans.txt',
  `security_txt` text DEFAULT NULL COMMENT 'Global default security.txt',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default global values if not exists
INSERT IGNORE INTO `wellknown_global` (id, robots_txt, ads_txt, humans_txt, security_txt) 
VALUES (
  1,
  '# Global Robots.txt\nUser-agent: *\nDisallow: /admin/\nDisallow: /api/\nDisallow: /.well-known/\n',
  '# Global Ads.txt\n# Add your advertising partners here\n',
  '# Global Humans.txt\n/* TEAM */\nWebmaster: Your Name\nContact: admin [at] example.com\n',
  '# Global Security.txt\nContact: mailto:security@example.com\nExpires: 2026-12-31T23:59:59.000Z\n'
);

-- Create table for custom error page templates (global)
CREATE TABLE IF NOT EXISTS `error_page_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'Template name',
  `description` text DEFAULT NULL COMMENT 'Template description',
  `html_403` mediumtext DEFAULT NULL,
  `html_404` mediumtext DEFAULT NULL,
  `html_429` mediumtext DEFAULT NULL,
  `html_500` mediumtext DEFAULT NULL,
  `html_502` mediumtext DEFAULT NULL,
  `html_503` mediumtext DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default error page template
INSERT IGNORE INTO `error_page_templates` (name, description, is_default, html_404, html_403, html_429, html_500, html_502, html_503)
VALUES (
  'default',
  'Default CatWAF error pages',
  1,
  -- 404 Page
  '<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>404 Not Found</title>
  <style>
    body { font-family: Arial, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
    .container { text-align: center; padding: 2rem; }
    h1 { font-size: 8rem; margin: 0; animation: bounce 1s infinite; }
    p { font-size: 1.5rem; }
    @keyframes bounce { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-20px); } }
  </style>
</head>
<body>
  <div class="container">
    <h1>404</h1>
    <p>Page Not Found</p>
  </div>
</body>
</html>',
  -- 403 Page
  '<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>403 Forbidden</title>
  <style>
    body { font-family: Arial, sans-serif; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
    .container { text-align: center; padding: 2rem; }
    h1 { font-size: 8rem; margin: 0; }
    p { font-size: 1.5rem; }
  </style>
</head>
<body>
  <div class="container">
    <h1>403</h1>
    <p>Access Forbidden</p>
  </div>
</body>
</html>',
  -- 429 Page
  '<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>429 Too Many Requests</title>
  <style>
    body { font-family: Arial, sans-serif; background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
    .container { text-align: center; padding: 2rem; }
    h1 { font-size: 8rem; margin: 0; }
    p { font-size: 1.5rem; }
  </style>
</head>
<body>
  <div class="container">
    <h1>429</h1>
    <p>Too Many Requests</p>
    <p style="font-size: 1rem;">Please slow down and try again later</p>
  </div>
</body>
</html>',
  -- 500 Page
  '<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>500 Internal Server Error</title>
  <style>
    body { font-family: Arial, sans-serif; background: linear-gradient(135deg, #ff6a00 0%, #ee0979 100%); color: white; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
    .container { text-align: center; padding: 2rem; }
    h1 { font-size: 8rem; margin: 0; }
    p { font-size: 1.5rem; }
  </style>
</head>
<body>
  <div class="container">
    <h1>500</h1>
    <p>Internal Server Error</p>
  </div>
</body>
</html>',
  -- 502 Page
  '<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>502 Bad Gateway</title>
  <style>
    body { font-family: Arial, sans-serif; background: linear-gradient(135deg, #3494e6 0%, #ec6ead 100%); color: white; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
    .container { text-align: center; padding: 2rem; }
    h1 { font-size: 8rem; margin: 0; }
    p { font-size: 1.5rem; }
  </style>
</head>
<body>
  <div class="container">
    <h1>502</h1>
    <p>Bad Gateway</p>
    <p style="font-size: 1rem;">Backend server is unavailable</p>
  </div>
</body>
</html>',
  -- 503 Page
  '<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>503 Service Unavailable</title>
  <style>
    body { font-family: Arial, sans-serif; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
    .container { text-align: center; padding: 2rem; }
    h1 { font-size: 8rem; margin: 0; }
    p { font-size: 1.5rem; }
  </style>
</head>
<body>
  <div class="container">
    <h1>503</h1>
    <p>Service Unavailable</p>
    <p style="font-size: 1rem;">We''ll be back soon</p>
  </div>
</body>
</html>'
);
