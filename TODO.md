# CatWAF TODO List

## ✅ Completed (Oct 15-17, 2025)

### Phase 1: UI & Navigation
- [x] Fix toggle/checkbox visibility (color scheme fix)
- [x] Remove duplicate HTTP headers
- [x] Remove X-Backend-Server header exposure
- [x] Fix cache.php routing errors
- [x] JavaScript Challenge for DDoS Protection
- [x] GeoIP Blocking UI
- [x] Site editor integration (no separate HTML files)
- [x] Fixed ModSecurity rules display
- [x] Fixed permanent IP bans display
- [x] Enhanced NGINX log format with upstream_addr

### Phase 2: Site Editor & Forms
- [x] Fixed site editor rendering
- [x] Enhanced all render functions with complete content
- [x] Fixed navigation (modals → pages)
- [x] Database migration (8 new columns)
- [x] Add/Copy Site converted to full page
- [x] Toast notifications on site save
- [x] Cloudflare DNS-01 Challenge support
- [x] Per-backend protocol toggles (HTTP/HTTPS/WS/WSS) in editor UI
- [x] "View Raw Config" button to inspect generated NGINX config (✅ WORKING)
- [x] Challenge slider visual improvements (12-24 range, 100% width, better labels)

### Phase 3: ModSecurity & Infrastructure  
- [x] ModSecurity OWASP CRS installation (moved to entrypoint)
  - Created `nginx/entrypoint.sh` that installs CRS before nginx starts
  - Fixed timing issue: CRS now installs synchronously before nginx starts
  - Added fallback: comments out CRS includes if git clone fails
  - ✅ Verified: 677 SecRule directives loaded successfully
- [x] Fixed ModSecurity stats endpoint (docker exec)
  - Uses `docker exec waf-nginx` to count rules from dashboard container
  - Stats endpoint now shows actual rule count
- [x] Regenerate All Configs API

### Phase 4: Certificate Automation (Oct 16-17, 2025)
- [x] Import/Export sites (merge/skip/replace modes)
- [x] HTTP redirect control (disable_http_redirect)
- [x] Cloudflare rate limit bypass
- [x] Cloudflare zone auto-detection
- [x] Migration system for prebuilt deployments
- [x] Certificate management (issue/renew/revoke)
- [x] Bulk certificate processing (auto-issue/renew)
- [x] Startup certificate safety checks
- [x] Environment-based credentials
- [x] Docker socket integration
- [x] Special domain handling (_, *.local)
- [x] Frontend error handling for certificates

### Phase 5: Security & Telemetry (Oct 17, 2025)
- [x] **URL Truncation & Sanitization**
  - Paths truncated at 200 chars
  - Query params truncated at 100 chars
  - Total URI limited to 500 chars
  - Prevents database bloat from very long URLs
- [x] **Sensitive Parameter Redaction**
  - Auto-detect: token, key, password, secret, auth, jwt, bearer
  - Redacts values: `[REDACTED]` or `abcd...[REDACTED]...xyz`
  - Keeps first/last 4 chars for troubleshooting
  - GDPR/Privacy compliance
- [x] **Enhanced Telemetry Display**
  - Added hostname/domain column to Slowest Endpoints
  - Shows which site has performance issues
  - Groups by domain + URI
  - Tooltip shows full URL on hover
- [x] Documentation: SECURITY-LOGGING.md
- [x] Test script: scripts/test-url-sanitization.ps1

