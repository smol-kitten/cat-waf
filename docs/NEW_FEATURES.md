# CatWAF Overhaul - New Features

## Overview
This document describes the new features added to CatWAF as part of the UI/UX overhaul.

## 1. Path-Based Routing (Sub-Page Routing)

### Description
Path-based routing enables you to route different URL paths to different backend servers, allowing for micro-service architecture and better service organization.

### Use Cases
- **Multi-service deployment**: Route different paths to different services
  - `service.domain.com` → Server 1 (main app)
  - `service.domain.com/api` → Server 2 (API service)
  - `service.domain.com/files` → Server 3 (file storage service)
  - `service.domain.com/admin` → Server 4 (admin panel)

- **Content separation**: Separate static content from dynamic content
  - `/static` → CDN or static file server
  - `/api` → Application server
  - `/ws` → WebSocket server

### API Endpoints

#### List Path Routes
```http
GET /api/path-routes
GET /api/path-routes/site/:site_id
```

#### Create Path Route
```http
POST /api/path-routes
Content-Type: application/json

{
  "site_id": 1,
  "path": "/api",
  "backend_url": "api-server:8080",
  "backend_protocol": "http",
  "priority": 10,
  "enabled": 1,
  "strip_path": 0,
  "enable_modsecurity": 1,
  "enable_rate_limit": 1,
  "custom_rate_limit": 100,
  "rate_limit_burst": 200,
  "max_body_size": "50M",
  "custom_headers": "{\"X-Service\":\"API\"}",
  "custom_config": ""
}
```

### Configuration Options

| Field | Type | Description |
|-------|------|-------------|
| `site_id` | integer | Parent site ID |
| `path` | string | URL path pattern (e.g., `/api`, `/files`) |
| `backend_url` | string | Backend server (hostname:port) |
| `backend_protocol` | string | Protocol: `http` or `https` |
| `priority` | integer | Higher priority routes are matched first |
| `enabled` | boolean | Enable/disable this route |
| `strip_path` | boolean | Remove path prefix when proxying |
| `enable_modsecurity` | boolean | Enable ModSecurity for this path |
| `enable_rate_limit` | boolean | Enable rate limiting |
| `custom_rate_limit` | integer | Requests per second limit |
| `rate_limit_burst` | integer | Burst limit |
| `max_body_size` | string | Max request body size (e.g., `10M`, `1G`) |

## 2. Security Check Center

### Description
A centralized security monitoring dashboard that runs automated health checks for various security components and system resources.

### Built-in Security Checks

1. **SSL Certificate Expiry Check** - Monitors certificate expiration dates
2. **ModSecurity Status** - Verifies ModSecurity module is loaded
3. **Fail2ban Status** - Checks if fail2ban service is available
4. **Disk Space Check** - Monitors available disk space
5. **NGINX Status** - Validates NGINX configuration
6. **Database Status** - Tests database connectivity
7. **Security Rules Check** - Counts active custom security rules
8. **Blocked Attacks Monitor** - Tracks attack volume

### API Endpoints

```http
GET /api/security-checks           # List all checks
POST /api/security-checks/run      # Run all checks
POST /api/security-checks/run/:id  # Run single check
GET /api/security-checks/:id/history  # Get check history
```

## 3. Maximum Body Size Configuration

Configure maximum request body size limits per site or per path route.

### Site-Level
```json
{
  "max_body_size": "100M",
  "max_body_size_enabled": 1
}
```

### Path-Level
```json
{
  "path": "/upload",
  "max_body_size": "500M"
}
```

## 4. UI/UX Improvements

- **Security Check Center Page** - Visual security health dashboard
- **Path Routing Tab** - Manage multiple backend routes per site
- **Improved Navigation** - Better organization of security features

## Migration Guide

1. **Automatic Migration**: Existing sites continue to work without changes
2. **Database Migrations**: Run automatically on container restart
3. **Optional Features**: Add path routing when needed

## Best Practices

### Path-Based Routing
- Use priority for specific paths
- Test strip_path setting
- Apply appropriate security per path
- Monitor performance

### Security Checks
- Run checks regularly (every hour)
- Alert on critical status
- Review history for trends

### Body Size Limits
- Start conservative
- Increase only for upload endpoints
- Balance security vs functionality
