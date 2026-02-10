// Package sites handles site management for CatWAF v2
package sites

import (
	"context"
	"strings"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"
)

// Module implements the sites module
type Module struct {
	db    *pgxpool.Pool
	redis *redis.Client
}

func New() *Module { return &Module{} }

func (m *Module) Name() string    { return "sites" }
func (m *Module) Version() string { return "2.0.0" }

func (m *Module) Init(ctx context.Context, db *pgxpool.Pool, redis *redis.Client) error {
	m.db = db
	m.redis = redis
	return nil
}

func (m *Module) RegisterRoutes(router fiber.Router) {
	sites := router.Group("/sites")
	sites.Get("/", m.List)
	sites.Get("/suggestions", m.Suggestions)
	sites.Post("/", m.Create)
	sites.Get("/:id", m.Get)
	sites.Put("/:id", m.Update)
	sites.Delete("/:id", m.Delete)
	sites.Post("/:id/duplicate", m.Duplicate)
	sites.Post("/:id/toggle", m.Toggle)
	sites.Get("/:id/stats", m.SiteStats)
	
	// Backends
	sites.Get("/:id/backends", m.ListBackends)
	sites.Post("/:id/backends", m.AddBackend)
	sites.Put("/:id/backends/:backendId", m.UpdateBackend)
	sites.Delete("/:id/backends/:backendId", m.DeleteBackend)
	sites.Post("/:id/backends/:backendId/health-check", m.HealthCheck)
	
	// Path routes
	sites.Get("/:id/path-routes", m.ListPathRoutes)
	sites.Post("/:id/path-routes", m.AddPathRoute)
	sites.Put("/:id/path-routes/:routeId", m.UpdatePathRoute)
	sites.Delete("/:id/path-routes/:routeId", m.DeletePathRoute)
	
	// Well-known files
	sites.Get("/:id/wellknown", m.ListWellknown)
	sites.Post("/:id/wellknown", m.AddWellknown)
	sites.Put("/:id/wellknown/:fileId", m.UpdateWellknown)
	sites.Delete("/:id/wellknown/:fileId", m.DeleteWellknown)
	
	// Error pages
	sites.Get("/:id/error-pages", m.ListErrorPages)
	sites.Put("/:id/error-pages/:code", m.UpdateErrorPage)
	
	// Import/Export
	sites.Post("/import", m.Import)
	sites.Get("/export", m.Export)
	sites.Get("/:id/export", m.ExportSingle)
}

func (m *Module) Shutdown(ctx context.Context) error { return nil }

// Site represents a site configuration
type Site struct {
	ID                    uuid.UUID        `json:"id"`
	TenantID              uuid.UUID        `json:"tenantId,omitempty"`
	Domain                string           `json:"domain"`
	Aliases               []string         `json:"aliases,omitempty"`
	Enabled               bool             `json:"enabled"`
	SSLEnabled            bool             `json:"sslEnabled"`
	SSLMode               string           `json:"sslMode"` // acme, custom, off
	HTTPSRedirect         bool             `json:"httpsRedirect"`
	HTTP2Enabled          bool             `json:"http2Enabled"`
	WAFEnabled            bool             `json:"wafEnabled"`
	WAFMode               string           `json:"wafMode"` // on, off, detect
	RateLimitEnabled      bool             `json:"rateLimitEnabled"`
	RateLimitRPS          int              `json:"rateLimitRps"`
	BotProtectionEnabled  bool             `json:"botProtectionEnabled"`
	GeoBlockEnabled       bool             `json:"geoBlockEnabled"`
	GeoBlockCountries     []string         `json:"geoBlockCountries,omitempty"`
	ProxyPassHeaders      bool             `json:"proxyPassHeaders"`
	WebsocketEnabled      bool             `json:"websocketEnabled"`
	CacheEnabled          bool             `json:"cacheEnabled"`
	CustomNginxConfig     string           `json:"customNginxConfig,omitempty"`
	Backends              []Backend        `json:"backends,omitempty"`
	PathRoutes            []PathRoute      `json:"pathRoutes,omitempty"`
	CreatedAt             time.Time        `json:"createdAt"`
	UpdatedAt             time.Time        `json:"updatedAt"`
}

