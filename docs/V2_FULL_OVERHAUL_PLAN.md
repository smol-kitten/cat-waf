# CatWAF v2.0 - Full Stack Overhaul Plan

**Created**: February 8, 2026  
**Target Completion**: Q2 2026  
**Status**: Planning Phase

---

## Executive Summary

CatWAF v2.0 represents a complete architectural overhaul addressing technical debt, scalability concerns, and modernizing the entire stack. This plan covers:

- **UI Overhaul**: Modern React/SvelteKit frontend replacing 9500+ line vanilla JS
- **Backend Rewrite**: Go or Rust API replacing PHP monolith (51 endpoint files)
- **Modular Architecture**: Plugin-based system for extensibility
- **Automated Testing**: Full CI/CD with unit, integration, and E2E tests
- **Infrastructure**: Kubernetes-ready with Helm charts

---

## Current State Analysis

### Pain Points Identified

| Area | Current | Problem | Impact |
|------|---------|---------|--------|
| Frontend | Vanilla JS (9500+ LOC in single file) | Unmaintainable, no type safety | High |
| Backend | PHP 8.2 monolith (51 endpoint files) | No DI, manual routing, scattered logic | High |
| Database | Raw SQL with manual migrations | No ORM, migration inconsistency | Medium |
| Testing | None | Zero coverage, manual QA only | Critical |
| Deployment | Docker Compose only | No K8s support, limited scaling | Medium |
| API | REST only, no versioning | Breaking changes affect clients | Medium |

### Current Tech Stack

```
Frontend:  Vanilla JavaScript + HTML/CSS
Backend:   PHP 8.2-FPM + NGINX
Database:  MariaDB 11.8
Cache:     None (planned Redis)
Queue:     PHP file-based queue
Infra:     Docker Compose (7 containers)
CI/CD:     Basic GitHub Actions
```

---

## Proposed v2.0 Architecture

### Technology Selection

#### Option A: Go Backend (Recommended)
```
Frontend:  SvelteKit 2.x + TypeScript + TailwindCSS
Backend:   Go 1.22+ with Fiber/Echo framework
Database:  PostgreSQL 16 (with MariaDB compatibility layer)
Cache:     Redis 7.x / Valkey
Queue:     Redis Streams / NATS
API:       REST v2 + GraphQL (optional)
Testing:   Vitest (FE) + Go testing + Playwright E2E
Infra:     Docker + Kubernetes + Helm
```

#### Option B: Rust Backend (Performance-critical)
```
Frontend:  React 19 + TypeScript + Radix UI
Backend:   Rust with Axum/Actix-web
Database:  PostgreSQL 16
Cache:     Redis 7.x
Queue:     RabbitMQ / NATS
API:       REST v2 + gRPC (internal)
Testing:   Jest (FE) + Cargo test + Cypress E2E
Infra:     Docker + Kubernetes + Helm
```

### Recommended: Option A (Go + SvelteKit)

**Rationale:**
- Go has excellent Docker/K8s ecosystem (same language as Docker, K8s)
- SvelteKit offers superior DX and smaller bundle sizes vs React
- Faster development velocity than Rust
- Strong type safety without Rust's steep learning curve
- Excellent for reverse proxy/WAF workloads (NGINX rewrite potential)

---

## Modular Architecture Design

### Core Modules

```
catwaf/
├── core/                    # Core framework
│   ├── config/              # Configuration management
│   ├── database/            # Database abstraction
│   ├── auth/                # Authentication/Authorization
│   ├── middleware/          # HTTP middleware chain
│   └── events/              # Event bus for plugins
│
├── modules/                 # Feature modules (pluggable)
│   ├── sites/               # Site management
│   ├── security/            # ModSecurity, WAF rules
│   ├── ssl/                 # Certificate management
│   ├── bots/                # Bot protection
│   ├── bans/                # IP ban management
│   ├── insights/            # Analytics & telemetry
│   ├── alerts/              # Alert system
│   ├── notifications/       # Webhooks, email, etc.
│   ├── cache/               # Cache management
│   └── routers/             # External router integration
│
├── plugins/                 # Third-party plugins
│   ├── cloudflare/          # Cloudflare integration
│   ├── mikrotik/            # MikroTik router
│   └── custom/              # User plugins
│
├── api/                     # API layer
│   ├── v2/                  # REST API v2
│   ├── graphql/             # GraphQL (optional)
│   └── grpc/                # Internal gRPC (optional)
│
└── ui/                      # Frontend application
    ├── src/
    │   ├── lib/             # Shared components
    │   ├── routes/          # SvelteKit routes
    │   ├── stores/          # State management
    │   └── api/             # API client
    └── tests/               # Frontend tests
```

