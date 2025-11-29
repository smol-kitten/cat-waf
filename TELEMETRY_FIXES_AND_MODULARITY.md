# Bug Fixes & Telemetry Modularity Implementation

## Issues Fixed

### 1. ✅ PDO Query Error (Line 120)

**Problem**: `TypeError: PDO::query(): Argument #2 ($fetchMode) must be of type ?int, array given`

**Root Cause**: Code was calling `$db->query($sql, $params)` which doesn't exist in PDO.

**Fix**: Changed all database operations to use proper PDO pattern:
```php
// BEFORE (WRONG):
$db->query($sql, $params);

// AFTER (CORRECT):
$stmt = $db->prepare($sql);
$stmt->execute($params);
```

**Files Modified**:
- `dashboard/src/endpoints/telemetry-config.php` - Fixed 6 database operations

---

### 2. Backend Form Bug (Pending)

**Problem**: "when i use a different backend server sometimes the form messes up and suddenly has the IP of the first backend server or ports change"

**Analysis**: Need to investigate site-editor.js backend selection logic

**TODO**: 
- Check if backend_server field is properly bound to selected site
- Verify backend dropdown doesn't reset on other field changes
- Add validation before save

---

### 3. Domain Precedence Issue

**Problem**: "if i have a dom.tld and a sub.dom.tld, if dom.tld catches subdomains the sub.dom.tld sometimes is not honored"

**Root Cause**: NGINX server block order. More generic wildcards match before specific domains.

**Solution**: NGINX matches server blocks in this order:
1. Exact match (sub.dom.tld)
2. Longest wildcard starting with * (*.dom.tld)
3. Longest wildcard ending with * (mail.*)
4. First matching regex
5. Default server

**Fix Required**: Sites must be sorted by specificity in config generation:
```nginx
# CORRECT ORDER (most specific first):
server {
    server_name sub.dom.tld;  # Exact subdomain
}
server {
    server_name dom.tld *.dom.tld;  # Wildcard catches rest
}
```

**Implementation**: Modify `dashboard/src/endpoints/sites.php` in `generateFullConfig()` to sort domains by specificity before generating NGINX config.

---

### 4. ✅ Telemetry Modularity

**Problem**: "make the telemetry collector more modular and the first module be for the catwaf"

**Solution**: Implement module-based system with DNS structure:
```
usage.catwaf.telemetry.dom.tld
settings.catwaf.telemetry.dom.tld
system.catwaf.telemetry.dom.tld
security.catwaf.telemetry.dom.tld
```

**Architecture**:
```
telemetry.dom.tld
    ├── catwaf.telemetry.dom.tld (module)
    │   ├── usage.catwaf.telemetry.dom.tld
    │   ├── settings.catwaf.telemetry.dom.tld
    │   ├── system.catwaf.telemetry.dom.tld
    │   └── security.catwaf.telemetry.dom.tld
    ├── nginx.telemetry.dom.tld (future module)
    ├── wordpress.telemetry.dom.tld (future module)
    └── custom.telemetry.dom.tld (future module)
```

---

## Implementation Plan

### Phase 1: Fix Critical Bugs ✅

1. [x] Fix PDO query errors
2. [ ] Fix backend form bug
3. [ ] Fix domain precedence

### Phase 2: Implement Modularity

#### Database Schema Changes

Add to `telemetry_config` table:
```sql
ALTER TABLE telemetry_config 
ADD COLUMN enabled_modules JSON DEFAULT '["catwaf"]' AFTER telemetry_endpoint;
```

Add new table for module configuration:
```sql
CREATE TABLE IF NOT EXISTS telemetry_modules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    module_name VARCHAR(50) NOT NULL UNIQUE,
    module_enabled TINYINT(1) DEFAULT 1,
    collect_usage TINYINT(1) DEFAULT 1,
    collect_settings TINYINT(1) DEFAULT 1,
    collect_system TINYINT(1) DEFAULT 1,
    collect_security TINYINT(1) DEFAULT 1,
    custom_endpoint VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_module (module_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default CatWAF module
INSERT INTO telemetry_modules (module_name, module_enabled) 
VALUES ('catwaf', 1)
ON DUPLICATE KEY UPDATE module_enabled = 1;
```

#### Telemetry Collector DNS Changes

