# CatWAF TODO - Comprehensive Issue Tracker

**Created**: 2026-02-07  
**Last Updated**: 2026-02-07  

---

## ✅ COMPLETED

### 1. [X] Insights SQL Error - Column 'request_uri' Not Found
**Status**: ✅ Fixed  
**File**: [dashboard/src/endpoints/insights.php](dashboard/src/endpoints/insights.php#L206-L211)  
**Fix**: Changed `request_uri` → `uri as request_uri` in getBasicInsights()

---

### 2. [X] ModSecurity Parameter Changes Not Reflecting
**Status**: ✅ Fixed  
**Changes**:
- Updated `updateModSecurityParanoiaLevel()` in settings.php to use shared volume
- Added `/etc/nginx/modsecurity/crs-setup-override.conf` managed by dashboard
- Added volume mount `waf-nginx-modsecurity` to dashboard container
- Modified nginx Dockerfile to include override config

---

### 3. [X] Notifications Endpoint 404
**Status**: ✅ Fixed  
**File**: Created [dashboard/src/endpoints/notifications.php](dashboard/src/endpoints/notifications.php)  
**Routes**:
- `GET /notifications` - Get notification settings
- `POST /notifications/test` - Test notification delivery
- `POST /notifications/send` - Send manual notification  
- `GET /notifications/history` - Get notification history
- `PUT /notifications/settings` - Update notification settings

---

### 4. [X] Re-add Auto-Ban Service
**Status**: ✅ Configured  
**Changes**:
- Added to `supervisord.conf` (autostart=false, controlled via settings)
- Added `controlAutoBanService()` function in settings.php
- Service starts/stops based on `enable_auto_ban` setting

---

### 5. [X] Recent Security Events "View All" - Incomplete Display
**Status**: ✅ Fixed  
**File**: Created [dashboard/src/endpoints/security-center.php](dashboard/src/endpoints/security-center.php)  
**Routes**:
- `GET /security-center/events` - Unified events from modsec, access_logs, bot_detections, banned_ips
- `GET /security-center/summary` - Aggregated security stats
- `GET /security-center/threats` - Top threats analysis
- `GET /security-center/timeline` - Event timeline for charts
- `GET /security-center/ip/:ip` - IP investigation (all events for an IP)

---

### 6. [X] Bot Protection UI and Blocking Refactor
**Status**: ✅ Implemented  
**Files**:
- Created [dashboard/src/endpoints/bot-center.php](dashboard/src/endpoints/bot-center.php)
- Created [mariadb/init/23-bot-protection-enhancements.sql](mariadb/init/23-bot-protection-enhancements.sql)

**API Routes**:
- `GET /bot-center/dashboard` - Comprehensive stats, top bots, recent activity
- `GET /bot-center/detections` - Filtered bot detections with pagination
- `GET/POST/PUT/DELETE /bot-center/rules/:id` - Bot rule CRUD
- `GET/POST /bot-center/patterns` - Preset bot patterns
- `GET/PUT /bot-center/challenges` - Challenge configuration
- `GET /bot-center/ips` - Bot IPs with detection counts
- `GET /bot-center/timeline` - Hourly bot activity chart data
- `GET/POST /bot-center/export` and `/import` - Rule import/export
- `GET/PUT /bot-center/settings` - Bot protection settings

---

### 7. [X] Log Parsing Refactor with Ingestion Queue
**Status**: ✅ Implemented  
**Files**:
- Created [log-parser/LogQueue.php](log-parser/LogQueue.php)
- Updated [log-parser/parser.php](log-parser/parser.php)

**Features**:
- Configurable buffer size (default 500 entries)
- Batch INSERT for access_logs, telemetry, bot_detections
- Disk queue fallback when DB unavailable
- Auto-recovery on DB reconnection
- Queue stats logging

---

### 8. [X] Automatic Cron Job Creation/Management
**Status**: ✅ Implemented  
**Files**:
- Created [mariadb/init/21-scheduled-tasks.sql](mariadb/init/21-scheduled-tasks.sql)
- Created [dashboard/src/task-scheduler.php](dashboard/src/task-scheduler.php)
- Created [dashboard/src/endpoints/tasks.php](dashboard/src/endpoints/tasks.php)
- Created task handlers in `dashboard/src/tasks/`

**API Routes**:
- `GET /tasks` - List all scheduled tasks
- `GET /tasks/:id` - Get task details with history
- `POST /tasks` - Create new task
- `PUT /tasks/:id` - Update task
- `DELETE /tasks/:id` - Delete task
- `POST /tasks/:id/run` - Run task immediately
- `POST /tasks/:id/toggle` - Enable/disable task
- `GET /tasks/history` - Execution history
- `GET/PUT /tasks/settings` - Scheduler settings

---

### 9. [X] Modular DROP Rule Builder (MikroTik/Router Integration)
**Status**: ✅ Implemented  
**Files**:
- Created [mariadb/init/22-router-integration.sql](mariadb/init/22-router-integration.sql)
- Created [dashboard/src/lib/Router/RouterAdapterInterface.php](dashboard/src/lib/Router/RouterAdapterInterface.php)
- Created [dashboard/src/lib/Router/MikroTikAdapter.php](dashboard/src/lib/Router/MikroTikAdapter.php)
- Created [dashboard/src/lib/Router/RouterManager.php](dashboard/src/lib/Router/RouterManager.php)
- Created [dashboard/src/endpoints/routers.php](dashboard/src/endpoints/routers.php)

**API Routes**:
- `GET /routers` - List configured routers
- `POST /routers` - Add new router
- `POST /routers/:id/test` - Test connection
- `POST /routers/:id/sync` - Sync rules to router
- `GET /routers/:id/rules` - Get DROP rules on router
- `POST /routers/:id/add-rule` - Add DROP rule
- `GET/PUT /routers/settings` - Integration settings

---

### 10. [X] 404 Scanner Detection & Endpoint Tracking
**Status**: ✅ Implemented  
**Files**:
- Created [mariadb/init/20-scanner-detection.sql](mariadb/init/20-scanner-detection.sql)
- Created [dashboard/src/endpoints/scanners.php](dashboard/src/endpoints/scanners.php)

---

### 11. [X] Cloudflare Origin Certificate Bypass Mode
**Status**: ✅ Implemented  
**Files**:
- Created [mariadb/init/24-cloudflare-origin-certs.sql](mariadb/init/24-cloudflare-origin-certs.sql)
- Created [dashboard/src/lib/CloudflareOriginManager.php](dashboard/src/lib/CloudflareOriginManager.php)
- Created [dashboard/src/endpoints/cf-origin.php](dashboard/src/endpoints/cf-origin.php)

**Features**:
- Upload Cloudflare Origin Certificate + Key
- Encrypted private key storage
- Automatic fallback when primary cert fails
- Health check monitoring
- Fallback history logging
- Alert on fallback activation

**API Routes**:
- `GET /cf-origin/domains` - List domains with CF cert status
- `POST /cf-origin/upload` - Upload new certificate
- `GET/DELETE /cf-origin/certificate/:id` - Manage certificates
- `GET/POST/DELETE /cf-origin/fallback/:domainId` - Control fallback
- `POST /cf-origin/check` - Run health check
- `GET /cf-origin/history` - Fallback history
- `GET/PUT /cf-origin/settings` - Configuration

---

### 12. [X] Certificate Authority Center
**Status**: ✅ Implemented  
**Files**:
- Created [mariadb/init/25-certificate-authority.sql](mariadb/init/25-certificate-authority.sql)
- Created [dashboard/src/lib/CertificateAuthorityManager.php](dashboard/src/lib/CertificateAuthorityManager.php)
- Created [dashboard/src/endpoints/ca-center.php](dashboard/src/endpoints/ca-center.php)

**Features**:
- Generate self-signed CA root certificates
- Import existing CA (Microsoft AD CS, custom)
- Issue certificates signed by CA (server, client, code-signing)
- Certificate revocation with CRL generation
- Certificate chain/bundle export
- RSA-2048/4096 and EC-P256/P384 key algorithms

**API Routes**:
- `GET /ca-center/list` - List all CAs
- `POST /ca-center/create` - Create new CA
- `POST /ca-center/import` - Import existing CA
- `GET /ca-center/:id` - Get CA details
- `GET /ca-center/:id/certificates` - List issued certificates
- `POST /ca-center/issue/:caId` - Issue new certificate
- `POST /ca-center/revoke/:certId` - Revoke certificate
- `GET /ca-center/crl/:caId` - Download CRL
- `GET /ca-center/bundle/:caId` - Get CA bundle

---

### 13. [X] Insights Page Preview (Playwright Container)
**Status**: ✅ Implemented  
**Files**:
- Created [playwright/Dockerfile](playwright/Dockerfile)
- Created [playwright/package.json](playwright/package.json)
- Created [playwright/server.js](playwright/server.js)
- Created [dashboard/src/endpoints/preview.php](dashboard/src/endpoints/preview.php)
- Updated [docker-compose.yml](docker-compose.yml) - Added playwright service

**Features**:
- Full page screenshots with configurable dimensions
- Thumbnail generation with WebP output
- Screenshot caching with TTL
- Mask sensitive elements option
- Concurrent request limiting

**API Routes**:
- `POST /preview/screenshot` - Capture full screenshot
- `POST /preview/thumbnail` - Generate thumbnail
- `GET /preview/list` - List screenshots
- `DELETE /preview/delete/:filename` - Delete screenshot
- `GET /preview/cache/stats` - Cache statistics
- `POST /preview/cache/clear` - Clear cache
- `GET /preview/status` - Service status

---

### 14. [X] Caching & Image Optimization Refactor
**Status**: ✅ Implemented  
**Files**:
- Created [mariadb/init/26-caching-optimization.sql](mariadb/init/26-caching-optimization.sql)
- Created [dashboard/src/lib/CacheManager.php](dashboard/src/lib/CacheManager.php)
- Created [dashboard/src/lib/ImageOptimizer.php](dashboard/src/lib/ImageOptimizer.php)
- Updated [dashboard/src/endpoints/cache.php](dashboard/src/endpoints/cache.php)

**Features**:
- Per-domain cache configuration
- Cache purge by URL, pattern, or all
- Cache warming queue with priority
- Image optimization with WebP/AVIF conversion
- libvips and ImageMagick support
- Purge history logging
- nginx cache config generation

**API Routes**:
- `GET /cache/stats` - Cache statistics
- `GET/PUT /cache/config/:domainId` - Domain cache config
- `POST /cache/purge` - Purge cache
- `GET/POST /cache/warm` - Warming queue management
- `GET /cache/history` - Purge history
- `GET /cache/image/stats` - Image optimization stats
- `GET/PUT /cache/image/config/:domainId` - Image config
- `POST /cache/image/optimize` - Optimize image
- `GET/PUT /cache/settings` - Global settings

---

## 📋 REMAINING LOW PRIORITY

### 15. [ ] Cleanup CI/CD Pipeline
**Status**: 📋 Maintenance  
- Remove redundant build steps
- Add proper caching
- Add security scanning (Trivy)

### 16. [ ] Redundant Check Cleanup
**Status**: 📋 Code Quality  
- Audit security-checks.php for duplicate checks
- Document all check purposes

---

## 📊 Progress Summary

| Category | Total | Done |
|----------|-------|------|
| Critical Fixes | 5 | 5 ✅ |
| Core Features | 5 | 5 ✅ |
| Advanced Features | 4 | 4 ✅ |
| Low Priority | 2 | 0 |
| **Total** | **16** | **14** |

**Completion: 87.5%**

---

*CatWAF TODO Tracker - Last updated 2026-02-07*
