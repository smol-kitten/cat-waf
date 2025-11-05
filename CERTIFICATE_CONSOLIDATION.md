# Certificate Consolidation Implementation

## Overview
All SSL/TLS certificates are now issued as **base domain + wildcard** pairs to prevent certificate proliferation and avoid Let's Encrypt rate limits.

## What Changed

### Before
- Each subdomain (e.g., `api.example.com`, `www.example.com`, `app.example.com`) received its own individual certificate
- This resulted in dozens of separate certificates per domain
- Hit Let's Encrypt rate limits (50 certificates per registered domain per week)
- Increased certificate management overhead

### After
- **Single certificate per base domain** covering `example.com` AND `*.example.com`
- All subdomains automatically covered by the wildcard
- Dramatically reduced number of certificates needed
- Prevents Let's Encrypt rate limit issues

## Technical Implementation

### 1. Base Domain Extraction
New function `extractRootDomain($domain)` extracts the base domain from any subdomain:
- `subdomain.example.com` → `example.com`
- `api.staging.example.co.uk` → `example.co.uk`
- Handles special TLDs: `.co.uk`, `.com.au`, `.co.nz`, etc.

### 2. Certificate Issuance
**Files Modified:**
- `dashboard/src/endpoints/certificates.php` - Main certificate API
- `dashboard/src/endpoints/certificate-issuer.php` - Background issuer

**Changes:**
```php
// OLD: Single domain certificate
acme.sh --issue -d subdomain.example.com

// NEW: Base domain + wildcard certificate
acme.sh --issue -d example.com -d *.example.com
```

**Certificate Storage:**
- Stored at: `/acme.sh/example.com/` (not `/acme.sh/subdomain.example.com/`)
- All subdomains reference the same base domain certificate

### 3. Symlink Strategy
**For subdomains:**
```bash
/etc/nginx/certs/subdomain.example.com/fullchain.pem → /acme.sh/example.com/fullchain.pem
/etc/nginx/certs/subdomain.example.com/key.pem → /acme.sh/example.com/key.pem
```

**For base domain:**
```bash
/etc/nginx/certs/example.com/fullchain.pem → /acme.sh/example.com/fullchain.pem
/etc/nginx/certs/example.com/key.pem → /acme.sh/example.com/key.pem
```

### 4. DNS-01 Challenge Required
**Why DNS-01 is mandatory:**
- Wildcard certificates (`*.example.com`) **cannot** be issued using HTTP-01 challenge
- DNS-01 is the only ACME challenge type that supports wildcards
- Requires Cloudflare API token configuration

**HTTP-01 Removed:**
- All HTTP-01 challenge code paths now return error messages
- Guides users to configure DNS-01 (Cloudflare) in site settings

### 5. Certificate Renewal
**renewAllCertificates() improvements:**
- Tracks `$processedBaseDomains` to avoid duplicate renewals
- If `api.example.com` and `www.example.com` both exist, only renews `example.com` once
- All subdomains automatically covered by the renewed wildcard certificate

**Example output:**
```json
{
  "success": true,
  "message": "Processed 3 base domain certificates (covering multiple subdomains), 0 failed",
  "total": 12,
  "processed_base_domains": 3,
  "results": [
    {
      "domain": "api.example.com",
      "base_domain": "example.com",
      "certificate_covers": ["example.com", "*.example.com"],
      "status": "success",
      "action": "renewed"
    },
    {
      "domain": "www.example.com",
      "status": "skipped",
      "reason": "Already processed as part of example.com wildcard certificate"
    }
  ]
}
```

## Migration Guide

### Existing Sites
1. **No immediate action required** - existing individual certificates will continue working
2. On next renewal, certificate will be consolidated to base + wildcard
3. Subdomains will be symlinked to the base domain certificate

### New Sites
1. **Configure Cloudflare API Token** in site settings
2. Set SSL Challenge Type to **"dns-01"**
3. Certificate will be issued as base + wildcard automatically

### Rate Limit Recovery
If you've hit Let's Encrypt rate limits:
1. Wait for the weekly rate limit window to reset
2. Update all sites to use DNS-01 challenge
3. Manually trigger certificate renewal for base domains only
4. All subdomains will be covered by the wildcard certificate

## Benefits

### 1. Prevents Certificate Proliferation
**Before:** 20 subdomains = 20 certificates  
**After:** 20 subdomains = 1 certificate (covering base + wildcard)

### 2. Avoids Rate Limits
Let's Encrypt limits:
- 50 certificates per registered domain per week
- With consolidation, you'll only issue 1 certificate per base domain

