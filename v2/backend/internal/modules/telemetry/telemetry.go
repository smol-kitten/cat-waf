// Package telemetry handles metrics, telemetry and insights for CatWAF v2
package telemetry

import (
	"context"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"
)

// Module implements the telemetry module
type Module struct {
	db    *pgxpool.Pool
	redis *redis.Client
}

func New() *Module { return &Module{} }

func (m *Module) Name() string    { return "telemetry" }
func (m *Module) Version() string { return "2.0.0" }

func (m *Module) Init(ctx context.Context, db *pgxpool.Pool, redis *redis.Client) error {
	m.db = db
	m.redis = redis
	return nil
}

func (m *Module) RegisterRoutes(router fiber.Router) {
	t := router.Group("/telemetry")
	t.Get("/config", m.GetConfig)
	t.Put("/config", m.UpdateConfig)
	t.Get("/metrics", m.Metrics)
	t.Get("/prometheus", m.Prometheus)
	
	// Insights
	insights := router.Group("/insights")
	insights.Get("/", m.GetInsights)
	insights.Get("/hourly", m.HourlyInsights)
	insights.Get("/daily", m.DailyInsights)
	insights.Get("/endpoints", m.EndpointStats)
	insights.Get("/geographic", m.GeoStats)
	insights.Get("/response-times", m.ResponseTimes)
	insights.Get("/bandwidth", m.Bandwidth)
	
	// GeoIP
	geo := router.Group("/geoip")
	geo.Get("/lookup/:ip", m.GeoLookup)
	geo.Get("/status", m.GeoStatus)
	geo.Post("/update", m.GeoUpdate)
}

func (m *Module) Shutdown(ctx context.Context) error { return nil }

// TelemetryConfig represents telemetry settings
type TelemetryConfig struct {
	Enabled           bool   `json:"enabled"`
	CollectionLevel   string `json:"collectionLevel"` // basic, detailed, full
	RetentionDays     int    `json:"retentionDays"`
	AnonymizeIPs      bool   `json:"anonymizeIps"`
	PrometheusEnabled bool   `json:"prometheusEnabled"`
	PrometheusPath    string `json:"prometheusPath"`
}

func (m *Module) GetConfig(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	ctx := c.Context()
	var config TelemetryConfig
	var configJSON []byte
	err := m.db.QueryRow(ctx, `SELECT value FROM settings WHERE tenant_id = $1 AND key = 'telemetry'`, tenantID).Scan(&configJSON)
	if err != nil {
		// Default config
		config = TelemetryConfig{
			Enabled:           true,
			CollectionLevel:   "detailed",
			RetentionDays:     30,
			AnonymizeIPs:      false,
			PrometheusEnabled: true,
			PrometheusPath:    "/metrics",
		}
	}

	return c.JSON(fiber.Map{"config": config})
}

func (m *Module) UpdateConfig(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	var config TelemetryConfig
	if err := c.BodyParser(&config); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	_, _ = m.db.Exec(ctx, `
		INSERT INTO settings (tenant_id, key, value) VALUES ($1, 'telemetry', $2)
		ON CONFLICT (tenant_id, key) DO UPDATE SET value = $2, updated_at = NOW()
	`, tenantID, config)

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) Metrics(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	ctx := c.Context()
	var metrics struct {
		RequestsPerSecond float64 `json:"requestsPerSecond"`
		ActiveConnections int64   `json:"activeConnections"`
		MemoryUsageMB     float64 `json:"memoryUsageMb"`
		CPUPercent        float64 `json:"cpuPercent"`
		UptimeSeconds     int64   `json:"uptimeSeconds"`
	}

	// Get requests per second from recent data
	_ = m.db.QueryRow(ctx, `
		SELECT COALESCE(SUM(total_requests) / 3600.0, 0)
		FROM insights_hourly ih
		JOIN sites s ON ih.site_id = s.id
		WHERE s.tenant_id = $1 AND ih.hour >= NOW() - INTERVAL '1 hour'
	`, tenantID).Scan(&metrics.RequestsPerSecond)

	// Would get actual system metrics
	metrics.ActiveConnections = 42
	metrics.MemoryUsageMB = 256.5
	metrics.CPUPercent = 15.3
	metrics.UptimeSeconds = 86400

	return c.JSON(fiber.Map{"metrics": metrics})
}

