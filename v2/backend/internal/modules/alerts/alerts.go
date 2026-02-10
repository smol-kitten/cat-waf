// Package alerts handles alert rules and notifications for CatWAF v2
package alerts

import (
	"context"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"
)

// Module implements the alerts module
type Module struct {
	db    *pgxpool.Pool
	redis *redis.Client
}

func New() *Module { return &Module{} }

func (m *Module) Name() string    { return "alerts" }
func (m *Module) Version() string { return "2.0.0" }

func (m *Module) Init(ctx context.Context, db *pgxpool.Pool, redis *redis.Client) error {
	m.db = db
	m.redis = redis
	return nil
}

func (m *Module) RegisterRoutes(router fiber.Router) {
	alerts := router.Group("/alerts")
	alerts.Get("/", m.List)
	alerts.Post("/", m.Create)
	alerts.Get("/:id", m.Get)
	alerts.Put("/:id", m.Update)
	alerts.Delete("/:id", m.Delete)
	alerts.Post("/:id/toggle", m.Toggle)
	alerts.Get("/history", m.History)
	alerts.Post("/history/:id/acknowledge", m.Acknowledge)
}

func (m *Module) Shutdown(ctx context.Context) error { return nil }

// AlertRule represents an alert rule
type AlertRule struct {
	ID              uuid.UUID              `json:"id"`
	TenantID        uuid.UUID              `json:"tenantId"`
	SiteID          *uuid.UUID             `json:"siteId,omitempty"`
	Name            string                 `json:"name"`
	Description     *string                `json:"description,omitempty"`
	Condition       map[string]interface{} `json:"condition"`
	Actions         []map[string]interface{} `json:"actions"`
	CooldownMinutes int                    `json:"cooldownMinutes"`
	Enabled         bool                   `json:"enabled"`
	LastTriggeredAt *time.Time             `json:"lastTriggeredAt,omitempty"`
	CreatedAt       time.Time              `json:"createdAt"`
	UpdatedAt       time.Time              `json:"updatedAt"`
}

// AlertHistory represents a triggered alert
type AlertHistory struct {
	ID           uuid.UUID              `json:"id"`
	AlertRuleID  uuid.UUID              `json:"alertRuleId"`
	RuleName     string                 `json:"ruleName"`
	TriggeredAt  time.Time              `json:"triggeredAt"`
	ConditionMet map[string]interface{} `json:"conditionMet"`
	ActionsTaken []map[string]interface{} `json:"actionsTaken"`
	ResolvedAt   *time.Time             `json:"resolvedAt,omitempty"`
	Acknowledged bool                   `json:"acknowledged"`
}

func (m *Module) List(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	ctx := c.Context()

	rows, err := m.db.Query(ctx,
		`SELECT ar.id, ar.tenant_id, ar.site_id, ar.name, ar.description, ar.condition, ar.actions,
		        ar.cooldown_minutes, ar.enabled, ar.last_triggered_at, ar.created_at, ar.updated_at,
		        s.domain as site_domain
		 FROM alert_rules ar
		 LEFT JOIN sites s ON ar.site_id = s.id
		 WHERE ar.tenant_id = $1
		 ORDER BY ar.created_at DESC`,
		tenantID,
	)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to fetch alert rules"})
	}
	defer rows.Close()

	type AlertRuleWithSite struct {
		AlertRule
		SiteDomain *string `json:"siteDomain,omitempty"`
	}

	rules := make([]AlertRuleWithSite, 0)
	for rows.Next() {
		var rule AlertRuleWithSite
		err := rows.Scan(&rule.ID, &rule.TenantID, &rule.SiteID, &rule.Name, &rule.Description,
			&rule.Condition, &rule.Actions, &rule.CooldownMinutes, &rule.Enabled,
			&rule.LastTriggeredAt, &rule.CreatedAt, &rule.UpdatedAt, &rule.SiteDomain)
		if err == nil {
			rules = append(rules, rule)
		}
	}

	return c.JSON(fiber.Map{"rules": rules})
}

