-- CatWAF v2 Initial Schema
-- PostgreSQL 16+

-- Enable extensions
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";
CREATE EXTENSION IF NOT EXISTS "btree_gist";

-- Tenants table (for multi-tenancy support)
CREATE TABLE tenants (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    settings JSONB NOT NULL DEFAULT '{}',
    tier VARCHAR(50) NOT NULL DEFAULT 'free',
    limits JSONB NOT NULL DEFAULT '{"sites": 5, "events_per_day": 10000}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Create default tenant
INSERT INTO tenants (id, name, slug, tier, limits) VALUES (
    '00000000-0000-0000-0000-000000000001',
    'Default',
    'default',
    'unlimited',
    '{"sites": -1, "events_per_day": -1}'
);

-- Users table
CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255),
    api_key VARCHAR(255),
    role VARCHAR(50) NOT NULL DEFAULT 'user',
    settings JSONB NOT NULL DEFAULT '{}',
    last_login_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(tenant_id, email),
    UNIQUE(api_key)
);

-- Create index for API key lookups
CREATE INDEX idx_users_api_key ON users(api_key) WHERE api_key IS NOT NULL;

-- Sites table
CREATE TABLE sites (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    domain VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT true,
    settings JSONB NOT NULL DEFAULT '{}',
    security_settings JSONB NOT NULL DEFAULT '{
        "modsecurity_enabled": true,
        "paranoia_level": 2,
        "bot_protection": true,
        "rate_limiting": {"enabled": true, "requests_per_second": 100}
    }',
    ssl_settings JSONB NOT NULL DEFAULT '{
        "mode": "auto",
        "force_https": true,
        "hsts_enabled": true
    }',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(tenant_id, domain)
);

-- Create indexes for sites
CREATE INDEX idx_sites_tenant ON sites(tenant_id);
CREATE INDEX idx_sites_domain ON sites(domain);
CREATE INDEX idx_sites_enabled ON sites(enabled) WHERE enabled = true;