### Plugin Interface

```go
// Go Plugin Interface
type Module interface {
    // Lifecycle
    Init(ctx context.Context, app *Application) error
    Start() error
    Stop() error
    
    // Metadata
    Name() string
    Version() string
    Dependencies() []string
    
    // Routes
    RegisterRoutes(router *fiber.App)
    
    // Events
    HandleEvent(event Event) error
}

// Example: Sites Module
type SitesModule struct {
    db     *Database
    cache  *Cache
    events *EventBus
}

func (m *SitesModule) RegisterRoutes(router *fiber.App) {
    sites := router.Group("/api/v2/sites")
    sites.Get("/", m.List)
    sites.Post("/", m.Create)
    sites.Get("/:id", m.Get)
    sites.Put("/:id", m.Update)
    sites.Delete("/:id", m.Delete)
}
```

---

## UI Overhaul

### SvelteKit Structure

```
ui/
├── src/
│   ├── lib/
│   │   ├── components/
│   │   │   ├── ui/           # Base UI components
│   │   │   │   ├── Button.svelte
│   │   │   │   ├── Card.svelte
│   │   │   │   ├── Modal.svelte
│   │   │   │   ├── Table.svelte
│   │   │   │   ├── Toast.svelte
│   │   │   │   └── ...
│   │   │   ├── layout/       # Layout components
│   │   │   │   ├── Sidebar.svelte
│   │   │   │   ├── Header.svelte
│   │   │   │   └── Navigation.svelte
│   │   │   └── domain/       # Domain-specific
│   │   │       ├── SiteCard.svelte
│   │   │       ├── EventTable.svelte
│   │   │       ├── MetricChart.svelte
│   │   │       └── ...
│   │   ├── stores/
│   │   │   ├── auth.ts       # Auth state
│   │   │   ├── sites.ts      # Sites store
│   │   │   ├── events.ts     # Events store
│   │   │   └── settings.ts   # Settings store
│   │   ├── api/
│   │   │   ├── client.ts     # API client
│   │   │   ├── sites.ts      # Sites API
│   │   │   ├── security.ts   # Security API
│   │   │   └── ...
│   │   └── utils/
│   │       ├── format.ts     # Formatters
│   │       ├── validation.ts # Validators
│   │       └── constants.ts  # Constants
│   │
│   ├── routes/
│   │   ├── +layout.svelte    # Root layout
│   │   ├── +page.svelte      # Dashboard
│   │   ├── login/
│   │   │   └── +page.svelte
│   │   ├── sites/
│   │   │   ├── +page.svelte
│   │   │   ├── [id]/
│   │   │   │   └── +page.svelte
│   │   │   └── new/
│   │   │       └── +page.svelte
│   │   ├── security/
│   │   │   ├── events/
│   │   │   ├── modsecurity/
│   │   │   ├── bots/
│   │   │   └── bans/
│   │   ├── monitoring/
│   │   │   ├── insights/
│   │   │   ├── alerts/
│   │   │   └── logs/
│   │   └── settings/
│   │       └── +page.svelte
│   │
│   ├── app.html
│   ├── app.css               # TailwindCSS
│   └── hooks.server.ts       # Server hooks
│
├── static/
│   └── catboy/               # Theme assets
├── tests/
│   ├── unit/
│   └── e2e/
├── svelte.config.js
├── tailwind.config.js
├── vite.config.ts
└── package.json
```

### Design System

