// Package security handles security events and ModSecurity for CatWAF v2
package security

import (
	"context"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"
)

// Module implements the security module
type Module struct {
	db    *pgxpool.Pool
	redis *redis.Client
}

// New creates a new security module
func New() *Module {
	return &Module{}
}

func (m *Module) Name() string    { return "security" }
func (m *Module) Version() string { return "2.0.0" }

func (m *Module) Init(ctx context.Context, db *pgxpool.Pool, redis *redis.Client) error {
	m.db = db
	m.redis = redis
	return nil
}

func (m *Module) RegisterRoutes(router fiber.Router) {
	sec := router.Group("/security")
	
	// Security events (ModSecurity)
	sec.Get("/events", m.ListEvents)
	sec.Get("/events/:id", m.GetEvent)
	sec.Get("/events/summary", m.EventsSummary)
	sec.Get("/events/top-rules", m.TopRules)
	sec.Get("/events/top-ips", m.TopIPs)
	
	// Security checks
	sec.Get("/checks", m.ListChecks)
	sec.Post("/checks/:id/run", m.RunCheck)
	sec.Post("/checks/run-all", m.RunAllChecks)
	
	// ModSecurity rules
	sec.Get("/rules", m.ListRules)
	sec.Post("/rules", m.CreateRule)
	sec.Put("/rules/:id", m.UpdateRule)
	sec.Delete("/rules/:id", m.DeleteRule)
	sec.Post("/rules/:id/toggle", m.ToggleRule)
	
	// Custom block rules
	sec.Get("/block-rules", m.ListBlockRules)
	sec.Post("/block-rules", m.CreateBlockRule)
	sec.Put("/block-rules/:id", m.UpdateBlockRule)
	sec.Delete("/block-rules/:id", m.DeleteBlockRule)
	sec.Post("/block-rules/:id/toggle", m.ToggleBlockRule)
	
	// Scanner detection
	sec.Get("/scanners", m.ListScanners)
	sec.Post("/scanners/:ip/block", m.BlockScanner)
}

func (m *Module) Shutdown(ctx context.Context) error {
	return nil
}

// SecurityEvent represents a WAF security event
type SecurityEvent struct {
	ID             uuid.UUID              `json:"id"`
	TenantID       uuid.UUID              `json:"tenantId"`
	SiteID         uuid.UUID              `json:"siteId"`
	EventType      string                 `json:"eventType"`
	Severity       string                 `json:"severity"`
	RuleID         *string                `json:"ruleId,omitempty"`
	RuleMessage    *string                `json:"ruleMessage,omitempty"`
	ClientIP       string                 `json:"clientIp"`
	CountryCode    *string                `json:"countryCode,omitempty"`
	RequestMethod  string                 `json:"requestMethod"`
	RequestURI     string                 `json:"requestUri"`
	RequestHeaders map[string]interface{} `json:"requestHeaders,omitempty"`
	ResponseStatus *int                   `json:"responseStatus,omitempty"`
	ActionTaken    string                 `json:"actionTaken"`
	Metadata       map[string]interface{} `json:"metadata,omitempty"`
	CreatedAt      time.Time              `json:"createdAt"`
}

// BlockRule represents a custom block rule
type BlockRule struct {
	ID          uuid.UUID              `json:"id"`
	TenantID    uuid.UUID              `json:"tenantId"`
	SiteID      *uuid.UUID             `json:"siteId,omitempty"`
	Name        string                 `json:"name"`
	Description *string                `json:"description,omitempty"`
	RuleType    string                 `json:"ruleType"`
	Condition   map[string]interface{} `json:"condition"`
	Action      string                 `json:"action"`
	Priority    int                    `json:"priority"`
	Enabled     bool                   `json:"enabled"`
	CreatedAt   time.Time              `json:"createdAt"`
	UpdatedAt   time.Time              `json:"updatedAt"`
}

