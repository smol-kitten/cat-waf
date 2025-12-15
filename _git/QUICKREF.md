# CatWAF Quick Reference Card 📋

Quick commands and tips for daily CatWAF operations.

---

## 🚀 Docker Commands

### Service Management
```bash
# Start all services
docker-compose up -d

# Stop all services
docker-compose down

# Restart specific service
docker-compose restart nginx
docker-compose restart dashboard

# View logs
docker-compose logs -f nginx
docker-compose logs -f dashboard --tail 50

# Check status
docker-compose ps
```

### NGINX Operations
```bash
# Test configuration
docker exec waf-nginx nginx -t

# Reload configuration (after changes)
docker exec waf-nginx nginx -s reload

# View NGINX logs
docker logs waf-nginx --tail 100

# Check error log
docker exec waf-nginx cat /var/log/nginx/error.log | tail -50
```

### Database Access
```bash
# Connect to database
docker exec -it waf-mariadb mysql -u waf_user -p
# Password from .env: DB_PASSWORD

# Quick queries
USE waf;
SELECT COUNT(*) FROM sites;
SELECT COUNT(*) FROM modsec_events WHERE severity <= 2;
SELECT * FROM banned_ips;
```

---

## 🔐 SSL/TLS Quick Start

### Snakeoil (Testing - Instant)
```bash
# Already auto-generated!
# Just select "Self-Signed (Snakeoil)" in dashboard
# Certificate at: /etc/nginx/ssl/snakeoil/cert.pem
# Valid for: 10 years
```

### Let's Encrypt HTTP-01
```bash
# 1. Point domain DNS to your server
# 2. Ensure port 80 is open
# 3. In dashboard: SSL Certificate Type → "Let's Encrypt (HTTP Challenge)"
# 4. Save and reload NGINX
# Certificate auto-requested on first HTTPS access
```

### Let's Encrypt DNS-01 (Cloudflare)
```bash
# 1. Get Cloudflare API token:
#    - Dashboard → My Profile → API Tokens
#    - Create Token → "Edit zone DNS" template
#    - Copy token

# 2. Get Zone ID:
#    - Cloudflare Dashboard → Select domain
#    - Overview → Zone ID (right sidebar)

# 3. In CatWAF dashboard:
#    - SSL Type → "Let's Encrypt (DNS Challenge)"
#    - Paste API Token
#    - Paste Zone ID
#    - Save
```

---

## 🛡️ Security Operations

### JavaScript Challenge (DDoS Protection)
```bash
# Enable JS Challenge for a site:
# 1. In dashboard: Edit site → Performance tab
# 2. Check "Enable JavaScript Challenge"
# 3. Set difficulty: 16 (easy) to 24 (very hard)
#    - 16 = ~0.1s solve time
#    - 18 = ~1s solve time  
#    - 20 = ~10s solve time
#    - 22 = ~60s solve time
#    - 24 = ~10min solve time (extreme)
# 4. Set duration: Cookie validity in hours (default: 1)
# 5. Optional: Check "Bypass for Cloudflare IPs"

# How it works:
# - Visitor is redirected to /challenge.html
# - JavaScript solves SHA-256 proof-of-work
# - Cookie (waf_challenge) is set with token
# - Server validates difficulty matches requirement
# - Visitor is redirected to original page
# - Subsequent visits bypass challenge (cookie valid)

# Security:
# - Cannot be bypassed by URL manipulation
# - Server validates difficulty via separate cookie
# - Protects against DDoS, automated scraping, bot attacks
# - No impact on legitimate users (one-time challenge)
```

### Error Pages
```bash
# Built-in Templates (Default):
# - Beautiful catboy-themed error pages
# - 403 Forbidden, 404 Not Found, 429 Rate Limited, 500 Server Error
# - Located in: nginx/error-pages/
# - No configuration needed

# Custom URLs:
# 1. In dashboard: Edit site → Advanced tab
# 2. Error Page Mode → "Custom URLs"
# 3. Set paths or URLs:
#    - Internal: /custom-404.html (served from backend)
#    - External: https://example.com/404 (external redirect)
# 4. Configure all 4 error types: 403, 404, 429, 500

# Examples:
# - Use your backend's error pages: /errors/404.html
# - Use a CDN: https://cdn.example.com/errors/404.html
# - Mix modes: 404 from backend, 429 external
```

