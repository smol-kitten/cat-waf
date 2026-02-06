# Multi-Tenant System Architecture Plan

## Overview
This document outlines the planned architecture for adding multi-tenancy support to CatWAF. Multi-tenancy will allow multiple organizations to use a single CatWAF instance with complete data isolation and separate configurations.

## Core Requirements

### 1. Tenant Isolation
- Complete data segregation between tenants
- Separate site configurations per tenant
- Isolated security events and logs
- Independent alert configurations
- Separate insights and analytics

### 2. Authentication & Authorization
- Tenant-specific user authentication
- Role-based access control (RBAC) within tenants
  - Admin: Full tenant control
  - User: View and manage sites
  - Viewer: Read-only access
- Master admin for system-wide management
- API tokens per tenant

### 3. Resource Management
- Per-tenant resource quotas
  - Maximum number of sites
  - Storage limits for logs/telemetry
  - Rate limiting per tenant
- Billing and usage tracking

## Database Schema Changes

### New Tables

#### tenants
```sql
CREATE TABLE tenants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(100) UNIQUE NOT NULL,
  enabled TINYINT(1) DEFAULT 1,
  plan VARCHAR(50) DEFAULT 'free',  -- free, pro, enterprise
  max_sites INT DEFAULT 5,
  max_storage_gb INT DEFAULT 10,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### tenant_users
```sql
CREATE TABLE tenant_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  username VARCHAR(100) NOT NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(50) DEFAULT 'user',  -- admin, user, viewer
  enabled TINYINT(1) DEFAULT 1,
  last_login TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE KEY unique_tenant_email (tenant_id, email)
);
```

#### tenant_api_tokens
```sql
CREATE TABLE tenant_api_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  user_id INT DEFAULT NULL,
  name VARCHAR(100) NOT NULL,
  token VARCHAR(255) UNIQUE NOT NULL,
  enabled TINYINT(1) DEFAULT 1,
  expires_at TIMESTAMP NULL,
  last_used TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES tenant_users(id) ON DELETE SET NULL
);
```

### Modified Tables

All existing tables need a tenant_id column:

```sql
ALTER TABLE sites ADD COLUMN tenant_id INT NOT NULL;
ALTER TABLE modsec_events ADD COLUMN tenant_id INT;
ALTER TABLE banned_ips ADD COLUMN tenant_id INT;
ALTER TABLE request_telemetry ADD COLUMN tenant_id INT;
ALTER TABLE alert_rules ADD COLUMN tenant_id INT NOT NULL;
ALTER TABLE insights_config ADD COLUMN tenant_id INT;
ALTER TABLE web_vitals ADD COLUMN tenant_id INT;

-- Add foreign keys and indexes
ALTER TABLE sites ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
CREATE INDEX idx_sites_tenant ON sites(tenant_id);
CREATE INDEX idx_modsec_tenant ON modsec_events(tenant_id);
CREATE INDEX idx_bans_tenant ON banned_ips(tenant_id);
CREATE INDEX idx_telemetry_tenant ON request_telemetry(tenant_id);
```

## API Changes

### Authentication Flow

1. **Master Admin Login**
   - POST `/api/auth/master` - System-wide admin
   - Returns master token

2. **Tenant Login**
   - POST `/api/auth/login` - Tenant-specific login
   - Requires: email, password, tenant_slug
   - Returns: tenant-scoped JWT token

3. **API Token Authentication**
   - Bearer token includes tenant_id claim
   - All API requests automatically scoped to tenant

### New Endpoints

```
# Tenant Management (Master Admin Only)
GET    /api/tenants              - List all tenants
POST   /api/tenants              - Create new tenant
GET    /api/tenants/:id          - Get tenant details
PUT    /api/tenants/:id          - Update tenant
DELETE /api/tenants/:id          - Delete tenant
GET    /api/tenants/:id/usage    - Get tenant resource usage

