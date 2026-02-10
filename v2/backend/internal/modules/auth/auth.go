// Package auth handles authentication and authorization for CatWAF v2
package auth

import (
	"context"
	"os"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/golang-jwt/jwt/v5"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"
)

// Module implements the auth module
type Module struct {
	db        *pgxpool.Pool
	redis     *redis.Client
	jwtSecret []byte
	handler   *Handler
}

// New creates a new auth module
func New() *Module {
	return &Module{}
}

func (m *Module) Name() string    { return "auth" }
func (m *Module) Version() string { return "2.0.0" }

func (m *Module) Init(ctx context.Context, db *pgxpool.Pool, redis *redis.Client) error {
	m.db = db
	m.redis = redis
	jwtSecret := os.Getenv("JWT_SECRET")
	if jwtSecret == "" {
		jwtSecret = "catwaf-default-secret-change-me"
	}
	m.jwtSecret = []byte(jwtSecret)
	m.handler = &Handler{module: m}
	return nil
}

func (m *Module) RegisterRoutes(router fiber.Router) {
	auth := router.Group("/auth")
	auth.Post("/login", m.handler.Login)
	auth.Post("/logout", m.handler.Logout)
	auth.Get("/me", m.handler.Me)
	auth.Post("/refresh", m.handler.Refresh)
	auth.Post("/api-key", m.handler.GenerateAPIKey)
}

func (m *Module) Shutdown(ctx context.Context) error {
	return nil
}

// Handler handles auth HTTP requests
type Handler struct {
	module *Module
}

// LoginRequest represents a login request
type LoginRequest struct {
	APIKey   string `json:"apiKey"`
	Email    string `json:"email,omitempty"`
	Password string `json:"password,omitempty"`
}

// LoginResponse represents a login response
type LoginResponse struct {
	Token     string `json:"token"`
	ExpiresAt int64  `json:"expiresAt"`
	User      *User  `json:"user"`
}

// User represents an authenticated user
type User struct {
	ID       uuid.UUID `json:"id"`
	TenantID uuid.UUID `json:"tenantId"`
	Email    string    `json:"email"`
	Role     string    `json:"role"`
}

// Claims represents JWT claims
type Claims struct {
	UserID   uuid.UUID `json:"userId"`
	TenantID uuid.UUID `json:"tenantId"`
	Role     string    `json:"role"`
	jwt.RegisteredClaims
}

// Login handles user authentication
func (h *Handler) Login(c *fiber.Ctx) error {
	var req LoginRequest
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{
			"error": "Invalid request body",
		})
	}

	ctx := c.Context()
	var user User

	if req.APIKey != "" {
		// API key authentication
		err := h.module.db.QueryRow(ctx,
			`SELECT id, tenant_id, email, role FROM users WHERE api_key = $1`,
			req.APIKey,
		).Scan(&user.ID, &user.TenantID, &user.Email, &user.Role)
		if err != nil {
			return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{
				"error": "Invalid API key",
			})
		}
	} else {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{
			"error": "API key required",
		})
	}

	// Update last login
	_, _ = h.module.db.Exec(ctx,
		`UPDATE users SET last_login_at = NOW() WHERE id = $1`,
		user.ID,
	)

	// Generate JWT
	expiresAt := time.Now().Add(24 * time.Hour)
	claims := &Claims{
		UserID:   user.ID,
		TenantID: user.TenantID,
		Role:     user.Role,
		RegisteredClaims: jwt.RegisteredClaims{
			ExpiresAt: jwt.NewNumericDate(expiresAt),
			IssuedAt:  jwt.NewNumericDate(time.Now()),
			Issuer:    "catwaf",
		},
	}

	token := jwt.NewWithClaims(jwt.SigningMethodHS256, claims)
	tokenString, err := token.SignedString(h.module.jwtSecret)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to generate token",
		})
	}

	return c.JSON(LoginResponse{
		Token:     tokenString,
		ExpiresAt: expiresAt.Unix(),
		User:      &user,
	})
}

