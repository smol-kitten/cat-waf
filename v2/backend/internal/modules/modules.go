// Package modules defines the module interface and registry for CatWAF v2
package modules

import (
	"context"

	"github.com/gofiber/fiber/v2"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"
)

// Module defines the interface that all CatWAF modules must implement
type Module interface {
	// Name returns the module's unique identifier
	Name() string
	// Version returns the module's semantic version
	Version() string
	// Init initializes the module with dependencies
	Init(ctx context.Context, deps *Dependencies) error
	// RegisterRoutes registers the module's HTTP routes
	RegisterRoutes(router fiber.Router)
	// Shutdown gracefully shuts down the module
	Shutdown(ctx context.Context) error
}

// Dependencies contains shared dependencies injected into modules
type Dependencies struct {
	DB     *pgxpool.Pool
	Redis  *redis.Client
	Config *Config
}

// Config holds module configuration
type Config struct {
	Environment string
	Debug       bool
}

// Registry holds all registered modules
type Registry struct {
	modules []Module
	deps    *Dependencies
}

// NewRegistry creates a new module registry
func NewRegistry(deps *Dependencies) *Registry {
	return &Registry{
		modules: make([]Module, 0),
		deps:    deps,
	}
}

// Register adds a module to the registry
func (r *Registry) Register(m Module) {
	r.modules = append(r.modules, m)
}

// InitAll initializes all registered modules
func (r *Registry) InitAll(ctx context.Context) error {
	for _, m := range r.modules {
		if err := m.Init(ctx, r.deps); err != nil {
			return err
		}
	}
	return nil
}

// RegisterAllRoutes registers routes for all modules
func (r *Registry) RegisterAllRoutes(api fiber.Router) {
	for _, m := range r.modules {
		m.RegisterRoutes(api)
	}
}

// ShutdownAll gracefully shuts down all modules
func (r *Registry) ShutdownAll(ctx context.Context) error {
	for _, m := range r.modules {
		if err := m.Shutdown(ctx); err != nil {
			return err
		}
	}
	return nil
}

// All returns all registered modules
func (r *Registry) All() []Module {
	return r.modules
}