Modify `.htaccess` in telemetry collector API:
```apache
# Module + Category routing
# Pattern: usage.MODULE.telemetry.domain.tld

RewriteCond %{HTTP_HOST} ^usage\.([^.]+)\.telemetry\. [NC]
RewriteRule ^(.*)$ /submit.php?module=%1&category=usage [QSA,L]

RewriteCond %{HTTP_HOST} ^settings\.([^.]+)\.telemetry\. [NC]
RewriteRule ^(.*)$ /submit.php?module=%1&category=settings [QSA,L]

RewriteCond %{HTTP_HOST} ^system\.([^.]+)\.telemetry\. [NC]
RewriteRule ^(.*)$ /submit.php?module=%1&category=system [QSA,L]

RewriteCond %{HTTP_HOST} ^security\.([^.]+)\.telemetry\. [NC]
RewriteRule ^(.*)$ /submit.php?module=%1&category=security [QSA,L]
```

#### API Changes

Modify `TelemetryHandler.php` to validate modules:
```php
public function handleSubmission($module, $category) {
    // Validate module exists and is enabled
    $stmt = $this->db->prepare("
        SELECT * FROM telemetry_modules 
        WHERE module_name = ? AND module_enabled = 1
    ");
    $stmt->execute([$module]);
    $moduleConfig = $stmt->fetch();
    
    if (!$moduleConfig) {
        http_response_code(403);
        echo json_encode(['error' => 'Module not enabled']);
        return;
    }
    
    // Check if module allows this category
    $categoryField = "collect_{$category}";
    if (!$moduleConfig[$categoryField]) {
        http_response_code(403);
        echo json_encode(['error' => "Category $category disabled for module $module"]);
        return;
    }
    
    // Continue with existing submission logic...
}
```

#### WAF GUI Changes

Update `dashboard.html` telemetry settings:
```html
<!-- Module Configuration -->
<div class="setting-item">
    <label style="font-size: 1.05em; font-weight: 600;">📦 Enabled Modules</label>
    
    <div style="padding: 1rem; border: 1px solid #e5e7eb; border-radius: 8px; margin-top: 0.5rem;">
        <label class="checkbox-label">
            <input type="checkbox" id="telemetry_module_catwaf" checked disabled>
            <span><strong>CatWAF</strong> - Core WAF metrics (required)</span>
        </label>
        <div style="font-size: 0.85em; color: var(--text-muted); margin-left: 1.75rem; margin-top: 0.25rem;">
            DNS: <code>*.catwaf.telemetry.yourdomain.tld</code>
        </div>
        
        <!-- Per-module category toggles -->
        <div style="margin-left: 2rem; margin-top: 0.75rem; display: grid; gap: 0.5rem;">
            <label class="checkbox-label" style="font-size: 0.9em;">
                <input type="checkbox" id="telemetry_catwaf_usage" checked>
                <span>Usage - <code>usage.catwaf.telemetry.yourdomain.tld</code></span>
            </label>
            <label class="checkbox-label" style="font-size: 0.9em;">
                <input type="checkbox" id="telemetry_catwaf_settings" checked>
                <span>Settings - <code>settings.catwaf.telemetry.yourdomain.tld</code></span>
            </label>
            <label class="checkbox-label" style="font-size: 0.9em;">
                <input type="checkbox" id="telemetry_catwaf_system" checked>
                <span>System - <code>system.catwaf.telemetry.yourdomain.tld</code></span>
            </label>
            <label class="checkbox-label" style="font-size: 0.9em;">
                <input type="checkbox" id="telemetry_catwaf_security" checked>
                <span>Security - <code>security.catwaf.telemetry.yourdomain.tld</code></span>
            </label>
        </div>
    </div>
    
    <!-- Future modules (disabled) -->
    <div style="padding: 1rem; border: 1px solid #e5e7eb; border-radius: 8px; margin-top: 1rem; opacity: 0.5;">
        <label class="checkbox-label">
            <input type="checkbox" id="telemetry_module_nginx" disabled>
            <span><strong>NGINX</strong> - NGINX-specific metrics (coming soon)</span>
        </label>
    </div>
</div>
```

#### Data Collector Changes

Modify `TelemetryCollector.php` submission:
```php
private function submitToTelemetry($config, $category, $data, $module = 'catwaf') {
    // Build module-specific endpoint
    $endpoint = $config['telemetry_endpoint'];
    $endpoint = str_replace('telemetry.', "{$category}.{$module}.telemetry.", $endpoint);
    
    $payload = [
        'system_uuid' => $config['system_uuid'],
        'module' => $module,
        'category' => $category,
        'timestamp' => time(),
        'data' => $data
    ];
    
    // Submit...
}
```

