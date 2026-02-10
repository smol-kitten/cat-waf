package main

import (
	"context"
	"os"
	"os/signal"
	"syscall"

	"github.com/rs/zerolog"
	"github.com/rs/zerolog/log"
	"github.com/smol-kitten/catwaf/v2/internal/app"
	"github.com/smol-kitten/catwaf/v2/internal/modules/alerts"
	"github.com/smol-kitten/catwaf/v2/internal/modules/auth"
	"github.com/smol-kitten/catwaf/v2/internal/modules/bans"
	"github.com/smol-kitten/catwaf/v2/internal/modules/bots"
	"github.com/smol-kitten/catwaf/v2/internal/modules/certificates"
	"github.com/smol-kitten/catwaf/v2/internal/modules/config"
	"github.com/smol-kitten/catwaf/v2/internal/modules/logs"
	"github.com/smol-kitten/catwaf/v2/internal/modules/routers"
	"github.com/smol-kitten/catwaf/v2/internal/modules/security"
	"github.com/smol-kitten/catwaf/v2/internal/modules/settings"
	"github.com/smol-kitten/catwaf/v2/internal/modules/sites"
	"github.com/smol-kitten/catwaf/v2/internal/modules/stats"
	"github.com/smol-kitten/catwaf/v2/internal/modules/telemetry"
)

func main() {
	// Setup logging
	zerolog.TimeFieldFormat = zerolog.TimeFormatUnix
	log.Logger = log.Output(zerolog.ConsoleWriter{Out: os.Stderr})

	log.Info().Msg("🐱 Starting CatWAF v2.0...")

	// Load configuration
	cfg, err := app.LoadConfig()
	if err != nil {
		log.Fatal().Err(err).Msg("Failed to load configuration")
	}

	// Create application
	application, err := app.New(cfg)
	if err != nil {
		log.Fatal().Err(err).Msg("Failed to create application")
	}

	// Register all modules
	registerModules(application)

	// Start application
	go func() {
		if err := application.Start(); err != nil {
			log.Fatal().Err(err).Msg("Application failed to start")
		}
	}()

	log.Info().Msgf("🚀 CatWAF API running on %s", cfg.Server.Address)

	// Graceful shutdown
	quit := make(chan os.Signal, 1)
	signal.Notify(quit, syscall.SIGINT, syscall.SIGTERM)
	<-quit

	log.Info().Msg("Shutting down server...")

	ctx, cancel := context.WithTimeout(context.Background(), cfg.Server.ShutdownTimeout)
	defer cancel()

	if err := application.Shutdown(ctx); err != nil {
		log.Error().Err(err).Msg("Server forced to shutdown")
	}

	log.Info().Msg("👋 Server exited properly")
}

// registerModules initializes and registers all application modules
func registerModules(application *app.Application) {
	modules := []app.Module{
		auth.New(),
		sites.New(),
		bans.New(),
		security.New(),
		bots.New(),
		certificates.New(),
		alerts.New(),
		settings.New(),
		stats.New(),
		logs.New(),
		config.New(),
		routers.New(),
		telemetry.New(),
	}

	for _, module := range modules {
		if err := application.RegisterModule(module); err != nil {
			log.Fatal().Err(err).Str("module", module.Name()).Msg("Failed to register module")
		}
	}

	log.Info().Int("count", len(modules)).Msg("✅ All modules registered")
}