-- Backends table
CREATE TABLE backends (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    site_id UUID NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    address VARCHAR(255) NOT NULL,
    port INTEGER NOT NULL DEFAULT 80,
    protocol VARCHAR(10) NOT NULL DEFAULT 'http',
    weight INTEGER NOT NULL DEFAULT 1,
    health_check_path VARCHAR(255) DEFAULT '/health',
    is_backup BOOLEAN NOT NULL DEFAULT false,
    enabled BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_backends_site ON backends(site_id);

-- Security events table (partitioned by date for performance)
CREATE TABLE security_events (
    id UUID NOT NULL DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL,
    site_id UUID NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    severity VARCHAR(20) NOT NULL,
    rule_id VARCHAR(50),
    rule_message TEXT,
    client_ip INET NOT NULL,
    country_code CHAR(2),
    request_method VARCHAR(10),
    request_uri TEXT,
    request_headers JSONB,
    response_status INTEGER,
    action_taken VARCHAR(50) NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);

-- Create partitions for next 12 months
DO $$
DECLARE
    start_date DATE := DATE_TRUNC('month', CURRENT_DATE);
    end_date DATE;
    partition_name TEXT;
BEGIN
    FOR i IN 0..11 LOOP
        end_date := start_date + INTERVAL '1 month';
        partition_name := 'security_events_' || TO_CHAR(start_date, 'YYYY_MM');
        
        EXECUTE format(
            'CREATE TABLE IF NOT EXISTS %I PARTITION OF security_events
            FOR VALUES FROM (%L) TO (%L)',
            partition_name,
            start_date,
            end_date
        );
        
        start_date := end_date;
    END LOOP;
END $$;

-- Create indexes for security events
CREATE INDEX idx_security_events_tenant_time ON security_events(tenant_id, created_at DESC);
CREATE INDEX idx_security_events_site_time ON security_events(site_id, created_at DESC);
CREATE INDEX idx_security_events_ip ON security_events(client_ip);
CREATE INDEX idx_security_events_severity ON security_events(severity) WHERE severity IN ('critical', 'high');

-- Banned IPs table
CREATE TABLE banned_ips (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    site_id UUID REFERENCES sites(id) ON DELETE CASCADE,
    ip_address INET NOT NULL,
    reason VARCHAR(255) NOT NULL,
    source VARCHAR(50) NOT NULL DEFAULT 'manual',
    expires_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(tenant_id, site_id, ip_address)
);

CREATE INDEX idx_banned_ips_lookup ON banned_ips(ip_address, tenant_id);
CREATE INDEX idx_banned_ips_expires ON banned_ips(expires_at) WHERE expires_at IS NOT NULL;

-- Alert rules table
CREATE TABLE alert_rules (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    site_id UUID REFERENCES sites(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    condition JSONB NOT NULL,
    actions JSONB NOT NULL DEFAULT '[]',
    cooldown_minutes INTEGER NOT NULL DEFAULT 15,
    enabled BOOLEAN NOT NULL DEFAULT true,
    last_triggered_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_alert_rules_tenant ON alert_rules(tenant_id);
CREATE INDEX idx_alert_rules_enabled ON alert_rules(enabled) WHERE enabled = true;

-- Alert history table
CREATE TABLE alert_history (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    alert_rule_id UUID NOT NULL REFERENCES alert_rules(id) ON DELETE CASCADE,
    triggered_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    condition_met JSONB NOT NULL,
    actions_taken JSONB NOT NULL DEFAULT '[]',
    resolved_at TIMESTAMPTZ
);

CREATE INDEX idx_alert_history_rule ON alert_history(alert_rule_id, triggered_at DESC);

-- Insights/Analytics aggregates
CREATE TABLE insights_hourly (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    site_id UUID NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    hour TIMESTAMPTZ NOT NULL,
    requests_total BIGINT NOT NULL DEFAULT 0,
    requests_blocked BIGINT NOT NULL DEFAULT 0,
    bytes_in BIGINT NOT NULL DEFAULT 0,
    bytes_out BIGINT NOT NULL DEFAULT 0,
    unique_ips INTEGER NOT NULL DEFAULT 0,
    status_codes JSONB NOT NULL DEFAULT '{}',
    top_paths JSONB NOT NULL DEFAULT '[]',
    top_countries JSONB NOT NULL DEFAULT '[]',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(site_id, hour)
);

CREATE INDEX idx_insights_hourly_tenant_time ON insights_hourly(tenant_id, hour DESC);
CREATE INDEX idx_insights_hourly_site_time ON insights_hourly(site_id, hour DESC);

-- SSL certificates table
CREATE TABLE ssl_certificates (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    site_id UUID NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    domain VARCHAR(255) NOT NULL,
    issuer VARCHAR(255),
    not_before TIMESTAMPTZ NOT NULL,
    not_after TIMESTAMPTZ NOT NULL,
    fingerprint VARCHAR(64) NOT NULL,
    certificate_pem TEXT NOT NULL,
    private_key_pem TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_ssl_certificates_site ON ssl_certificates(site_id);
CREATE INDEX idx_ssl_certificates_expiry ON ssl_certificates(not_after);

-- Custom block rules table
CREATE TABLE custom_block_rules (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    site_id UUID REFERENCES sites(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    rule_type VARCHAR(50) NOT NULL,
    condition JSONB NOT NULL,
    action VARCHAR(50) NOT NULL DEFAULT 'block',
    priority INTEGER NOT NULL DEFAULT 100,
    enabled BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_custom_block_rules_tenant ON custom_block_rules(tenant_id);
CREATE INDEX idx_custom_block_rules_site ON custom_block_rules(site_id);

-- Bot whitelist table
CREATE TABLE bot_whitelist (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    user_agent_pattern VARCHAR(500),
    ip_ranges JSONB DEFAULT '[]',
    enabled BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_bot_whitelist_tenant ON bot_whitelist(tenant_id);

-- Jobs/Tasks table for background processing
CREATE TABLE jobs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    type VARCHAR(100) NOT NULL,
    payload JSONB NOT NULL DEFAULT '{}',
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    priority INTEGER NOT NULL DEFAULT 0,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    last_error TEXT,
    run_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    started_at TIMESTAMPTZ,
    completed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_jobs_pending ON jobs(run_at, priority DESC) WHERE status = 'pending';
CREATE INDEX idx_jobs_status ON jobs(status);

-- Audit log table
CREATE TABLE audit_logs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    user_id UUID REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(100) NOT NULL,
    resource_id UUID,
    changes JSONB,
    ip_address INET,
    user_agent TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_audit_logs_tenant_time ON audit_logs(tenant_id, created_at DESC);
CREATE INDEX idx_audit_logs_resource ON audit_logs(resource_type, resource_id);

-- Function to update updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Apply updated_at triggers
CREATE TRIGGER update_tenants_updated_at
    BEFORE UPDATE ON tenants
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_users_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_sites_updated_at
    BEFORE UPDATE ON sites
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_alert_rules_updated_at
    BEFORE UPDATE ON alert_rules
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_custom_block_rules_updated_at
    BEFORE UPDATE ON custom_block_rules
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- Function to create new monthly partitions for security_events
CREATE OR REPLACE FUNCTION create_security_events_partition()
RETURNS void AS $$
DECLARE
    next_month DATE := DATE_TRUNC('month', CURRENT_DATE + INTERVAL '1 month');
    partition_name TEXT := 'security_events_' || TO_CHAR(next_month, 'YYYY_MM');
    end_date DATE := next_month + INTERVAL '1 month';
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_class WHERE relname = partition_name
    ) THEN
        EXECUTE format(
            'CREATE TABLE IF NOT EXISTS %I PARTITION OF security_events
            FOR VALUES FROM (%L) TO (%L)',
            partition_name,
            next_month,
            end_date
        );
        RAISE NOTICE 'Created partition %', partition_name;
    END IF;
END;
$$ LANGUAGE plpgsql;

-- Comment on tables
COMMENT ON TABLE tenants IS 'Multi-tenant organizations';
COMMENT ON TABLE users IS 'User accounts with authentication';
COMMENT ON TABLE sites IS 'Protected websites/domains';
COMMENT ON TABLE backends IS 'Backend servers for sites';
COMMENT ON TABLE security_events IS 'WAF security events (partitioned)';
COMMENT ON TABLE banned_ips IS 'Manually or automatically banned IP addresses';
COMMENT ON TABLE alert_rules IS 'Alerting rule configurations';
COMMENT ON TABLE insights_hourly IS 'Hourly aggregated analytics';
COMMENT ON TABLE jobs IS 'Background job queue';
COMMENT ON TABLE audit_logs IS 'System audit trail';