### Compression
```bash
# Brotli Compression (Recommended):
# - Better compression than gzip (10-20% smaller files)
# - Supported by all modern browsers
# - Enable in dashboard: Edit site → Performance tab
# - Check "Enable Brotli Compression"
# - Set compression level: 1 (fast) to 9 (best)
# - Default: 6 (balanced)

# Gzip Compression (Fallback):
# - Always enabled for older browsers
# - Fallback when Brotli not supported
# - Same compression level as Brotli

# Check if Brotli is working:
curl -I -H "Accept-Encoding: br" https://your-site.com | grep -i content-encoding
# Should show: content-encoding: br
```

### ModSecurity
```bash
# View rule count
docker exec waf-nginx sh -c \
  'grep -r "^[[:space:]]*SecRule" /etc/nginx/modsecurity/coreruleset/rules/*.conf | wc -l'

# Change paranoia level
# Dashboard → Settings → ModSecurity Paranoia Level → 1-4

# Temporarily disable for a site
# Dashboard → Sites → Edit → Security → Uncheck "ModSecurity"
```

### IP Bans
```bash
# Manual ban via CLI
docker exec waf-fail2ban fail2ban-client set modsecurity banip 1.2.3.4

# Unban IP
docker exec waf-fail2ban fail2ban-client set modsecurity unbanip 1.2.3.4

# Check ban status
docker exec waf-fail2ban fail2ban-client status modsecurity

# Via Dashboard
# Dashboard → Bans → Add IP → Enter IP and reason → Save
```

### Rate Limiting
```bash
# Available zones:
# - general: 10 req/s (600/min) - Default
# - strict: 2 req/s (120/min) - High security
# - api: 30 req/s (1800/min) - API servers
# - custom: Your values

# Change for a site:
# Dashboard → Sites → Edit → Security tab → Rate Limiting → Select zone
```

---

## 📊 Monitoring

### Real-Time Analytics
```bash
# GoAccess (embedded in dashboard)
Dashboard → GoAccess page

# Or direct access:
http://localhost:7890

# Updates every second with:
# - Visitor count
# - Top URLs
# - OS/Browser stats
# - Status codes
```

### Security Events
```bash
# View recent ModSecurity blocks
Dashboard → Security Events → Filter by severity

# Database query
docker exec -it waf-mariadb mysql -u waf_user -p
USE waf;
SELECT * FROM modsec_events 
WHERE severity <= 2 
ORDER BY timestamp DESC 
LIMIT 20;
```

### Performance Metrics
```bash
# Slowest endpoints
Dashboard → Telemetry → Slowest Endpoints table

# Database query
docker exec -it waf-mariadb mysql -u waf_user -p
USE waf;
SELECT uri, 
  AVG(response_time) as avg_ms,
  COUNT(*) as requests,
  MAX(response_time) as max_ms
FROM request_telemetry 
WHERE response_time IS NOT NULL 
GROUP BY uri 
ORDER BY avg_ms DESC 
LIMIT 10;
```

---

## 🔧 Troubleshooting

### Dashboard Not Loading
```bash
# Check containers
docker-compose ps

# Restart dashboard
docker-compose restart web-dashboard dashboard

# Check API health
curl http://localhost:8080/api/health

# View errors
docker logs waf-dashboard --tail 50
```

### Site Not Working After Adding
```bash
# 1. Test NGINX config
docker exec waf-nginx nginx -t

# 2. Check if config file exists
docker exec waf-nginx ls -lh /etc/nginx/sites-enabled/

# 3. Reload NGINX
docker exec waf-nginx nginx -s reload

# 4. Check for errors
docker logs waf-nginx | grep error
```

### SSL Certificate Issues
```bash
# Regenerate snakeoil cert
docker exec waf-nginx sh -c "
  rm -rf /etc/nginx/ssl/snakeoil
  mkdir -p /etc/nginx/ssl/snakeoil
  openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
    -keyout /etc/nginx/ssl/snakeoil/key.pem \
    -out /etc/nginx/ssl/snakeoil/cert.pem \
    -subj '/C=US/ST=State/L=City/O=Snakeoil/CN=localhost'
"
docker exec waf-nginx nginx -s reload
```

### ModSecurity Blocking Legitimate Traffic
```bash
# Option 1: Lower paranoia level
Dashboard → Settings → Paranoia Level → 1

# Option 2: Disable for specific site
Dashboard → Sites → Edit site → Security → Uncheck ModSecurity

# Option 3: Review false positives
Dashboard → Security Events → Find offending rule ID
# Add exclusion (coming soon via UI)
```

---

## 📈 Performance Tuning

