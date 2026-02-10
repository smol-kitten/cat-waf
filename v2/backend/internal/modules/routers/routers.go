// Package routers handles router and RSL (Regional Site Load-balancing) for CatWAF v2
package routers

import (
	"context"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"
)

// Module implements the routers module
type Module struct {
	db    *pgxpool.Pool
	redis *redis.Client
}

func New() *Module { return &Module{} }

func (m *Module) Name() string    { return "routers" }
func (m *Module) Version() string { return "2.0.0" }

func (m *Module) Init(ctx context.Context, db *pgxpool.Pool, redis *redis.Client) error {
	m.db = db
	m.redis = redis
	return nil
}

func (m *Module) RegisterRoutes(router fiber.Router) {
	// Routers
	r := router.Group("/routers")
	r.Get("/", m.List)
	r.Post("/", m.Create)
	r.Get("/:id", m.Get)
	r.Put("/:id", m.Update)
	r.Delete("/:id", m.Delete)
	r.Get("/:id/nodes", m.ListNodes)
	r.Post("/:id/nodes", m.AddNode)
	r.Put("/:id/nodes/:nodeId", m.UpdateNode)
	r.Delete("/:id/nodes/:nodeId", m.DeleteNode)
	r.Post("/:id/sync", m.Sync)

	// RSL (Regional Site Load-balancing)
	rsl := router.Group("/rsl")
	rsl.Get("/regions", m.ListRegions)
	rsl.Post("/regions", m.CreateRegion)
	rsl.Put("/regions/:id", m.UpdateRegion)
	rsl.Delete("/regions/:id", m.DeleteRegion)
	rsl.Get("/sites/:siteId", m.GetSiteRSL)
	rsl.Put("/sites/:siteId", m.UpdateSiteRSL)
	rsl.Post("/test", m.TestRSL)
}

func (m *Module) Shutdown(ctx context.Context) error { return nil }

// Router represents a router node for distributed deployment
type Router struct {
	ID          uuid.UUID    `json:"id"`
	TenantID    uuid.UUID    `json:"tenantId,omitempty"`
	Name        string       `json:"name"`
	Description string       `json:"description,omitempty"`
	Endpoint    string       `json:"endpoint"`
	APIKey      string       `json:"apiKey,omitempty"`
	Enabled     bool         `json:"enabled"`
	LastSync    time.Time    `json:"lastSync,omitempty"`
	Status      string       `json:"status"` // online, offline, syncing
	Nodes       []RouterNode `json:"nodes,omitempty"`
	CreatedAt   time.Time    `json:"createdAt"`
}

// RouterNode represents a node within a router
type RouterNode struct {
	ID        uuid.UUID `json:"id"`
	RouterID  uuid.UUID `json:"routerId"`
	Hostname  string    `json:"hostname"`
	IPAddress string    `json:"ipAddress"`
	Weight    int       `json:"weight"`
	Healthy   bool      `json:"healthy"`
	Region    string    `json:"region,omitempty"`
}

// Region represents a geographic region for RSL
type Region struct {
	ID        uuid.UUID `json:"id"`
	TenantID  uuid.UUID `json:"tenantId,omitempty"`
	Name      string    `json:"name"`
	Code      string    `json:"code"` // e.g., us-east, eu-west
	Countries []string  `json:"countries,omitempty"`
	Enabled   bool      `json:"enabled"`
}

// SiteRSLConfig represents RSL configuration for a site
type SiteRSLConfig struct {
	SiteID         uuid.UUID              `json:"siteId"`
	Enabled        bool                   `json:"enabled"`
	DefaultRegion  string                 `json:"defaultRegion,omitempty"`
	RegionBackends map[string][]uuid.UUID `json:"regionBackends,omitempty"`
	Failover       bool                   `json:"failover"`
}

func (m *Module) List(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	ctx := c.Context()
	rows, err := m.db.Query(ctx, `
		SELECT id, name, description, endpoint, enabled, last_sync, status, created_at
		FROM routers WHERE tenant_id = $1
		ORDER BY name
	`, tenantID)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to fetch routers"})
	}
	defer rows.Close()

	routers := make([]Router, 0)
	for rows.Next() {
		var r Router
		if rows.Scan(&r.ID, &r.Name, &r.Description, &r.Endpoint, &r.Enabled, &r.LastSync, &r.Status, &r.CreatedAt) == nil {
			routers = append(routers, r)
		}
	}

	return c.JSON(fiber.Map{"routers": routers})
}

