# Known Issues:
[X] - Fixed  
[-] - Wont Fix (for now)  
[ ] - In Progress  


[X] Modescurity set Paranoia Level not saving - FIXED: Corrected database column reference (setting_value)
[X] Logs not properly showing all access/security logs - FIXED: Added regex parsing for raw NGINX log format
[X] No site filter for logs - FIXED: Added site dropdown filter in logs page
[X] 🔐 SSL/TLS Certificates (Let's Encrypt) in Settings tab getting long and hard to manage - FIXED: Added scrollable container (max-height: 500px)
[X] Ratelimit Presets opening extra page where user is not authenticated - FIXED: Added API token passing via URL parameter and localStorage, with auth check on page load
[X] Bot Protection -> Bot Activity (Last 24h) not implemented - FIXED: Implemented Chart.js line chart with blocked/allowed/total bot activity
[X] Security Events not properly showing events from mod security - FIXED: Corrected field mapping (client_ip, rule_message), added parsing for comma/pipe-separated values  
[X] Tables like: Recent Bot Detections getting very long - FIXED: Added scrollable container (max-height: 400px)
[X] Confidence in Bot Protection always showing null% - FIXED: Shows "N/A" when null, displays actual value when present
[ ] Dashboard is actually web dashboard. The "dashboard" is just the api and management -> rename cleanly
[X] Bot Activity (Last 24h) chart is all 0s even with data - FIXED: Changed chart to show all available bot data instead of forcing empty 24h window
[X] Migration sql files duplicate numbering - FIXED: Files properly numbered 01-04 (Oct 18, 2025)
  - Repo files: 01-complete-schema.sql, 02-migration-redirect-cf-ratelimit.sql, 03-migration-jobs-table.sql, 04-custom-block-rules.sql
  - Volume synced with repo files for fresh deployments
[X] Bad Bots Blocked 698 - EXPLAINED: Historical entries from before IP preservation fix (Oct 18, 2025)
  - 675 detections from Docker gateway IP 172.21.0.1 (before real_ip config)
  - Log parser reads IP from NGINX logs (which showed gateway IP before fix)
  - New detections after real_ip configuration will show actual client IPs
  - Consider cleanup: DELETE FROM bot_detections WHERE ip_address='172.21.0.1';
[X] Recent Security Events table very long - FIXED: Added scrollable container (max-height: 500px) with sticky headers
[X] Recent Security events in Overview showing trash and not useful data - FIXED: Improved display to show domain, method, path, status, and rule details  
>Recent Security Events  
View All
GET / - 200
Unknown
GET / - 200
Unknown
GET / - 200
Unknown
GET / - 200


[X] Slowest endpoints cant be flooded with iamges before first optimisation like:
  FIXED: Added filtering to exclude static assets (.png, .jpg, .css, .js, fonts, etc.) - now only shows dynamic endpoints

s>Slowest Endpoints
Host	Path	Avg Response	P95	P99	Requests
dom2.tld	/preview.php	595.8ms	735ms	735ms	10
dom1.tld	/assets/Village.png	443.4ms	492ms	492ms	5
dom1.tld	/assets/screenshots/2025-08-16_03.16.02.png	416ms	416ms	416ms	2
dom1.tld	/assets/screenshots/2025-08-16_02.59.35.png	399ms	399ms	399ms	2
dom1.tld	/assets/screenshots/2025-08-16_03.21.52.png	393ms	393ms	393ms	2
dom1.tld	/assets/screenshots/2025-08-16_02.54.58.png	390ms	390ms	390ms	2
dom1.tld	/assets/screenshots/2025-08-16_02.53.37.png	382.7ms	487ms	487ms	8
dom1.tld	/assets/screenshots/2025-08-16_03.00.01.png	343ms	343ms	343ms	2
dom1.tld	/assets/screenshots/2025-08-16_02.56.25.png	342ms	342ms	342ms	2

# To Test / Verify:
[ ] Custom ModSecurity rules
[ ] Auto bans
[ ] Test if IP Bans work at all
[X] IP Preservation/Prevent translation to docker IP´s - FIXED: Added real_ip module configuration (Oct 18, 2025)
  - Added `real_ip_header X-Forwarded-For` to nginx.conf
  - Trust Docker networks: 172.16.0.0/12, 10.0.0.0/8, 192.168.0.0/16, localhost
  - Enabled `real_ip_recursive on` for multi-proxy chains
  - Bot detections and logs will now capture real client IPs instead of Docker gateway IPs

## Recent Fixes / Notes (October 18, 2025)

**Session 14 - Certificate Auto-Issue & Infrastructure (October 18, 2025):**
- [x] **Auto-Issue on Renew Failure** - When acme.sh reports "is not an issued domain", automatically attempts certificate issuance
  - Fixed database connection bug (`global $db` → `getDB()`)
  - Detects renewal failures and triggers `--issue --force` command
  - Proper two-tier symlink creation for ECC certificates
  - Cloudflare token fallback: site-specific → global CLOUDFLARE_API_TOKEN
  - Tested successfully with cat-boy.dev (valid Let's Encrypt cert issued)
- [x] **Real IP Preservation** - Nginx now captures real client IPs instead of Docker gateway
  - Added `real_ip_header X-Forwarded-For` configuration
  - Trusts Docker networks (172.16.0.0/12, 10.0.0.0/8, 192.168.0.0/16)
  - Enabled `real_ip_recursive on` for multi-proxy scenarios
- [x] **Docker Volume Architecture** - Unified certificate access between containers
  - Added `/acme.sh` mount to nginx container (same volume as `/etc/nginx/certs`)
  - Enables flexible symlink strategies for certificate management
  - Resolved symlink loop issues with proper cleanup

**Session 8 - Backend & UI Enhancements:**
- [x] Per-backend protocol toggles (HTTP/HTTPS/WS/WSS) added to site editor UI — allows enabling/disabling protocols per backend
- [x] "View Raw Config" endpoint and UI button added — GET /api/sites/:id/config returns generated NGINX config (✅ WORKING)
- [x] Challenge difficulty slider visual improvements (wider range 12-24, numeric display, 100% width)
- [x] Backend port configuration fixed — fallback port now properly included in upstream config (fixes 503 errors)
- [x] viewRawConfig() fixed to use correct variable (currentSiteData instead of siteData)

**Session 9 - Data Display & UX Fixes:**
- [x] ModSecurity Paranoia Level saving - Fixed database column reference (setting_value)
- [x] Recent Security Events display - Now shows domain, method, path, status code, and actual rule details instead of "Unknown"
- [x] Bot Protection Confidence - Fixed null% display, shows "N/A" when no data, actual percentage when available
- [x] Long tables (Bot Detections) - Added scrollable containers (max-height: 400px) with sticky headers
- [x] Site filter for logs - Added dropdown to filter access logs by domain

- [x] Frontend null handling - Updated app.js to display 'N/A' for null hits and fallback to key when URL missing
- [x] Hit/Miss rate calculation - Verified working from request_telemetry over last 1 hour window
- [x] Bot Activity Chart - Changed to show all available data instead of enforcing empty "last 24h from now" window
- [x] Recent Security Events scrolling - Added max-height: 500px with sticky table headers

## Known Issues to Address

[X] Per-backend protocol toggles backend implementation in generateSiteConfig() — COMPLETED
  - Frontend UI complete with checkboxes and data structure (backend.proto.{http,https,ws,wss})
  - ✅ NGINX config generation now respects fallback port when useProtocolPorts=false
  - ✅ Protocol-specific ports supported when useProtocolPorts=true
  - Backend properly generates `server address:port` in upstream blocks