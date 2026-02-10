# CatWAF v2

Modern Web Application Firewall with Go API and SvelteKit Dashboard.

## Quick Start

### Prerequisites

- Docker & Docker Compose
- Node.js 20+ (for local UI development)
- Go 1.22+ (for local API development)
- pnpm (recommended for UI)

### Development

```bash
# Start all services
docker compose up -d

# Or start just the databases for local development
docker compose up -d postgres redis

# Run API locally
cd backend
go run ./cmd/catwaf serve

# Run UI locally (in another terminal)
cd ui
pnpm install
pnpm dev
```

### Access Points

- **Dashboard**: http://localhost:5173 (dev) or http://localhost:3000 (prod)
- **API**: http://localhost:8080
- **API Docs**: http://localhost:8080/api/docs

### Default Credentials

```
API Key: dev-api-key (development only)
```

## Architecture

```
┌─────────────────┐     ┌─────────────────┐
│   SvelteKit UI  │────▶│    Go API       │
│   (Port 5173)   │     │   (Port 8080)   │
└─────────────────┘     └────────┬────────┘
                                 │
                    ┌────────────┴────────────┐
                    ▼                         ▼
            ┌──────────────┐          ┌──────────────┐
            │  PostgreSQL  │          │    Redis     │
            │  (Port 5432) │          │  (Port 6379) │
            └──────────────┘          └──────────────┘
```

## Project Structure

```
v2/
├── backend/                 # Go API server
│   ├── cmd/catwaf/         # Main application
│   ├── internal/           # Internal packages
│   │   ├── app/            # Application core
│   │   └── modules/        # Feature modules
│   │       └── sites/      # Sites management
│   ├── migrations/         # Database migrations
│   └── tests/              # Backend tests
│
├── ui/                      # SvelteKit frontend
│   ├── src/
│   │   ├── lib/            # Shared components
│   │   │   ├── components/ # UI components
│   │   │   ├── stores/     # Svelte stores
│   │   │   └── api/        # API client
│   │   └── routes/         # Pages
│   └── tests/              # Frontend tests
│
├── nginx/                   # NGINX WAF proxy
├── log-parser/             # Log processing
├── fail2ban/               # IP banning
│
├── docs/                    # Documentation
│   └── MIGRATION.md        # v1 to v2 migration guide
│
├── docker-compose.yml       # Development environment
└── docker-compose.test.yml  # Testing environment
```

## Development

### Backend

```bash
cd backend

# Run tests
go test ./...

# Run with live reload (using air)
air

# Format code
go fmt ./...

# Lint
golangci-lint run
```

### Frontend

```bash
cd ui

# Install dependencies
pnpm install

# Development server
pnpm dev

# Type check
pnpm check

# Lint
pnpm lint

# Run tests
pnpm test

# E2E tests
pnpm test:e2e
```

## Testing

```bash
# Run all tests via Docker
docker compose -f docker-compose.test.yml up --build --abort-on-container-exit

# Run just backend tests
cd backend && go test ./...

# Run just frontend tests
cd ui && pnpm test

# Run E2E tests
cd ui && pnpm test:e2e
```

## API Overview

### Authentication

```bash
# Login with API key
curl -X POST http://localhost:8080/api/v2/auth/login \
  -H "Content-Type: application/json" \
  -d '{"apiKey": "dev-api-key"}'

# Use JWT token
curl http://localhost:8080/api/v2/sites \
  -H "Authorization: Bearer <token>"
```

### Sites

```bash
# List sites
GET /api/v2/sites

# Create site
POST /api/v2/sites
{
  "domain": "example.com",
  "display_name": "My Site",
  "backends": [{"address": "192.168.1.1", "port": 80}]
}

# Get site
GET /api/v2/sites/:id

# Update site
PATCH /api/v2/sites/:id

# Delete site
DELETE /api/v2/sites/:id
```

## Configuration

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `CATWAF_SERVER_PORT` | API server port | `8080` |
| `CATWAF_DATABASE_HOST` | PostgreSQL host | `localhost` |
| `CATWAF_DATABASE_PORT` | PostgreSQL port | `5432` |
| `CATWAF_DATABASE_USER` | Database user | `catwaf` |
| `CATWAF_DATABASE_PASSWORD` | Database password | - |
| `CATWAF_DATABASE_DATABASE` | Database name | `catwaf` |
| `CATWAF_REDIS_HOST` | Redis host | `localhost` |
| `CATWAF_REDIS_PORT` | Redis port | `6379` |
| `CATWAF_AUTH_JWTSECRET` | JWT signing secret | - |
| `CATWAF_AUTH_DEFAULTAPIKEY` | Default admin API key | - |

## Migration from v1

See [docs/MIGRATION.md](./docs/MIGRATION.md) for detailed migration instructions.

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Run tests
5. Submit a pull request

## License

MIT License - see LICENSE file for details.