func (m *Module) Create(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	var router Router
	if err := c.BodyParser(&router); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	router.ID = uuid.New()
	router.TenantID = tenantID
	router.Status = "offline"
	router.CreatedAt = time.Now()

	ctx := c.Context()
	_, err := m.db.Exec(ctx, `
		INSERT INTO routers (id, tenant_id, name, description, endpoint, api_key, enabled, status, created_at)
		VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)
	`, router.ID, router.TenantID, router.Name, router.Description, router.Endpoint, router.APIKey, router.Enabled, router.Status, router.CreatedAt)

	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to create router"})
	}

	return c.Status(fiber.StatusCreated).JSON(fiber.Map{"router": router})
}

func (m *Module) Get(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id, _ := uuid.Parse(c.Params("id"))

	ctx := c.Context()
	var r Router
	err := m.db.QueryRow(ctx, `
		SELECT id, name, description, endpoint, enabled, last_sync, status, created_at
		FROM routers WHERE id = $1 AND tenant_id = $2
	`, id, tenantID).Scan(&r.ID, &r.Name, &r.Description, &r.Endpoint, &r.Enabled, &r.LastSync, &r.Status, &r.CreatedAt)

	if err != nil {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "Router not found"})
	}

	// Load nodes
	rows, _ := m.db.Query(ctx, `SELECT id, hostname, ip_address, weight, healthy, region FROM router_nodes WHERE router_id = $1`, id)
	defer rows.Close()
	for rows.Next() {
		var n RouterNode
		if rows.Scan(&n.ID, &n.Hostname, &n.IPAddress, &n.Weight, &n.Healthy, &n.Region) == nil {
			n.RouterID = id
			r.Nodes = append(r.Nodes, n)
		}
	}

	return c.JSON(fiber.Map{"router": r})
}

func (m *Module) Update(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id, _ := uuid.Parse(c.Params("id"))

	var router Router
	if err := c.BodyParser(&router); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	_, err := m.db.Exec(ctx, `
		UPDATE routers SET name = $3, description = $4, endpoint = $5, api_key = COALESCE(NULLIF($6, ''), api_key), enabled = $7
		WHERE id = $1 AND tenant_id = $2
	`, id, tenantID, router.Name, router.Description, router.Endpoint, router.APIKey, router.Enabled)

	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to update router"})
	}

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) Delete(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id, _ := uuid.Parse(c.Params("id"))

	ctx := c.Context()
	_, _ = m.db.Exec(ctx, `DELETE FROM routers WHERE id = $1 AND tenant_id = $2`, id, tenantID)

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) ListNodes(c *fiber.Ctx) error {
	routerID, _ := uuid.Parse(c.Params("id"))

	ctx := c.Context()
	rows, _ := m.db.Query(ctx, `SELECT id, hostname, ip_address, weight, healthy, region FROM router_nodes WHERE router_id = $1`, routerID)
	defer rows.Close()

	nodes := make([]RouterNode, 0)
	for rows.Next() {
		var n RouterNode
		if rows.Scan(&n.ID, &n.Hostname, &n.IPAddress, &n.Weight, &n.Healthy, &n.Region) == nil {
			n.RouterID = routerID
			nodes = append(nodes, n)
		}
	}

	return c.JSON(fiber.Map{"nodes": nodes})
}

func (m *Module) AddNode(c *fiber.Ctx) error {
	routerID, _ := uuid.Parse(c.Params("id"))
	var node RouterNode
	if err := c.BodyParser(&node); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	node.ID = uuid.New()
	node.RouterID = routerID
	if node.Weight == 0 {
		node.Weight = 1
	}

	ctx := c.Context()
	_, _ = m.db.Exec(ctx, `
		INSERT INTO router_nodes (id, router_id, hostname, ip_address, weight, healthy, region)
		VALUES ($1, $2, $3, $4, $5, $6, $7)
	`, node.ID, node.RouterID, node.Hostname, node.IPAddress, node.Weight, node.Healthy, node.Region)

	return c.Status(fiber.StatusCreated).JSON(fiber.Map{"node": node})
}

