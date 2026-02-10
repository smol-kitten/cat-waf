// Package bans handles IP ban management for CatWAF v2
package bans

import (
	"context"
	"net"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"
)

// Module implements the bans module
type Module struct {
	db    *pgxpool.Pool
	redis *redis.Client
}

// New creates a new bans module
func New() *Module {
	return &Module{}
}

func (m *Module) Name() string    { return "bans" }
func (m *Module) Version() string { return "2.0.0" }

func (m *Module) Init(ctx context.Context, db *pgxpool.Pool, redis *redis.Client) error {
	m.db = db
	m.redis = redis
	return nil
}

func (m *Module) RegisterRoutes(router fiber.Router) {
	bans := router.Group("/bans")
	bans.Get("/", m.List)
	bans.Post("/", m.Create)
	bans.Get("/:id", m.Get)
	bans.Delete("/:id", m.Delete)
	bans.Delete("/ip/:ip", m.DeleteByIP)
	bans.Get("/check/:ip", m.Check)
	bans.Post("/bulk", m.BulkCreate)
	bans.Delete("/bulk", m.BulkDelete)
	bans.Get("/stats", m.Stats)
}

func (m *Module) Shutdown(ctx context.Context) error {
	return nil
}

// BannedIP represents a banned IP address
type BannedIP struct {
	ID        uuid.UUID  `json:"id"`
	TenantID  uuid.UUID  `json:"tenantId"`
	SiteID    *uuid.UUID `json:"siteId,omitempty"`
	IPAddress string     `json:"ipAddress"`
	Reason    string     `json:"reason"`
	Source    string     `json:"source"`
	ExpiresAt *time.Time `json:"expiresAt,omitempty"`
	CreatedAt time.Time  `json:"createdAt"`
}

// CreateBanRequest represents a request to ban an IP
type CreateBanRequest struct {
	IPAddress string  `json:"ipAddress"`
	SiteID    string  `json:"siteId,omitempty"`
	Reason    string  `json:"reason"`
	Duration  *int    `json:"duration,omitempty"` // minutes, null = permanent
	Source    string  `json:"source,omitempty"`
}

// List returns all banned IPs
func (m *Module) List(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	siteID := c.Query("siteId")
	page := c.QueryInt("page", 1)
	limit := c.QueryInt("limit", 50)
	offset := (page - 1) * limit

	ctx := c.Context()
	var rows interface{}
	var total int

	query := `
		SELECT id, tenant_id, site_id, ip_address::text, reason, source, expires_at, created_at
		FROM banned_ips
		WHERE tenant_id = $1
		AND (expires_at IS NULL OR expires_at > NOW())
	`
	countQuery := `SELECT COUNT(*) FROM banned_ips WHERE tenant_id = $1 AND (expires_at IS NULL OR expires_at > NOW())`

	args := []interface{}{tenantID}
	if siteID != "" {
		query += " AND site_id = $2"
		countQuery += " AND site_id = $2"
		args = append(args, siteID)
	}

	query += " ORDER BY created_at DESC LIMIT $" + string(rune('0'+len(args)+1)) + " OFFSET $" + string(rune('0'+len(args)+2))
	args = append(args, limit, offset)

	// Get total count
	_ = m.db.QueryRow(ctx, countQuery, args[:len(args)-2]...).Scan(&total)

	// Get bans
	r, err := m.db.Query(ctx, query, args...)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to fetch bans",
		})
	}
	defer r.Close()

	bans := make([]BannedIP, 0)
	for r.Next() {
		var ban BannedIP
		if err := r.Scan(&ban.ID, &ban.TenantID, &ban.SiteID, &ban.IPAddress, &ban.Reason, &ban.Source, &ban.ExpiresAt, &ban.CreatedAt); err == nil {
			bans = append(bans, ban)
		}
	}

	rows = bans

	return c.JSON(fiber.Map{
		"bans":       rows,
		"total":      total,
		"page":       page,
		"limit":      limit,
		"totalPages": (total + limit - 1) / limit,
	})
}

