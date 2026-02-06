-- Add passkey whitelist support to security rules
-- Run this migration to add passkey_whitelist rule type

-- Add passkey_whitelist to the enum if not already there
ALTER TABLE `security_rules` 
MODIFY COLUMN `rule_type` ENUM(
    'scanner_detection', 
    'learning_mode', 
    'wordpress_block', 
    'rate_limit', 
    'path_block',
    'passkey_whitelist'
) NOT NULL;

-- Insert default passkey whitelist rule (disabled by default)
INSERT INTO security_rules (site_id, rule_type, enabled, config) VALUES
(NULL, 'passkey_whitelist', 0, JSON_OBJECT(
  'allow_cbor', true,
  'allow_webauthn_paths', true,
  'whitelist_paths', JSON_ARRAY(
    '/auth/',
    '/login',
    '/register',
    '/api/auth/'
  ),
  'description', 'Whitelist WebAuthn/Passkey authentication requests to prevent blocking by ModSecurity rule 920420'
))
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;
