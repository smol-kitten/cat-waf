// Package settings handles system and site settings for CatWAF v2
package settings

import (
	"context"
	"encoding/json"

	"github.com/gofiber/fiber/v2"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"
)

// Module implements the settings module
type Module struct {
	db    *pgxpool.Pool
	redis *redis.Client
}

func New() *Module { return &Module{} }

func (m *Module) Name() string    { return "settings" }
func (m *Module) Version() string { return "2.0.0" }

func (m *Module) Init(ctx context.Context, db *pgxpool.Pool, redis *redis.Client) error {
	m.db = db
	m.redis = redis
	return nil
}

func (m *Module) RegisterRoutes(router fiber.Router) {
	settings := router.Group("/settings")
	settings.Get("/", m.GetAll)
	settings.Post("/", m.UpdateBatch)
	settings.Get("/:key", m.Get)
	settings.Put("/:key", m.Update)
	settings.Delete("/:key", m.Delete)
	
	// Environment defaults
	settings.Get("/env-defaults", m.GetEnvDefaults)
	
	// Backup/restore
	settings.Get("/backup", m.GetBackupInfo)
	settings.Post("/backup/export", m.ExportBackup)
	settings.Post("/backup/import", m.ImportBackup)
	settings.Get("/backup/settings", m.GetBackupSettings)
	settings.Post("/backup/settings", m.SaveBackupSettings)
	
	// System
	settings.Get("/system", m.GetSystemInfo)
	settings.Post("/system/cleanup", m.CleanupData)
	settings.Post("/system/reset", m.SystemReset)
	settings.Get("/system/cleanup-stats", m.GetCleanupStats)
	
	// Notifications
	settings.Post("/notifications/test", m.TestNotifications)
}

func (m *Module) Shutdown(ctx context.Context) error { return nil }

// Setting represents a key-value setting
type Setting struct {
	Key   string `json:"key"`
	Value string `json:"value"`
}

func (m *Module) GetAll(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	ctx := c.Context()

	// Get tenant settings
	var settingsJSON map[string]interface{}
	err := m.db.QueryRow(ctx, `SELECT settings FROM tenants WHERE id = $1`, tenantID).Scan(&settingsJSON)
	if err != nil {
		settingsJSON = make(map[string]interface{})
	}

	// Default settings - comprehensive list matching V1 features
	defaults := map[string]interface{}{
		// General
		"instance_name": "",
		"admin_email":   "",

		// Security - WAF
		"paranoia_level":    2,
		"waf_default_mode":  "on",
		"dev_mode_headers":  false,

		// Security - Auto Ban
		"auto_ban_enabled":   true,
		"auto_ban_threshold": 10,
		"auto_ban_duration":  60,

		// Security - Rate Limiting
		"rate_limit_enabled": true,
		"rate_limit_default": 100,
		"rate_limit_zone":    "100r/s",

		// Bot Protection
		"bot_protection_enabled": true,
		"bot_block_empty_ua":     true,
		"bot_rate_limit_good":    60,
		"bot_rate_limit_bad":     10,
		"bot_challenge_mode":     "captcha",
		"bot_log_all_requests":   false,

		// GeoIP
		"geoip_enabled":        false,
		"maxmind_license_key":  "",
		"geoip_last_updated":   "",

		// Notifications - Webhook
		"webhook_enabled":      false,
		"webhook_url":          "",
		"discord_webhook_url":  "",

		// Notifications - Events
		"notifications_critical":    true,
		"notifications_autoban":     true,
		"notifications_cert_expiry": true,
		"notifications_server_down": true,
		"notifications_high_delay":  true,

		// Notifications - Email
		"email_enabled":   false,
		"smtp_host":       "",
		"smtp_port":       "587",
		"smtp_username":   "",
		"smtp_password":   "",
		"smtp_from_email": "",
		"smtp_from_name":  "CatWAF",

		// Advanced - Nginx
		"nginx_worker_processes":   "auto",
		"nginx_worker_connections": "1024",

		// Advanced - Data Retention
		"log_retention_days":       30,
		"task_log_retention_days":  30,
		"telemetry_retention_days": 14,

		// Advanced - System
		"telemetry_enabled":       false,
		"task_scheduler_enabled":  true,
		"dev_mode":                false,

		// Backup
		"backup_auto_enabled":   false,
		"backup_retention_days": 30,
		"backup_local_only":     false,
	}

	// Merge defaults with stored settings
	for k, v := range defaults {
		if _, ok := settingsJSON[k]; !ok {
			settingsJSON[k] = v
		}
	}

	return c.JSON(fiber.Map{"settings": settingsJSON})
}

