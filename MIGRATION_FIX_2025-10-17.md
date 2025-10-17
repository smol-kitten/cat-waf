# Migration Fix Summary - 2025-10-17

## Issues Fixed

### 1. ✅ Schema Missing New Columns
**Problem:** `01-complete-schema.sql` didn't include the 4 new columns added in session:
- `disable_http_redirect`
- `cf_bypass_ratelimit`
- `cf_custom_rate_limit`
- `cf_rate_limit_burst`

**Fix:** Updated `01-complete-schema.sql` to include all columns so fresh installs work correctly.

### 2. ✅ Migrations Don't Run on Existing Databases
**Problem:** MariaDB only runs init scripts on empty data volumes. Existing production databases won't get new columns automatically.

**Fix:** 
- Created migration guide: `mariadb/init/MIGRATIONS.md`
- Created helper scripts:
  - `scripts/apply-migrations.sh` (Linux/Mac)
  - `scripts/apply-migrations.ps1` (Windows)
- Migration uses `IF NOT EXISTS` for safety

### 3. ✅ Cloudflare Token Redundancy
**Problem:** Multiple overlapping CF variables:
- `CF_API_KEY` + `CF_EMAIL` (for ACME DNS-01)
- `CLOUDFLARE_API_KEY` + `CLOUDFLARE_EMAIL` (duplicate)
- `CLOUDFLARE_API_TOKEN` (new for zone detection)

**Fix:** Simplified to:
- `CF_API_KEY` + `CF_EMAIL` - Used for both ACME DNS-01 AND zone detection fallback
- `CLOUDFLARE_API_TOKEN` - Dedicated token for zone detection (recommended)

## How to Apply Migration to Existing Database

### Windows (PowerShell)
```powershell
.\scripts\apply-migrations.ps1
```

### Linux/Mac (Bash)
```bash
chmod +x scripts/apply-migrations.sh
./scripts/apply-migrations.sh
```

### Manual
```bash
docker exec -i waf-mariadb mariadb -u root -p waf_db < mariadb/init/06-migration-redirect-cf-ratelimit.sql
```

## Verification

Check if columns exist:
```sql
docker exec waf-mariadb mariadb -u root -p waf_db -e "
SELECT COLUMN_NAME FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'waf_db' AND TABLE_NAME = 'sites' 
AND COLUMN_NAME IN ('disable_http_redirect', 'cf_bypass_ratelimit', 'cf_custom_rate_limit', 'cf_rate_limit_burst');
"
```

Expected: 4 rows if migration applied, 0 rows if not.

## Environment Variables After Cleanup

### .env file
```bash
# Cloudflare for ACME DNS-01 challenge + zone detection fallback
CF_API_KEY=your_global_api_key
CF_EMAIL=your@email.com

# Cloudflare zone detection (recommended - more secure)
CLOUDFLARE_API_TOKEN=your_scoped_token
```

### docker-compose.yml (dashboard service)
```yaml
environment:
  # These map to CF_API_KEY and CF_EMAIL (shared with ACME)
  CLOUDFLARE_API_KEY: ${CF_API_KEY:-}
  CLOUDFLARE_EMAIL: ${CF_EMAIL:-}
  # This is dedicated for zone detection
  CLOUDFLARE_API_TOKEN: ${CLOUDFLARE_API_TOKEN:-}
```

## What Changed

### Files Modified
1. ✅ `mariadb/init/01-complete-schema.sql` - Added 4 columns to sites table
2. ✅ `mariadb/init/06-migration-redirect-cf-ratelimit.sql` - Improved documentation
3. ✅ `docker-compose.yml` - Simplified CF environment variables
4. ✅ `.env.example` - Cleaned up redundant CF variables

### Files Created
1. 📄 `mariadb/init/MIGRATIONS.md` - Complete migration guide
2. 📄 `scripts/apply-migrations.sh` - Linux/Mac migration helper
3. 📄 `scripts/apply-migrations.ps1` - Windows migration helper

## For Fresh Installs

Everything works automatically! The schema includes all columns.

```bash
docker compose down
docker volume rm waf_db-data  # ⚠️ DELETES ALL DATA
docker compose up -d
```

## For Existing Production

Run migration script to add new columns without losing data:

```powershell
# Windows
.\scripts\apply-migrations.ps1
```

```bash
# Linux/Mac
./scripts/apply-migrations.sh
```

## Testing

✅ Fresh install: Columns present in schema
✅ Existing DB: Migration adds columns safely with `IF NOT EXISTS`
✅ Re-run migration: No errors (idempotent)
✅ CF tokens: No redundancy, clear purpose
