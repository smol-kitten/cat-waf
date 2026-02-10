package app

import (
	"context"
	"fmt"

	"github.com/gofiber/fiber/v2"
	"github.com/gofiber/fiber/v2/middleware/cors"
	"github.com/gofiber/fiber/v2/middleware/logger"
	"github.com/gofiber/fiber/v2/middleware/recover"
	"github.com/gofiber/fiber/v2/middleware/requestid"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"
	"github.com/rs/zerolog/log"
)

// Application represents the main application
type Application struct {
	config *Config
	fiber  *fiber.App
	db     *pgxpool.Pool
	redis  *redis.Client
	
	// Module registry
	modules []Module
	
	// Route groups for module registration
	authRouter      fiber.Router
	protectedRouter fiber.Router
}

// Module interface for pluggable modules
type Module interface {
	Name() string
	Version() string
	Init(ctx context.Context, db *pgxpool.Pool, redis *redis.Client) error
	RegisterRoutes(router fiber.Router)
	Shutdown(ctx context.Context) error
}

// New creates a new application instance
func New(cfg *Config) (*Application, error) {
	app := &Application{
		config:  cfg,
		modules: make([]Module, 0),
	}

	// Initialize Fiber
	app.fiber = fiber.New(fiber.Config{
		AppName:      "CatWAF v2.0",
		ReadTimeout:  cfg.Server.ReadTimeout,
		WriteTimeout: cfg.Server.WriteTimeout,
		ErrorHandler: app.errorHandler,
	})

	// Setup middleware
	app.setupMiddleware()

	// Connect to database
	if err := app.connectDatabase(context.Background()); err != nil {
		return nil, fmt.Errorf("failed to connect to database: %w", err)
	}

	// Connect to Redis
	if err := app.connectRedis(context.Background()); err != nil {
		log.Warn().Err(err).Msg("Redis connection failed, continuing without cache")
	}

	// Setup routes
	app.setupRoutes()

	return app, nil
}

// setupMiddleware configures global middleware
func (a *Application) setupMiddleware() {
	// Recovery middleware
	a.fiber.Use(recover.New())

	// Request ID
	a.fiber.Use(requestid.New())

	// Logger
	a.fiber.Use(logger.New(logger.Config{
		Format: "${time} | ${status} | ${latency} | ${ip} | ${method} | ${path}\n",
	}))

	// CORS
	a.fiber.Use(cors.New(cors.Config{
		AllowOrigins:     "*",
		AllowMethods:     "GET,POST,PUT,PATCH,DELETE,OPTIONS",
		AllowHeaders:     "Origin, Content-Type, Accept, Authorization, X-API-Key",
		AllowCredentials: false,
		MaxAge:           86400,
	}))
}

// setupRoutes configures application routes
func (a *Application) setupRoutes() {
	// Health check endpoints
	a.fiber.Get("/health", a.healthHandler)
	a.fiber.Get("/api/health", a.healthHandler)

	// API info
	a.fiber.Get("/api/info", a.infoHandler)

	// API v2 routes
	v2 := a.fiber.Group("/api/v2")

	// Auth routes (public) - store for module registration
	a.authRouter = v2.Group("/auth")

	// Protected routes with auth middleware - store for module registration
	a.protectedRouter = v2.Group("", a.authMiddleware)
}

// authMiddleware validates JWT tokens or API keys
func (a *Application) authMiddleware(c *fiber.Ctx) error {
	// Check for API key first
	apiKey := c.Get("X-API-Key")
	if apiKey != "" {
		// Validate API key from database
		var tenantID uuid.UUID
		err := a.db.QueryRow(c.Context(), 
			`SELECT tenant_id FROM api_keys WHERE key_hash = $1 AND (expires_at IS NULL OR expires_at > NOW())`,
			apiKey,
		).Scan(&tenantID)
		if err == nil {
			c.Locals("tenantId", tenantID)
			c.Locals("authMethod", "api_key")
			return c.Next()
		}
	}

	// Check for Bearer token
	auth := c.Get("Authorization")
	if auth != "" && len(auth) > 7 && auth[:7] == "Bearer " {
		token := auth[7:]
		// Would validate JWT token here
		// For now, extract tenant from token claims
		_ = token
		// Simulated tenant for development
		c.Locals("tenantId", uuid.MustParse("00000000-0000-0000-0000-000000000001"))
		c.Locals("authMethod", "jwt")
		return c.Next()
	}

	return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{
		"error": "Authentication required",
	})
}