```css
/* Catboy Theme v2 - TailwindCSS Config */
module.exports = {
  theme: {
    extend: {
      colors: {
        catboy: {
          pink: {
            50: '#fdf2f8',
            100: '#fce7f3',
            200: '#fbcfe8',
            300: '#f9a8d4',
            400: '#f472b6',
            500: '#ec4899',  /* Primary */
            600: '#db2777',
            700: '#be185d',
            800: '#9d174d',
            900: '#831843',
          },
          purple: {
            50: '#faf5ff',
            100: '#f3e8ff',
            200: '#e9d5ff',
            300: '#d8b4fe',
            400: '#c084fc',
            500: '#a855f7',  /* Secondary */
            600: '#9333ea',
            700: '#7e22ce',
            800: '#6b21a8',
            900: '#581c87',
          }
        }
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
        mono: ['JetBrains Mono', 'monospace'],
      }
    }
  }
}
```

---

## Backend API v2

### Go Project Structure

```
backend/
├── cmd/
│   └── catwaf/
│       └── main.go           # Entry point
├── internal/
│   ├── app/
│   │   ├── app.go            # Application struct
│   │   └── config.go         # Configuration
│   ├── modules/
│   │   ├── sites/
│   │   │   ├── handler.go    # HTTP handlers
│   │   │   ├── service.go    # Business logic
│   │   │   ├── repository.go # Data access
│   │   │   ├── model.go      # Domain models
│   │   │   └── dto.go        # DTOs
│   │   ├── security/
│   │   ├── ssl/
│   │   ├── bots/
│   │   └── ...
│   ├── middleware/
│   │   ├── auth.go
│   │   ├── ratelimit.go
│   │   ├── logging.go
│   │   └── cors.go
│   ├── database/
│   │   ├── postgres.go
│   │   ├── migrations/
│   │   └── queries/          # SQL queries (sqlc)
│   └── pkg/
│       ├── validator/
│       ├── logger/
│       └── errors/
├── api/
│   ├── openapi.yaml          # OpenAPI 3.1 spec
│   └── proto/                # Protobuf definitions
├── migrations/               # Database migrations
├── tests/
│   ├── unit/
│   ├── integration/
│   └── fixtures/
├── Dockerfile
├── go.mod
└── go.sum
```

### API Versioning

```yaml
# OpenAPI 3.1 Specification
openapi: 3.1.0
info:
  title: CatWAF API
  version: 2.0.0
  description: Web Application Firewall Management API

servers:
  - url: /api/v2
    description: Current API version
  - url: /api/v1
    description: Legacy API (deprecated)

paths:
  /sites:
    get:
      summary: List all sites
      tags: [Sites]
      parameters:
        - $ref: '#/components/parameters/PageParam'
        - $ref: '#/components/parameters/LimitParam'
        - $ref: '#/components/parameters/SearchParam'
      responses:
        '200':
          description: Success
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/SiteListResponse'
```

---

## Database Schema v2

### Migration to PostgreSQL

```sql
-- PostgreSQL schema with proper types and constraints

-- Tenants (Multi-tenant support)
CREATE TABLE tenants (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    plan tenant_plan DEFAULT 'free',
    settings JSONB DEFAULT '{}',
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TYPE tenant_plan AS ENUM ('free', 'pro', 'enterprise');

-- Sites with proper indexing
CREATE TABLE sites (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID REFERENCES tenants(id) ON DELETE CASCADE,
    domain VARCHAR(253) NOT NULL,
    display_name VARCHAR(255),
    enabled BOOLEAN DEFAULT true,
    settings JSONB DEFAULT '{}',
    security_settings JSONB DEFAULT '{}',
    ssl_settings JSONB DEFAULT '{}',
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(tenant_id, domain)
);

CREATE INDEX idx_sites_tenant ON sites(tenant_id);
CREATE INDEX idx_sites_domain ON sites(domain);
CREATE INDEX idx_sites_enabled ON sites(enabled) WHERE enabled = true;

-- Security Events with partitioning for scale
CREATE TABLE security_events (
    id BIGSERIAL,
    tenant_id UUID REFERENCES tenants(id),
    site_id UUID REFERENCES sites(id),
    timestamp TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    event_type VARCHAR(50) NOT NULL,
    severity severity_level DEFAULT 'medium',
    source_ip INET NOT NULL,
    request_uri TEXT,
    rule_id VARCHAR(20),
    message TEXT,
    metadata JSONB DEFAULT '{}',
    PRIMARY KEY (id, timestamp)
) PARTITION BY RANGE (timestamp);

CREATE TYPE severity_level AS ENUM ('critical', 'high', 'medium', 'low', 'info');

-- Create monthly partitions
CREATE TABLE security_events_2026_02 PARTITION OF security_events
    FOR VALUES FROM ('2026-02-01') TO ('2026-03-01');
```

