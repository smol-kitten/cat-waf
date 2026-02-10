-- CatWAF v2 Additional Schema
-- Adds tables for routers, RSL, path routes, well-known files, api keys, settings, etc.

-- API Keys table (separate from users for service-to-service auth)
CREATE TABLE api_keys (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    key_hash VARCHAR(255) NOT NULL UNIQUE,
    key_prefix VARCHAR(8) NOT NULL, -- First 8 chars for identification
    scopes JSONB NOT NULL DEFAULT '["*"]', -- Permissions
    rate_limit INTEGER DEFAULT 1000, -- Requests per minute
    expires_at TIMESTAMPTZ,
    last_used_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_api_keys_tenant ON api_keys(tenant_id);
CREATE INDEX idx_api_keys_hash ON api_keys(key_hash);

-- Settings table (key-value store per tenant)
CREATE TABLE settings (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    key VARCHAR(255) NOT NULL,
    value JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(tenant_id, key)
);

CREATE INDEX idx_settings_tenant_key ON settings(tenant_id, key);

-- Routers table (for distributed deployments)
CREATE TABLE routers (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    endpoint VARCHAR(500) NOT NULL,
    api_key TEXT,
    enabled BOOLEAN NOT NULL DEFAULT true,
    status VARCHAR(50) NOT NULL DEFAULT 'offline',
    last_sync TIMESTAMPTZ,
    metadata JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_routers_tenant ON routers(tenant_id);

-- Router nodes table
CREATE TABLE router_nodes (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    router_id UUID NOT NULL REFERENCES routers(id) ON DELETE CASCADE,
    hostname VARCHAR(255) NOT NULL,
    ip_address INET NOT NULL,
    weight INTEGER NOT NULL DEFAULT 1,
    healthy BOOLEAN NOT NULL DEFAULT true,
    region VARCHAR(100),
    metadata JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_router_nodes_router ON router_nodes(router_id);

-- RSL (Regional Site Load-balancing) Regions
CREATE TABLE rsl_regions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) NOT NULL,
    countries TEXT[] DEFAULT '{}',
    enabled BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_rsl_regions_tenant ON rsl_regions(tenant_id);
CREATE UNIQUE INDEX idx_rsl_regions_unique ON rsl_regions(tenant_id, code);

-- RSL Site configuration
CREATE TABLE site_rsl_config (
    site_id UUID PRIMARY KEY REFERENCES sites(id) ON DELETE CASCADE,
    enabled BOOLEAN NOT NULL DEFAULT false,
    default_region VARCHAR(50),
    failover BOOLEAN NOT NULL DEFAULT true,
    region_backends JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Path routes table
CREATE TABLE path_routes (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    site_id UUID NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    path VARCHAR(500) NOT NULL,
    match_type VARCHAR(20) NOT NULL DEFAULT 'prefix', -- prefix, exact, regex
    backend_id UUID REFERENCES backends(id) ON DELETE SET NULL,
    redirect_url VARCHAR(1000),
    redirect_code INTEGER,
    priority INTEGER NOT NULL DEFAULT 100,
    enabled BOOLEAN NOT NULL DEFAULT true,
    metadata JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_path_routes_site ON path_routes(site_id);

-- Well-known files table
CREATE TABLE wellknown_files (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    site_id UUID NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    path VARCHAR(255) NOT NULL, -- e.g., 'security.txt', 'robots.txt'
    content TEXT NOT NULL,
    content_type VARCHAR(100) NOT NULL DEFAULT 'text/plain',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(site_id, path)
);

CREATE INDEX idx_wellknown_files_site ON wellknown_files(site_id);

-- Error pages table  
CREATE TABLE error_pages (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    site_id UUID NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    error_code INTEGER NOT NULL,
    template TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(site_id, error_code)
);

CREATE INDEX idx_error_pages_site ON error_pages(site_id);

-- Bot detections table (for analytics)
CREATE TABLE bot_detections (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    site_id UUID NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    client_ip INET NOT NULL,
    user_agent TEXT,
    bot_type VARCHAR(100) NOT NULL, -- crawler, scraper, automation, etc.
    confidence NUMERIC(3,2) NOT NULL DEFAULT 0.5,
    action_taken VARCHAR(50) NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_bot_detections_tenant_time ON bot_detections(tenant_id, created_at DESC);
CREATE INDEX idx_bot_detections_site_time ON bot_detections(site_id, created_at DESC);
CREATE INDEX idx_bot_detections_ip ON bot_detections(client_ip);

-- Endpoint stats table (for per-path analytics)
CREATE TABLE endpoint_stats (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    site_id UUID NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    path VARCHAR(500) NOT NULL,
    hour TIMESTAMPTZ NOT NULL,
    request_count BIGINT NOT NULL DEFAULT 0,
    avg_response_time NUMERIC(10,3) NOT NULL DEFAULT 0,
    status_2xx BIGINT NOT NULL DEFAULT 0,
    status_4xx BIGINT NOT NULL DEFAULT 0,
    status_5xx BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(site_id, path, hour)
);

CREATE INDEX idx_endpoint_stats_site_time ON endpoint_stats(site_id, hour DESC);

-- Geo stats table
CREATE TABLE geo_stats (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    site_id UUID NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    country CHAR(2) NOT NULL,
    hour TIMESTAMPTZ NOT NULL,
    request_count BIGINT NOT NULL DEFAULT 0,
    blocked_count BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(site_id, country, hour)
);

CREATE INDEX idx_geo_stats_site_time ON geo_stats(site_id, hour DESC);

-- Add tenant_id to jobs table
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS tenant_id UUID REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS progress INTEGER NOT NULL DEFAULT 0;
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS message TEXT;

-- Add more columns to sites table
ALTER TABLE sites ADD COLUMN IF NOT EXISTS aliases TEXT[] DEFAULT '{}';
ALTER TABLE sites ADD COLUMN IF NOT EXISTS ssl_enabled BOOLEAN NOT NULL DEFAULT true;
ALTER TABLE sites ADD COLUMN IF NOT EXISTS ssl_mode VARCHAR(20) NOT NULL DEFAULT 'acme';
ALTER TABLE sites ADD COLUMN IF NOT EXISTS https_redirect BOOLEAN NOT NULL DEFAULT true;
ALTER TABLE sites ADD COLUMN IF NOT EXISTS http2_enabled BOOLEAN NOT NULL DEFAULT true;
ALTER TABLE sites ADD COLUMN IF NOT EXISTS waf_enabled BOOLEAN NOT NULL DEFAULT true;
ALTER TABLE sites ADD COLUMN IF NOT EXISTS waf_mode VARCHAR(20) NOT NULL DEFAULT 'on';
ALTER TABLE sites ADD COLUMN IF NOT EXISTS rate_limit_enabled BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE sites ADD COLUMN IF NOT EXISTS rate_limit_rps INTEGER NOT NULL DEFAULT 60;
ALTER TABLE sites ADD COLUMN IF NOT EXISTS bot_protection_enabled BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE sites ADD COLUMN IF NOT EXISTS geo_block_enabled BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE sites ADD COLUMN IF NOT EXISTS geo_block_countries TEXT[] DEFAULT '{}';
ALTER TABLE sites ADD COLUMN IF NOT EXISTS websocket_enabled BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE sites ADD COLUMN IF NOT EXISTS custom_nginx_config TEXT;

-- Add more columns to backends table
ALTER TABLE backends ADD COLUMN IF NOT EXISTS max_fails INTEGER NOT NULL DEFAULT 3;
ALTER TABLE backends ADD COLUMN IF NOT EXISTS fail_timeout INTEGER NOT NULL DEFAULT 30;
ALTER TABLE backends ADD COLUMN IF NOT EXISTS is_primary BOOLEAN NOT NULL DEFAULT false;

-- Add unique visitor count to insights
ALTER TABLE insights_hourly ADD COLUMN IF NOT EXISTS unique_visitors BIGINT NOT NULL DEFAULT 0;
ALTER TABLE insights_hourly ADD COLUMN IF NOT EXISTS avg_response_time NUMERIC(10,3) NOT NULL DEFAULT 0;
ALTER TABLE insights_hourly ADD COLUMN IF NOT EXISTS min_response_time NUMERIC(10,3);
ALTER TABLE insights_hourly ADD COLUMN IF NOT EXISTS max_response_time NUMERIC(10,3);
ALTER TABLE insights_hourly ADD COLUMN IF NOT EXISTS bandwidth_bytes BIGINT NOT NULL DEFAULT 0;
ALTER TABLE insights_hourly ADD COLUMN IF NOT EXISTS blocked_requests BIGINT NOT NULL DEFAULT 0;
ALTER TABLE insights_hourly ADD COLUMN IF NOT EXISTS total_requests BIGINT NOT NULL DEFAULT 0;

-- Apply updated_at trigger to new tables
CREATE TRIGGER update_settings_updated_at
    BEFORE UPDATE ON settings
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_site_rsl_config_updated_at
    BEFORE UPDATE ON site_rsl_config
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_wellknown_files_updated_at
    BEFORE UPDATE ON wellknown_files
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_error_pages_updated_at
    BEFORE UPDATE ON error_pages
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- Comments
COMMENT ON TABLE api_keys IS 'API keys for programmatic access';
COMMENT ON TABLE settings IS 'Tenant-scoped configuration settings';
COMMENT ON TABLE routers IS 'Distributed router nodes';
COMMENT ON TABLE rsl_regions IS 'Geographic regions for RSL';
COMMENT ON TABLE path_routes IS 'Path-based routing rules';
COMMENT ON TABLE wellknown_files IS 'Well-known file content';
COMMENT ON TABLE error_pages IS 'Custom error page templates';
COMMENT ON TABLE bot_detections IS 'Bot detection events';
COMMENT ON TABLE endpoint_stats IS 'Per-endpoint analytics';
COMMENT ON TABLE geo_stats IS 'Geographic traffic analytics';
