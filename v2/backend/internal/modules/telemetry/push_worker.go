// Package telemetry — external push worker for CatTelemetry integration
package telemetry

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"runtime"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/rs/zerolog/log"
)

// ExternalPushConfig holds configuration for pushing telemetry to the CatTelemetry collector.
type ExternalPushConfig struct {
	Enabled       bool          `json:"enabled"`
	Endpoint      string        `json:"endpoint"`
	ProjectToken  string        `json:"projectToken"`
	PushInterval  time.Duration `json:"pushInterval"`
	HeartbeatFreq time.Duration `json:"heartbeatInterval"`
}

// PushWorker periodically pushes aggregated WAF stats to the CatTelemetry collector.
type PushWorker struct {
	cfg    ExternalPushConfig
	db     *pgxpool.Pool
	client *http.Client
	cancel context.CancelFunc
}

// NewPushWorker creates a push worker from environment variables.
func NewPushWorker(db *pgxpool.Pool) *PushWorker {
	cfg := ExternalPushConfig{
		Enabled:       os.Getenv("TELEMETRY_ENABLED") != "false",
		Endpoint:      getEnv("TELEMETRY_ENDPOINT", "https://telemetry.catboy.systems"),
		ProjectToken:  os.Getenv("TELEMETRY_TOKEN"),
		PushInterval:  5 * time.Minute,
		HeartbeatFreq: 2 * time.Minute,
	}

	if cfg.ProjectToken == "" {
		cfg.Enabled = false
	}

	return &PushWorker{
		cfg:    cfg,
		db:     db,
		client: &http.Client{Timeout: 5 * time.Second},
	}
}

// Start begins the background push goroutine.
func (w *PushWorker) Start() {
	if !w.cfg.Enabled {
		log.Info().Msg("[CatTelemetry] External push disabled (no TELEMETRY_TOKEN)")
		return
	}

	ctx, cancel := context.WithCancel(context.Background())
	w.cancel = cancel

	log.Info().Str("endpoint", w.cfg.Endpoint).Msg("[CatTelemetry] Starting external push worker")

	// Stats push loop
	go func() {
		ticker := time.NewTicker(w.cfg.PushInterval)
		defer ticker.Stop()

		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				w.pushStats(ctx)
			}
		}
	}()

	// Heartbeat loop
	go func() {
		ticker := time.NewTicker(w.cfg.HeartbeatFreq)
		defer ticker.Stop()

		// Send initial heartbeat
		w.sendHeartbeat(ctx)

		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				w.sendHeartbeat(ctx)
			}
		}
	}()
}

// Stop gracefully stops the push worker.
func (w *PushWorker) Stop() {
	if w.cancel != nil {
		w.cancel()
	}
}

func (w *PushWorker) pushStats(ctx context.Context) {
	stats := []map[string]interface{}{}

	// Aggregate last push interval from insights_hourly
	row := w.db.QueryRow(ctx, `
		SELECT COALESCE(SUM(total_requests), 0),
		       COALESCE(SUM(blocked_requests), 0),
		       COALESCE(SUM(unique_visitors), 0),
		       COALESCE(AVG(avg_response_time), 0),
		       COALESCE(SUM(bandwidth_bytes), 0)
		FROM insights_hourly
		WHERE hour >= NOW() - INTERVAL '1 hour'
	`)

	var totalReqs, blockedReqs, uniqueVisitors, bandwidthBytes int64
	var avgResponseTime float64
	if err := row.Scan(&totalReqs, &blockedReqs, &uniqueVisitors, &avgResponseTime, &bandwidthBytes); err != nil {
		log.Warn().Err(err).Msg("[CatTelemetry] Failed to query insights")
		return
	}

	now := time.Now().UTC().Format("2006-01-02 15:00:00")

	stats = append(stats,
		map[string]interface{}{"key": "waf_requests_total", "value": totalReqs, "period": "hour", "period_start": now},
		map[string]interface{}{"key": "waf_blocked_total", "value": blockedReqs, "period": "hour", "period_start": now},
		map[string]interface{}{"key": "waf_unique_visitors", "value": uniqueVisitors, "period": "hour", "period_start": now},
		map[string]interface{}{"key": "waf_avg_response_ms", "value": avgResponseTime, "period": "hour", "period_start": now},
		map[string]interface{}{"key": "waf_bandwidth_bytes", "value": bandwidthBytes, "period": "hour", "period_start": now},
	)

	if len(stats) > 0 {
		w.send("/api/v2/stats", map[string]interface{}{"stats": stats})
	}
}

func (w *PushWorker) sendHeartbeat(ctx context.Context) {
	var m runtime.MemStats
	runtime.ReadMemStats(&m)

	metadata := map[string]interface{}{
		"go_version":    runtime.Version(),
		"os":            runtime.GOOS,
		"arch":          runtime.GOARCH,
		"goroutines":    runtime.NumGoroutine(),
		"memory_alloc":  m.Alloc,
		"memory_sys":    m.Sys,
		"service":       "cat-waf-v2",
	}

	// Check DB health
	dbStatus := "ok"
	if err := w.db.Ping(ctx); err != nil {
		dbStatus = "error: " + err.Error()
	}
	metadata["db_status"] = dbStatus

	w.send("/api/v2/heartbeat", map[string]interface{}{
		"status":   "healthy",
		"metadata": metadata,
	})
}

func (w *PushWorker) send(path string, data map[string]interface{}) {
	body, err := json.Marshal(data)
	if err != nil {
		return
	}

	req, err := http.NewRequest("POST", w.cfg.Endpoint+path, bytes.NewReader(body))
	if err != nil {
		return
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Project-Token", w.cfg.ProjectToken)
	req.Header.Set("User-Agent", "CatTelemetry-CatWAF/1.0.0")

	resp, err := w.client.Do(req)
	if err != nil {
		log.Debug().Err(err).Str("path", path).Msg("[CatTelemetry] Push failed")
		return
	}
	defer resp.Body.Close()

	if resp.StatusCode >= 400 {
		log.Debug().Int("status", resp.StatusCode).Str("path", path).Msg("[CatTelemetry] Push returned error")
	}
}

func getEnv(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