### Phase 6: Dashboard Fixes (Oct 18, 2025)
- [x] **ModSecurity Paranoia Level saving** - Fixed database column reference
- [x] **Security Events display** - Corrected field mapping (client_ip, rule_message)
- [x] **Access Logs parsing** - Added regex parsing for raw NGINX log format
- [x] **Bot Confidence null handling** - Shows "N/A" instead of "null%"
- [x] **Long tables scrollable** - Added max-height containers (400-500px)
- [x] **Site filter for logs** - Dropdown to filter by domain
- [x] **SSL Certificate UI** - Scrollable container for long certificate lists
- [x] **Bot Activity Chart** - Chart.js line chart with 24h bot activity
- [x] **Rate Limit Presets Auth** - Token-based authentication for presets page
- [x] **Slowest Endpoints Filtering** - Exclude static assets (images, CSS, JS, etc.)
- [x] **Backend port configuration** - Fixed 503 errors with proper port inclusion
- [x] **Per-backend protocol toggles** - UI for HTTP/HTTPS/WS/WSS per backend
- [x] **Challenge slider improvements** - Extended range (12-24), better UX
- [x] **View Raw Config button** - GET /api/sites/:id/config endpoint

## 🚧 In Progress

### ~~Database Schema~~ ✅ COMPLETED
- [x] **Updated mariadb/init/*.sql** with all new columns
  - Created 05-migration-complete-schema.sql with all missing columns
  - Includes: Load balancing, JS Challenge, SSL Challenge, Wildcard domains
  - Added challenge_passes and ssl_challenges tables
  - ✅ Complete schema for fresh installs

### ~~Site Save Functionality~~ ✅ COMPLETED
- [x] **Fixed PUT handler** in sites.php to save all fields
  - Was only saving 10 fields, now saves all ~40 fields
  - Includes SSL, compression, caching, challenge settings
  - User's settings will now persist correctly

### ~~Site Configuration~~ ✅ COMPLETED
- [x] **Updated generateNginxConfig()** for wildcard subdomains
  - Generates `server_name *.domain.com domain.com;` when wildcard_subdomains=1
  - ✅ Implemented in sites.php lines 478-479

### ~~Telemetry & Monitoring~~ ✅ COMPLETED
- [x] **Fixed telemetry backend tracking** - Slowest endpoints now show hostname/domain
- [x] **Cache stats working** - Backend calculates hit/miss rates correctly (1-hour window)
- [x] **Data cleanup buttons** - Delete old logs and telemetry data with configurable age
- [x] **Cache items listing** - Fixed backend to enumerate actual nginx cache files with BusyBox-compatible commands
- [x] **Cache hit/miss display** - Frontend correctly shows rates from request_telemetry over last 1 hour
- [x] **Bot Activity Chart fix** - Changed to show all available bot data instead of empty "last 24h from now" window
- [x] **Security Events table scrolling** - Added max-height: 500px with sticky headers for long tables

## 📋 Backlog

### Security
- [ ] Custom ModSecurity rules with toggles
- [ ] Rate limit templates per use case
- [ ] IP reputation integration (AbuseIPDB)

### Performance
- [ ] Per-site cache zones
- [ ] Cache bypass rules
- [ ] Dynamic compression optimization
- [ ] **NGINX API Module** - Replace docker exec with ngx_http_api_module for 50x faster cache stats (see CACHE-IMPROVEMENTS.md)
- [ ] **Configurable cache mount** - Allow users to specify host path, NFS, or S3 for cache storage

### Monitoring
- [ ] Real-time log streaming
- [ ] Attack visualization
- [ ] Email/Slack notifications
- [x] ~~Phase out settings to delete old telemetry data and logs~~ - ✅ COMPLETED
- [ ] Automated health checks
- [x] ~~Buttons to clear logs and telemetry data~~ - ✅ COMPLETED with configurable age (7-365 days)

### UX
- [ ] Dark mode toggle
- [ ] Keyboard shortcuts
- [ ] Bulk operations
- [ ] Import/export configs
- [ ] Map for GeoIP blocking and map for non-blocked requests
---

## 📝 Notes

### Known Issues Fixed
- ✅ Docker build CRS clone failure → Moved to entrypoint
- ✅ Dashboard can't see nginx files → Fixed with docker exec
- ✅ Save button only GET → Already using PUT correctly

### Architecture
- Single-page application -> modular pages later
- API-first design
- Docker socket for container management
