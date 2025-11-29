# Traffic Analysis & Scanner Detection Enhancement

## 🎯 Overview
Comprehensive security and analytics enhancement with:
- Interactive traffic spike analysis with drill-down
- Scanner detection and auto-blocking
- Learning mode for anomaly detection
- WordPress path scanning protection

## ✨ Features Implemented

### 1. **Interactive Traffic Analysis** 
**Click any hour on the traffic chart to see detailed breakdown:**
- Summary stats (total requests, unique domains/IPs, avg response time)
- Pie charts: Traffic by domain, Status code distribution
- Tabbed views:
  - **Top Endpoints**: Most accessed paths with response times
  - **Errors**: All 4xx/5xx errors with counts
  - **Top IPs**: Suspicious IPs with 404 counts, quick ban button
  - **Bot Activity**: Bots detected in that hour

### 2. **Scanner Detection System**
**Automatic detection of:**
- WordPress path scanning (`/wp-admin`, `/wp-includes`, etc.)
- Exploit path scanning (`/.env`, `/.git`, `/phpmyadmin`, etc.)
- Directory brute-forcing (multiple 404s)

**Auto-blocking triggers:**
- Configurable 404 threshold (default: 10 in 60s)
- WordPress instant block option
- Automatic IP ban + nginx reload

### 3. **Learning Mode** (Database ready, needs worker)
- Detects traffic anomalies (bot/IP spikes)
- Auto-blocks when thresholds exceeded
- Whitelist known good bots
- Configurable sensitivity

### 4. **Security Rules Management**
**New API endpoints:**
- `GET /security-rules/list` - Get all security rules
- `POST /security-rules/update` - Update rule config
- `POST /security-rules/block-scanner` - Manually block detected scanner
- `GET /security-rules/scanner-stats` - Scanner detection statistics

**Rule types:**
- `scanner_detection` - 404 threshold, time window, auto-block duration
- `learning_mode` - Spike thresholds, auto-block settings
- `wordpress_block` - Instant block WordPress paths

## 📊 Database Schema

### `security_rules` table
```sql
- id, site_id (NULL=global), rule_type, enabled
- config (JSON): threshold_404, time_window_seconds, auto_block_duration, wordpress_instant_block
```

### `scanner_detections` table
```sql
- ip_address, domain, scan_type (wordpress/exploit/directory/generic)
- request_count, error_404_count, suspicious_paths
- first_seen, last_seen, auto_blocked, block_reason
```

## 🔧 Configuration Examples

### Scanner Detection (Default)
```json
{
  "threshold_404": 10,
  "time_window_seconds": 60,
  "auto_block_duration": 3600,
  "wordpress_instant_block": false
}
```

### WordPress Instant Block
```json
{
  "instant_block": true,
  "paths": [
    "/wp-admin/", "/wp-includes/", "/wp-content/",
    "/wp-login.php", "/xmlrpc.php", "/wp-json/"
  ]
}
```

### Learning Mode
```json
{
  "bot_spike_threshold": 100,
  "ip_spike_threshold": 50,
  "time_window_seconds": 300,
  "auto_block_duration": 1800,
  "whitelist_known_bots": true
}
```

## 📡 New API Endpoints

### Traffic Analysis
- `GET /traffic-analysis/hour-detail?timestamp=2025-11-29 15:00:00`
  - Returns complete breakdown for that hour
  - Used by clickable chart modal

- `GET /traffic-analysis/spike-detection?threshold=3.0`
  - Auto-detects traffic spikes (3x normal)
  - Returns spike hours with multipliers

- `GET /traffic-analysis/scanner-activity?limit=50`
  - Recent scanner detections
  - Shows scan type, paths, block status

### Security Rules
- `GET /security-rules/list?site_id=1`
  - Get security rules (global + site-specific)

- `POST /security-rules/update` 
  ```json
  {"id": 1, "enabled": 1, "config": {...}}
  ```

- `POST /security-rules/block-scanner`
  ```json
  {"ip_address": "1.2.3.4", "duration": 3600, "reason": "..."}
  ```

## 🚀 Usage

### View Traffic Spike Details
1. Go to Dashboard
2. See large spike in "Traffic Over Time" chart
3. Click on that hour's bar
4. Modal opens with complete breakdown
5. View domains, status codes, errors, suspicious IPs
6. Quick-ban suspicious IPs directly from analysis

### Enable WordPress Blocking
```sql
UPDATE security_rules 
SET enabled = 1, 
    config = JSON_SET(config, '$.wordpress_instant_block', true)
WHERE rule_type = 'scanner_detection';
```