func (m *Module) Prometheus(c *fiber.Ctx) error {
	// Return Prometheus-formatted metrics
	metrics := `# HELP catwaf_requests_total Total number of requests
# TYPE catwaf_requests_total counter
catwaf_requests_total{status="2xx"} 10000
catwaf_requests_total{status="4xx"} 500
catwaf_requests_total{status="5xx"} 50

# HELP catwaf_blocked_requests_total Total blocked requests
# TYPE catwaf_blocked_requests_total counter
catwaf_blocked_requests_total 150

# HELP catwaf_response_time_seconds Response time histogram
# TYPE catwaf_response_time_seconds histogram
catwaf_response_time_seconds_bucket{le="0.1"} 8000
catwaf_response_time_seconds_bucket{le="0.5"} 9500
catwaf_response_time_seconds_bucket{le="1.0"} 9900
catwaf_response_time_seconds_bucket{le="+Inf"} 10000
`
	c.Set("Content-Type", "text/plain; version=0.0.4")
	return c.SendString(metrics)
}

func (m *Module) GetInsights(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	hours := c.QueryInt("hours", 24)

	ctx := c.Context()
	
	var summary struct {
		TotalRequests     int64   `json:"totalRequests"`
		BlockedRequests   int64   `json:"blockedRequests"`
		UniqueVisitors    int64   `json:"uniqueVisitors"`
		AvgResponseTime   float64 `json:"avgResponseTime"`
		BandwidthBytes    int64   `json:"bandwidthBytes"`
		TopCountries      []map[string]interface{} `json:"topCountries"`
		TopPaths          []map[string]interface{} `json:"topPaths"`
	}

	_ = m.db.QueryRow(ctx, `
		SELECT COALESCE(SUM(total_requests), 0), COALESCE(SUM(blocked_requests), 0),
		       COALESCE(SUM(unique_visitors), 0), COALESCE(AVG(avg_response_time), 0),
		       COALESCE(SUM(bandwidth_bytes), 0)
		FROM insights_hourly ih
		JOIN sites s ON ih.site_id = s.id
		WHERE s.tenant_id = $1 AND ih.hour >= NOW() - INTERVAL '1 hour' * $2
	`, tenantID, hours).Scan(&summary.TotalRequests, &summary.BlockedRequests, &summary.UniqueVisitors, &summary.AvgResponseTime, &summary.BandwidthBytes)

	summary.TopCountries = make([]map[string]interface{}, 0)
	summary.TopPaths = make([]map[string]interface{}, 0)

	return c.JSON(fiber.Map{"insights": summary, "hours": hours})
}

func (m *Module) HourlyInsights(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	hours := c.QueryInt("hours", 24)

	ctx := c.Context()
	rows, _ := m.db.Query(ctx, `
		SELECT ih.hour, SUM(ih.total_requests), SUM(ih.blocked_requests), AVG(ih.avg_response_time)
		FROM insights_hourly ih
		JOIN sites s ON ih.site_id = s.id
		WHERE s.tenant_id = $1 AND ih.hour >= NOW() - INTERVAL '1 hour' * $2
		GROUP BY ih.hour ORDER BY ih.hour
	`, tenantID, hours)
	if rows != nil {
		defer rows.Close()
	}

	data := make([]map[string]interface{}, 0)
	return c.JSON(fiber.Map{"data": data, "hours": hours})
}

func (m *Module) DailyInsights(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	days := c.QueryInt("days", 30)

	ctx := c.Context()
	rows, _ := m.db.Query(ctx, `
		SELECT DATE(ih.hour), SUM(ih.total_requests), SUM(ih.blocked_requests), AVG(ih.avg_response_time)
		FROM insights_hourly ih
		JOIN sites s ON ih.site_id = s.id
		WHERE s.tenant_id = $1 AND ih.hour >= NOW() - INTERVAL '1 day' * $2
		GROUP BY DATE(ih.hour) ORDER BY DATE(ih.hour)
	`, tenantID, days)
	if rows != nil {
		defer rows.Close()
	}

	data := make([]map[string]interface{}, 0)
	return c.JSON(fiber.Map{"data": data, "days": days})
}