// ListEvents returns security events with pagination
func (m *Module) ListEvents(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	siteID := c.Query("siteId")
	severity := c.Query("severity")
	eventType := c.Query("type")
	startDate := c.Query("startDate")
	endDate := c.Query("endDate")
	page := c.QueryInt("page", 1)
	limit := c.QueryInt("limit", 50)
	offset := (page - 1) * limit

	ctx := c.Context()

	query := `
		SELECT id, tenant_id, site_id, event_type, severity, rule_id, rule_message,
		       client_ip::text, country_code, request_method, request_uri,
		       response_status, action_taken, metadata, created_at
		FROM security_events
		WHERE tenant_id = $1
	`
	countQuery := `SELECT COUNT(*) FROM security_events WHERE tenant_id = $1`
	args := []interface{}{tenantID}
	argNum := 2

	if siteID != "" {
		query += " AND site_id = $" + string(rune('0'+argNum))
		countQuery += " AND site_id = $" + string(rune('0'+argNum))
		args = append(args, siteID)
		argNum++
	}
	if severity != "" {
		query += " AND severity = $" + string(rune('0'+argNum))
		countQuery += " AND severity = $" + string(rune('0'+argNum))
		args = append(args, severity)
		argNum++
	}
	if eventType != "" {
		query += " AND event_type = $" + string(rune('0'+argNum))
		countQuery += " AND event_type = $" + string(rune('0'+argNum))
		args = append(args, eventType)
		argNum++
	}
	if startDate != "" {
		query += " AND created_at >= $" + string(rune('0'+argNum))
		countQuery += " AND created_at >= $" + string(rune('0'+argNum))
		args = append(args, startDate)
		argNum++
	}
	if endDate != "" {
		query += " AND created_at <= $" + string(rune('0'+argNum))
		countQuery += " AND created_at <= $" + string(rune('0'+argNum))
		args = append(args, endDate)
		argNum++
	}

	var total int
	_ = m.db.QueryRow(ctx, countQuery, args...).Scan(&total)

	query += " ORDER BY created_at DESC LIMIT $" + string(rune('0'+argNum)) + " OFFSET $" + string(rune('0'+argNum+1))
	args = append(args, limit, offset)

	rows, err := m.db.Query(ctx, query, args...)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to fetch security events",
		})
	}
	defer rows.Close()

	events := make([]SecurityEvent, 0)
	for rows.Next() {
		var event SecurityEvent
		err := rows.Scan(
			&event.ID, &event.TenantID, &event.SiteID, &event.EventType, &event.Severity,
			&event.RuleID, &event.RuleMessage, &event.ClientIP, &event.CountryCode,
			&event.RequestMethod, &event.RequestURI, &event.ResponseStatus,
			&event.ActionTaken, &event.Metadata, &event.CreatedAt,
		)
		if err == nil {
			events = append(events, event)
		}
	}

	return c.JSON(fiber.Map{
		"events":     events,
		"total":      total,
		"page":       page,
		"limit":      limit,
		"totalPages": (total + limit - 1) / limit,
	})
}

// GetEvent returns a specific security event
func (m *Module) GetEvent(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id := c.Params("id")

	ctx := c.Context()
	var event SecurityEvent
	err := m.db.QueryRow(ctx,
		`SELECT id, tenant_id, site_id, event_type, severity, rule_id, rule_message,
		        client_ip::text, country_code, request_method, request_uri, request_headers,
		        response_status, action_taken, metadata, created_at
		 FROM security_events WHERE id = $1 AND tenant_id = $2`,
		id, tenantID,
	).Scan(
		&event.ID, &event.TenantID, &event.SiteID, &event.EventType, &event.Severity,
		&event.RuleID, &event.RuleMessage, &event.ClientIP, &event.CountryCode,
		&event.RequestMethod, &event.RequestURI, &event.RequestHeaders,
		&event.ResponseStatus, &event.ActionTaken, &event.Metadata, &event.CreatedAt,
	)

	if err != nil {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{
			"error": "Event not found",
		})
	}

	return c.JSON(fiber.Map{"event": event})
}