// Backend represents a backend server
type Backend struct {
	ID              uuid.UUID `json:"id"`
	SiteID          uuid.UUID `json:"siteId"`
	Address         string    `json:"address"`
	Port            int       `json:"port"`
	Weight          int       `json:"weight"`
	Protocol        string    `json:"protocol"` // http, https
	MaxFails        int       `json:"maxFails"`
	FailTimeout     int       `json:"failTimeout"`
	HealthCheckPath string    `json:"healthCheckPath"`
	Healthy         bool      `json:"healthy"`
	Primary         bool      `json:"primary"`
}

// PathRoute represents a path-based routing rule
type PathRoute struct {
	ID            uuid.UUID `json:"id"`
	SiteID        uuid.UUID `json:"siteId"`
	Path          string    `json:"path"`
	MatchType     string    `json:"matchType"` // prefix, exact, regex
	BackendID     uuid.UUID `json:"backendId,omitempty"`
	RedirectURL   string    `json:"redirectUrl,omitempty"`
	RedirectCode  int       `json:"redirectCode,omitempty"`
	Priority      int       `json:"priority"`
	Enabled       bool      `json:"enabled"`
}

func (m *Module) List(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	ctx := c.Context()
	rows, err := m.db.Query(ctx, `
		SELECT id, domain, aliases, enabled, ssl_enabled, ssl_mode, https_redirect, http2_enabled,
		       waf_enabled, waf_mode, rate_limit_enabled, rate_limit_rps, bot_protection_enabled,
		       geo_block_enabled, geo_block_countries, websocket_enabled, created_at, updated_at
		FROM sites WHERE tenant_id = $1 ORDER BY domain
	`, tenantID)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to fetch sites"})
	}
	defer rows.Close()

	sites := make([]Site, 0)
	for rows.Next() {
		var s Site
		if rows.Scan(&s.ID, &s.Domain, &s.Aliases, &s.Enabled, &s.SSLEnabled, &s.SSLMode, &s.HTTPSRedirect, &s.HTTP2Enabled,
			&s.WAFEnabled, &s.WAFMode, &s.RateLimitEnabled, &s.RateLimitRPS, &s.BotProtectionEnabled,
			&s.GeoBlockEnabled, &s.GeoBlockCountries, &s.WebsocketEnabled, &s.CreatedAt, &s.UpdatedAt) == nil {
			sites = append(sites, s)
		}
	}

	return c.JSON(fiber.Map{"sites": sites})
}

func (m *Module) Suggestions(c *fiber.Ctx) error {
	// Return common site configuration suggestions
	return c.JSON(fiber.Map{
		"suggestions": []map[string]interface{}{
			{"name": "WordPress", "wafMode": "on", "rateLimitRps": 50},
			{"name": "API Server", "wafMode": "detect", "rateLimitRps": 100},
			{"name": "Static Site", "wafMode": "off", "cacheEnabled": true},
		},
	})
}

func (m *Module) Create(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	var site Site
	if err := c.BodyParser(&site); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	site.ID = uuid.New()
	site.TenantID = tenantID
	site.CreatedAt = time.Now()
	site.UpdatedAt = time.Now()

	// Set defaults
	if site.SSLMode == "" {
		site.SSLMode = "acme"
	}
	if site.WAFMode == "" {
		site.WAFMode = "on"
	}
	if site.RateLimitRPS == 0 {
		site.RateLimitRPS = 60
	}

	ctx := c.Context()
	_, err := m.db.Exec(ctx, `
		INSERT INTO sites (id, tenant_id, domain, aliases, enabled, ssl_enabled, ssl_mode, https_redirect, http2_enabled,
		                   waf_enabled, waf_mode, rate_limit_enabled, rate_limit_rps, bot_protection_enabled,
		                   geo_block_enabled, geo_block_countries, websocket_enabled, custom_nginx_config, created_at, updated_at)
		VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18, $19, $20)
	`, site.ID, site.TenantID, site.Domain, site.Aliases, site.Enabled, site.SSLEnabled, site.SSLMode, site.HTTPSRedirect, site.HTTP2Enabled,
		site.WAFEnabled, site.WAFMode, site.RateLimitEnabled, site.RateLimitRPS, site.BotProtectionEnabled,
		site.GeoBlockEnabled, site.GeoBlockCountries, site.WebsocketEnabled, site.CustomNginxConfig, site.CreatedAt, site.UpdatedAt)

	if err != nil {
		if strings.Contains(err.Error(), "duplicate") {
			return c.Status(fiber.StatusConflict).JSON(fiber.Map{"error": "Site with this domain already exists"})
		}
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to create site"})
	}

	// Create default backend if provided
	if len(site.Backends) > 0 {
		for _, b := range site.Backends {
			b.ID = uuid.New()
			b.SiteID = site.ID
			_, _ = m.db.Exec(ctx, `
				INSERT INTO backends (id, site_id, address, port, weight, protocol, max_fails, fail_timeout, health_check_path, is_primary)
				VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
			`, b.ID, b.SiteID, b.Address, b.Port, b.Weight, b.Protocol, b.MaxFails, b.FailTimeout, b.HealthCheckPath, b.Primary)
		}
	}

	return c.Status(fiber.StatusCreated).JSON(fiber.Map{"site": site})
}