func (m *Module) Create(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	var req struct {
		Name            string                 `json:"name"`
		Description     string                 `json:"description"`
		SiteID          string                 `json:"siteId"`
		Condition       map[string]interface{} `json:"condition"`
		Actions         []map[string]interface{} `json:"actions"`
		CooldownMinutes int                    `json:"cooldownMinutes"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	rule := AlertRule{
		ID:              uuid.New(),
		TenantID:        tenantID,
		Name:            req.Name,
		Condition:       req.Condition,
		Actions:         req.Actions,
		CooldownMinutes: req.CooldownMinutes,
		Enabled:         true,
		CreatedAt:       time.Now(),
		UpdatedAt:       time.Now(),
	}

	if req.CooldownMinutes == 0 {
		rule.CooldownMinutes = 15
	}

	if req.Description != "" {
		rule.Description = &req.Description
	}

	var siteID *uuid.UUID
	if req.SiteID != "" {
		id, _ := uuid.Parse(req.SiteID)
		siteID = &id
		rule.SiteID = siteID
	}

	_, err := m.db.Exec(ctx,
		`INSERT INTO alert_rules (id, tenant_id, site_id, name, description, condition, actions, cooldown_minutes, enabled, created_at, updated_at)
		 VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)`,
		rule.ID, rule.TenantID, siteID, rule.Name, rule.Description, rule.Condition,
		rule.Actions, rule.CooldownMinutes, rule.Enabled, rule.CreatedAt, rule.UpdatedAt,
	)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to create alert rule"})
	}

	return c.Status(fiber.StatusCreated).JSON(fiber.Map{"rule": rule})
}

func (m *Module) Get(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id := c.Params("id")

	ctx := c.Context()
	var rule AlertRule
	err := m.db.QueryRow(ctx,
		`SELECT id, tenant_id, site_id, name, description, condition, actions, cooldown_minutes, enabled, last_triggered_at, created_at, updated_at
		 FROM alert_rules WHERE id = $1 AND tenant_id = $2`,
		id, tenantID,
	).Scan(&rule.ID, &rule.TenantID, &rule.SiteID, &rule.Name, &rule.Description, &rule.Condition,
		&rule.Actions, &rule.CooldownMinutes, &rule.Enabled, &rule.LastTriggeredAt, &rule.CreatedAt, &rule.UpdatedAt)

	if err != nil {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "Alert rule not found"})
	}

	return c.JSON(fiber.Map{"rule": rule})
}

func (m *Module) Update(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id := c.Params("id")

	var req struct {
		Name            string                 `json:"name"`
		Description     string                 `json:"description"`
		Condition       map[string]interface{} `json:"condition"`
		Actions         []map[string]interface{} `json:"actions"`
		CooldownMinutes int                    `json:"cooldownMinutes"`
		Enabled         *bool                  `json:"enabled"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	result, err := m.db.Exec(ctx,
		`UPDATE alert_rules SET 
		 name = COALESCE(NULLIF($1, ''), name),
		 description = $2,
		 condition = COALESCE($3, condition),
		 actions = COALESCE($4, actions),
		 cooldown_minutes = COALESCE(NULLIF($5, 0), cooldown_minutes),
		 enabled = COALESCE($6, enabled),
		 updated_at = NOW()
		 WHERE id = $7 AND tenant_id = $8`,
		req.Name, req.Description, req.Condition, req.Actions, req.CooldownMinutes, req.Enabled, id, tenantID,
	)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to update alert rule"})
	}

	if result.RowsAffected() == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "Alert rule not found"})
	}

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) Delete(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id := c.Params("id")

	ctx := c.Context()
	result, err := m.db.Exec(ctx, `DELETE FROM alert_rules WHERE id = $1 AND tenant_id = $2`, id, tenantID)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to delete alert rule"})
	}

	if result.RowsAffected() == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "Alert rule not found"})
	}

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) Toggle(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id := c.Params("id")

	ctx := c.Context()
	result, err := m.db.Exec(ctx,
		`UPDATE alert_rules SET enabled = NOT enabled, updated_at = NOW() WHERE id = $1 AND tenant_id = $2`,
		id, tenantID,
	)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to toggle alert rule"})
	}

	if result.RowsAffected() == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "Alert rule not found"})
	}

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) History(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	limit := c.QueryInt("limit", 50)

	ctx := c.Context()
	rows, err := m.db.Query(ctx,
		`SELECT ah.id, ah.alert_rule_id, ar.name, ah.triggered_at, ah.condition_met, ah.actions_taken, ah.resolved_at
		 FROM alert_history ah
		 JOIN alert_rules ar ON ah.alert_rule_id = ar.id
		 WHERE ar.tenant_id = $1
		 ORDER BY ah.triggered_at DESC
		 LIMIT $2`,
		tenantID, limit,
	)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to fetch alert history"})
	}
	defer rows.Close()

	history := make([]AlertHistory, 0)
	for rows.Next() {
		var h AlertHistory
		if rows.Scan(&h.ID, &h.AlertRuleID, &h.RuleName, &h.TriggeredAt, &h.ConditionMet, &h.ActionsTaken, &h.ResolvedAt) == nil {
			h.Acknowledged = h.ResolvedAt != nil
			history = append(history, h)
		}
	}

	return c.JSON(fiber.Map{"history": history})
}

func (m *Module) Acknowledge(c *fiber.Ctx) error {
	id := c.Params("id")

	ctx := c.Context()
	result, err := m.db.Exec(ctx,
		`UPDATE alert_history SET resolved_at = NOW() WHERE id = $1 AND resolved_at IS NULL`,
		id,
	)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to acknowledge alert"})
	}

	if result.RowsAffected() == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "Alert not found or already acknowledged"})
	}

	return c.JSON(fiber.Map{"success": true})
}
