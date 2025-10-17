# CatWAF Setup Guide 🐱🛡️

Complete installation and configuration guide for CatWAF.

---

## 📋 Prerequisites

### System Requirements
- **OS**: Linux, macOS, or Windows with Docker
- **RAM**: Minimum 4GB, recommended 8GB+
- **Disk**: 10GB free space (20GB+ for production)
- **CPU**: 2+ cores recommended

### Required Software
- [Docker](https://docs.docker.com/get-docker/) 20.10+
- [Docker Compose](https://docs.docker.com/compose/install/) 2.0+
- (Optional) Domain name with DNS access
- (Optional) Cloudflare account for DNS-01 ACME

---

## 🚀 Quick Start (5 Minutes)

### 1. Clone Repository
```bash
git clone <repository-url>
cd waf
```

### 2. Configure Environment
```bash
# Copy example environment file
cp .env.example .env

# Edit with your favorite editor
nano .env
```

**Minimum Required Settings:**
```env
# Dashboard API Key (generate a strong random key)
DASHBOARD_API_KEY=your-super-secret-api-key-here-min-32-chars

# Database Passwords
DB_ROOT_PASSWORD=strongrootpassword
DB_PASSWORD=strongwafpassword

# Email for Let's Encrypt notifications
ACME_EMAIL=admin@yourdomain.com
```

### 3. Start Services
```bash
# Build and start all containers
docker-compose up -d --build

# Watch startup logs
docker-compose logs -f
```

**Wait 2-3 minutes for:**
- MariaDB initialization
- OWASP CRS download (~677 rules)
- Snakeoil certificate generation
- NGINX configuration

### 4. Access Dashboard
Open your browser:
- **Dashboard**: http://localhost:8080
- **GoAccess**: http://localhost:7890
- **NGINX**: http://localhost:8081 (proxied sites)

**Default Login**: Use the `DASHBOARD_API_KEY` from your `.env` file

---

## 🔧 Detailed Configuration

### Environment Variables

#### Essential Settings
```env
# API Authentication
DASHBOARD_API_KEY=your-secret-key-min-32-characters-long

# Database
DB_HOST=mariadb
DB_PORT=3306
DB_NAME=waf
DB_USER=waf_user
DB_PASSWORD=strong_password_here
DB_ROOT_PASSWORD=even_stronger_password

# Let's Encrypt
ACME_EMAIL=your-email@domain.com
ACME_STAGING=false  # Set to true for testing
```

#### Optional Settings
```env
# Timezone
TZ=UTC

# Port Mappings (change if ports are in use)
NGINX_HTTP_PORT=8081
NGINX_HTTPS_PORT=8443
DASHBOARD_PORT=8080
GOACCESS_PORT=7890

# Fail2Ban
FAIL2BAN_BANTIME=3600
FAIL2BAN_FINDTIME=600
FAIL2BAN_MAXRETRY=5

# GoAccess
GOACCESS_REAL_TIME=true
GOACCESS_WS_ORIGIN=*
```

### Port Configuration

Default port mapping:
| Service | Internal | External | Purpose |
|---------|----------|----------|---------|
| NGINX HTTP | 80 | 8081 | Web traffic |
| NGINX HTTPS | 443 | 8443 | Secure web traffic |
| Dashboard | 80 | 8080 | Web UI & API |
| GoAccess | 7890 | 7890 | Real-time analytics |
| MariaDB | 3306 | - | Database (internal only) |

**To change external ports**, edit `docker-compose.yml`:
```yaml
nginx-waf:
  ports:
    - "80:80"      # Change left side (host port)
    - "443:443"
```

---

## 🌐 Adding Your First Site

### Method 1: Dashboard (Recommended)

1. **Navigate to Sites**
   - Open dashboard: http://localhost:8080
   - Click **Sites** in sidebar

2. **Click "Add New Site"**
   - Opens tabbed editor

3. **General Tab**
   - **Domain**: `example.com` (or `example.local` for testing)
   - **Backend URL**: `http://192.168.1.100:3000` (your app server)
   - **Enable**: ✅ Checked
   - **Wildcard Subdomains**: Optional

4. **Security Tab**
   - **ModSecurity**: ✅ Enabled (recommended)
   - **Bot Protection**: ✅ Enabled
   - **Rate Limiting**: Select zone or custom
   - **GeoIP Blocking**: (Optional) Select countries
   - **IP Whitelist**: (Optional) Trusted IPs

5. **SSL/TLS Tab**
   - **Enable SSL**: ✅ Checked
   - **Certificate Type**: Choose:
     - **Let's Encrypt (HTTP Challenge)**: For public domains
     - **Let's Encrypt (DNS Challenge)**: For wildcards (needs Cloudflare)
     - **Self-Signed (Snakeoil)**: For testing/internal use
     - **Custom Certificate**: Provide your own paths

6. **For Cloudflare DNS-01** (if selected):
   - **API Token**: Your Cloudflare API token
   - **Zone ID**: Your Cloudflare zone ID

7. **Click "Save Site"**

8. **Reload NGINX**
   ```bash
   docker exec waf-nginx nginx -s reload
   ```

### Method 2: API

```bash
curl -X POST http://localhost:8080/api/sites \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "example.com",
    "backend_url": "http://192.168.1.100:3000",
    "enabled": 1,
    "ssl_enabled": 1,
    "ssl_challenge_type": "snakeoil",
    "enable_modsecurity": 1,
    "enable_bot_protection": 1,
    "enable_rate_limit": 1,
    "rate_limit_zone": "general"
  }'
```

### Method 3: Database Direct

```sql
-- Connect to database
docker exec -it waf-mariadb mysql -u waf_user -p

USE waf;

INSERT INTO sites (
  domain, 
  backend_url, 
  enabled, 
  ssl_enabled,
  ssl_challenge_type,
  enable_modsecurity,
  enable_bot_protection,
  enable_rate_limit,
  rate_limit_zone
) VALUES (
  'example.com',
  'http://192.168.1.100:3000',
  1,
  1,
  'snakeoil',
  1,
  1,
  1,
  'general'
);
```

Then regenerate configs via dashboard or API.

---

## 🎯 Catch-All Site Configuration

### Using "_" as a Default Backend

The WAF supports a special catch-all site using the domain **`_`** (underscore) which handles requests for:
- Unrecognized domain names
- Direct IP address access
- Domains not yet configured

**Why use it?**
- Provides a default response for unconfigured domains
- Prevents errors when WAF receives requests for unknown sites
- Useful for development/testing environments
- Can serve a landing page or redirect to your main site

### Setting Up Catch-All Site

**Via Dashboard:**
1. Go to Sites → Add New Site
2. **Domain**: Enter `_` (just the underscore character)
3. **Backend URL**: Point to your default backend (e.g., `http://default-backend:80`)
4. Configure security settings as needed
5. Save and reload NGINX

**Via API:**
```bash
curl -X POST http://localhost:8080/api/sites \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "_",
    "backend_url": "http://default-backend:80",
    "enabled": 1,
    "enable_modsecurity": 1,
    "enable_bot_protection": 1
  }'
```

**Default Backend Container:**
The stack includes a `default-backend` service that serves a simple HTML page.
You can customize it by editing `backends/default/index.html`.

### Site Suggestions from Catch-All Traffic

The dashboard includes a **Site Suggester** feature that analyzes traffic hitting the catch-all site and suggests domains you should configure:

**Via Dashboard:**
- Go to **Sites** page
- Click **"Site Suggestions"** button
- View domains receiving traffic with:
  - Request counts
  - Unique visitors
  - Error rates
  - Priority recommendations (High/Medium/Low)

**Via API:**
```bash
curl http://localhost:8080/api/site-suggestions \
  -H "X-API-Key: YOUR_API_KEY"
```

**Response includes:**
- List of unconfigured domains receiving traffic
- Traffic statistics per domain
- Priority recommendations
- Catch-all site statistics

This helps you discover which domains need proper configuration without manually reviewing logs!

---

## 🔐 SSL/TLS Certificate Setup

### Option 1: Snakeoil (Testing)

**Best for**: Local development, testing, internal services

```bash
# Automatically generated on first start
# Located at: /etc/nginx/ssl/snakeoil/
# Valid for: 10 years
# CN: localhost
```

**No additional setup needed!** Just select "Self-Signed (Snakeoil)" in dashboard.

**Browser Warning**: You'll see SSL warnings - this is normal for self-signed certs.

### Option 2: Let's Encrypt (HTTP-01)

**Best for**: Public websites with HTTP access

**Requirements**:
- Domain must point to your server's public IP
- Port 80 must be accessible from internet
- Set `ACME_EMAIL` in `.env`

**Setup**:
1. Add site with SSL enabled
2. Select "Let's Encrypt (HTTP Challenge)"
3. Save site and reload NGINX
4. Certificate auto-generated on first request

**ACME Challenge Path**: `/.well-known/acme-challenge/`

### Option 3: Let's Encrypt (DNS-01)

**Best for**: Wildcard certificates, private servers

**Requirements**:
- Cloudflare account
- Domain managed by Cloudflare
- Cloudflare API token with DNS edit permissions

**Getting Cloudflare Credentials**:

1. **API Token**:
   - Go to Cloudflare Dashboard
   - My Profile → API Tokens → Create Token
   - Use template: "Edit zone DNS"
   - Zone Resources: Include → Specific zone → Your domain
   - Copy token (starts with `cf_...`)

2. **Zone ID**:
   - Go to your domain in Cloudflare
   - Overview tab → Right sidebar
   - Copy "Zone ID"

3. **Configure in Dashboard**:
   - SSL/TLS tab
   - Select "Let's Encrypt (DNS Challenge)"
   - Paste API Token
   - Paste Zone ID
   - Save

### Option 4: Custom Certificate

**Best for**: Purchased certificates, corporate CAs

**Requirements**:
- Certificate file (`.crt` or `.pem`)
- Private key file (`.key` or `.pem`)
- (Optional) CA bundle

**Setup**:
1. Copy files to container:
   ```bash
   docker cp cert.pem waf-nginx:/etc/nginx/ssl/custom/
   docker cp key.pem waf-nginx:/etc/nginx/ssl/custom/
   ```

2. Configure in dashboard:
   - Select "Custom Certificate"
   - Site config will use paths:
     - `/etc/nginx/ssl/custom/cert.pem`
     - `/etc/nginx/ssl/custom/key.pem`

3. Or edit site config directly:
   ```nginx
   ssl_certificate /path/to/cert.pem;
   ssl_certificate_key /path/to/key.pem;
   ```

---

## 🛡️ ModSecurity Configuration

### Paranoia Levels

| Level | Protection | False Positives | Best For |
|-------|-----------|-----------------|----------|
| 1 | Basic | Very Low | Production sites |
| 2 | Enhanced | Low | Most websites |
| 3 | Advanced | Medium | High security needs |
| 4 | Maximum | High | Critical applications |

**Change via Dashboard**:
1. Go to Settings page
2. Find "ModSecurity Paranoia Level"
3. Select level (1-4)
4. Save settings
5. Reload NGINX

### Custom Rules

**Location**: `/etc/nginx/modsecurity/custom-rules/`

**Example Rule**:
```bash
# Create custom rule file
docker exec waf-nginx sh -c 'cat > /etc/nginx/modsecurity/custom-rules/custom.conf << EOF
# Block specific user agent
SecRule REQUEST_HEADERS:User-Agent "@contains bad-bot" \
  "id:10001,\
   phase:1,\
   deny,\
   status:403,\
   msg:\"Bad bot detected\""
EOF'

# Reload NGINX
docker exec waf-nginx nginx -s reload
```

### Rule Exclusions

**Per-Site Exclusions** (coming soon):
```nginx
# Disable specific rule for a site
modsecurity_rules '
  SecRuleRemoveById 920170
';
```

### Reviewing Blocks

1. **Dashboard → Security Events**
2. Filter by severity
3. Review blocked requests
4. Identify false positives
5. Add exclusions as needed

---

## 🤖 Bot Protection

### Good Bots (Whitelisted)

Automatically allowed:
- Googlebot, Bingbot (search engines)
- Slackbot, Discord, Telegram (messaging)
- Facebook, Twitter, LinkedIn (social)
- Many others...

### Bad Bots (Blocked)

Automatically blocked with 403:
- Scrapers: ahrefsbot, semrush, dotbot
- Scanners: masscan, nmap, nikto
- Attack tools: sqlmap, metasploit, burp
- Generic: python-requests, wget, curl
- Many others...

### Custom Bot Rules

**Edit**: `nginx/conf.d/bot-protection.conf`

```nginx
# Block custom bot
map $http_user_agent $block_bot {
    ~*my-bad-bot "1";
}

# Allow custom bot  
map $http_user_agent $block_bot {
    ~*my-good-bot "0";
}
```

**Reload**: `docker exec waf-nginx nginx -s reload`

---

## 📊 Monitoring & Analytics

### Dashboard Access

**URL**: http://localhost:8080

**Authentication**: Bearer token (set in `.env` as `DASHBOARD_API_KEY`)

**Pages**:
- **Overview**: Quick stats, recent activity
- **Sites**: Manage your sites
- **Bans**: View and manage IP bans
- **Security Events**: ModSecurity alerts
- **ModSecurity**: WAF statistics
- **Bot Protection**: Bot detection tracking
- **Telemetry**: Performance metrics
- **GoAccess**: Embedded analytics
- **Logs**: Access log viewer
- **Settings**: Global configuration

### GoAccess Real-Time Analytics

**Direct Access**: http://localhost:7890

**Features**:
- Real-time visitor tracking
- Top URLs and referrers
- OS/Browser breakdown
- Status code distribution
- Request timeline
- Auto-updates every second

**Customization**: Edit `goaccess/goaccess.conf`

### Database Queries

**Connect**:
```bash
docker exec -it waf-mariadb mysql -u waf_user -p
# Enter password from .env: DB_PASSWORD
```

**Useful Queries**:
```sql
USE waf;

-- Top 10 visited URLs
SELECT uri, COUNT(*) as hits 
FROM access_logs 
GROUP BY uri 
ORDER BY hits DESC 
LIMIT 10;

-- Recent ModSecurity blocks
SELECT * FROM modsec_events 
WHERE severity <= 2 
ORDER BY timestamp DESC 
LIMIT 20;

-- Top blocked IPs
SELECT client_ip, COUNT(*) as blocks 
FROM modsec_events 
GROUP BY client_ip 
ORDER BY blocks DESC 
LIMIT 10;

-- Bot detection summary
SELECT bot_type, COUNT(*) as detections 
FROM bot_detections 
GROUP BY bot_type;

-- Slowest endpoints
SELECT uri, AVG(response_time) as avg_time 
FROM request_telemetry 
WHERE response_time IS NOT NULL 
GROUP BY uri 
ORDER BY avg_time DESC 
LIMIT 10;
```

---

## 🔥 Fail2Ban Configuration

### Default Jails

Automatically enabled:
- **nginx-http-auth**: Failed HTTP auth attempts
- **nginx-noscript**: Script kiddie attacks
- **nginx-badbots**: Known bad bots
- **nginx-noproxy**: Proxy abuse attempts
- **modsecurity**: ModSecurity rule violations

### Ban Settings

**Edit**: `fail2ban/jail.local`

```ini
[DEFAULT]
bantime = 3600      # Ban for 1 hour
findtime = 600      # Window to count failures
maxretry = 5        # Failures before ban

[modsecurity]
enabled = true
bantime = 7200      # Ban for 2 hours
maxretry = 3        # Block after 3 ModSecurity violations
```

**Apply Changes**:
```bash
docker-compose restart fail2ban
```

### Manual IP Management

**Ban IP**:
```bash
docker exec waf-fail2ban fail2ban-client set modsecurity banip 1.2.3.4
```

**Unban IP**:
```bash
docker exec waf-fail2ban fail2ban-client set modsecurity unbanip 1.2.3.4
```

**Check Status**:
```bash
docker exec waf-fail2ban fail2ban-client status modsecurity
```

### Whitelist IPs

**Edit**: `fail2ban/jail.local`

```ini
[DEFAULT]
ignoreip = 127.0.0.1/8 ::1 10.0.0.0/8 192.168.0.0/16
```

---

## 🐛 Troubleshooting

### Dashboard Shows No Data

**Check database population**:
```bash
docker exec -it waf-mariadb mysql -u waf_user -p
```
```sql
USE waf;
SELECT COUNT(*) FROM access_logs;
SELECT COUNT(*) FROM modsec_events;
```

**If empty**: Generate traffic to your sites, wait for log parsing.

**Restart services**:
```bash
docker-compose restart dashboard log-parser
```

### NGINX Won't Start

**Check configuration**:
```bash
docker exec waf-nginx nginx -t
```

**Common issues**:
1. **Snakeoil cert missing**: Fixed by entrypoint script
2. **Port already in use**: Change ports in `docker-compose.yml`
3. **Invalid site config**: Check `/etc/nginx/sites-enabled/`

**View logs**:
```bash
docker logs waf-nginx --tail 100
```

### Site Not Working

**Verify site config exists**:
```bash
docker exec waf-nginx ls -lh /etc/nginx/sites-enabled/
```

**Test backend connectivity**:
```bash
docker exec waf-nginx wget -O- http://your-backend-ip:port
```

**Check NGINX logs**:
```bash
docker logs waf-nginx | grep "example.com"
```

**Reload NGINX**:
```bash
docker exec waf-nginx nginx -s reload
```

### ModSecurity Blocking Legitimate Traffic

**Option 1: Lower Paranoia Level**
- Dashboard → Settings
- Change to level 1 or 2

**Option 2: Disable for Specific Site**
- Dashboard → Sites → Edit site
- Security tab → Uncheck "ModSecurity"

**Option 3: Review and Exclude Rules**
- Dashboard → Security Events
- Find false positive
- Note rule ID
- (Coming soon: Add exclusion via UI)

### SSL Certificate Issues

**Snakeoil not working**:
```bash
# Regenerate certificate
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

**Let's Encrypt failing**:
1. Verify domain DNS points to server
2. Check port 80 accessible
3. Review ACME logs: `docker logs waf-acme`
4. Try staging first: `ACME_STAGING=true` in `.env`

### Performance Issues

**Slow dashboard load times**:
- Disable GeoIP for large event lists (already disabled by default)
- Reduce event limit in queries
- Archive old logs

**High memory usage**:
- Reduce GoAccess buffer size
- Limit ModSecurity body inspection size
- Tune MariaDB memory settings

**Check resource usage**:
```bash
docker stats
```

---

## 🔄 Updating CatWAF

### Update Docker Images

```bash
# Pull latest changes
git pull

# Rebuild containers
docker-compose down
docker-compose up -d --build

# Watch startup
docker-compose logs -f
```

### Update OWASP CRS

```bash
# Enter nginx container
docker exec -it waf-nginx sh

# Update CRS
cd /etc/nginx/modsecurity/coreruleset
git pull origin main

# Exit and reload
exit
docker exec waf-nginx nginx -s reload
```

### Database Migrations

Currently manual. Example for new column:

```sql
docker exec -it waf-mariadb mysql -u waf_user -p

USE waf;
ALTER TABLE sites ADD COLUMN new_feature TINYINT DEFAULT 0;
```

**Planned**: Automatic migration system.

---

## 🔐 Security Hardening

### Production Checklist

- [ ] Change all default passwords
- [ ] Use strong API keys (32+ chars)
- [ ] Enable SSL/TLS for all sites
- [ ] Set up HTTPS for dashboard (reverse proxy)
- [ ] Restrict dashboard port access (firewall)
- [ ] Enable Fail2Ban
- [ ] Set ModSecurity paranoia level 2+
- [ ] Configure proper rate limits
- [ ] Set up backup system
- [ ] Monitor security events daily
- [ ] Keep OWASP CRS updated
- [ ] Review banned IPs weekly
- [ ] Test disaster recovery

### Firewall Rules (iptables)

```bash
# Allow only necessary ports
iptables -A INPUT -p tcp --dport 80 -j ACCEPT
iptables -A INPUT -p tcp --dport 443 -j ACCEPT

# Restrict dashboard to specific IPs
iptables -A INPUT -p tcp --dport 8080 -s YOUR_IP -j ACCEPT
iptables -A INPUT -p tcp --dport 8080 -j DROP
```

If you already have pre-existing rules, adjust accordingly.
NAT -> change destination address and ports as needed to point to your WAF server.
Public IP direct -> Firewall rules or change DNS. you probably know what you're doing.

### Reverse Proxy for Dashboard

Nginx reverse proxy with HTTPS:

```nginx
server {
    listen 443 ssl http2;
    server_name waf-dashboard.example.com;
    
    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;
    
    location / {
        proxy_pass http://localhost:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

---

## 📚 Additional Resources

### Official Documentation
- [ModSecurity Handbook](https://www.modsecurity.org/documentation.html)
- [OWASP CRS Documentation](https://coreruleset.org/docs/)
- [NGINX Documentation](https://nginx.org/en/docs/)
- [Fail2Ban Manual](https://www.fail2ban.org/wiki/index.php/Main_Page)

### Configuration Guides
- See `FEATURES.md` for complete feature list
- See `README.md` for project overview
- Check `docker-compose.yml` for service configuration
- Review `.env.example` for all environment options

### Getting Help

1. Check logs: `docker-compose logs -f`
2. Test configuration: `docker exec waf-nginx nginx -t`
3. Review dashboard error messages
4. Check database for data issues
5. Verify network connectivity

---

## 🎉 Next Steps

After completing setup:

1. **Add Your Sites**
   - Start with snakeoil certificates for testing
   - Switch to Let's Encrypt for production

2. **Configure Security**
   - Review ModSecurity paranoia level
   - Set up rate limits appropriate for your traffic
   - Enable bot protection

3. **Monitor Activity**
   - Check dashboard daily
   - Review security events
   - Watch for false positives

4. **Optimize Performance**
   - Enable caching for static content
   - Tune rate limits based on actual traffic
   - Archive old logs

5. **Set Up Alerts**
   - Configure email notifications (coming soon)
   - Set up monitoring checks
   - Create backup schedule

---

Made with 💖 by catboys 🐱✨

*Welcome to the purr-fect WAF! 🛡️*
