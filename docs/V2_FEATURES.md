# CatWAF v2.0 - Overhaul Features Documentation

## Overview
CatWAF v2.0 introduces major improvements across the stack including multi-provider certificate support, advanced insights analytics, configurable alert rules, webhook notifications, and planning for multi-tenant architecture.

---

## 🔐 ZeroSSL ACME Support

### What's New
CatWAF now supports both Let's Encrypt and ZeroSSL as ACME certificate providers, giving you more options for SSL/TLS certificate management.

### Configuration

#### Environment Variables
Add to your `.env` file:
```bash
ACME_EMAIL=admin@yourdomain.com
ACME_PROVIDER=letsencrypt  # Options: letsencrypt, zerossl
ZEROSSL_API_KEY=your_zerossl_api_key_here  # Required if using ZeroSSL
```

#### Per-Site Configuration
You can configure the ACME provider per site in the database:
```sql
UPDATE sites SET acme_provider = 'zerossl' WHERE domain = 'example.com';
```

### Getting a ZeroSSL API Key
1. Sign up at [https://zerossl.com](https://zerossl.com)
2. Navigate to Developer → API Access
3. Generate a new API key
4. Add it to your `.env` file as `ZEROSSL_API_KEY`

### Certificate Issuance
Certificates are issued automatically when:
- A new site is added with SSL enabled
- An existing certificate expires within 30 days
- Manual renewal is triggered

The system will:
1. Check which ACME provider is configured
2. Use the appropriate credentials (CF_API_KEY for DNS-01, ZEROSSL_API_KEY for ZeroSSL)
3. Issue certificate for base domain + wildcard
4. Install certificate to NGINX

---

## 💡 Insights Portal

### Overview
The new Insights portal replaces the legacy telemetry system with a modern, privacy-focused analytics platform offering two levels of data collection:

- **Basic Level**: Request counts, response times, status codes (enabled by default)
- **Extended Level**: Core Web Vitals (LCP, FCP, TTFB, CLS, FID) - opt-in only

### Configuration

#### Enable Insights
Navigate to **Insights** page in the dashboard:

1. Toggle "Enable Insights Collection"
2. Select insight level:
   - **Basic**: Lightweight metrics, no JavaScript required
   - **Extended**: Requires JavaScript integration for Web Vitals

#### Extended Insights Setup
For extended insights with Web Vitals, add to your website:

```html
<script>
// Web Vitals reporting to CatWAF
import {onLCP, onFCP, onTTFB, onCLS, onFID} from 'web-vitals';

function sendToAnalytics({name, value, id}) {
  fetch('https://your-waf.com/api/insights/vitals', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      domain: window.location.hostname,
      path: window.location.pathname,
      [name.toLowerCase()]: value / 1000, // Convert to seconds
      device_type: /mobile/i.test(navigator.userAgent) ? 'mobile' : 'desktop'
    })
  });
}

onLCP(sendToAnalytics);
onFCP(sendToAnalytics);
onTTFB(sendToAnalytics);
onCLS(sendToAnalytics);
onFID(sendToAnalytics);
</script>
```

### API Endpoints

#### Get Insights Configuration
```http
GET /api/insights
Authorization: Bearer <token>
```

#### Update Insights Configuration
```http
POST /api/insights/config
Authorization: Bearer <token>
Content-Type: application/json

{
  "enabled": true,
  "level": "extended",
  "collect_web_vitals": true,
  "collect_user_agent": true,
  "collect_referrer": true,
  "retention_days": 30
}
```

#### Get Basic Insights
```http
GET /api/insights/basic?range=24h
Authorization: Bearer <token>
```

Response:
```json
{
  "basic_stats": {
    "total_requests": 15420,
    "unique_visitors": 892,
    "unique_domains": 5,
    "avg_response_time": 0.145
  },
  "status_codes": {
    "status_2xx": 14200,
    "status_3xx": 980,
    "status_4xx": 200,
    "status_5xx": 40
  },
  "top_paths": [
    {
      "request_uri": "/api/data",
      "count": 3420,
      "avg_time": 0.089
    }
  ]
}
```

#### Get Extended Insights
```http
GET /api/insights/extended?range=24h
Authorization: Bearer <token>
```

Response:
```json
{
  "vitals": {
    "avg_lcp": 1.245,
    "avg_fcp": 0.789,
    "avg_ttfb": 0.234,
    "avg_cls": 0.045,
    "avg_fid": 12.3,
    "sample_count": 1523
  },
  "device_breakdown": [
    {
      "device_type": "desktop",
      "count": 892,
      "avg_lcp": 1.123
    },
    {
      "device_type": "mobile",
      "count": 631,
      "avg_lcp": 1.456
    }
  ],
  "slowest_pages": [
    {
      "domain": "example.com",
      "path": "/heavy-page",
      "avg_lcp": 3.456,
      "count": 45
    }
  ]
}
```

### Data Retention
- Configurable retention period (default: 30 days)
- Automatic cleanup of old data
- Per-site configuration supported

---

## 🔔 Configurable Alert Rules

### Overview
Set up automated alerts that monitor your infrastructure and notify you when issues occur. Supports multiple alert types with customizable thresholds.

### Alert Types

#### 1. High Response Time
Alerts when average response time exceeds threshold.

**Configuration:**
```json
{
  "threshold_ms": 3000,
  "duration_minutes": 5,
  "min_requests": 10
}
```

#### 2. Certificate Expiring Soon
Alerts when SSL certificates approach expiration.

**Configuration:**
```json
{
  "warning_days": 30,
  "critical_days": 7
}
```

#### 3. Backend Server Down
Alerts when backend servers become unreachable.

**Configuration:**
```json
{
  "check_interval_seconds": 300
}
```

#### 4. High Error Rate
Alerts when 5xx error rate exceeds threshold.

**Configuration:**
```json
{
  "threshold_percent": 10,
  "duration_minutes": 5,
  "min_requests": 20
}
```

#### 5. Rate Limit Breach
Alerts when rate limiting blocks exceed threshold.

**Configuration:**
```json
{
  "threshold_blocks": 100,
  "duration_minutes": 5
}
```

### API Endpoints

#### List Alert Rules
```http
GET /api/alerts
Authorization: Bearer <token>
```

#### Create Alert Rule
```http
POST /api/alerts
Authorization: Bearer <token>
Content-Type: application/json

{
  "rule_name": "High Response Time - Production",
  "rule_type": "delay",
  "enabled": true,
  "site_id": null,
  "config": {
    "threshold_ms": 3000,
    "duration_minutes": 5,
    "min_requests": 10
  }
}
```

#### Update Alert Rule
```http
PUT /api/alerts/:id
Authorization: Bearer <token>
Content-Type: application/json

{
  "enabled": false
}
```

#### Get Alert History
```http
GET /api/alerts/history?limit=50
Authorization: Bearer <token>
```

#### Acknowledge Alert
```http
POST /api/alerts/history/:id/acknowledge
Authorization: Bearer <token>
Content-Type: application/json

{
  "acknowledged_by": "admin"
}
```

### Alert Monitoring Service

The alert monitoring service runs periodically to check all enabled alert rules and fire notifications when conditions are met.

#### Setup Cron Job
Add to your crontab to run every 5 minutes:
```bash
*/5 * * * * docker exec waf-dashboard php /dashboard/src/alert-monitor.php >> /var/log/alert-monitor.log 2>&1
```

#### Manual Execution
```bash
docker exec waf-dashboard php /dashboard/src/alert-monitor.php
```

#### Features
- Checks all enabled alert rules
- Prevents spam with 1-hour cooldown per alert
- Records all fired alerts in history
- Sends notifications via configured channels
- Logs all activity for debugging

---

## 🌐 Webhook Notifications (Discord, Slack)

### Overview
Send real-time alerts to Discord, Slack, or any webhook-compatible service. Rich embeds with color-coding and detailed information.

### Configuration

#### Discord Setup
1. Open Discord Server Settings
2. Navigate to Integrations → Webhooks
3. Click "New Webhook"
4. Customize name and channel
5. Copy webhook URL
6. Add to CatWAF Settings → Notifications

#### Settings UI
Navigate to **Settings → Notifications**:

1. Enable webhook notifications
2. Paste Discord webhook URL
3. Select which events trigger notifications:
   - Critical Security Events
   - IP Auto-Bans
   - Certificate Expiring Soon
   - Backend Server Down
   - High Response Time
   - Rate Limit Breaches

#### Example Discord Message
```
🚨 Critical Security Event Detected

Time: 2026-02-06 15:30:42
IP Address: 192.168.1.100
Domain: example.com
URI: /admin/login
Rule ID: 920350
Severity: CRITICAL
Message: SQL Injection Attack Detected
Action: BLOCKED
```

### Webhook Integration

#### Using WebhookNotifier Class
```php
require_once 'lib/WebhookNotifier.php';

$notifier = new WebhookNotifier($db);

// Send critical security alert
$notifier->sendCriticalSecurityAlert([
    'timestamp' => date('Y-m-d H:i:s'),
    'ip_address' => '192.168.1.100',
    'domain' => 'example.com',
    'uri' => '/admin/login',
    'rule_id' => '920350',
    'severity' => 'CRITICAL',
    'message' => 'SQL Injection Attack Detected',
    'action' => 'BLOCKED'
]);

// Send certificate expiry alert
$notifier->sendCertExpiryAlert('example.com', 7);

// Send server down alert
$notifier->sendServerDownAlert('http://backend1:8080', 'example.com');

// Send high delay alert
$notifier->sendHighDelayAlert('example.com', 3.5, 3.0);
```

### Supported Services

#### Discord
- Rich embeds with colors
- Inline fields for structured data
- Timestamp support
- Custom avatar and username

#### Slack (Coming Soon)
- Block kit layouts
- Color-coded messages
- Action buttons

#### Generic Webhooks
Any service that accepts JSON POST requests can be integrated.

---

## 📧 Email Notifications

### Configuration
Navigate to **Settings → Notifications**:

1. Enable email alerts
2. Configure SMTP settings:
   - SMTP Server (e.g., smtp.gmail.com)
   - SMTP Port (e.g., 587)
   - SMTP Username
   - SMTP Password
   - From Address
   - To Address(es)

### Gmail Configuration
1. Enable 2-factor authentication
2. Generate app-specific password
3. Use app password in SMTP settings

### Email Templates
- HTML formatted with inline CSS
- Color-coded by severity
- Detailed event information
- Professional layout

---

## 🏢 Multi-Tenant System (Planned)

### Overview
Future support for multiple organizations sharing a single CatWAF instance with complete data isolation.

### Features (Planned)
- Tenant isolation at database level
- Separate user authentication per tenant
- Role-based access control (Admin, User, Viewer)
- Per-tenant resource quotas
- Tenant-specific branding
- Master admin dashboard

### Implementation Timeline
See [MULTI_TENANT_PLAN.md](MULTI_TENANT_PLAN.md) for detailed architecture and implementation plan.

Estimated timeline: 8 weeks
- Phase 1: Database Schema (2 weeks)
- Phase 2: Authentication & API (2 weeks)
- Phase 3: UI Updates (2 weeks)
- Phase 4: Testing & Documentation (2 weeks)

---

## 🎨 UI Improvements

### Navigation Reorganization
- **Security Section**: Security Events, Security Center, ModSecurity, Bot Protection, IP Bans
- **Monitoring Section**: Insights, Alerts, Logs
- **System Section**: Settings

### New Pages
- **Insights**: Modern analytics dashboard with basic and extended metrics
- **Alerts**: Configure and manage alert rules with history tracking

### Updated Version
Dashboard now displays v2.0.0 with all new features integrated.

---

## 🚀 Getting Started with v2.0

### Upgrade from v1.x

1. **Pull latest changes:**
   ```bash
   git pull origin main
   ```

2. **Update environment:**
   ```bash
   # Add to .env if using ZeroSSL
   ACME_PROVIDER=letsencrypt
   ZEROSSL_API_KEY=
   ```

3. **Restart services:**
   ```bash
   docker-compose down
   docker-compose up -d
   ```

4. **Database migrations run automatically on startup**

5. **Configure new features:**
   - Visit Settings → Notifications to set up webhooks
   - Visit Insights page to configure analytics level
   - Visit Alerts page to set up monitoring rules

### Fresh Installation

Follow standard installation guide, all new features are enabled by default with sensible defaults.

---

## 📊 Metrics & Performance

### Insights System
- Minimal performance impact
- Basic insights: <1ms per request
- Extended insights: Requires client-side JavaScript
- Configurable retention (default 30 days)

### Alert System
- Runs every 5 minutes via cron
- 1-hour cooldown prevents alert spam
- Efficient database queries
- Minimal resource usage

### Webhook Notifications
- Async delivery (non-blocking)
- 5-second timeout per webhook
- Retry logic for failed deliveries
- Rate limiting to prevent abuse

---

## 🔒 Security Considerations

### Insights Data
- No PII collection by default
- IP addresses anonymized in extended insights
- GDPR-compliant data retention
- Per-site opt-in for extended metrics

### Webhook Security
- HTTPS required for webhook URLs
- Secret validation support (coming soon)
- Rate limiting on webhook endpoints
- Webhook URL validation

### Alert System
- Read-only database access for monitoring
- Alert cooldown prevents abuse
- Authenticated API access only
- Audit log for all alert activity

---

## 📝 API Reference

See individual sections above for detailed API documentation:
- [Insights API](#api-endpoints)
- [Alerts API](#api-endpoints-1)

---

## 🐛 Troubleshooting

### Insights Not Collecting
1. Check insights configuration is enabled
2. Verify retention period hasn't expired
3. Check database disk space
4. Review dashboard logs: `docker logs waf-dashboard`

### Alerts Not Firing
1. Verify alert monitoring cron is running
2. Check alert rule is enabled
3. Review alert monitor logs: `/var/log/alert-monitor.log`
4. Verify threshold conditions are being met

### Webhooks Not Working
1. Test webhook URL manually with curl
2. Verify webhook is enabled in settings
3. Check network connectivity from container
4. Review dashboard logs for webhook errors

### ZeroSSL Certificates Failing
1. Verify ZEROSSL_API_KEY is set correctly
2. Check API key has not expired
3. Verify DNS-01 challenge is configured
4. Review certificate logs: `docker logs waf-acme`

---

## 📚 Additional Resources

- [Main README](../README.md)
- [Multi-Tenant Plan](MULTI_TENANT_PLAN.md)
- [Setup Guide](../_git/SETUP.md)
- [Quick Reference](../_git/QUICKREF.md)

---

## 🎉 What's Next?

### v2.1 (Planned)
- Alert rule templates
- Custom alert rule builder UI
- Slack notification support
- Advanced insights filtering
- Performance benchmarking dashboard

### v3.0 (Future)
- Multi-tenant architecture
- White-label support
- Marketplace for integrations
- Advanced ML-based anomaly detection
- Geographic traffic routing

---

**Questions or feedback?** Open an issue on GitHub!
