-- Migration: Clean up backends and enforce backend_protocol usage
-- This migration removes conflicting port fields from backends JSON
-- and ensures backend_protocol is the single source of truth

-- First, update backend_protocol for all sites with :443 or https:// in backend_url
UPDATE sites 
SET backend_protocol = 'https' 
WHERE backend_protocol = 'http' 
AND (backend_url LIKE '%:443%' OR backend_url LIKE 'https://%');

-- Clean up backends JSON by removing protocol-specific port fields
-- MariaDB doesn't support wildcards in JSON_REMOVE, so we rebuild the array
UPDATE sites
SET backends = (
    SELECT JSON_ARRAYAGG(
        JSON_REMOVE(
            backend_item,
            '$.httpPort',
            '$.httpsPort',
            '$.useProtocolPorts',
            '$.proto'
        )
    )
    FROM JSON_TABLE(
        backends,
        '$[*]' COLUMNS(
            backend_item JSON PATH '$'
        )
    ) AS jt
)
WHERE backends IS NOT NULL
AND JSON_VALID(backends)
AND JSON_TYPE(backends) = 'ARRAY'
AND JSON_LENGTH(backends) > 0;

-- Ensure all sites have backend_protocol set (default to http if null)
UPDATE sites 
SET backend_protocol = 'http' 
WHERE backend_protocol IS NULL OR backend_protocol = '';

-- Ensure all sites have websocket_protocol set (default to ws if null)
UPDATE sites 
SET websocket_protocol = 'ws' 
WHERE websocket_protocol IS NULL OR websocket_protocol = '';

-- Record migration
INSERT IGNORE INTO `migration_logs` (`migration_name`) VALUES ('09-cleanup-backend-protocol');

