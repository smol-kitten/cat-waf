# CatWAF v1 to v2 Migration Guide

This guide helps you migrate from CatWAF v1 (PHP + Vanilla JS) to CatWAF v2 (Go + SvelteKit).

## Overview

CatWAF v2 is a complete rewrite with:
- **Backend**: Go with Fiber framework (replacing PHP)
- **Frontend**: SvelteKit with TypeScript (replacing Vanilla JS)
- **Database**: PostgreSQL (with MariaDB compatibility option)
- **Testing**: Full CI/CD pipeline with unit, integration, and E2E tests
- **Deployment**: Kubernetes + Helm support (Docker Compose still available)

## Pre-Migration Checklist

- [ ] Backup your current CatWAF installation
- [ ] Export all sites configuration
- [ ] Note any custom ModSecurity rules
- [ ] Document custom configurations

## Database Migration

### Export v1 Data

```bash
# From v1 installation
docker exec waf-mariadb mysqldump -u root -p waf_db > catwaf_v1_backup.sql
```

### Import to v2 (PostgreSQL)

CatWAF v2 includes a migration tool that converts MariaDB data to PostgreSQL:

```bash
# Run migration tool
./catwaf migrate --from=mysql --to=postgres \
  --mysql-dsn="user:pass@tcp(localhost:3306)/waf_db" \
  --postgres-dsn="postgres://user:pass@localhost:5432/catwaf"
```

### Schema Changes

| v1 Table | v2 Table | Changes |
|----------|----------|---------|
| `sites` | `sites` | Added `tenant_id`, settings stored as JSONB |
| `modsec_events` | `security_events` | Partitioned by date, added `tenant_id` |
| `banned_ips` | `banned_ips` | Added `tenant_id`, IP stored as INET type |
| `request_telemetry` | `insights` | Renamed, restructured |
| `alert_rules` | `alert_rules` | Added `tenant_id` |

### Migration Script

```sql
-- Example migration from v1 to v2
-- Run after setting up v2 database

-- Migrate sites
INSERT INTO sites (id, tenant_id, domain, display_name, enabled, settings, security_settings, ssl_settings, created_at)
SELECT 
    gen_random_uuid(),
    '00000000-0000-0000-0000-000000000001'::uuid, -- Default tenant
    domain,
    name,
    enabled,
    jsonb_build_object(
        'backends', COALESCE(backends::jsonb, '[]'::jsonb),
        'max_body_size', max_body_size
    ),
    jsonb_build_object(
        'modsecurity_enabled', modsec_enabled,
        'paranoia_level', COALESCE(paranoia_level, 2),
        'bot_protection', bot_protection_enabled,
        'rate_limiting', jsonb_build_object(
            'enabled', rate_limit_enabled,
            'zone', rate_limit_zone
        )
    ),
    jsonb_build_object(
        'mode', ssl_type,
        'force_https', force_ssl
    ),
    created_at
FROM mysql_fdw.sites;
```

## Configuration Migration

### Environment Variables

v1 `.env`:
```bash
DASHBOARD_API_KEY=your-api-key
DB_PASSWORD=your-db-password
ACME_EMAIL=admin@example.com
```

v2 `.env`:
```bash
CATWAF_AUTH_JWTSECRET=your-jwt-secret
CATWAF_AUTH_DEFAULTAPIKEY=your-api-key

CATWAF_DATABASE_HOST=localhost
CATWAF_DATABASE_PORT=5432
CATWAF_DATABASE_USER=catwaf
CATWAF_DATABASE_PASSWORD=your-db-password
CATWAF_DATABASE_DATABASE=catwaf

CATWAF_REDIS_HOST=localhost
CATWAF_REDIS_PORT=6379

ACME_EMAIL=admin@example.com
```

### NGINX Configuration

v2 generates NGINX configs differently. Your custom configurations need to be reviewed:

```bash
# Export custom configs from v1
docker exec waf-nginx cat /etc/nginx/conf.d/custom.conf > custom_configs.conf
```

Review and adapt for v2's configuration structure.

## API Changes

### Breaking Changes