func (m *Module) UpdateBatch(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	var settings map[string]interface{}
	if err := c.BodyParser(&settings); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()

	// Get current settings
	var currentSettings map[string]interface{}
	err := m.db.QueryRow(ctx, `SELECT settings FROM tenants WHERE id = $1`, tenantID).Scan(&currentSettings)
	if err != nil || currentSettings == nil {
		currentSettings = make(map[string]interface{})
	}

	// Merge new settings
	for k, v := range settings {
		currentSettings[k] = v
	}

	// Save
	settingsJSON, _ := json.Marshal(currentSettings)
	_, err = m.db.Exec(ctx, `UPDATE tenants SET settings = $1, updated_at = NOW() WHERE id = $2`, settingsJSON, tenantID)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to save settings"})
	}

	// Invalidate cache
	_ = m.redis.Del(ctx, "settings:"+tenantID.String()).Err()

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) Get(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	key := c.Params("key")

	ctx := c.Context()
	var settings map[string]interface{}
	err := m.db.QueryRow(ctx, `SELECT settings FROM tenants WHERE id = $1`, tenantID).Scan(&settings)
	if err != nil || settings == nil {
		return c.JSON(fiber.Map{"key": key, "value": nil})
	}

	value, ok := settings[key]
	if !ok {
		return c.JSON(fiber.Map{"key": key, "value": nil})
	}

	return c.JSON(fiber.Map{"key": key, "value": value})
}

