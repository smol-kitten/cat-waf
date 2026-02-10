// Package stats handles dashboard statistics for CatWAF v2
package stats

import (
	"context"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"
)

// Module implements the stats module
type Module struct {
	db    *pgxpool.Pool
	redis *redis.Client
}

func New() *Module { return &Module{} }

func (m *Module) Name() string    { return "stats" }
func (m *Module) Version() string { return "2.0.0" }

func (m *Module) Init(ctx context.Context, db *pgxpool.Pool, redis *redis.Client) error {
	m.db = db
	m.redis = redis
	return nil
}

func (m *Module) RegisterRoutes(router fiber.Router) {
	stats := router.Group("/stats")
	stats.Get("/dashboard", m.Dashboard)
	stats.Get("/traffic", m.Traffic)
	stats.Get("/security", m.Security)
	stats.Get("/sites", m.Sites)
	stats.Get("/recent-activity", m.RecentActivity)
	stats.Get("/traffic-analysis/:timestamp", m.TrafficAnalysis)
}

func (m *Module) Shutdown(ctx context.Context) error { return nil }

// DashboardStats represents dashboard statistics
type DashboardStats struct {
	TotalRequests   int64   `json:"totalRequests"`
	BlockedRequests int64   `json:"blockedRequests"`
	BytesIn         int64   `json:"bytesIn"`
	BytesOut        int64   `json:"bytesOut"`
	UniqueIPs       int     `json:"uniqueIps"`
	ActiveSites     int     `json:"activeSites"`
	BannedIPs       int     `json:"bannedIps"`
	SecurityEvents  int     `json:"securityEvents"`
	AvgResponseTime float64 `json:"avgResponseTime"`
}

func (m *Module) Dashboard(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	rangeParam := c.Query("range", "24h")

	ctx := c.Context()

	// Parse time range
	var hours int
	switch rangeParam {
	case "1h":
		hours = 1
	case "6h":
		hours = 6
	case "24h":
		hours = 24
	case "7d":
		hours = 168
	case "30d":
		hours = 720
	default:
		hours = 24
	}

	var stats DashboardStats

	// Get aggregated stats from insights_hourly
	err := m.db.QueryRow(ctx, `
		SELECT COALESCE(SUM(requests_total), 0), COALESCE(SUM(requests_blocked), 0),
		       COALESCE(SUM(bytes_in), 0), COALESCE(SUM(bytes_out), 0),
		       COALESCE(SUM(unique_ips), 0)
		FROM insights_hourly
		WHERE tenant_id = $1 AND hour > NOW() - INTERVAL '1 hour' * $2
	`, tenantID, hours).Scan(&stats.TotalRequests, &stats.BlockedRequests, &stats.BytesIn, &stats.BytesOut, &stats.UniqueIPs)
	if err != nil {
		// Initialize with zeros if no data
		stats = DashboardStats{}
	}

	// Active sites
	_ = m.db.QueryRow(ctx, `SELECT COUNT(*) FROM sites WHERE tenant_id = $1 AND enabled = true`, tenantID).Scan(&stats.ActiveSites)

	// Banned IPs
	_ = m.db.QueryRow(ctx, `SELECT COUNT(*) FROM banned_ips WHERE tenant_id = $1 AND (expires_at IS NULL OR expires_at > NOW())`, tenantID).Scan(&stats.BannedIPs)

	// Security events
	_ = m.db.QueryRow(ctx, `SELECT COUNT(*) FROM security_events WHERE tenant_id = $1 AND created_at > NOW() - INTERVAL '1 hour' * $2`, tenantID, hours).Scan(&stats.SecurityEvents)

	return c.JSON(fiber.Map{"stats": stats})
}

func (m *Module) Traffic(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	hours := c.QueryInt("hours", 24)

	ctx := c.Context()
	rows, err := m.db.Query(ctx, `
		SELECT hour, requests_total, requests_blocked, bytes_in, bytes_out
		FROM insights_hourly
		WHERE tenant_id = $1 AND hour > NOW() - INTERVAL '1 hour' * $2
		ORDER BY hour ASC
	`, tenantID, hours)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to fetch traffic data"})
	}
	defer rows.Close()

	var data []map[string]interface{}
	for rows.Next() {
		var hour time.Time
		var total, blocked, bytesIn, bytesOut int64
		if rows.Scan(&hour, &total, &blocked, &bytesIn, &bytesOut) == nil {
			data = append(data, map[string]interface{}{
				"hour":     hour,
				"total":    total,
				"blocked":  blocked,
				"bytesIn":  bytesIn,
				"bytesOut": bytesOut,
			})
		}
	}

	return c.JSON(fiber.Map{"traffic": data})
}