### For High Traffic Sites
```bash
# 1. Increase rate limits
Dashboard → Sites → Edit → Security → Rate Limiting → custom
# Set to 50r/s or higher

# 2. Disable ModSecurity body inspection
# Edit: dashboard/src/endpoints/sites.php
# In generateSiteConfig(), comment out:
# modsecurity_rules 'SecRequestBodyAccess Off';

# 3. Lower paranoia level
Dashboard → Settings → Paranoia Level → 1

# 4. Disable GeoIP for security events
# Already disabled by default in frontend
```

### For Low-Resource Servers
```bash
# 1. Limit Docker memory
# Edit docker-compose.yml:
services:
  nginx:
    mem_limit: 1g
  dashboard:
    mem_limit: 512m

# 2. Disable compression
Dashboard → Sites → Edit → General → Uncheck "Enable Gzip"

# 3. Reduce log retention
# Archive old logs monthly
docker exec -it waf-mariadb mysql -u waf_user -p
DELETE FROM access_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY);
DELETE FROM modsec_events WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

---

## ⌨️ Dashboard Keyboard Shortcuts

```
Ctrl/Cmd + K    Quick search (coming soon)
Ctrl/Cmd + S    Save current form (if in edit mode)
Esc             Close modal
```

---

## 🔗 Important URLs

```bash
# Main Dashboard
http://localhost:8080

# API Base
http://localhost:8080/api/

# Health Check
http://localhost:8080/api/health

# API Info
http://localhost:8080/api/info

# GoAccess Analytics
http://localhost:7890

# Your Sites (proxied)
http://localhost:8081  # HTTP
https://localhost:8443  # HTTPS
```

---

## 📞 Quick API Examples

### Get Site List
```bash
curl -H "Authorization: Bearer YOUR_API_KEY" \
  http://localhost:8080/api/sites
```

### Add New Site
```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "example.com",
    "backend_url": "http://192.168.1.100:3000",
    "enabled": 1,
    "ssl_enabled": 1,
    "ssl_challenge_type": "snakeoil"
  }' \
  http://localhost:8080/api/sites
```

### Ban IP
```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "ip": "1.2.3.4",
    "reason": "Malicious activity"
  }' \
  http://localhost:8080/api/bans
```

### Get Security Events
```bash
curl -H "Authorization: Bearer YOUR_API_KEY" \
  "http://localhost:8080/api/modsec/events?severity=0&limit=20"
```

---

## 🆘 Emergency Procedures

### Service Won't Start
```bash
# 1. Check logs for all services
docker-compose logs

# 2. Rebuild and restart
docker-compose down
docker-compose up -d --build

# 3. Check for port conflicts
netstat -ano | findstr "8080 8081 3306 7890"
# (Linux: netstat -tlnp | grep -E '8080|8081|3306|7890')
```

### Database Corrupted
```bash
# 1. Stop services
docker-compose down

# 2. Backup database (if possible)
docker-compose up -d mariadb
docker exec waf-mariadb mysqldump -u root -p waf > backup.sql

# 3. Rebuild database
docker volume rm waf_mariadb_data
docker-compose up -d --build

# 4. Restore from backup
docker exec -i waf-mariadb mysql -u root -p waf < backup.sql
```

### Complete Reset
```bash
# WARNING: Deletes all data!

# 1. Stop everything
docker-compose down

# 2. Remove volumes
docker volume rm waf_mariadb_data
docker volume rm waf_nginx_config

# 3. Rebuild from scratch
docker-compose up -d --build

# 4. Wait for initialization (~3 minutes)
docker-compose logs -f
```

---

## 💡 Pro Tips

1. **Always test NGINX config** before reload: `docker exec waf-nginx nginx -t`
2. **Start with paranoia level 1**, increase gradually
3. **Use snakeoil certs** for testing, Let's Encrypt for production
4. **Monitor security events daily**, especially first week
5. **Archive old logs monthly** to keep database performant
6. **Backup database weekly**: `docker exec waf-mariadb mysqldump...`
7. **Keep OWASP CRS updated** monthly
8. **Test rate limits** with realistic traffic before production
9. **Use GoAccess** for real-time traffic insights
10. **Check API health** regularly: `/api/health` endpoint

---

## 📚 More Documentation

- **[SETUP.md](_git/SETUP.md)** - Complete installation guide
- **[FEATURES.md](_git/FEATURES.md)** - Full feature list
- **[README.md](readme.md)** - Project overview
- **[TODO.md](TODO.md)** - Development roadmap

---

**CatWAF v1.5.0** | Made with 💖 by catboys 🐱

*Keep purr-tecting!* 🛡️✨