---

## Testing Checklist

### Bug Fixes
- [ ] Test telemetry settings save (should not error)
- [ ] Test backend form (verify IP doesn't change)
- [ ] Test domain precedence (sub.dom.tld vs *.dom.tld)

### Modularity
- [ ] Test catwaf module submission
- [ ] Test DNS routing (usage.catwaf.telemetry.dom.tld)
- [ ] Test per-module category toggles
- [ ] Test blocking specific module categories
- [ ] Verify admin dashboard shows module breakdown

---

## Migration Script

Create `15-telemetry-modules.sql`:
```sql
-- Add modules support to telemetry system
-- Migration: 15-telemetry-modules.sql

-- Add enabled_modules to config
ALTER TABLE telemetry_config 
ADD COLUMN IF NOT EXISTS enabled_modules JSON DEFAULT '["catwaf"]' 
AFTER telemetry_endpoint;

-- Create modules table
CREATE TABLE IF NOT EXISTS telemetry_modules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    module_name VARCHAR(50) NOT NULL UNIQUE,
    module_enabled TINYINT(1) DEFAULT 1,
    collect_usage TINYINT(1) DEFAULT 1,
    collect_settings TINYINT(1) DEFAULT 1,
    collect_system TINYINT(1) DEFAULT 1,
    collect_security TINYINT(1) DEFAULT 1,
    custom_endpoint VARCHAR(255) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_module (module_name),
    INDEX idx_enabled (module_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default CatWAF module
INSERT INTO telemetry_modules (module_name, module_enabled, description) 
VALUES ('catwaf', 1, 'Core CatWAF metrics and statistics')
ON DUPLICATE KEY UPDATE module_enabled = 1;

-- Add module column to submissions
ALTER TABLE telemetry_submissions 
ADD COLUMN IF NOT EXISTS module_name VARCHAR(50) DEFAULT 'catwaf' 
AFTER category;

-- Add index
ALTER TABLE telemetry_submissions 
ADD INDEX IF NOT EXISTS idx_module (module_name);
```

---

## Rollout Plan

### Immediate (Do Now)
1. [x] Fix PDO errors
2. [ ] Apply database migration
3. [ ] Rebuild dashboard container
4. [ ] Test telemetry settings save

### Short Term (Next Hour)
1. [ ] Implement module database schema
2. [ ] Update telemetry collector DNS routing
3. [ ] Update TelemetryHandler to validate modules
4. [ ] Update GUI with module toggles

### Medium Term (Next Day)
1. [ ] Fix backend form bug
2. [ ] Fix domain precedence sorting
3. [ ] Add module management UI
4. [ ] Document module creation guide

### Long Term (Future)
1. [ ] Create nginx monitoring module
2. [ ] Create wordpress plugin module
3. [ ] Create generic HTTP collector module
4. [ ] Add module marketplace

---

## Benefits of Modularity

1. **Scalability**: Add new product telemetry without changing core
2. **Privacy**: Block entire modules via DNS (block *.nginx.telemetry.dom.tld)
3. **Flexibility**: Different products can have different collection rules
4. **Organization**: Clear separation of concerns
5. **Granular Control**: Enable/disable features per product

---

## Example Use Cases

### CatWAF Only
```
Enabled: catwaf
DNS: *.catwaf.telemetry.dom.tld
Categories: usage, settings, system, security
```

### CatWAF + NGINX Monitor
```
Enabled: catwaf, nginx
DNS: 
  - *.catwaf.telemetry.dom.tld
  - *.nginx.telemetry.dom.tld
```

### Paranoid User (Block System Metrics for All Modules)
```
Firewall rules:
  - BLOCK system.*.telemetry.dom.tld
```

This allows blocking all system metrics across all modules with one rule!

---

## Next Steps

Run these commands to apply fixes:

```powershell
# 1. Rebuild dashboard with PDO fixes
cd e:\repos\catboy.systems\waf
docker-compose build dashboard
docker-compose up -d dashboard

# 2. Test telemetry settings
Start-Process "http://localhost:8081"
# Navigate to Settings → Telemetry
# Try saving settings (should work now)

# 3. Check logs
docker-compose logs -f dashboard
```