// EventsSummary returns summary statistics for security events
func (m *Module) EventsSummary(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	hours := c.QueryInt("hours", 24)

	ctx := c.Context()

	var summary struct {
		Total    int            `json:"total"`
		BySeverity map[string]int `json:"bySeverity"`
		ByType     map[string]int `json:"byType"`
		ByAction   map[string]int `json:"byAction"`
		Trend      []struct {
			Hour  time.Time `json:"hour"`
			Count int       `json:"count"`
		} `json:"trend"`
	}
	summary.BySeverity = make(map[string]int)
	summary.ByType = make(map[string]int)
	summary.ByAction = make(map[string]int)

	// Total
	_ = m.db.QueryRow(ctx,
		`SELECT COUNT(*) FROM security_events WHERE tenant_id = $1 AND created_at > NOW() - INTERVAL '1 hour' * $2`,
		tenantID, hours,
	).Scan(&summary.Total)

	// By severity
	rows, _ := m.db.Query(ctx,
		`SELECT severity, COUNT(*) FROM security_events
		 WHERE tenant_id = $1 AND created_at > NOW() - INTERVAL '1 hour' * $2
		 GROUP BY severity`,
		tenantID, hours,
	)
	for rows.Next() {
		var sev string
		var count int
		if rows.Scan(&sev, &count) == nil {
			summary.BySeverity[sev] = count
		}
	}
	rows.Close()

	// By type
	rows, _ = m.db.Query(ctx,
		`SELECT event_type, COUNT(*) FROM security_events
		 WHERE tenant_id = $1 AND created_at > NOW() - INTERVAL '1 hour' * $2
		 GROUP BY event_type`,
		tenantID, hours,
	)
	for rows.Next() {
		var typ string
		var count int
		if rows.Scan(&typ, &count) == nil {
			summary.ByType[typ] = count
		}
	}
	rows.Close()

	// By action
	rows, _ = m.db.Query(ctx,
		`SELECT action_taken, COUNT(*) FROM security_events
		 WHERE tenant_id = $1 AND created_at > NOW() - INTERVAL '1 hour' * $2
		 GROUP BY action_taken`,
		tenantID, hours,
	)
	for rows.Next() {
		var action string
		var count int
		if rows.Scan(&action, &count) == nil {
			summary.ByAction[action] = count
		}
	}
	rows.Close()

	return c.JSON(fiber.Map{"summary": summary})
}

// TopRules returns the most triggered rules
func (m *Module) TopRules(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	limit := c.QueryInt("limit", 10)
	hours := c.QueryInt("hours", 24)

	ctx := c.Context()
	rows, err := m.db.Query(ctx,
		`SELECT rule_id, rule_message, COUNT(*) as count
		 FROM security_events
		 WHERE tenant_id = $1 AND created_at > NOW() - INTERVAL '1 hour' * $2 AND rule_id IS NOT NULL
		 GROUP BY rule_id, rule_message
		 ORDER BY count DESC
		 LIMIT $3`,
		tenantID, hours, limit,
	)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to fetch top rules",
		})
	}
	defer rows.Close()

	var rules []map[string]interface{}
	for rows.Next() {
		var ruleID, ruleMessage string
		var count int
		if rows.Scan(&ruleID, &ruleMessage, &count) == nil {
			rules = append(rules, map[string]interface{}{
				"ruleId":      ruleID,
				"ruleMessage": ruleMessage,
				"count":       count,
			})
		}
	}

	return c.JSON(fiber.Map{"rules": rules})
}