### Using SQLC for Type-Safe Queries

```sql
-- queries/sites.sql
-- name: GetSite :one
SELECT * FROM sites
WHERE id = $1 AND tenant_id = $2;

-- name: ListSites :many
SELECT * FROM sites
WHERE tenant_id = $1
ORDER BY created_at DESC
LIMIT $2 OFFSET $3;

-- name: CreateSite :one
INSERT INTO sites (tenant_id, domain, display_name, settings)
VALUES ($1, $2, $3, $4)
RETURNING *;

-- name: UpdateSite :one
UPDATE sites
SET domain = $2, display_name = $3, settings = $4, updated_at = NOW()
WHERE id = $1 AND tenant_id = $5
RETURNING *;
```

---

## Automated Testing Strategy

### Testing Pyramid

```
                    ┌─────────────┐
                    │  E2E Tests  │  (Playwright)
                    │    10%      │
                    └──────┬──────┘
                   ┌───────┴───────┐
                   │ Integration   │  (API + DB)
                   │    Tests 30%  │
                   └───────┬───────┘
              ┌────────────┴────────────┐
              │     Unit Tests 60%      │  (Components + Logic)
              └─────────────────────────┘
```

### Frontend Testing (Vitest + Playwright)

```typescript
// tests/unit/components/SiteCard.test.ts
import { render, screen } from '@testing-library/svelte';
import { describe, it, expect } from 'vitest';
import SiteCard from '$lib/components/domain/SiteCard.svelte';

describe('SiteCard', () => {
  it('renders site domain', () => {
    render(SiteCard, { 
      props: { 
        site: { 
          id: '1', 
          domain: 'example.com', 
          enabled: true 
        }
      }
    });
    expect(screen.getByText('example.com')).toBeInTheDocument();
  });

  it('shows disabled badge when site is disabled', () => {
    render(SiteCard, { 
      props: { 
        site: { 
          id: '1', 
          domain: 'example.com', 
          enabled: false 
        }
      }
    });
    expect(screen.getByText('Disabled')).toBeInTheDocument();
  });
});

// tests/e2e/sites.spec.ts
import { test, expect } from '@playwright/test';

test.describe('Sites Management', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.fill('[name="apiKey"]', process.env.TEST_API_KEY!);
    await page.click('button[type="submit"]');
    await page.waitForURL('/');
  });

  test('can create a new site', async ({ page }) => {
    await page.click('[data-testid="add-site-btn"]');
    await page.fill('[name="domain"]', 'test.example.com');
    await page.click('button[type="submit"]');
    
    await expect(page.getByText('test.example.com')).toBeVisible();
  });
});
```

### Backend Testing (Go)

```go
// internal/modules/sites/handler_test.go
package sites_test

import (
    "encoding/json"
    "net/http/httptest"
    "strings"
    "testing"

    "github.com/gofiber/fiber/v2"
    "github.com/stretchr/testify/assert"
    "github.com/stretchr/testify/mock"
    
    "catwaf/internal/modules/sites"
)

type MockSiteService struct {
    mock.Mock
}

func (m *MockSiteService) List(ctx context.Context, tenantID string) ([]sites.Site, error) {
    args := m.Called(ctx, tenantID)
    return args.Get(0).([]sites.Site), args.Error(1)
}

func TestSiteHandler_List(t *testing.T) {
    app := fiber.New()
    mockService := new(MockSiteService)
    handler := sites.NewHandler(mockService)
    
    app.Get("/api/v2/sites", handler.List)

    mockService.On("List", mock.Anything, "tenant-1").Return([]sites.Site{
        {ID: "1", Domain: "example.com", Enabled: true},
    }, nil)

    req := httptest.NewRequest("GET", "/api/v2/sites", nil)
    req.Header.Set("X-Tenant-ID", "tenant-1")
    
    resp, err := app.Test(req)
    
    assert.NoError(t, err)
    assert.Equal(t, 200, resp.StatusCode)
    
    var result struct {
        Sites []sites.Site `json:"sites"`
    }
    json.NewDecoder(resp.Body).Decode(&result)
    assert.Len(t, result.Sites, 1)
    assert.Equal(t, "example.com", result.Sites[0].Domain)
}

// Integration test with real database
func TestSiteRepository_Integration(t *testing.T) {
    if testing.Short() {
        t.Skip("Skipping integration test")
    }
    
    db := setupTestDB(t) // Uses testcontainers
    defer db.Close()
    
    repo := sites.NewRepository(db)
    
    // Create
    site, err := repo.Create(context.Background(), &sites.CreateSiteDTO{
        TenantID: "test-tenant",
        Domain:   "integration-test.com",
    })
    assert.NoError(t, err)
    assert.NotEmpty(t, site.ID)
    
    // Read
    found, err := repo.GetByID(context.Background(), site.ID, "test-tenant")
    assert.NoError(t, err)
    assert.Equal(t, "integration-test.com", found.Domain)
}
```