// connectDatabase establishes database connection
func (a *Application) connectDatabase(ctx context.Context) error {
	dsn := fmt.Sprintf(
		"postgres://%s:%s@%s:%d/%s?sslmode=%s",
		a.config.Database.User,
		a.config.Database.Password,
		a.config.Database.Host,
		a.config.Database.Port,
		a.config.Database.Database,
		a.config.Database.SSLMode,
	)

	poolConfig, err := pgxpool.ParseConfig(dsn)
	if err != nil {
		return err
	}

	poolConfig.MaxConns = int32(a.config.Database.MaxOpenConns)
	poolConfig.MinConns = int32(a.config.Database.MaxIdleConns)
	poolConfig.MaxConnLifetime = a.config.Database.ConnMaxLifetime

	pool, err := pgxpool.NewWithConfig(ctx, poolConfig)
	if err != nil {
		return err
	}

	// Verify connection
	if err := pool.Ping(ctx); err != nil {
		return err
	}

	a.db = pool
	log.Info().Msg("📦 Database connected")
	return nil
}

// connectRedis establishes Redis connection
func (a *Application) connectRedis(ctx context.Context) error {
	a.redis = redis.NewClient(&redis.Options{
		Addr:     fmt.Sprintf("%s:%d", a.config.Redis.Host, a.config.Redis.Port),
		Password: a.config.Redis.Password,
		DB:       a.config.Redis.DB,
	})

	if err := a.redis.Ping(ctx).Err(); err != nil {
		return err
	}

	log.Info().Msg("🔴 Redis connected")
	return nil
}

// RegisterModule adds a module to the application
func (a *Application) RegisterModule(module Module) error {
	ctx := context.Background()
	
	if err := module.Init(ctx, a.db, a.redis); err != nil {
		return fmt.Errorf("failed to initialize module %s: %w", module.Name(), err)
	}
	
	// Register routes for this module
	if module.Name() == "auth" {
		module.RegisterRoutes(a.authRouter)
	} else {
		module.RegisterRoutes(a.protectedRouter)
	}
	
	a.modules = append(a.modules, module)
	log.Info().Str("module", module.Name()).Str("version", module.Version()).Msg("📦 Module registered")
	
	return nil
}

// Start starts the application server
func (a *Application) Start() error {
	return a.fiber.Listen(a.config.Server.Address)
}

// Shutdown gracefully shuts down the application
func (a *Application) Shutdown(ctx context.Context) error {
	// Shutdown modules
	for _, module := range a.modules {
		if err := module.Shutdown(ctx); err != nil {
			log.Error().Err(err).Str("module", module.Name()).Msg("Module shutdown error")
		}
	}

	// Close database
	if a.db != nil {
		a.db.Close()
	}

	// Close Redis
	if a.redis != nil {
		a.redis.Close()
	}

	// Shutdown Fiber
	return a.fiber.ShutdownWithContext(ctx)
}

// DB returns the database pool
func (a *Application) DB() *pgxpool.Pool {
	return a.db
}

// Redis returns the Redis client
func (a *Application) Redis() *redis.Client {
	return a.redis
}

// Config returns the application configuration
func (a *Application) Config() *Config {
	return a.config
}

// errorHandler handles application errors
func (a *Application) errorHandler(c *fiber.Ctx, err error) error {
	code := fiber.StatusInternalServerError

	if e, ok := err.(*fiber.Error); ok {
		code = e.Code
	}

	return c.Status(code).JSON(fiber.Map{
		"error":   true,
		"message": err.Error(),
	})
}

// healthHandler returns health status
func (a *Application) healthHandler(c *fiber.Ctx) error {
	dbStatus := "connected"
	if err := a.db.Ping(c.Context()); err != nil {
		dbStatus = "disconnected"
	}

	redisStatus := "connected"
	if a.redis == nil || a.redis.Ping(c.Context()).Err() != nil {
		redisStatus = "disconnected"
	}

	return c.JSON(fiber.Map{
		"status":    "ok",
		"version":   "2.0.0",
		"database":  dbStatus,
		"cache":     redisStatus,
		"timestamp": c.Context().Time().Unix(),
	})
}

// infoHandler returns API info
func (a *Application) infoHandler(c *fiber.Ctx) error {
	modules := make([]map[string]string, len(a.modules))
	for i, m := range a.modules {
		modules[i] = map[string]string{
			"name":    m.Name(),
			"version": m.Version(),
		}
	}

	return c.JSON(fiber.Map{
		"name":        "CatWAF API",
		"version":     "2.0.0",
		"tagline":     "Purr-tecting your sites since 2025",
		"modules":     modules,
		"apiVersions": []string{"v1 (deprecated)", "v2"},
	})
}