# Tenant User Management (Tenant Admin)
GET    /api/tenant/users         - List tenant users
POST   /api/tenant/users         - Create new user
PUT    /api/tenant/users/:id     - Update user
DELETE /api/tenant/users/:id     - Delete user
```

### Modified Endpoints

All existing endpoints automatically filter by tenant_id based on authenticated token:
- `/api/sites` - Only returns sites for authenticated tenant
- `/api/modsec` - Only returns events for authenticated tenant
- `/api/insights` - Only shows insights for authenticated tenant
- etc.

## UI Changes

### Master Admin Dashboard
- New "Tenants" page for system-wide management
- Tenant creation wizard
- Resource usage monitoring per tenant
- System-wide statistics

### Tenant Dashboard
- Current tenant indicator in header
- Tenant-specific branding (logo, colors)
- Tenant settings page
- User management page

### Login Flow
1. User enters tenant slug (subdomain or slug field)
2. System loads tenant-specific login page
3. User authenticates
4. Dashboard loads with tenant context

## Implementation Phases

### Phase 1: Database Schema (Week 1-2)
- [ ] Create migration for tenant tables
- [ ] Add tenant_id to existing tables
- [ ] Create data migration script
- [ ] Test isolation

### Phase 2: Authentication & API (Week 3-4)
- [ ] Implement tenant authentication
- [ ] Add tenant context middleware
- [ ] Update all API endpoints for tenant filtering
- [ ] Create tenant management endpoints
- [ ] Test API isolation

### Phase 3: UI Updates (Week 5-6)
- [ ] Create master admin dashboard
- [ ] Add tenant management interface
- [ ] Update login flow
- [ ] Add tenant branding support
- [ ] Create user management page

### Phase 4: Testing & Documentation (Week 7-8)
- [ ] End-to-end testing
- [ ] Performance testing with multiple tenants
- [ ] Security audit
- [ ] Documentation
- [ ] Migration guide

## Security Considerations

1. **Data Isolation**
   - Row-level security enforced at application layer
   - Database views for additional safety
   - Regular audits of data access

2. **Rate Limiting**
   - Per-tenant rate limits
   - Prevent resource exhaustion
   - Fair usage policies

3. **Backup & Recovery**
   - Per-tenant backup capability
   - Tenant data export functionality
   - GDPR compliance (data portability)

4. **Audit Logging**
   - Log all tenant management actions
   - Track cross-tenant access attempts
   - User activity logs per tenant

## Resource Quotas

### Free Tier
- 5 sites maximum
- 10 GB storage
- 30 days data retention
- Basic insights only

### Pro Tier
- 25 sites maximum
- 100 GB storage
- 90 days data retention
- Extended insights with web vitals
- Priority support

### Enterprise Tier
- Unlimited sites
- Unlimited storage
- 365 days data retention
- All features
- Dedicated support
- Custom SLA

## Migration Strategy

### For New Installations
- Create default "system" tenant on first boot
- All future installations are multi-tenant by default

### For Existing Installations
1. Create "default" tenant
2. Migrate all existing data to default tenant
3. Set tenant_id for all rows
4. Enable multi-tenant mode via config flag

## Configuration

### Environment Variables
```bash
# Enable multi-tenant mode
MULTI_TENANT_ENABLED=true

# Master admin credentials
MASTER_ADMIN_EMAIL=admin@example.com
MASTER_ADMIN_PASSWORD=secure_password

# Default tenant for existing data migration
DEFAULT_TENANT_SLUG=default
```

## Future Enhancements

1. **Tenant Isolation Levels**
   - Shared infrastructure (current plan)
   - Separate databases per tenant
   - Separate instances per tenant

2. **White-Label Support**
   - Custom domains per tenant
   - Branded email templates
   - Custom UI themes

3. **Marketplace**
   - Third-party integrations
   - Tenant-specific plugins
   - Custom security rules marketplace

## References

- [Multi-Tenancy Patterns](https://docs.microsoft.com/en-us/azure/architecture/patterns/multi-tenancy)
- [SaaS Security Best Practices](https://owasp.org/www-project-saas-security/)
- [Database Isolation Strategies](https://martinfowler.com/articles/patterns-of-distributed-systems/multi-tenant-database.html)