### CI/CD Pipeline

```yaml
# .github/workflows/ci.yml
name: CI

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main]

jobs:
  frontend:
    runs-on: ubuntu-latest
    defaults:
      run:
        working-directory: ./ui
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'pnpm'
      
      - run: pnpm install
      - run: pnpm run lint
      - run: pnpm run check
      - run: pnpm run test:unit
      
      - name: Build
        run: pnpm run build
      
      - uses: actions/upload-artifact@v4
        with:
          name: frontend-build
          path: ui/build

  backend:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_PASSWORD: test
          POSTGRES_DB: catwaf_test
        ports:
          - 5432:5432
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-go@v5
        with:
          go-version: '1.22'
      
      - name: Lint
        uses: golangci/golangci-lint-action@v4
      
      - name: Unit Tests
        run: go test -v -short ./...
      
      - name: Integration Tests
        run: go test -v -run Integration ./...
        env:
          DATABASE_URL: postgres://postgres:test@localhost:5432/catwaf_test

  e2e:
    needs: [frontend, backend]
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      
      - name: Install Playwright
        run: npx playwright install --with-deps
      
      - name: Start services
        run: docker compose -f docker-compose.test.yml up -d
      
      - name: Wait for services
        run: |
          timeout 60 bash -c 'until curl -s http://localhost:8080/api/health; do sleep 1; done'
      
      - name: Run E2E tests
        run: npx playwright test
      
      - uses: actions/upload-artifact@v4
        if: failure()
        with:
          name: playwright-report
          path: playwright-report

  security:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      
      - name: Run Trivy (Docker)
        uses: aquasecurity/trivy-action@master
        with:
          scan-type: 'fs'
          scan-ref: '.'
          severity: 'CRITICAL,HIGH'
      
      - name: Run Snyk
        uses: snyk/actions/go@master
        env:
          SNYK_TOKEN: ${{ secrets.SNYK_TOKEN }}
```

---

## Infrastructure

### Kubernetes Deployment

```yaml
# k8s/base/deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: catwaf-api
spec:
  replicas: 3
  selector:
    matchLabels:
      app: catwaf-api
  template:
    metadata:
      labels:
        app: catwaf-api
    spec:
      containers:
        - name: api
          image: ghcr.io/smol-kitten/catwaf-api:v2.0.0
          ports:
            - containerPort: 8080
          env:
            - name: DATABASE_URL
              valueFrom:
                secretKeyRef:
                  name: catwaf-secrets
                  key: database-url
          resources:
            requests:
              cpu: 100m
              memory: 128Mi
            limits:
              cpu: 500m
              memory: 512Mi
          livenessProbe:
            httpGet:
              path: /api/health
              port: 8080
            initialDelaySeconds: 5
          readinessProbe:
            httpGet:
              path: /api/health
              port: 8080
            initialDelaySeconds: 5
---
apiVersion: v1
kind: Service
metadata:
  name: catwaf-api
spec:
  selector:
    app: catwaf-api
  ports:
    - port: 8080
```

### Helm Chart