func (m *Module) UpdateNode(c *fiber.Ctx) error {
	nodeID, _ := uuid.Parse(c.Params("nodeId"))
	var node RouterNode
	if err := c.BodyParser(&node); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	_, _ = m.db.Exec(ctx, `
		UPDATE router_nodes SET hostname = $2, ip_address = $3, weight = $4, region = $5
		WHERE id = $1
	`, nodeID, node.Hostname, node.IPAddress, node.Weight, node.Region)

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) DeleteNode(c *fiber.Ctx) error {
	nodeID, _ := uuid.Parse(c.Params("nodeId"))
	ctx := c.Context()
	_, _ = m.db.Exec(ctx, `DELETE FROM router_nodes WHERE id = $1`, nodeID)
	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) Sync(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id, _ := uuid.Parse(c.Params("id"))

	// Update status to syncing
	ctx := c.Context()
	_, _ = m.db.Exec(ctx, `UPDATE routers SET status = 'syncing' WHERE id = $1 AND tenant_id = $2`, id, tenantID)

	// Would trigger actual sync to remote router
	go func() {
		time.Sleep(2 * time.Second)
		m.db.Exec(context.Background(), `
			UPDATE routers SET status = 'online', last_sync = NOW() WHERE id = $1
		`, id)
	}()

	return c.JSON(fiber.Map{"message": "Sync initiated"})
}

// RSL handlers
func (m *Module) ListRegions(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	ctx := c.Context()
	rows, _ := m.db.Query(ctx, `SELECT id, name, code, countries, enabled FROM rsl_regions WHERE tenant_id = $1`, tenantID)
	defer rows.Close()

	regions := make([]Region, 0)
	for rows.Next() {
		var r Region
		if rows.Scan(&r.ID, &r.Name, &r.Code, &r.Countries, &r.Enabled) == nil {
			regions = append(regions, r)
		}
	}

	return c.JSON(fiber.Map{"regions": regions})
}

func (m *Module) CreateRegion(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	var region Region
	if err := c.BodyParser(&region); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	region.ID = uuid.New()
	region.TenantID = tenantID

	ctx := c.Context()
	_, _ = m.db.Exec(ctx, `
		INSERT INTO rsl_regions (id, tenant_id, name, code, countries, enabled)
		VALUES ($1, $2, $3, $4, $5, $6)
	`, region.ID, region.TenantID, region.Name, region.Code, region.Countries, region.Enabled)

	return c.Status(fiber.StatusCreated).JSON(fiber.Map{"region": region})
}

func (m *Module) UpdateRegion(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id, _ := uuid.Parse(c.Params("id"))

	var region Region
	if err := c.BodyParser(&region); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	_, _ = m.db.Exec(ctx, `
		UPDATE rsl_regions SET name = $3, code = $4, countries = $5, enabled = $6
		WHERE id = $1 AND tenant_id = $2
	`, id, tenantID, region.Name, region.Code, region.Countries, region.Enabled)

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) DeleteRegion(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id, _ := uuid.Parse(c.Params("id"))

	ctx := c.Context()
	_, _ = m.db.Exec(ctx, `DELETE FROM rsl_regions WHERE id = $1 AND tenant_id = $2`, id, tenantID)

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) GetSiteRSL(c *fiber.Ctx) error {
	siteID, _ := uuid.Parse(c.Params("siteId"))

	ctx := c.Context()
	var config SiteRSLConfig
	config.SiteID = siteID

	err := m.db.QueryRow(ctx, `
		SELECT enabled, default_region, failover FROM site_rsl_config WHERE site_id = $1
	`, siteID).Scan(&config.Enabled, &config.DefaultRegion, &config.Failover)

	if err != nil {
		config.Enabled = false
	}

	return c.JSON(fiber.Map{"config": config})
}

func (m *Module) UpdateSiteRSL(c *fiber.Ctx) error {
	siteID, _ := uuid.Parse(c.Params("siteId"))

	var config SiteRSLConfig
	if err := c.BodyParser(&config); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	_, _ = m.db.Exec(ctx, `
		INSERT INTO site_rsl_config (site_id, enabled, default_region, failover)
		VALUES ($1, $2, $3, $4)
		ON CONFLICT (site_id) DO UPDATE SET enabled = $2, default_region = $3, failover = $4
	`, siteID, config.Enabled, config.DefaultRegion, config.Failover)

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) TestRSL(c *fiber.Ctx) error {
	var req struct {
		ClientIP string `json:"clientIp"`
		Domain   string `json:"domain"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	// Would perform RSL lookup and return which backend would be selected
	return c.JSON(fiber.Map{
		"clientIp":       req.ClientIP,
		"domain":         req.Domain,
		"detectedRegion": "us-east",
		"selectedBackend": "backend-1.example.com",
	})
}