// Create bans a new IP address
func (m *Module) Create(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	var req CreateBanRequest
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{
			"error": "Invalid request body",
		})
	}

	// Validate IP address
	if net.ParseIP(req.IPAddress) == nil {
		// Try parsing as CIDR
		_, _, err := net.ParseCIDR(req.IPAddress)
		if err != nil {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{
				"error": "Invalid IP address or CIDR",
			})
		}
	}

	ctx := c.Context()
	ban := BannedIP{
		ID:        uuid.New(),
		TenantID:  tenantID,
		IPAddress: req.IPAddress,
		Reason:    req.Reason,
		Source:    "manual",
		CreatedAt: time.Now(),
	}

	if req.Source != "" {
		ban.Source = req.Source
	}

	if req.Duration != nil && *req.Duration > 0 {
		expires := time.Now().Add(time.Duration(*req.Duration) * time.Minute)
		ban.ExpiresAt = &expires
	}

	var siteID *uuid.UUID
	if req.SiteID != "" {
		id, err := uuid.Parse(req.SiteID)
		if err == nil {
			siteID = &id
			ban.SiteID = siteID
		}
	}

	_, err := m.db.Exec(ctx,
		`INSERT INTO banned_ips (id, tenant_id, site_id, ip_address, reason, source, expires_at, created_at)
		 VALUES ($1, $2, $3, $4::inet, $5, $6, $7, $8)
		 ON CONFLICT (tenant_id, site_id, ip_address) DO UPDATE SET
		 reason = EXCLUDED.reason, expires_at = EXCLUDED.expires_at`,
		ban.ID, ban.TenantID, siteID, ban.IPAddress, ban.Reason, ban.Source, ban.ExpiresAt, ban.CreatedAt,
	)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to create ban",
		})
	}

	// Update ban cache in Redis
	_ = m.redis.SAdd(ctx, "banned_ips:"+tenantID.String(), ban.IPAddress).Err()

	return c.Status(fiber.StatusCreated).JSON(fiber.Map{
		"ban": ban,
	})
}

// Get returns a specific banned IP
func (m *Module) Get(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id := c.Params("id")

	ctx := c.Context()
	var ban BannedIP
	err := m.db.QueryRow(ctx,
		`SELECT id, tenant_id, site_id, ip_address::text, reason, source, expires_at, created_at
		 FROM banned_ips WHERE id = $1 AND tenant_id = $2`,
		id, tenantID,
	).Scan(&ban.ID, &ban.TenantID, &ban.SiteID, &ban.IPAddress, &ban.Reason, &ban.Source, &ban.ExpiresAt, &ban.CreatedAt)

	if err != nil {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{
			"error": "Ban not found",
		})
	}

	return c.JSON(fiber.Map{"ban": ban})
}

// Delete removes a banned IP by ID
func (m *Module) Delete(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id := c.Params("id")

	ctx := c.Context()

	// Get IP before deleting
	var ipAddress string
	_ = m.db.QueryRow(ctx, `SELECT ip_address::text FROM banned_ips WHERE id = $1 AND tenant_id = $2`, id, tenantID).Scan(&ipAddress)

	result, err := m.db.Exec(ctx,
		`DELETE FROM banned_ips WHERE id = $1 AND tenant_id = $2`,
		id, tenantID,
	)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to delete ban",
		})
	}

	if result.RowsAffected() == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{
			"error": "Ban not found",
		})
	}

	// Remove from Redis cache
	if ipAddress != "" {
		_ = m.redis.SRem(ctx, "banned_ips:"+tenantID.String(), ipAddress).Err()
	}

	return c.JSON(fiber.Map{"success": true})
}

// DeleteByIP removes a banned IP by address
func (m *Module) DeleteByIP(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	ip := c.Params("ip")

	ctx := c.Context()
	result, err := m.db.Exec(ctx,
		`DELETE FROM banned_ips WHERE ip_address = $1::inet AND tenant_id = $2`,
		ip, tenantID,
	)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to delete ban",
		})
	}

	if result.RowsAffected() == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{
			"error": "Ban not found",
		})
	}

	// Remove from Redis cache
	_ = m.redis.SRem(ctx, "banned_ips:"+tenantID.String(), ip).Err()

	return c.JSON(fiber.Map{"success": true})
}

// Check checks if an IP is banned
func (m *Module) Check(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	ip := c.Params("ip")

	ctx := c.Context()

	// Check Redis cache first
	isBanned, err := m.redis.SIsMember(ctx, "banned_ips:"+tenantID.String(), ip).Result()
	if err == nil && isBanned {
		return c.JSON(fiber.Map{"banned": true})
	}

	// Check database
	var count int
	err = m.db.QueryRow(ctx,
		`SELECT COUNT(*) FROM banned_ips
		 WHERE ip_address = $1::inet AND tenant_id = $2
		 AND (expires_at IS NULL OR expires_at > NOW())`,
		ip, tenantID,
	).Scan(&count)

	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to check ban status",
		})
	}

	return c.JSON(fiber.Map{"banned": count > 0})
}

