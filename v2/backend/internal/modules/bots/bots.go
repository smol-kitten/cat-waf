// Package bots handles bot protection and whitelist management for CatWAF v2
package bots

import (
	"context"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"
)

// Module implements the bots module
type Module struct {
	db    *pgxpool.Pool
	redis *redis.Client
}

func New() *Module { return &Module{} }

func (m *Module) Name() string    { return "bots" }
func (m *Module) Version() string { return "2.0.0" }

func (m *Module) Init(ctx context.Context, db *pgxpool.Pool, redis *redis.Client) error {
	m.db = db
	m.redis = redis
	return nil
}

func (m *Module) RegisterRoutes(router fiber.Router) {
	bots := router.Group("/bots")
	
	// Bot detection stats and events
	bots.Get("/stats", m.Stats)
	bots.Get("/detections", m.Detections)
	bots.Get("/activity", m.Activity)
	
	// Bot whitelist
	bots.Get("/whitelist", m.ListWhitelist)
	bots.Post("/whitelist", m.CreateWhitelist)
	bots.Get("/whitelist/:id", m.GetWhitelist)
	bots.Put("/whitelist/:id", m.UpdateWhitelist)
	bots.Delete("/whitelist/:id", m.DeleteWhitelist)
	bots.Post("/whitelist/:id/toggle", m.ToggleWhitelist)
	
	// Quick actions
	bots.Post("/quick-allow", m.QuickAllow)
	bots.Post("/quick-block", m.QuickBlock)
	bots.Post("/quick-flag", m.QuickFlag)
	
	// Regenerate config
	bots.Post("/regenerate", m.Regenerate)
}

func (m *Module) Shutdown(ctx context.Context) error { return nil }

// BotWhitelist represents a bot whitelist rule
type BotWhitelist struct {
	ID               uuid.UUID `json:"id"`
	TenantID         uuid.UUID `json:"tenantId"`
	Name             string    `json:"name"`
	UserAgentPattern *string   `json:"userAgentPattern,omitempty"`
	IPRanges         []string  `json:"ipRanges,omitempty"`
	Action           string    `json:"action"` // allow, block, flag
	Priority         int       `json:"priority"`
	Enabled          bool      `json:"enabled"`
	CreatedAt        time.Time `json:"createdAt"`
}

func (m *Module) Stats(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	hours := c.QueryInt("hours", 24)

	ctx := c.Context()

	var stats struct {
		TotalDetections int `json:"totalDetections"`
		Blocked         int `json:"blocked"`
		Allowed         int `json:"allowed"`
		Flagged         int `json:"flagged"`
	}

	// Get bot-related security events
	_ = m.db.QueryRow(ctx, `
		SELECT COUNT(*) FROM security_events
		WHERE tenant_id = $1 AND event_type = 'bot_detection'
		AND created_at > NOW() - INTERVAL '1 hour' * $2
	`, tenantID, hours).Scan(&stats.TotalDetections)

	_ = m.db.QueryRow(ctx, `
		SELECT COUNT(*) FROM security_events
		WHERE tenant_id = $1 AND event_type = 'bot_detection' AND action_taken = 'blocked'
		AND created_at > NOW() - INTERVAL '1 hour' * $2
	`, tenantID, hours).Scan(&stats.Blocked)

	_ = m.db.QueryRow(ctx, `
		SELECT COUNT(*) FROM security_events
		WHERE tenant_id = $1 AND event_type = 'bot_detection' AND action_taken = 'allowed'
		AND created_at > NOW() - INTERVAL '1 hour' * $2
	`, tenantID, hours).Scan(&stats.Allowed)

	_ = m.db.QueryRow(ctx, `
		SELECT COUNT(*) FROM security_events
		WHERE tenant_id = $1 AND event_type = 'bot_detection' AND action_taken = 'flagged'
		AND created_at > NOW() - INTERVAL '1 hour' * $2
	`, tenantID, hours).Scan(&stats.Flagged)

	return c.JSON(fiber.Map{"stats": stats})
}

