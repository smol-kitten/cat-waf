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

-- Add unique constraint to prevent duplicate global rules
CREATE UNIQUE INDEX IF NOT EXISTS idx_site_rule_unique ON security_rules(site_id, rule_type);

-- Insert default passkey whitelist rule (disabled by default)
-- Use IGNORE to skip if already exists due to unique constraint
INSERT IGNORE INTO security_rules (site_id, rule_type, enabled, config) VALUES
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
));