| v1 Endpoint | v2 Endpoint | Notes |
|-------------|-------------|-------|
| `GET /api/sites` | `GET /api/v2/sites` | Response structure changed |
| `POST /api/sites` | `POST /api/v2/sites` | Request body structure changed |
| `GET /api/modsec` | `GET /api/v2/security/events` | Renamed |
| `GET /api/telemetry` | `GET /api/v2/insights` | Renamed and restructured |

### New Authentication

v2 uses JWT tokens instead of simple API keys:

```bash
# v1 - API Key in header
curl -H "Authorization: Bearer your-api-key" /api/sites

# v2 - Login to get JWT
curl -X POST /api/v2/auth/login -d '{"apiKey": "your-api-key"}'
# Returns: {"token": "jwt-token", "user": {...}}

# Use JWT for subsequent requests
curl -H "Authorization: Bearer jwt-token" /api/v2/sites
```

### Response Format Changes

v1 response:
```json
{
  "sites": [...],
  "count": 10
}
```

v2 response:
```json
{
  "sites": [...],
  "total": 10,
  "page": 1,
  "limit": 20,
  "total_pages": 1
}
```

## Step-by-Step Migration

### 1. Backup v1 Installation

```bash
# Stop services
cd /path/to/catwaf-v1
docker compose down

# Backup database
docker run --rm -v waf-mysql-data:/data -v $(pwd):/backup alpine \
  tar czf /backup/waf-mysql-data.tar.gz -C /data .

# Backup certificates
docker run --rm -v waf-certs:/data -v $(pwd):/backup alpine \
  tar czf /backup/waf-certs.tar.gz -C /data .

# Backup configurations
docker run --rm -v waf-nginx-sites:/data -v $(pwd):/backup alpine \
  tar czf /backup/waf-nginx-sites.tar.gz -C /data .
```

### 2. Install v2

```bash
# Clone v2
git clone https://github.com/smol-kitten/catwaf.git catwaf-v2
cd catwaf-v2/v2

# Copy environment
cp .env.example .env
nano .env  # Configure as needed
```

### 3. Run Migration

```bash
# Start v2 database only
docker compose up -d postgres redis

# Run migration tool
./scripts/migrate-v1.sh /path/to/v1/backup

# Start v2
docker compose up -d
```

### 4. Verify Migration

```bash
# Check API health
curl http://localhost:8080/api/health

# Check sites migrated
curl -H "Authorization: Bearer $TOKEN" http://localhost:8080/api/v2/sites

# Verify in dashboard
# Open http://localhost:3000
```

### 5. Update DNS/Proxy

Once verified, update your DNS records or reverse proxy to point to the v2 installation.

## Rollback Procedure

If migration fails:

```bash
# Stop v2
cd /path/to/catwaf-v2
docker compose down

# Restore v1
cd /path/to/catwaf-v1
docker compose up -d
```

## Feature Comparison

| Feature | v1 | v2 |
|---------|----|----|
| Sites Management | ✅ | ✅ |
| ModSecurity WAF | ✅ | ✅ |
| Bot Protection | ✅ | ✅ |
| Rate Limiting | ✅ | ✅ |
| SSL/TLS | ✅ | ✅ |
| IP Banning | ✅ | ✅ |
| Insights/Analytics | ✅ | ✅ Enhanced |
| Alert Rules | ✅ | ✅ Enhanced |
| Multi-tenancy | ❌ | ✅ |
| GraphQL API | ❌ | ✅ Optional |
| Kubernetes | ❌ | ✅ |
| Plugin System | ❌ | ✅ |
| Automated Testing | ❌ | ✅ |

## Getting Help

- **Documentation**: [docs/](./docs/)
- **Issues**: [GitHub Issues](https://github.com/smol-kitten/catwaf/issues)
- **Discussions**: [GitHub Discussions](https://github.com/smol-kitten/catwaf/discussions)

## Timeline

- **Phase 1** (Current): Core API and UI
- **Phase 2**: Feature parity with v1
- **Phase 3**: Multi-tenancy
- **Phase 4**: Plugin ecosystem

---

*CatWAF Migration Guide - Last updated February 8, 2026*