Or via API:
```javascript
await apiRequest('/security-rules/update', {
  method: 'POST',
  body: JSON.stringify({
    id: 1,
    config: {
      threshold_404: 5,
      time_window_seconds: 60,
      auto_block_duration: 7200,
      wordpress_instant_block: true
    }
  })
});
```

### Check Scanner Activity
```bash
# Via API
curl http://localhost:8080/api/traffic-analysis/scanner-activity?limit=100

# Via Database
docker exec -it waf-mariadb mysql -u waf_user -p
USE waf_db;
SELECT * FROM scanner_detections 
WHERE last_seen > DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY request_count DESC;
```

## 📝 Files Modified

### New Files
- `mariadb/init/13-security-rules-scanner-detection.sql` - Database schema
- `dashboard/src/endpoints/traffic-analysis.php` - Analysis API
- `dashboard/src/endpoints/security-rules.php` - Security rules API

### Modified Files
- `dashboard/src/index.php` - Registered new endpoints
- `log-parser/parser.php` - Added scanner detection logic
- `web-dashboard/src/dashboard.html` - Added traffic analysis modal
- `web-dashboard/src/app.js` - Click handler + analysis functions
- `web-dashboard/src/style.css` - Modal tabs styling

## 🧪 Testing

### Test Scanner Detection
```bash
# Trigger WordPress scan
for i in {1..15}; do
  curl http://your-site.com/wp-admin/
  curl http://your-site.com/wp-includes/
  curl http://your-site.com/wp-login.php
done

# Check if auto-banned
docker exec waf-log-parser tail -50 /dev/stdout | grep "SCANNER BLOCKED"
```

### Test Traffic Analysis
1. Click any hour on dashboard traffic chart
2. Verify modal shows:
   - Summary cards populated
   - Domain pie chart
   - Status code distribution
   - Tabbed data tables

### View Auto-Banned IPs
```sql
SELECT ip_address, reason, banned_at, duration 
FROM banned_ips 
WHERE reason LIKE '%Scanner%'
ORDER BY banned_at DESC;
```

## 🔮 Future Enhancements

### UI Needed
- **Security Settings Page**: Configure rules via dashboard
  - Toggle WordPress instant block
  - Adjust 404 thresholds
  - Enable/disable learning mode
  - View scanner statistics

- **Scanner Dashboard**: Dedicated page for scanner activity
  - Real-time scanner detections
  - Geographic map of scanners
  - Most scanned paths
  - Auto-block history

### Workers Needed
- **Learning Mode Worker**: Background process to:
  - Calculate traffic baselines
  - Detect anomalies
  - Auto-block spikes
  - Generate alerts

- **Scanner Cleanup**: Periodically clean old scanner_detections

## 📋 Deployment

```bash
# 1. Run migration
docker exec waf-mariadb mysql -u waf_user -p waf_db < mariadb/init/13-security-rules-scanner-detection.sql

# 2. Rebuild containers
docker-compose build log-parser dashboard web-dashboard

# 3. Restart services
docker-compose up -d

# 4. Verify endpoints
curl http://localhost:8080/api/security-rules/list
curl http://localhost:8080/api/traffic-analysis/spike-detection
```

## 🎨 UI Preview

**Traffic Chart (Clickable)**
```
┌─────────────────────────────────────────┐
│ Traffic Over Time                        │
│ 💡 Click on any bar to analyze that hour│
│ ┌─────────────────────────────────────┐ │
│ │    ████                              │ │
│ │ ██ ████ ██                           │ │
│ │ ██ ████ ██ ██                       │ │
│ └─────────────────────────────────────┘ │
└─────────────────────────────────────────┘
        ↓ Click
┌─────────────────────────────────────────┐
│ 📊 Traffic Analysis - 15:00             │
│ ┌───┐┌───┐┌───┐┌───┐                  │
│ │1.2k││ 3 ││152││45ms│                │
│ └───┘└───┘└───┘└───┘                  │
│ [Domain Pie] [Status Pie]               │
│ [Top Endpoints][Errors][IPs][Bots]     │
└─────────────────────────────────────────┘
```

## ⚠️ Important Notes

1. **Scanner Detection**: Real-time, runs in log parser
2. **Learning Mode**: Database ready, needs background worker
3. **WordPress Blocking**: Instant block requires `wordpress_instant_block: true`
4. **Auto-Bans**: Automatically regenerate nginx banlist
5. **Performance**: Scanner tracking uses in-memory + DB

## 🐛 Known Limitations

- Learning mode detection logic needs implementation
- No UI for security rules management yet
- Scanner cleanup needs cron job
- No alerts/notifications for auto-blocks