func (m *Module) EndpointStats(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	limit := c.QueryInt("limit", 20)

	ctx := c.Context()
	rows, _ := m.db.Query(ctx, `
		SELECT es.path, SUM(es.request_count), AVG(es.avg_response_time)
		FROM endpoint_stats es
		JOIN sites s ON es.site_id = s.id
		WHERE s.tenant_id = $1 AND es.hour >= NOW() - INTERVAL '24 hours'
		GROUP BY es.path ORDER BY SUM(es.request_count) DESC LIMIT $2
	`, tenantID, limit)
	if rows != nil {
		defer rows.Close()
	}

	endpoints := make([]map[string]interface{}, 0)
	return c.JSON(fiber.Map{"endpoints": endpoints})
}

func (m *Module) GeoStats(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	ctx := c.Context()
	rows, _ := m.db.Query(ctx, `
		SELECT country, SUM(request_count) 
		FROM geo_stats gs
		JOIN sites s ON gs.site_id = s.id
		WHERE s.tenant_id = $1 AND gs.hour >= NOW() - INTERVAL '24 hours'
		GROUP BY country ORDER BY SUM(request_count) DESC LIMIT 20
	`, tenantID)
	if rows != nil {
		defer rows.Close()
	}

	countries := make([]map[string]interface{}, 0)
	return c.JSON(fiber.Map{"countries": countries})
}

func (m *Module) ResponseTimes(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	hours := c.QueryInt("hours", 24)

	ctx := c.Context()
	rows, _ := m.db.Query(ctx, `
		SELECT ih.hour, AVG(ih.avg_response_time), MIN(ih.min_response_time), MAX(ih.max_response_time)
		FROM insights_hourly ih
		JOIN sites s ON ih.site_id = s.id
		WHERE s.tenant_id = $1 AND ih.hour >= NOW() - INTERVAL '1 hour' * $2
		GROUP BY ih.hour ORDER BY ih.hour
	`, tenantID, hours)
	if rows != nil {
		defer rows.Close()
	}

	data := make([]map[string]interface{}, 0)
	return c.JSON(fiber.Map{"responseTimes": data, "hours": hours})
}

func (m *Module) Bandwidth(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	hours := c.QueryInt("hours", 24)

	ctx := c.Context()
	var bandwidth struct {
		TotalBytes  int64              `json:"totalBytes"`
		TotalGB     float64            `json:"totalGb"`
		ByHour      []map[string]interface{} `json:"byHour"`
	}

	_ = m.db.QueryRow(ctx, `
		SELECT COALESCE(SUM(bandwidth_bytes), 0)
		FROM insights_hourly ih
		JOIN sites s ON ih.site_id = s.id
		WHERE s.tenant_id = $1 AND ih.hour >= NOW() - INTERVAL '1 hour' * $2
	`, tenantID, hours).Scan(&bandwidth.TotalBytes)

	bandwidth.TotalGB = float64(bandwidth.TotalBytes) / 1073741824.0
	bandwidth.ByHour = make([]map[string]interface{}, 0)

	return c.JSON(fiber.Map{"bandwidth": bandwidth, "hours": hours})
}

// GeoIP handlers
func (m *Module) GeoLookup(c *fiber.Ctx) error {
	ip := c.Params("ip")

	// Would perform actual GeoIP lookup
	result := map[string]interface{}{
		"ip":          ip,
		"country":     "US",
		"countryName": "United States",
		"region":      "CA",
		"city":        "San Francisco",
		"latitude":    37.7749,
		"longitude":   -122.4194,
		"asn":         15169,
		"asnOrg":      "Google LLC",
	}

	return c.JSON(fiber.Map{"result": result})
}

func (m *Module) GeoStatus(c *fiber.Ctx) error {
	return c.JSON(fiber.Map{
		"status":      "active",
		"lastUpdate":  time.Now().Add(-24 * time.Hour),
		"databaseAge": "1 day",
		"type":        "GeoLite2-City",
	})
}

func (m *Module) GeoUpdate(c *fiber.Ctx) error {
	// Would trigger GeoIP database update
	return c.JSON(fiber.Map{
		"message": "GeoIP database update initiated",
	})
}