func (m *Module) Security(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	hours := c.QueryInt("hours", 24)

	ctx := c.Context()

	// Security events by severity
	rows, _ := m.db.Query(ctx, `
		SELECT severity, COUNT(*) as count
		FROM security_events
		WHERE tenant_id = $1 AND created_at > NOW() - INTERVAL '1 hour' * $2
		GROUP BY severity
	`, tenantID, hours)

	bySeverity := make(map[string]int)
	for rows.Next() {
		var sev string
		var count int
		if rows.Scan(&sev, &count) == nil {
			bySeverity[sev] = count
		}
	}
	rows.Close()

	// Top attack types
	rows, _ = m.db.Query(ctx, `
		SELECT event_type, COUNT(*) as count
		FROM security_events
		WHERE tenant_id = $1 AND created_at > NOW() - INTERVAL '1 hour' * $2
		GROUP BY event_type
		ORDER BY count DESC
		LIMIT 10
	`, tenantID, hours)

	var topTypes []map[string]interface{}
	for rows.Next() {
		var typ string
		var count int
		if rows.Scan(&typ, &count) == nil {
			topTypes = append(topTypes, map[string]interface{}{
				"type":  typ,
				"count": count,
			})
		}
	}
	rows.Close()

	return c.JSON(fiber.Map{
		"bySeverity": bySeverity,
		"topTypes":   topTypes,
	})
}

func (m *Module) Sites(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	hours := c.QueryInt("hours", 24)

	ctx := c.Context()
	rows, err := m.db.Query(ctx, `
		SELECT s.id, s.domain, s.display_name,
		       COALESCE(SUM(i.requests_total), 0) as requests,
		       COALESCE(SUM(i.requests_blocked), 0) as blocked
		FROM sites s
		LEFT JOIN insights_hourly i ON s.id = i.site_id AND i.hour > NOW() - INTERVAL '1 hour' * $2
		WHERE s.tenant_id = $1
		GROUP BY s.id, s.domain, s.display_name
		ORDER BY requests DESC
	`, tenantID, hours)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to fetch site stats"})
	}
	defer rows.Close()

	var sites []map[string]interface{}
	for rows.Next() {
		var id uuid.UUID
		var domain, displayName string
		var requests, blocked int64
		if rows.Scan(&id, &domain, &displayName, &requests, &blocked) == nil {
			sites = append(sites, map[string]interface{}{
				"id":          id,
				"domain":      domain,
				"displayName": displayName,
				"requests":    requests,
				"blocked":     blocked,
			})
		}
	}

	return c.JSON(fiber.Map{"sites": sites})
}

func (m *Module) RecentActivity(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	limit := c.QueryInt("limit", 20)

	ctx := c.Context()
	rows, err := m.db.Query(ctx, `
		SELECT se.id, se.event_type, se.severity, se.client_ip::text, se.request_uri,
		       se.action_taken, se.created_at, s.domain
		FROM security_events se
		LEFT JOIN sites s ON se.site_id = s.id
		WHERE se.tenant_id = $1
		ORDER BY se.created_at DESC
		LIMIT $2
	`, tenantID, limit)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to fetch recent activity"})
	}
	defer rows.Close()

	var activity []map[string]interface{}
	for rows.Next() {
		var id uuid.UUID
		var eventType, severity, clientIP, requestURI, actionTaken string
		var createdAt time.Time
		var domain *string
		if rows.Scan(&id, &eventType, &severity, &clientIP, &requestURI, &actionTaken, &createdAt, &domain) == nil {
			activity = append(activity, map[string]interface{}{
				"id":          id,
				"eventType":   eventType,
				"severity":    severity,
				"clientIp":    clientIP,
				"requestUri":  requestURI,
				"actionTaken": actionTaken,
				"createdAt":   createdAt,
				"domain":      domain,
			})
		}
	}

	return c.JSON(fiber.Map{"activity": activity})
}

func (m *Module) TrafficAnalysis(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	timestamp := c.Params("timestamp")

	ctx := c.Context()

	// Parse timestamp and get hour range
	t, err := time.Parse(time.RFC3339, timestamp)
	if err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid timestamp"})
	}

	startHour := t.Truncate(time.Hour)
	endHour := startHour.Add(time.Hour)

	// Get detailed analytics for this hour
	var analysis struct {
		Domains   []map[string]interface{} `json:"domains"`
		Statuses  []map[string]interface{} `json:"statuses"`
		Endpoints []map[string]interface{} `json:"endpoints"`
		IPs       []map[string]interface{} `json:"ips"`
		Errors    []map[string]interface{} `json:"errors"`
	}

	// Top domains
	rows, _ := m.db.Query(ctx, `
		SELECT s.domain, SUM(i.requests_total) as requests
		FROM insights_hourly i
		JOIN sites s ON i.site_id = s.id
		WHERE i.tenant_id = $1 AND i.hour >= $2 AND i.hour < $3
		GROUP BY s.domain
		ORDER BY requests DESC
		LIMIT 10
	`, tenantID, startHour, endHour)
	for rows.Next() {
		var domain string
		var requests int64
		if rows.Scan(&domain, &requests) == nil {
			analysis.Domains = append(analysis.Domains, map[string]interface{}{
				"domain":   domain,
				"requests": requests,
			})
		}
	}
	rows.Close()

	// Status codes from aggregated data
	rows, _ = m.db.Query(ctx, `
		SELECT status_codes FROM insights_hourly
		WHERE tenant_id = $1 AND hour >= $2 AND hour < $3
	`, tenantID, startHour, endHour)
	statusTotals := make(map[string]int64)
	for rows.Next() {
		var codes map[string]int64
		if rows.Scan(&codes) == nil {
			for code, count := range codes {
				statusTotals[code] += count
			}
		}
	}
	rows.Close()

	for code, count := range statusTotals {
		analysis.Statuses = append(analysis.Statuses, map[string]interface{}{
			"status": code,
			"count":  count,
		})
	}

	return c.JSON(fiber.Map{"analysis": analysis})
}