// Logout handles user logout
func (h *Handler) Logout(c *fiber.Ctx) error {
	// Invalidate token by adding to blacklist in Redis
	token := c.Get("Authorization")
	if token != "" {
		ctx := c.Context()
		_ = h.module.redis.Set(ctx, "blacklist:"+token, "1", 24*time.Hour).Err()
	}
	return c.JSON(fiber.Map{"success": true})
}

// Me returns the current user's information
func (h *Handler) Me(c *fiber.Ctx) error {
	claims := c.Locals("claims").(*Claims)
	if claims == nil {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{
			"error": "Not authenticated",
		})
	}

	ctx := c.Context()
	var user User
	err := h.module.db.QueryRow(ctx,
		`SELECT id, tenant_id, email, role FROM users WHERE id = $1`,
		claims.UserID,
	).Scan(&user.ID, &user.TenantID, &user.Email, &user.Role)
	if err != nil {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{
			"error": "User not found",
		})
	}

	return c.JSON(fiber.Map{"user": user})
}

// Refresh refreshes the JWT token
func (h *Handler) Refresh(c *fiber.Ctx) error {
	claims := c.Locals("claims").(*Claims)
	if claims == nil {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{
			"error": "Not authenticated",
		})
	}

	expiresAt := time.Now().Add(24 * time.Hour)
	newClaims := &Claims{
		UserID:   claims.UserID,
		TenantID: claims.TenantID,
		Role:     claims.Role,
		RegisteredClaims: jwt.RegisteredClaims{
			ExpiresAt: jwt.NewNumericDate(expiresAt),
			IssuedAt:  jwt.NewNumericDate(time.Now()),
			Issuer:    "catwaf",
		},
	}

	token := jwt.NewWithClaims(jwt.SigningMethodHS256, newClaims)
	tokenString, err := token.SignedString(h.module.jwtSecret)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to generate token",
		})
	}

	return c.JSON(fiber.Map{
		"token":     tokenString,
		"expiresAt": expiresAt.Unix(),
	})
}

// GenerateAPIKey generates a new API key for the user
func (h *Handler) GenerateAPIKey(c *fiber.Ctx) error {
	claims := c.Locals("claims").(*Claims)
	if claims == nil {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{
			"error": "Not authenticated",
		})
	}

	apiKey := uuid.New().String()
	ctx := c.Context()

	_, err := h.module.db.Exec(ctx,
		`UPDATE users SET api_key = $1 WHERE id = $2`,
		apiKey, claims.UserID,
	)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{
			"error": "Failed to generate API key",
		})
	}

	return c.JSON(fiber.Map{
		"apiKey": apiKey,
	})
}

// JWTMiddleware validates JWT tokens
func JWTMiddleware(jwtSecret []byte, redis *redis.Client) fiber.Handler {
	return func(c *fiber.Ctx) error {
		auth := c.Get("Authorization")
		if auth == "" {
			return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{
				"error": "Missing authorization header",
			})
		}

		// Remove "Bearer " prefix
		tokenString := auth
		if len(auth) > 7 && auth[:7] == "Bearer " {
			tokenString = auth[7:]
		}

		// Check blacklist
		ctx := c.Context()
		blacklisted, _ := redis.Exists(ctx, "blacklist:"+auth).Result()
		if blacklisted > 0 {
			return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{
				"error": "Token has been revoked",
			})
		}

		// Parse and validate token
		token, err := jwt.ParseWithClaims(tokenString, &Claims{}, func(token *jwt.Token) (interface{}, error) {
			return jwtSecret, nil
		})

		if err != nil || !token.Valid {
			return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{
				"error": "Invalid or expired token",
			})
		}

		claims, ok := token.Claims.(*Claims)
		if !ok {
			return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{
				"error": "Invalid token claims",
			})
		}

		c.Locals("claims", claims)
		c.Locals("userId", claims.UserID)
		c.Locals("tenantId", claims.TenantID)
		c.Locals("role", claims.Role)

		return c.Next()
	}
}