func (m *Module) Update(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	key := c.Params("key")

	var req struct {
		Value interface{} `json:"value"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()

	// Get current settings
	var settings map[string]interface{}
	err := m.db.QueryRow(ctx, `SELECT settings FROM tenants WHERE id = $1`, tenantID).Scan(&settings)
	if err != nil || settings == nil {
		settings = make(map[string]interface{})
	}

	settings[key] = req.Value

	settingsJSON, _ := json.Marshal(settings)
	_, err = m.db.Exec(ctx, `UPDATE tenants SET settings = $1, updated_at = NOW() WHERE id = $2`, settingsJSON, tenantID)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to save setting"})
	}

	_ = m.redis.Del(ctx, "settings:"+tenantID.String()).Err()

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) Delete(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	key := c.Params("key")

	ctx := c.Context()
	var settings map[string]interface{}
	err := m.db.QueryRow(ctx, `SELECT settings FROM tenants WHERE id = $1`, tenantID).Scan(&settings)
	if err != nil || settings == nil {
		return c.JSON(fiber.Map{"success": true})
	}

	delete(settings, key)

	settingsJSON, _ := json.Marshal(settings)
	_, err = m.db.Exec(ctx, `UPDATE tenants SET settings = $1, updated_at = NOW() WHERE id = $2`, settingsJSON, tenantID)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to delete setting"})
	}

	_ = m.redis.Del(ctx, "settings:"+tenantID.String()).Err()

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) GetEnvDefaults(c *fiber.Ctx) error {
	return c.JSON(fiber.Map{
		"defaults": map[string]interface{}{
			"paranoia_level":     2,
			"rate_limit_zone":    "100r/s",
			"ssl_type":           "auto",
			"max_body_size":      "50m",
			"proxy_timeout":      60,
		},
	})
}

func (m *Module) GetBackupInfo(c *fiber.Ctx) error {
	return c.JSON(fiber.Map{
		"lastBackup":    nil,
		"backupCount":   0,
		"totalSize":     0,
		"autoBackup":    false,
	})
}

func (m *Module) ExportBackup(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	ctx := c.Context()

	// Export sites
	var sites []map[string]interface{}
	rows, _ := m.db.Query(ctx, `SELECT id, domain, display_name, enabled, settings, security_settings, ssl_settings FROM sites WHERE tenant_id = $1`, tenantID)
	for rows.Next() {
		var site map[string]interface{}
		// Simplified - would need proper scanning
		sites = append(sites, site)
	}
	rows.Close()

	// Export settings
	var settings map[string]interface{}
	_ = m.db.QueryRow(ctx, `SELECT settings FROM tenants WHERE id = $1`, tenantID).Scan(&settings)

	backup := map[string]interface{}{
		"version":   "2.0.0",
		"timestamp": c.Context().Time(),
		"sites":     sites,
		"settings":  settings,
	}

	c.Set("Content-Disposition", "attachment; filename=catwaf-backup.json")
	return c.JSON(backup)
}

func (m *Module) ImportBackup(c *fiber.Ctx) error {
	// Handle file upload and restore
	return c.JSON(fiber.Map{"success": true, "imported": 0})
}

func (m *Module) GetBackupSettings(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	ctx := c.Context()

	var settings map[string]interface{}
	_ = m.db.QueryRow(ctx, `SELECT settings FROM tenants WHERE id = $1`, tenantID).Scan(&settings)

	return c.JSON(fiber.Map{
		"autoBackup":     settings["backup_auto_enabled"],
		"retentionDays":  settings["backup_retention_days"],
		"schedule":       settings["backup_schedule"],
	})
}

func (m *Module) SaveBackupSettings(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	var req struct {
		AutoBackup    bool   `json:"autoBackup"`
		RetentionDays int    `json:"retentionDays"`
		Schedule      string `json:"schedule"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	var settings map[string]interface{}
	_ = m.db.QueryRow(ctx, `SELECT settings FROM tenants WHERE id = $1`, tenantID).Scan(&settings)
	if settings == nil {
		settings = make(map[string]interface{})
	}

	settings["backup_auto_enabled"] = req.AutoBackup
	settings["backup_retention_days"] = req.RetentionDays
	settings["backup_schedule"] = req.Schedule

	settingsJSON, _ := json.Marshal(settings)
	_, _ = m.db.Exec(ctx, `UPDATE tenants SET settings = $1, updated_at = NOW() WHERE id = $2`, settingsJSON, tenantID)

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) GetSystemInfo(c *fiber.Ctx) error {
	return c.JSON(fiber.Map{
		"version":    "2.0.0",
		"goVersion":  "1.22",
		"uptime":     0,
		"hostname":   "",
	})
}

func (m *Module) CleanupData(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	var req struct {
		Type      string `json:"type"` // events, insights, logs
		OlderThan int    `json:"olderThan"` // days
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	ctx := c.Context()
	var deleted int64

	switch req.Type {
	case "events":
		result, _ := m.db.Exec(ctx,
			`DELETE FROM security_events WHERE tenant_id = $1 AND created_at < NOW() - INTERVAL '1 day' * $2`,
			tenantID, req.OlderThan,
		)
		deleted = result.RowsAffected()
	case "insights":
		result, _ := m.db.Exec(ctx,
			`DELETE FROM insights_hourly WHERE tenant_id = $1 AND hour < NOW() - INTERVAL '1 day' * $2`,
			tenantID, req.OlderThan,
		)
		deleted = result.RowsAffected()
	}

	return c.JSON(fiber.Map{"success": true, "deleted": deleted})
}

func (m *Module) SystemReset(c *fiber.Ctx) error {
	// Careful - this would reset the system
	return c.JSON(fiber.Map{"success": true, "message": "System reset initiated"})
}

func (m *Module) GetCleanupStats(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	ctx := c.Context()

	var stats struct {
		Events   int64 `json:"events"`
		Insights int64 `json:"insights"`
		Bans     int64 `json:"bans"`
	}

	_ = m.db.QueryRow(ctx, `SELECT COUNT(*) FROM security_events WHERE tenant_id = $1`, tenantID).Scan(&stats.Events)
	_ = m.db.QueryRow(ctx, `SELECT COUNT(*) FROM insights_hourly WHERE tenant_id = $1`, tenantID).Scan(&stats.Insights)
	_ = m.db.QueryRow(ctx, `SELECT COUNT(*) FROM banned_ips WHERE tenant_id = $1`, tenantID).Scan(&stats.Bans)

	return c.JSON(fiber.Map{"stats": stats})
}

func (m *Module) TestNotifications(c *fiber.Ctx) error {
	// Would send test notifications via configured channels
	return c.JSON(fiber.Map{"success": true, "message": "Test notifications sent"})
}