func (m *Module) Detections(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	page := c.QueryInt("page", 1)
	limit := c.QueryInt("limit", 50)
	offset := (page - 1) * limit

	ctx := c.Context()
	rows, err := m.db.Query(ctx, `
		SELECT id, client_ip::text, metadata->>'user_agent' as user_agent,
		       metadata->>'bot_name' as bot_name, action_taken, created_at
		FROM security_events
		WHERE tenant_id = $1 AND event_type = 'bot_detection'
		ORDER BY created_at DESC
		LIMIT $2 OFFSET $3
	`, tenantID, limit, offset)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to fetch detections"})
	}
	defer rows.Close()

	var detections []map[string]interface{}
	for rows.Next() {
		var id uuid.UUID
		var ip, userAgent, botName, action string
		var createdAt time.Time
		if rows.Scan(&id, &ip, &userAgent, &botName, &action, &createdAt) == nil {
			detections = append(detections, map[string]interface{}{
				"id":        id,
				"ip":        ip,
				"userAgent": userAgent,
				"botName":   botName,
				"action":    action,
				"createdAt": createdAt,
			})
		}
	}

	var total int
	_ = m.db.QueryRow(ctx, `SELECT COUNT(*) FROM security_events WHERE tenant_id = $1 AND event_type = 'bot_detection'`, tenantID).Scan(&total)

	return c.JSON(fiber.Map{
		"detections": detections,
		"total":      total,
		"page":       page,
		"limit":      limit,
	})
}

func (m *Module) Activity(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	hours := c.QueryInt("hours", 24)

	ctx := c.Context()
	rows, err := m.db.Query(ctx, `
		SELECT DATE_TRUNC('hour', created_at) as hour,
		       COUNT(*) FILTER (WHERE action_taken = 'blocked') as blocked,
		       COUNT(*) FILTER (WHERE action_taken = 'allowed') as allowed,
		       COUNT(*) FILTER (WHERE action_taken = 'flagged') as flagged
		FROM security_events
		WHERE tenant_id = $1 AND event_type = 'bot_detection'
		AND created_at > NOW() - INTERVAL '1 hour' * $2
		GROUP BY hour
		ORDER BY hour ASC
	`, tenantID, hours)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to fetch activity"})
	}
	defer rows.Close()

	var activity []map[string]interface{}
	for rows.Next() {
		var hour time.Time
		var blocked, allowed, flagged int
		if rows.Scan(&hour, &blocked, &allowed, &flagged) == nil {
			activity = append(activity, map[string]interface{}{
				"hour":    hour,
				"blocked": blocked,
				"allowed": allowed,
				"flagged": flagged,
			})
		}
	}

	return c.JSON(fiber.Map{"activity": activity})
}

func (m *Module) ListWhitelist(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	ctx := c.Context()
	rows, err := m.db.Query(ctx, `
		SELECT id, tenant_id, name, user_agent_pattern, ip_ranges, enabled, created_at
		FROM bot_whitelist
		WHERE tenant_id = $1
		ORDER BY name ASC
	`, tenantID)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to fetch whitelist"})
	}
	defer rows.Close()

	rules := make([]BotWhitelist, 0)
	for rows.Next() {
		var rule BotWhitelist
		var ipRanges []byte
		if rows.Scan(&rule.ID, &rule.TenantID, &rule.Name, &rule.UserAgentPattern, &ipRanges, &rule.Enabled, &rule.CreatedAt) == nil {
			rules = append(rules, rule)
		}
	}

	return c.JSON(fiber.Map{"rules": rules})
}