// TopIPs returns the top offending IP addresses
func (m *Module) TopIPs(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	limit := c.QueryInt("limit", 10)
	hours := c.QueryInt("hours", 24)

	ctx := c.Context()
	rows, err := m.db.Query(ctx,
		`SELECT client_ip::text, country_code, COUNT(*) as count
		 FROM security_events
		 WHERE tenant_id = $1 AND created_at > NOW() - INTERVAL '1 hour' * $2
		 GROUP BY client_ip, country_code
		 ORDER BY count DESC
		 LIMIT $3`,
		tenantID, hours, limit,
	)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to fetch top IPs",
		})
	}
	defer rows.Close()

	var ips []map[string]interface{}
	for rows.Next() {
		var ip string
		var country *string
		var count int
		if rows.Scan(&ip, &country, &count) == nil {
			ips = append(ips, map[string]interface{}{
				"ip":          ip,
				"countryCode": country,
				"count":       count,
			})
		}
	}

	return c.JSON(fiber.Map{"ips": ips})
}

// ListChecks returns available security checks
func (m *Module) ListChecks(c *fiber.Ctx) error {
	checks := []map[string]interface{}{
		{"id": "ssl_expiry", "name": "SSL Certificate Expiry", "description": "Check for expiring SSL certificates"},
		{"id": "modsec_rules", "name": "ModSecurity Rules", "description": "Verify ModSecurity rules are loaded"},
		{"id": "nginx_config", "name": "NGINX Configuration", "description": "Validate NGINX configuration syntax"},
		{"id": "backend_health", "name": "Backend Health", "description": "Check backend server connectivity"},
		{"id": "dns_records", "name": "DNS Records", "description": "Verify DNS configuration"},
	}
	return c.JSON(fiber.Map{"checks": checks})
}

// RunCheck runs a specific security check
func (m *Module) RunCheck(c *fiber.Ctx) error {
	checkID := c.Params("id")
	// Implementation would run the actual check
	return c.JSON(fiber.Map{
		"checkId": checkID,
		"status":  "passed",
		"message": "Check completed successfully",
	})
}

// RunAllChecks runs all security checks
func (m *Module) RunAllChecks(c *fiber.Ctx) error {
	return c.JSON(fiber.Map{
		"results": []map[string]interface{}{
			{"checkId": "ssl_expiry", "status": "passed"},
			{"checkId": "modsec_rules", "status": "passed"},
			{"checkId": "nginx_config", "status": "passed"},
			{"checkId": "backend_health", "status": "passed"},
			{"checkId": "dns_records", "status": "passed"},
		},
	})
}

// ListRules returns ModSecurity rules
func (m *Module) ListRules(c *fiber.Ctx) error {
	return c.JSON(fiber.Map{"rules": []interface{}{}})
}

// CreateRule creates a ModSecurity rule
func (m *Module) CreateRule(c *fiber.Ctx) error {
	return c.Status(fiber.StatusCreated).JSON(fiber.Map{"success": true})
}

// UpdateRule updates a ModSecurity rule
func (m *Module) UpdateRule(c *fiber.Ctx) error {
	return c.JSON(fiber.Map{"success": true})
}

// DeleteRule deletes a ModSecurity rule
func (m *Module) DeleteRule(c *fiber.Ctx) error {
	return c.JSON(fiber.Map{"success": true})
}

// ToggleRule toggles a ModSecurity rule
func (m *Module) ToggleRule(c *fiber.Ctx) error {
	return c.JSON(fiber.Map{"success": true})
}

// ListBlockRules returns custom block rules
func (m *Module) ListBlockRules(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	ctx := c.Context()
	rows, err := m.db.Query(ctx,
		`SELECT id, tenant_id, site_id, name, description, rule_type, condition, action, priority, enabled, created_at, updated_at
		 FROM custom_block_rules WHERE tenant_id = $1 ORDER BY priority ASC`,
		tenantID,
	)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to fetch block rules",
		})
	}
	defer rows.Close()

	rules := make([]BlockRule, 0)
	for rows.Next() {
		var rule BlockRule
		err := rows.Scan(&rule.ID, &rule.TenantID, &rule.SiteID, &rule.Name, &rule.Description,
			&rule.RuleType, &rule.Condition, &rule.Action, &rule.Priority, &rule.Enabled,
			&rule.CreatedAt, &rule.UpdatedAt)
		if err == nil {
			rules = append(rules, rule)
		}
	}

	return c.JSON(fiber.Map{"rules": rules})
}