```yaml
# helm/catwaf/values.yaml
replicaCount: 3

image:
  repository: ghcr.io/smol-kitten/catwaf
  tag: "v2.0.0"
  pullPolicy: IfNotPresent

api:
  port: 8080
  resources:
    limits:
      cpu: 500m
      memory: 512Mi

database:
  enabled: true  # Use bundled PostgreSQL
  external:
    enabled: false
    host: ""
    port: 5432

redis:
  enabled: true
  architecture: standalone

nginx:
  enabled: true
  ingress:
    enabled: true
    className: nginx
    hosts:
      - host: waf.example.com
        paths:
          - path: /
            pathType: Prefix

modsecurity:
  enabled: true
  paranoia: 2
  customRules: []

autoscaling:
  enabled: false
  minReplicas: 2
  maxReplicas: 10
  targetCPUUtilization: 80
```

---

## Migration Strategy

### Phase 1: Foundation (Weeks 1-2)
- [ ] Set up new repository structure
- [ ] Initialize Go backend with Fiber
- [ ] Initialize SvelteKit frontend
- [ ] Set up PostgreSQL with migrations
- [ ] Configure CI/CD pipeline

### Phase 2: Core API (Weeks 3-4)
- [ ] Implement auth module (JWT + API keys)
- [ ] Implement sites module
- [ ] Implement security events module
- [ ] Create API v2 documentation

### Phase 3: UI Development (Weeks 5-6)
- [ ] Build component library
- [ ] Implement dashboard layout
- [ ] Build sites management pages
- [ ] Build security monitoring pages

### Phase 4: Feature Parity (Weeks 7-8)
- [ ] SSL/Certificate management
- [ ] Bot protection
- [ ] Insights/Analytics
- [ ] Alerts & Notifications
- [ ] Settings & Configuration

### Phase 5: Advanced Features (Weeks 9-10)
- [ ] Multi-tenancy
- [ ] GraphQL API (optional)
- [ ] Plugin system
- [ ] Kubernetes operators

### Phase 6: Testing & Polish (Weeks 11-12)
- [ ] Full E2E test coverage
- [ ] Performance optimization
- [ ] Security audit
- [ ] Documentation
- [ ] Migration tools from v1

---

## Directory Structure Overview

```
catwaf-v2/
├── .github/
│   ├── workflows/
│   │   ├── ci.yml
│   │   ├── release.yml
│   │   └── security.yml
│   └── ISSUE_TEMPLATE/
├── api/
│   ├── openapi.yaml
│   └── proto/
├── backend/
│   ├── cmd/catwaf/
│   ├── internal/
│   ├── migrations/
│   ├── tests/
│   ├── Dockerfile
│   ├── go.mod
│   └── Makefile
├── ui/
│   ├── src/
│   ├── static/
│   ├── tests/
│   ├── Dockerfile
│   ├── package.json
│   └── svelte.config.js
├── nginx/
│   ├── Dockerfile
│   ├── nginx.conf
│   └── modsecurity/
├── k8s/
│   ├── base/
│   └── overlays/
├── helm/
│   └── catwaf/
├── docker/
│   ├── docker-compose.yml
│   ├── docker-compose.dev.yml
│   └── docker-compose.test.yml
├── docs/
│   ├── getting-started.md
│   ├── api-reference.md
│   ├── deployment.md
│   └── contributing.md
├── scripts/
│   ├── migrate-v1.sh
│   └── dev-setup.sh
├── README.md
├── CHANGELOG.md
└── LICENSE
```

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Migration data loss | Low | Critical | Staged rollout, backup verification |
| Learning curve (Go) | Medium | Medium | Good documentation, training |
| Timeline overrun | Medium | Medium | Agile sprints, MVP focus |
| Breaking API changes | Medium | High | API versioning, deprecation period |
| Performance regression | Low | Medium | Load testing, benchmarks |

---

## Success Metrics

- **Code Quality**: 80%+ test coverage
- **Performance**: <100ms P95 API latency
- **Reliability**: 99.9% uptime
- **Developer Experience**: <15min local setup
- **Maintainability**: <1 day to add new module

---

## Next Steps

1. **Approve this plan** with stakeholders
2. **Create v2 branch** in repository
3. **Set up development environment**
4. **Begin Phase 1** implementation

---

*CatWAF v2.0 Overhaul Plan - Created February 8, 2026*