### 3. Simplified Management
- Single certificate expiry to track per base domain
- Fewer renewal operations
- Less storage space for certificates

### 4. Automatic Subdomain Coverage
- Add new subdomain? Already covered by existing wildcard cert
- No need to issue new certificate for every subdomain

## API Response Changes

### Certificate Issuance
```json
{
  "success": true,
  "message": "Certificate issued successfully for api.example.com",
  "base_domain": "example.com",
  "certificate_covers": ["example.com", "*.example.com"],
  "challenge_method": "dns-01"
}
```

### Certificate Renewal
```json
{
  "success": true,
  "message": "Certificate renewed successfully for www.example.com",
  "base_domain": "example.com",
  "certificate_covers": ["example.com", "*.example.com"]
}
```

## Error Messages

### HTTP-01 Not Supported
```json
{
  "error": "HTTP-01 challenge not supported for wildcard certificates",
  "hint": "All certificates now use base domain + wildcard to prevent certificate proliferation. Please set SSL Challenge Type to \"dns-01\" (Cloudflare) in site settings.",
  "base_domain": "example.com",
  "certificate_will_cover": ["example.com", "*.example.com"]
}
```

### Missing Cloudflare Token
```json
{
  "error": "Cloudflare API token not configured. DNS-01 challenge required for wildcard certificates."
}
```

## Cloudflare Setup

### Requirements
1. Domain DNS managed by Cloudflare
2. Cloudflare API Token with DNS edit permissions
3. Optional: Zone ID for faster DNS propagation

### Configuration
1. Go to site settings in dashboard
2. Set **SSL Challenge Type**: `dns-01`
3. Enter **Cloudflare API Token**
4. Optional: Enter **Cloudflare Zone ID**

### API Token Permissions
Create token with:
- **Zone** → **DNS** → **Edit** permission
- Include specific zone or all zones

## Troubleshooting

### "Certificate not found" errors
- Run certificate renewal: `POST /api/certificates/{domain}/renew`
- System will automatically issue base domain + wildcard certificate

### Subdomain not covered
- Check base domain certificate exists
- Verify symlink: `docker exec waf-nginx ls -la /etc/nginx/certs/{subdomain}/`
- Should point to: `/acme.sh/{basedomain}/fullchain.pem`

### Rate limit errors
- Wait for weekly rate limit reset
- Consolidation prevents future rate limit issues
- Only one certificate needed per base domain

## Implementation Files

### Modified Files
1. `dashboard/src/endpoints/certificates.php`
   - Added `extractRootDomain()` function
   - Updated `issueCertificate()` to use base + wildcard
   - Updated `renewCertificate()` to use base + wildcard
   - Updated `renewAllCertificates()` to deduplicate base domains
   - Modified symlink creation logic

2. `dashboard/src/endpoints/certificate-issuer.php`
   - Added `extractRootDomain()` function
   - Updated certificate issuance to use base + wildcard
   - Updated symlink creation for subdomains
   - Removed HTTP-01 support

### Key Functions
- `extractRootDomain($domain)` - Extracts base domain from subdomain
- `issueCertificate($domain)` - Issues base domain + wildcard cert
- `renewCertificate($domain)` - Renews base domain + wildcard cert
- `renewAllCertificates()` - Batch renewal with deduplication

## Testing

### Test Certificate Issuance
```bash
# Issue certificate for subdomain
POST /api/certificates/api.example.com

# Expected result:
# - Certificate issued for example.com + *.example.com
# - Symlink created: api.example.com → example.com cert
```

### Test Multiple Subdomains
```bash
# Add multiple subdomains
POST /api/sites (domain: api.example.com)
POST /api/sites (domain: www.example.com)
POST /api/sites (domain: app.example.com)

# Issue certificates
POST /api/certificates/api.example.com

# Expected result:
# - Single certificate for example.com + *.example.com
# - All 3 subdomains use same certificate via symlinks
```

### Verify Certificate Coverage
```bash
# Check certificate SAN (Subject Alternative Names)
docker exec waf-nginx openssl x509 -in /acme.sh/example.com/fullchain.pem -noout -text | grep -A1 "Subject Alternative Name"

# Expected output:
# DNS:example.com, DNS:*.example.com
```

## Summary

This implementation consolidates all certificates to base domain + wildcard pairs, preventing:
- ✅ Certificate proliferation (dozens of individual certs)
- ✅ Let's Encrypt rate limits (50 certs/week per domain)
- ✅ Certificate management overhead
- ✅ Unnecessary API calls to Let's Encrypt

All subdomains are automatically covered by a single wildcard certificate, dramatically reducing certificate count and preventing rate limit issues.