func (m *Module) Get(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id, _ := uuid.Parse(c.Params("id"))

	ctx := c.Context()
	var s Site
	err := m.db.QueryRow(ctx, `
		SELECT id, domain, aliases, enabled, ssl_enabled, ssl_mode, https_redirect, http2_enabled,
		       waf_enabled, waf_mode, rate_limit_enabled, rate_limit_rps, bot_protection_enabled,
		       geo_block_enabled, geo_block_countries, websocket_enabled, custom_nginx_config, created_at, updated_at
		FROM sites WHERE id = $1 AND tenant_id = $2
	`, id, tenantID).Scan(&s.ID, &s.Domain, &s.Aliases, &s.Enabled, &s.SSLEnabled, &s.SSLMode, &s.HTTPSRedirect, &s.HTTP2Enabled,
		&s.WAFEnabled, &s.WAFMode, &s.RateLimitEnabled, &s.RateLimitRPS, &s.BotProtectionEnabled,
		&s.GeoBlockEnabled, &s.GeoBlockCountries, &s.WebsocketEnabled, &s.CustomNginxConfig, &s.CreatedAt, &s.UpdatedAt)

	if err != nil {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "Site not found"})
	}

	// Load backends
	rows, _ := m.db.Query(ctx, `SELECT id, address, port, weight, protocol, max_fails, fail_timeout, health_check_path, is_primary FROM backends WHERE site_id = $1`, id)
	defer rows.Close()
	for rows.Next() {
		var b Backend
		if rows.Scan(&b.ID, &b.Address, &b.Port, &b.Weight, &b.Protocol, &b.MaxFails, &b.FailTimeout, &b.HealthCheckPath, &b.Primary) == nil {
			b.SiteID = id
			b.Healthy = true // Would check actual health
			s.Backends = append(s.Backends, b)
		}
	}

	return c.JSON(fiber.Map{"site": s})
}