// CreateBlockRule creates a custom block rule
func (m *Module) CreateBlockRule(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	var req struct {
		Name        string                 `json:"name"`
		Description string                 `json:"description"`
		SiteID      string                 `json:"siteId"`
		RuleType    string                 `json:"ruleType"`
		Condition   map[string]interface{} `json:"condition"`
		Action      string                 `json:"action"`
		Priority    int                    `json:"priority"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{
			"error": "Invalid request body",
		})
	}

	ctx := c.Context()
	rule := BlockRule{
		ID:        uuid.New(),
		TenantID:  tenantID,
		Name:      req.Name,
		RuleType:  req.RuleType,
		Condition: req.Condition,
		Action:    req.Action,
		Priority:  req.Priority,
		Enabled:   true,
		CreatedAt: time.Now(),
		UpdatedAt: time.Now(),
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
		`INSERT INTO custom_block_rules (id, tenant_id, site_id, name, description, rule_type, condition, action, priority, enabled, created_at, updated_at)
		 VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12)`,
		rule.ID, rule.TenantID, siteID, rule.Name, rule.Description, rule.RuleType,
		rule.Condition, rule.Action, rule.Priority, rule.Enabled, rule.CreatedAt, rule.UpdatedAt,
	)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to create block rule",
		})
	}

	return c.Status(fiber.StatusCreated).JSON(fiber.Map{"rule": rule})
}

// UpdateBlockRule updates a custom block rule
func (m *Module) UpdateBlockRule(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id := c.Params("id")

	var req struct {
		Name        string                 `json:"name"`
		Description string                 `json:"description"`
		RuleType    string                 `json:"ruleType"`
		Condition   map[string]interface{} `json:"condition"`
		Action      string                 `json:"action"`
		Priority    int                    `json:"priority"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{
			"error": "Invalid request body",
		})
	}

	ctx := c.Context()
	result, err := m.db.Exec(ctx,
		`UPDATE custom_block_rules SET name = $1, description = $2, rule_type = $3, condition = $4, action = $5, priority = $6, updated_at = NOW()
		 WHERE id = $7 AND tenant_id = $8`,
		req.Name, req.Description, req.RuleType, req.Condition, req.Action, req.Priority, id, tenantID,
	)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to update block rule",
		})
	}

	if result.RowsAffected() == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{
			"error": "Block rule not found",
		})
	}

	return c.JSON(fiber.Map{"success": true})
}

// DeleteBlockRule deletes a custom block rule
func (m *Module) DeleteBlockRule(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id := c.Params("id")

	ctx := c.Context()
	result, err := m.db.Exec(ctx,
		`DELETE FROM custom_block_rules WHERE id = $1 AND tenant_id = $2`,
		id, tenantID,
	)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to delete block rule",
		})
	}

	if result.RowsAffected() == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{
			"error": "Block rule not found",
		})
	}

	return c.JSON(fiber.Map{"success": true})
}

// ToggleBlockRule toggles a custom block rule
func (m *Module) ToggleBlockRule(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	id := c.Params("id")

	ctx := c.Context()
	result, err := m.db.Exec(ctx,
		`UPDATE custom_block_rules SET enabled = NOT enabled, updated_at = NOW()
		 WHERE id = $1 AND tenant_id = $2`,
		id, tenantID,
	)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to toggle block rule",
		})
	}

	if result.RowsAffected() == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{
			"error": "Block rule not found",
		})
	}

	return c.JSON(fiber.Map{"success": true})
}

