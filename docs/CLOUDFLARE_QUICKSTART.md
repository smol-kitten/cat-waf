# Cloudflare Zone Auto-Detection - Quick Start

## What It Does
Automatically queries Cloudflare API to detect and populate zone IDs for all your sites. This prepares your sites for Cloudflare-specific features like rate limiting bypass, cache management, etc.

## Setup (2 minutes)

### 1. Get Cloudflare API Token
```
1. Visit: https://dash.cloudflare.com/profile/api-tokens
2. Click "Create Token"
3. Select "Read all resources" template
   OR create custom with: Zone → Zone → Read permission
4. Copy the token
```

### 2. Configure Environment
Add to your `.env` file:
```bash
CLOUDFLARE_API_TOKEN=your_token_here
```

### 3. Restart Dashboard
```bash
docker compose up -d dashboard
```

## Usage

### From Dashboard UI
1. Go to **Sites** page
2. Click **Detect CF Zones** button (☁️)
3. Review results in console and toast notifications

### From Browser Console
```javascript
// Detect all sites without zone IDs
detectCloudflareZones()

// Force re-detect all sites (even with existing zone IDs)
detectCloudflareZones(null, true)

// Detect specific site by ID
detectCloudflareZones(123)
```

## Results
- ✅ **Green Toast**: All zones detected successfully
- ⚠️ **Orange Toast**: Some zones not found (domain not in CF account)
- ❌ **Red Toast**: API error (check credentials)

Detailed results appear in browser console with zone IDs.

## What Gets Updated
- Database column: `sites.cf_zone_id`
- Sites list automatically refreshes after detection
- Zone IDs stored for future use (cache purging, firewall rules, etc.)

## Troubleshooting

**"Cloudflare credentials not configured"**
- Add `CLOUDFLARE_API_TOKEN` to docker-compose.yml environment
- Restart: `docker compose up -d dashboard`

**"Zone not found"**
- Domain must exist in your Cloudflare account
- System auto-converts `*.example.com` → `example.com`
- Check API token has Zone:Read permission

**No button visible?**
- Clear browser cache: Ctrl+F5
- Verify web-dashboard container restarted
- Check browser console for JavaScript errors

## Technical Details

**Endpoint**: `/api/endpoints/cloudflare-zone-detect.php`

**Logic**:
1. Gets all enabled sites without zone IDs (or all if force=1)
2. Extracts root domain (handles wildcards, subdomains, multi-part TLDs)
3. Queries Cloudflare API: `GET /zones?name={domain}`
4. Updates database with zone ID
5. Returns summary with detected/failed counts

**Domain Extraction Examples**:
- `*.catboy.systems` → `catboy.systems`
- `api.example.com` → `example.com`
- `site.co.uk` → `site.co.uk` (multi-part TLD preserved)

**Response Format**:
```json
{
  "success": true,
  "detected": 5,
  "failed": 0,
  "sites": [
    {
      "domain": "*.example.com",
      "root_domain": "example.com",
      "zone_id": "abc123def456...",
      "status": "detected",
      "previous_zone_id": null
    }
  ]
}
```

## Future Features
- Automatic cache purge on config changes
- Cloudflare Firewall Rules sync
- SSL/TLS mode detection
- Rate limit analytics from CF
