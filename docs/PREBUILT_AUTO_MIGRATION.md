# Prebuilt Deployment - Auto Migration System

## Overview

The prebuilt deployment (`docker-compose.prebuilt.yml`) now includes **automatic database migration** that works for both fresh installs and existing databases.

## How It Works

### Service Chain

```
mariadb-init (downloads) → mariadb (starts) → migration-runner (applies) → dashboard (starts)
```

1. **mariadb-init** - Downloads schema and migrations from GitHub
   - `01-complete-schema.sql` - Complete database schema
   - `06-migration-redirect-cf-ratelimit.sql` - Latest migrations
   - Files saved to shared volume

2. **mariadb** - Database starts
   - Uses init scripts for **fresh installs only**
   - Existing data preserved
   - Waits for healthy status

3. **migration-runner** - Applies migrations
   - Runs **after MariaDB is healthy**
   - Applies all `*-migration-*.sql` files
   - Uses `IF NOT EXISTS` for safety
   - Runs once and exits (`restart: "no"`)
   - Verifies column count

4. **dashboard** - API starts
   - Waits for migration-runner completion
   - Database guaranteed to be up-to-date

### Fresh Install Flow

1. mariadb-init downloads files → `/init/` volume
2. MariaDB starts with empty data directory
3. MariaDB auto-runs `/docker-entrypoint-initdb.d/*.sql` (schema + migrations)
4. migration-runner runs but does nothing (columns exist via `IF NOT EXISTS`)
5. Dashboard starts with complete schema

**Result:** Fresh database with all columns ✅

### Existing Database Flow

1. mariadb-init downloads files → `/init/` volume
2. MariaDB starts with existing data
3. MariaDB **skips** init scripts (data exists)
4. **migration-runner applies migrations** ← This is the key!
5. Dashboard starts with updated schema

**Result:** Existing database upgraded with new columns ✅

## What Gets Downloaded

From GitHub repository `smol-kitten/cat-waf`:

- `mariadb/init/01-complete-schema.sql`
  - Complete database structure
  - All tables with current columns
  - Used for fresh installs

- `mariadb/init/06-migration-redirect-cf-ratelimit.sql`
  - Adds: `disable_http_redirect`
  - Adds: `cf_bypass_ratelimit`
  - Adds: `cf_custom_rate_limit`
  - Adds: `cf_rate_limit_burst`
  - Safe to run multiple times (uses `IF NOT EXISTS`)

## Usage

### Deploy with Auto-Migration

```bash
# Fresh install or upgrade
docker compose -f docker-compose.prebuilt.yml up -d
```

That's it! Migrations apply automatically.

### Check Migration Status

```bash
# View migration-runner logs
docker logs waf-migration-runner

# Check column count (should be 62+)
docker exec waf-mariadb mariadb -u waf_user -pchangeme -sN waf_db -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'waf_db' AND TABLE_NAME = 'sites';"

# Verify new columns exist
docker exec waf-mariadb mariadb -u waf_user -pchangeme waf_db -e "SHOW COLUMNS FROM sites;" | grep -E "cf_|disable_http"
```

### Force Re-run Migrations

```bash
# Restart migration-runner
docker compose -f docker-compose.prebuilt.yml up -d migration-runner

# View logs
docker logs -f waf-migration-runner
```

## Migration-Runner Details

**Image:** `mariadb:latest` (includes mariadb client)

**Command:**
1. Wait 5 seconds for MariaDB readiness
2. Loop through all `*-migration-*.sql` files
3. Apply each migration with error handling
4. Verify schema
5. Exit

**Environment:**
- `DB_PASSWORD` - From `.env` or default

**Volumes:**
- `waf-mariadb-init:/migrations:ro` - Read-only access to downloaded migrations

**Dependencies:**
- Waits for MariaDB health check
- Waits for mariadb-init completion

**Restart Policy:** `"no"` - Runs once per deployment

## Adding New Migrations

### 1. Create Migration File
```sql
-- mariadb/init/07-new-feature.sql
USE waf_db;
ALTER TABLE sites ADD COLUMN IF NOT EXISTS new_column VARCHAR(255) DEFAULT NULL;
```

### 2. Update mariadb-init Download Command
```yaml
wget -q https://raw.githubusercontent.com/.../07-new-feature.sql -O /init/07-new-feature.sql &&
```

### 3. Update Schema File
Add column to `01-complete-schema.sql`

### 4. Deploy
```bash
docker compose -f docker-compose.prebuilt.yml up -d
```

Migration applies automatically!

## Troubleshooting

### Migration failed
```bash
# Check logs
docker logs waf-migration-runner

# Common issues:
# - Database password wrong (update .env)
# - Migration has syntax error (check SQL)
# - Network issue downloading files
```

### Columns not added
```bash
# Verify migration downloaded
docker run --rm -v waf-mariadb-init:/init alpine ls -lh /init/

# Should see:
# 01-complete-schema.sql
# 06-migration-redirect-cf-ratelimit.sql

# Manually run migration
docker exec -i waf-mariadb mariadb -u waf_user -pchangeme waf_db < /docker-entrypoint-initdb.d/06-migration-redirect-cf-ratelimit.sql
```

### Migration-runner keeps restarting
```bash
# Check if restart policy was changed
docker inspect waf-migration-runner | grep -A 5 RestartPolicy

# Should show: "Name": ""

# If not, update compose file and recreate:
docker compose -f docker-compose.prebuilt.yml up -d migration-runner
```

## Benefits

✅ **Automatic** - No manual intervention needed
✅ **Safe** - Uses `IF NOT EXISTS` to prevent errors
✅ **Fast** - Downloads latest migrations from GitHub
✅ **Idempotent** - Can run multiple times safely
✅ **Fresh Installs** - Complete schema from start
✅ **Upgrades** - Existing databases get new columns
✅ **Verifiable** - Logs show column count
✅ **Non-blocking** - Runs once and exits

## Architecture

```
┌─────────────────┐
│ mariadb-init    │  Downloads schema + migrations from GitHub
│ (alpine)        │  Saves to: waf-mariadb-init volume
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ mariadb         │  Starts database
│ (mariadb)       │  Fresh: Runs init scripts
│                 │  Existing: Skips init scripts
└────────┬────────┘
         │ (healthy)
         ▼
┌─────────────────┐
│ migration-runner│  Applies migrations
│ (mariadb)       │  Reads from: waf-mariadb-init volume
│                 │  Runs: *-migration-*.sql files
│                 │  Verifies: Column count
└────────┬────────┘
         │ (completed)
         ▼
┌─────────────────┐
│ dashboard       │  API server starts
│ (dashboard)     │  Database guaranteed up-to-date
└─────────────────┘
```

## Comparison: Regular vs Prebuilt

### docker-compose.yml (Regular Build)
- Uses local files from `mariadb/init/`
- Migrations require manual application on existing DBs
- Build from source

### docker-compose.prebuilt.yml (Prebuilt)
- Downloads files from GitHub
- **Migrations apply automatically** ✅
- Uses pre-built images from GHCR

Both support fresh installs, but **prebuilt has automatic upgrades**!