// BulkCreate bans multiple IPs at once
func (m *Module) BulkCreate(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	var req struct {
		IPs      []string `json:"ips"`
		Reason   string   `json:"reason"`
		Duration *int     `json:"duration,omitempty"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{
			"error": "Invalid request body",
		})
	}

	ctx := c.Context()
	created := 0

	for _, ip := range req.IPs {
		// Validate
		if net.ParseIP(ip) == nil {
			continue
		}

		var expires *time.Time
		if req.Duration != nil && *req.Duration > 0 {
			t := time.Now().Add(time.Duration(*req.Duration) * time.Minute)
			expires = &t
		}

		_, err := m.db.Exec(ctx,
			`INSERT INTO banned_ips (id, tenant_id, ip_address, reason, source, expires_at, created_at)
			 VALUES ($1, $2, $3::inet, $4, 'bulk', $5, NOW())
			 ON CONFLICT (tenant_id, site_id, ip_address) DO NOTHING`,
			uuid.New(), tenantID, ip, req.Reason, expires,
		)
		if err == nil {
			created++
			_ = m.redis.SAdd(ctx, "banned_ips:"+tenantID.String(), ip).Err()
		}
	}

	return c.JSON(fiber.Map{
		"created": created,
		"total":   len(req.IPs),
	})
}

// BulkDelete removes multiple bans
func (m *Module) BulkDelete(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	var req struct {
		IDs []string `json:"ids"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{
			"error": "Invalid request body",
		})
	}

	ctx := c.Context()
	deleted := 0

	for _, id := range req.IDs {
		var ip string
		_ = m.db.QueryRow(ctx, `SELECT ip_address::text FROM banned_ips WHERE id = $1 AND tenant_id = $2`, id, tenantID).Scan(&ip)

		result, err := m.db.Exec(ctx,
			`DELETE FROM banned_ips WHERE id = $1 AND tenant_id = $2`,
			id, tenantID,
		)
		if err == nil && result.RowsAffected() > 0 {
			deleted++
			if ip != "" {
				_ = m.redis.SRem(ctx, "banned_ips:"+tenantID.String(), ip).Err()
			}
		}
	}

	return c.JSON(fiber.Map{
		"deleted": deleted,
		"total":   len(req.IDs),
	})
}

// Stats returns ban statistics
func (m *Module) Stats(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	ctx := c.Context()
	var stats struct {
		Total     int `json:"total"`
		Active    int `json:"active"`
		Permanent int `json:"permanent"`
		Temporary int `json:"temporary"`
		Manual    int `json:"manual"`
		Automatic int `json:"automatic"`
	}

	// Total bans
	_ = m.db.QueryRow(ctx,
		`SELECT COUNT(*) FROM banned_ips WHERE tenant_id = $1`,
		tenantID,
	).Scan(&stats.Total)

	// Active (not expired)
	_ = m.db.QueryRow(ctx,
		`SELECT COUNT(*) FROM banned_ips WHERE tenant_id = $1 AND (expires_at IS NULL OR expires_at > NOW())`,
		tenantID,
	).Scan(&stats.Active)

	// Permanent
	_ = m.db.QueryRow(ctx,
		`SELECT COUNT(*) FROM banned_ips WHERE tenant_id = $1 AND expires_at IS NULL`,
		tenantID,
	).Scan(&stats.Permanent)

	// Temporary
	_ = m.db.QueryRow(ctx,
		`SELECT COUNT(*) FROM banned_ips WHERE tenant_id = $1 AND expires_at IS NOT NULL AND expires_at > NOW()`,
		tenantID,
	).Scan(&stats.Temporary)

	// By source
	_ = m.db.QueryRow(ctx,
		`SELECT COUNT(*) FROM banned_ips WHERE tenant_id = $1 AND source = 'manual'`,
		tenantID,
	).Scan(&stats.Manual)

	_ = m.db.QueryRow(ctx,
		`SELECT COUNT(*) FROM banned_ips WHERE tenant_id = $1 AND source != 'manual'`,
		tenantID,
	).Scan(&stats.Automatic)

	return c.JSON(fiber.Map{"stats": stats})
}