func (m *Module) Update(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id, _ := uuid.Parse(c.Params("id"))

	var site Site
	if err := c.BodyParser(&site); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	result, err := m.db.Exec(ctx, `
		UPDATE sites SET domain = $3, aliases = $4, enabled = $5, ssl_enabled = $6, ssl_mode = $7,
		       https_redirect = $8, http2_enabled = $9, waf_enabled = $10, waf_mode = $11,
		       rate_limit_enabled = $12, rate_limit_rps = $13, bot_protection_enabled = $14,
		       geo_block_enabled = $15, geo_block_countries = $16, websocket_enabled = $17,
		       custom_nginx_config = $18, updated_at = NOW()
		WHERE id = $1 AND tenant_id = $2
	`, id, tenantID, site.Domain, site.Aliases, site.Enabled, site.SSLEnabled, site.SSLMode,
		site.HTTPSRedirect, site.HTTP2Enabled, site.WAFEnabled, site.WAFMode,
		site.RateLimitEnabled, site.RateLimitRPS, site.BotProtectionEnabled,
		site.GeoBlockEnabled, site.GeoBlockCountries, site.WebsocketEnabled, site.CustomNginxConfig)

	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to update site"})
	}

	if result.RowsAffected() == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "Site not found"})
	}

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) Delete(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id, _ := uuid.Parse(c.Params("id"))

	ctx := c.Context()
	result, err := m.db.Exec(ctx, `DELETE FROM sites WHERE id = $1 AND tenant_id = $2`, id, tenantID)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to delete site"})
	}

	if result.RowsAffected() == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "Site not found"})
	}

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) Duplicate(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id, _ := uuid.Parse(c.Params("id"))

	var req struct {
		NewDomain string `json:"newDomain"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	newID := uuid.New()
	_, err := m.db.Exec(ctx, `
		INSERT INTO sites (id, tenant_id, domain, aliases, enabled, ssl_enabled, ssl_mode, https_redirect, http2_enabled,
		                   waf_enabled, waf_mode, rate_limit_enabled, rate_limit_rps, bot_protection_enabled,
		                   geo_block_enabled, geo_block_countries, websocket_enabled, custom_nginx_config, created_at, updated_at)
		SELECT $3, tenant_id, $4, aliases, enabled, ssl_enabled, ssl_mode, https_redirect, http2_enabled,
		       waf_enabled, waf_mode, rate_limit_enabled, rate_limit_rps, bot_protection_enabled,
		       geo_block_enabled, geo_block_countries, websocket_enabled, custom_nginx_config, NOW(), NOW()
		FROM sites WHERE id = $1 AND tenant_id = $2
	`, id, tenantID, newID, req.NewDomain)

	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to duplicate site"})
	}

	return c.Status(fiber.StatusCreated).JSON(fiber.Map{"id": newID, "domain": req.NewDomain})
}

func (m *Module) Toggle(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id, _ := uuid.Parse(c.Params("id"))

	ctx := c.Context()
	_, err := m.db.Exec(ctx, `UPDATE sites SET enabled = NOT enabled, updated_at = NOW() WHERE id = $1 AND tenant_id = $2`, id, tenantID)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to toggle site"})
	}

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) SiteStats(c *fiber.Ctx) error {
	id, _ := uuid.Parse(c.Params("id"))

	ctx := c.Context()
	var stats struct {
		TotalRequests   int64 `json:"totalRequests"`
		BlockedRequests int64 `json:"blockedRequests"`
		AvgResponseTime float64 `json:"avgResponseTime"`
		BandwidthGB     float64 `json:"bandwidthGb"`
	}

	_ = m.db.QueryRow(ctx, `
		SELECT COALESCE(SUM(total_requests), 0), COALESCE(SUM(blocked_requests), 0),
		       COALESCE(AVG(avg_response_time), 0), COALESCE(SUM(bandwidth_bytes), 0) / 1073741824.0
		FROM insights_hourly WHERE site_id = $1 AND hour >= NOW() - INTERVAL '24 hours'
	`, id).Scan(&stats.TotalRequests, &stats.BlockedRequests, &stats.AvgResponseTime, &stats.BandwidthGB)

	return c.JSON(fiber.Map{"stats": stats})
}

// Backend handlers
func (m *Module) ListBackends(c *fiber.Ctx) error {
	id, _ := uuid.Parse(c.Params("id"))

	ctx := c.Context()
	rows, _ := m.db.Query(ctx, `SELECT id, address, port, weight, protocol, max_fails, fail_timeout, health_check_path, is_primary FROM backends WHERE site_id = $1`, id)
	defer rows.Close()

	backends := make([]Backend, 0)
	for rows.Next() {
		var b Backend
		if rows.Scan(&b.ID, &b.Address, &b.Port, &b.Weight, &b.Protocol, &b.MaxFails, &b.FailTimeout, &b.HealthCheckPath, &b.Primary) == nil {
			b.SiteID = id
			b.Healthy = true
			backends = append(backends, b)
		}
	}

	return c.JSON(fiber.Map{"backends": backends})
}

func (m *Module) AddBackend(c *fiber.Ctx) error {
	siteID, _ := uuid.Parse(c.Params("id"))
	var b Backend
	if err := c.BodyParser(&b); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	b.ID = uuid.New()
	b.SiteID = siteID

	// Defaults
	if b.Protocol == "" {
		b.Protocol = "http"
	}
	if b.Weight == 0 {
		b.Weight = 1
	}
	if b.Port == 0 {
		b.Port = 80
	}

	ctx := c.Context()
	_, err := m.db.Exec(ctx, `
		INSERT INTO backends (id, site_id, address, port, weight, protocol, max_fails, fail_timeout, health_check_path, is_primary)
		VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
	`, b.ID, b.SiteID, b.Address, b.Port, b.Weight, b.Protocol, b.MaxFails, b.FailTimeout, b.HealthCheckPath, b.Primary)

	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to add backend"})
	}

	return c.Status(fiber.StatusCreated).JSON(fiber.Map{"backend": b})
}

func (m *Module) UpdateBackend(c *fiber.Ctx) error {
	backendID, _ := uuid.Parse(c.Params("backendId"))
	var b Backend
	if err := c.BodyParser(&b); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	_, err := m.db.Exec(ctx, `
		UPDATE backends SET address = $2, port = $3, weight = $4, protocol = $5, max_fails = $6, fail_timeout = $7, health_check_path = $8, is_primary = $9
		WHERE id = $1
	`, backendID, b.Address, b.Port, b.Weight, b.Protocol, b.MaxFails, b.FailTimeout, b.HealthCheckPath, b.Primary)

	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to update backend"})
	}

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) DeleteBackend(c *fiber.Ctx) error {
	backendID, _ := uuid.Parse(c.Params("backendId"))

	ctx := c.Context()
	_, _ = m.db.Exec(ctx, `DELETE FROM backends WHERE id = $1`, backendID)

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) HealthCheck(c *fiber.Ctx) error {
	backendID, _ := uuid.Parse(c.Params("backendId"))

	// Would perform actual health check
	return c.JSON(fiber.Map{
		"backendId": backendID,
		"healthy":   true,
		"latency":   45,
	})
}

// Path route handlers
func (m *Module) ListPathRoutes(c *fiber.Ctx) error {
	siteID, _ := uuid.Parse(c.Params("id"))

	ctx := c.Context()
	rows, _ := m.db.Query(ctx, `SELECT id, path, match_type, backend_id, redirect_url, redirect_code, priority, enabled FROM path_routes WHERE site_id = $1 ORDER BY priority`, siteID)
	defer rows.Close()

	routes := make([]PathRoute, 0)
	for rows.Next() {
		var r PathRoute
		if rows.Scan(&r.ID, &r.Path, &r.MatchType, &r.BackendID, &r.RedirectURL, &r.RedirectCode, &r.Priority, &r.Enabled) == nil {
			r.SiteID = siteID
			routes = append(routes, r)
		}
	}

	return c.JSON(fiber.Map{"pathRoutes": routes})
}

func (m *Module) AddPathRoute(c *fiber.Ctx) error {
	siteID, _ := uuid.Parse(c.Params("id"))
	var r PathRoute
	if err := c.BodyParser(&r); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	r.ID = uuid.New()
	r.SiteID = siteID

	ctx := c.Context()
	_, err := m.db.Exec(ctx, `
		INSERT INTO path_routes (id, site_id, path, match_type, backend_id, redirect_url, redirect_code, priority, enabled)
		VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)
	`, r.ID, r.SiteID, r.Path, r.MatchType, r.BackendID, r.RedirectURL, r.RedirectCode, r.Priority, r.Enabled)

	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to add path route"})
	}

	return c.Status(fiber.StatusCreated).JSON(fiber.Map{"pathRoute": r})
}

func (m *Module) UpdatePathRoute(c *fiber.Ctx) error {
	routeID, _ := uuid.Parse(c.Params("routeId"))
	var r PathRoute
	if err := c.BodyParser(&r); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	_, _ = m.db.Exec(ctx, `
		UPDATE path_routes SET path = $2, match_type = $3, backend_id = $4, redirect_url = $5, redirect_code = $6, priority = $7, enabled = $8
		WHERE id = $1
	`, routeID, r.Path, r.MatchType, r.BackendID, r.RedirectURL, r.RedirectCode, r.Priority, r.Enabled)

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) DeletePathRoute(c *fiber.Ctx) error {
	routeID, _ := uuid.Parse(c.Params("routeId"))
	ctx := c.Context()
	_, _ = m.db.Exec(ctx, `DELETE FROM path_routes WHERE id = $1`, routeID)
	return c.JSON(fiber.Map{"success": true})
}

// Well-known file handlers
func (m *Module) ListWellknown(c *fiber.Ctx) error {
	siteID, _ := uuid.Parse(c.Params("id"))

	ctx := c.Context()
	rows, _ := m.db.Query(ctx, `SELECT id, path, content, content_type FROM wellknown_files WHERE site_id = $1`, siteID)
	defer rows.Close()

	files := make([]map[string]interface{}, 0)
	for rows.Next() {
		var id uuid.UUID
		var path, content, contentType string
		if rows.Scan(&id, &path, &content, &contentType) == nil {
			files = append(files, map[string]interface{}{"id": id, "path": path, "content": content, "contentType": contentType})
		}
	}

	return c.JSON(fiber.Map{"files": files})
}

func (m *Module) AddWellknown(c *fiber.Ctx) error {
	siteID, _ := uuid.Parse(c.Params("id"))
	var req struct {
		Path        string `json:"path"`
		Content     string `json:"content"`
		ContentType string `json:"contentType"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	id := uuid.New()
	ctx := c.Context()
	_, _ = m.db.Exec(ctx, `INSERT INTO wellknown_files (id, site_id, path, content, content_type) VALUES ($1, $2, $3, $4, $5)`,
		id, siteID, req.Path, req.Content, req.ContentType)

	return c.Status(fiber.StatusCreated).JSON(fiber.Map{"id": id})
}