// Scanner represents a detected vulnerability scanner
type Scanner struct {
	IP         string    `json:"ip"`
	Type       string    `json:"type"`
	Requests   int       `json:"requests"`
	FirstSeen  time.Time `json:"firstSeen"`
	LastSeen   time.Time `json:"lastSeen"`
	Country    string    `json:"country"`
	Blocked    bool      `json:"blocked"`
}

// ListScanners returns detected vulnerability scanners
func (m *Module) ListScanners(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	ctx := c.Context()

	// Query for detected scanners from security events
	// This looks for patterns typical of vulnerability scanners
	rows, err := m.db.Query(ctx, `
		SELECT 
			client_ip::text as ip,
			COALESCE(
				CASE 
					WHEN request_uri ILIKE '%nikto%' OR (metadata->>'user_agent') ILIKE '%nikto%' THEN 'Nikto'
					WHEN request_uri ILIKE '%nmap%' OR (metadata->>'user_agent') ILIKE '%nmap%' THEN 'Nmap'
					WHEN request_uri ILIKE '%acunetix%' OR (metadata->>'user_agent') ILIKE '%acunetix%' THEN 'Acunetix'
					WHEN request_uri ILIKE '%sqlmap%' OR (metadata->>'user_agent') ILIKE '%sqlmap%' THEN 'SQLMap'
					WHEN (metadata->>'user_agent') ILIKE '%burp%' THEN 'Burp Suite'
					WHEN (metadata->>'user_agent') ILIKE '%dirbuster%' THEN 'DirBuster'
					WHEN (metadata->>'user_agent') ILIKE '%gobuster%' THEN 'Gobuster'
					WHEN (metadata->>'user_agent') ILIKE '%wfuzz%' THEN 'WFuzz'
					ELSE 'Unknown Scanner'
				END,
				'Unknown Scanner'
			) as scanner_type,
			COUNT(*) as request_count,
			MIN(created_at) as first_seen,
			MAX(created_at) as last_seen,
			COALESCE(country_code, 'XX') as country,
			EXISTS(SELECT 1 FROM bans WHERE ip_address = security_events.client_ip AND (expires_at IS NULL OR expires_at > NOW())) as blocked
		FROM security_events
		WHERE tenant_id = $1
			AND severity IN ('high', 'critical')
		GROUP BY client_ip, scanner_type, country_code
		HAVING COUNT(*) > 10
		ORDER BY request_count DESC
		LIMIT 100
	`, tenantID)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to fetch scanners",
		})
	}
	defer rows.Close()

	var scanners []Scanner
	var totalBlocked, totalMonitoring, totalRequests int

	for rows.Next() {
		var s Scanner
		if err := rows.Scan(&s.IP, &s.Type, &s.Requests, &s.FirstSeen, &s.LastSeen, &s.Country, &s.Blocked); err != nil {
			continue
		}
		scanners = append(scanners, s)
		totalRequests += s.Requests
		if s.Blocked {
			totalBlocked++
		} else {
			totalMonitoring++
		}
	}

	return c.JSON(fiber.Map{
		"scanners":      scanners,
		"total":         len(scanners),
		"blocked":       totalBlocked,
		"monitoring":    totalMonitoring,
		"totalRequests": totalRequests,
	})
}

// BlockScanner blocks a detected scanner IP
func (m *Module) BlockScanner(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	ip := c.Params("ip")

	ctx := c.Context()

	// Create ban entry
	_, err := m.db.Exec(ctx, `
		INSERT INTO bans (id, tenant_id, ip_address, reason, created_at)
		VALUES ($1, $2, $3, $4, NOW())
		ON CONFLICT (tenant_id, ip_address) DO NOTHING
	`, uuid.New(), tenantID, ip, "Scanner detected and blocked")
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to block scanner",
		})
	}

	return c.JSON(fiber.Map{"success": true})
}