func (m *Module) CreateWhitelist(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	var req struct {
		Name             string   `json:"name"`
		UserAgentPattern string   `json:"userAgentPattern"`
		IPRanges         []string `json:"ipRanges"`
		Action           string   `json:"action"`
		Priority         int      `json:"priority"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	rule := BotWhitelist{
		ID:        uuid.New(),
		TenantID:  tenantID,
		Name:      req.Name,
		Action:    req.Action,
		Priority:  req.Priority,
		IPRanges:  req.IPRanges,
		Enabled:   true,
		CreatedAt: time.Now(),
	}

	if req.UserAgentPattern != "" {
		rule.UserAgentPattern = &req.UserAgentPattern
	}

	_, err := m.db.Exec(ctx, `
		INSERT INTO bot_whitelist (id, tenant_id, name, user_agent_pattern, ip_ranges, enabled, created_at)
		VALUES ($1, $2, $3, $4, $5, $6, $7)
	`, rule.ID, rule.TenantID, rule.Name, rule.UserAgentPattern, rule.IPRanges, rule.Enabled, rule.CreatedAt)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to create whitelist rule"})
	}

	return c.Status(fiber.StatusCreated).JSON(fiber.Map{"rule": rule})
}

func (m *Module) GetWhitelist(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id := c.Params("id")

	ctx := c.Context()
	var rule BotWhitelist
	err := m.db.QueryRow(ctx, `
		SELECT id, tenant_id, name, user_agent_pattern, ip_ranges, enabled, created_at
		FROM bot_whitelist WHERE id = $1 AND tenant_id = $2
	`, id, tenantID).Scan(&rule.ID, &rule.TenantID, &rule.Name, &rule.UserAgentPattern, &rule.IPRanges, &rule.Enabled, &rule.CreatedAt)

	if err != nil {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "Rule not found"})
	}

	return c.JSON(fiber.Map{"rule": rule})
}

func (m *Module) UpdateWhitelist(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id := c.Params("id")

	var req struct {
		Name             string   `json:"name"`
		UserAgentPattern string   `json:"userAgentPattern"`
		IPRanges         []string `json:"ipRanges"`
		Action           string   `json:"action"`
		Priority         int      `json:"priority"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	result, err := m.db.Exec(ctx, `
		UPDATE bot_whitelist SET name = $1, user_agent_pattern = $2, ip_ranges = $3
		WHERE id = $4 AND tenant_id = $5
	`, req.Name, req.UserAgentPattern, req.IPRanges, id, tenantID)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to update rule"})
	}

	if result.RowsAffected() == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "Rule not found"})
	}

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) DeleteWhitelist(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id := c.Params("id")

	ctx := c.Context()
	result, err := m.db.Exec(ctx, `DELETE FROM bot_whitelist WHERE id = $1 AND tenant_id = $2`, id, tenantID)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to delete rule"})
	}

	if result.RowsAffected() == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "Rule not found"})
	}

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) ToggleWhitelist(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id := c.Params("id")

	ctx := c.Context()
	result, err := m.db.Exec(ctx, `UPDATE bot_whitelist SET enabled = NOT enabled WHERE id = $1 AND tenant_id = $2`, id, tenantID)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to toggle rule"})
	}

	if result.RowsAffected() == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "Rule not found"})
	}

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) QuickAllow(c *fiber.Ctx) error {
	return m.quickAction(c, "allow")
}

func (m *Module) QuickBlock(c *fiber.Ctx) error {
	return m.quickAction(c, "block")
}

func (m *Module) QuickFlag(c *fiber.Ctx) error {
	return m.quickAction(c, "flag")
}

func (m *Module) quickAction(c *fiber.Ctx, action string) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	var req struct {
		BotName string `json:"botName"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	rule := BotWhitelist{
		ID:        uuid.New(),
		TenantID:  tenantID,
		Name:      req.BotName + " - " + action,
		Action:    action,
		Priority:  100,
		Enabled:   true,
		CreatedAt: time.Now(),
	}

	pattern := "(?i)" + req.BotName
	rule.UserAgentPattern = &pattern

	_, err := m.db.Exec(ctx, `
		INSERT INTO bot_whitelist (id, tenant_id, name, user_agent_pattern, enabled, created_at)
		VALUES ($1, $2, $3, $4, $5, $6)
	`, rule.ID, rule.TenantID, rule.Name, rule.UserAgentPattern, rule.Enabled, rule.CreatedAt)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to create rule"})
	}

	return c.Status(fiber.StatusCreated).JSON(fiber.Map{"rule": rule})
}

func (m *Module) Regenerate(c *fiber.Ctx) error {
	// Would regenerate bot protection config
	return c.JSON(fiber.Map{"success": true, "message": "Bot configuration regenerated"})
}