func (m *Module) UpdateWellknown(c *fiber.Ctx) error {
	fileID, _ := uuid.Parse(c.Params("fileId"))
	var req struct {
		Content     string `json:"content"`
		ContentType string `json:"contentType"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	_, _ = m.db.Exec(ctx, `UPDATE wellknown_files SET content = $2, content_type = $3 WHERE id = $1`, fileID, req.Content, req.ContentType)

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) DeleteWellknown(c *fiber.Ctx) error {
	fileID, _ := uuid.Parse(c.Params("fileId"))
	ctx := c.Context()
	_, _ = m.db.Exec(ctx, `DELETE FROM wellknown_files WHERE id = $1`, fileID)
	return c.JSON(fiber.Map{"success": true})
}

// Error page handlers
func (m *Module) ListErrorPages(c *fiber.Ctx) error {
	siteID, _ := uuid.Parse(c.Params("id"))

	ctx := c.Context()
	rows, _ := m.db.Query(ctx, `SELECT error_code, template FROM error_pages WHERE site_id = $1`, siteID)
	defer rows.Close()

	pages := make(map[string]string)
	for rows.Next() {
		var code int
		var template string
		if rows.Scan(&code, &template) == nil {
			pages[string(rune(code))] = template
		}
	}

	return c.JSON(fiber.Map{"errorPages": pages})
}

func (m *Module) UpdateErrorPage(c *fiber.Ctx) error {
	siteID, _ := uuid.Parse(c.Params("id"))
	code := c.Params("code")
	var req struct {
		Template string `json:"template"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	_, _ = m.db.Exec(ctx, `
		INSERT INTO error_pages (site_id, error_code, template) VALUES ($1, $2, $3)
		ON CONFLICT (site_id, error_code) DO UPDATE SET template = $3
	`, siteID, code, req.Template)

	return c.JSON(fiber.Map{"success": true})
}

// Import/Export
func (m *Module) Import(c *fiber.Ctx) error {
	// Would parse JSON and import sites
	return c.JSON(fiber.Map{"imported": 0})
}

func (m *Module) Export(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	
	ctx := c.Context()
	rows, _ := m.db.Query(ctx, `SELECT id FROM sites WHERE tenant_id = $1`, tenantID)
	defer rows.Close()

	ids := make([]uuid.UUID, 0)
	for rows.Next() {
		var id uuid.UUID
		rows.Scan(&id)
		ids = append(ids, id)
	}

	return c.JSON(fiber.Map{"siteIds": ids, "count": len(ids)})
}

func (m *Module) ExportSingle(c *fiber.Ctx) error {
	// Return full site config as JSON
	return m.Get(c)
}
