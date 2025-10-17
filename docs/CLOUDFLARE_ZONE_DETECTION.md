# Cloudflare Zone Auto-Detection Setup

## Overview
The WAF can automatically detect and populate Cloudflare zone IDs for your sites using the Cloudflare API. This is useful for:
- Automatic configuration of Cloudflare-specific features
- Cache purging integration (future feature)
- Cloudflare rate limiting bypass

## Setup Instructions

### 1. Create Cloudflare API Token (Recommended)

1. Go to [Cloudflare Dashboard](https://dash.cloudflare.com/profile/api-tokens)
2. Click **Create Token**
3. Use the **Read all resources** template or create custom with:
   - **Permissions**: Zone → Zone → Read
   - **Zone Resources**: Include → All zones (or specific zones)
4. Copy the generated token

### 2. Configure Environment Variable

Add to your `.env` file or `docker-compose.yml`:

```bash
CLOUDFLARE_API_TOKEN=your_token_here
```

**OR** use the legacy method with Global API Key:

```bash
CLOUDFLARE_API_KEY=your_global_api_key
CLOUDFLARE_EMAIL=your@email.com
```

### 3. Restart Dashboard Service

```bash
docker compose up -d dashboard
```

## Usage

### Auto-Detect All Sites

1. Go to the **Sites** page in the dashboard
2. Click the **Detect CF Zones** button (☁️)
3. The system will:
   - Query Cloudflare API for each site's root domain
   - Update the `cf_zone_id` column automatically
   - Skip sites that already have a zone ID (unless forced)
   - Show results summary

### Force Re-Detection

To re-detect zones for sites that already have zone IDs:

```javascript
// In browser console
detectCloudflareZones(null, true)
```

### Detect Single Site

```javascript
// In browser console, replace 123 with site ID
detectCloudflareZones(123)
```

## How It Works

1. **Domain Extraction**: The system extracts the root domain from your site configuration:
   - `*.example.com` → `example.com`
   - `sub.example.com` → `example.com`
   - `example.co.uk` → `example.co.uk` (handles multi-part TLDs)

2. **API Query**: Queries Cloudflare's `/zones?name=example.com` endpoint

3. **Zone ID Storage**: Updates the `cf_zone_id` column in the database

4. **Results**: Shows success/failure for each domain

## Troubleshooting

### "Cloudflare credentials not configured"
- Ensure `CLOUDFLARE_API_TOKEN` is set in docker-compose.yml
- Restart the dashboard service after adding credentials

### "Zone not found"
- The domain must exist in your Cloudflare account
- Wildcards and subdomains are automatically converted to root domains
- Check that your API token has permission to read the zone

### API Token vs API Key

**API Token (Recommended)**:
- ✅ More secure (scoped permissions)
- ✅ Can be restricted to specific zones
- ✅ Can be revoked without affecting other services

**Global API Key (Legacy)**:
- ⚠️ Full account access
- ⚠️ Requires email + key
- ❌ Less secure

## API Endpoint

**GET** `/api/endpoints/cloudflare-zone-detect.php`

**Query Parameters**:
- `site_id` (optional): Detect zone for specific site ID
- `force` (optional): Re-detect even if zone ID exists (value: `1`)

**Authentication**: Bearer token in `Authorization` header

**Response**:
```json
{
  "success": true,
  "detected": 5,
  "failed": 1,
  "sites": [
    {
      "domain": "example.com",
      "root_domain": "example.com",
      "zone_id": "abc123...",
      "status": "detected"
    },
    {
      "domain": "*.another.com",
      "root_domain": "another.com",
      "zone_id": null,
      "status": "not_found",
      "message": "Zone not found in Cloudflare account"
    }
  ]
}
```

## Future Enhancements

- Automatic cache purge when site config changes
- Cloudflare Firewall Rules management
- Automatic SSL/TLS mode detection
- Real visitor IP restoration from CF-Connecting-IP
