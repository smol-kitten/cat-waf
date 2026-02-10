package app

import (
	"strings"
	"time"

	"github.com/spf13/viper"
)

// Config holds the application configuration
type Config struct {
	Server   ServerConfig
	Database DatabaseConfig
	Redis    RedisConfig
	Auth     AuthConfig
	Logging  LoggingConfig
}

// ServerConfig holds HTTP server configuration
type ServerConfig struct {
	Address         string
	ReadTimeout     time.Duration
	WriteTimeout    time.Duration
	ShutdownTimeout time.Duration
	TrustedProxies  []string
}

// DatabaseConfig holds database configuration
type DatabaseConfig struct {
	Host            string
	Port            int
	User            string
	Password        string
	Database        string
	SSLMode         string
	MaxOpenConns    int
	MaxIdleConns    int
	ConnMaxLifetime time.Duration
}

// RedisConfig holds Redis configuration
type RedisConfig struct {
	Host     string
	Port     int
	Password string
	DB       int
}

// AuthConfig holds authentication configuration
type AuthConfig struct {
	JWTSecret       string
	JWTExpiration   time.Duration
	APIKeyHeader    string
	DefaultAPIKey   string // For initial setup only
}

// LoggingConfig holds logging configuration
type LoggingConfig struct {
	Level  string
	Format string
	Output string
}

// LoadConfig loads configuration from environment and files
func LoadConfig() (*Config, error) {
	v := viper.New()

	// Set defaults
	v.SetDefault("server.address", ":8080")
	v.SetDefault("server.readTimeout", "10s")
	v.SetDefault("server.writeTimeout", "10s")
	v.SetDefault("server.shutdownTimeout", "30s")
	v.SetDefault("server.trustedProxies", []string{"127.0.0.1", "::1"})

	v.SetDefault("database.host", "localhost")
	v.SetDefault("database.port", 5432)
	v.SetDefault("database.user", "catwaf")
	v.SetDefault("database.database", "catwaf")
	v.SetDefault("database.sslMode", "disable")
	v.SetDefault("database.maxOpenConns", 25)
	v.SetDefault("database.maxIdleConns", 5)
	v.SetDefault("database.connMaxLifetime", "5m")

	v.SetDefault("redis.host", "localhost")
	v.SetDefault("redis.port", 6379)
	v.SetDefault("redis.db", 0)

	v.SetDefault("auth.jwtExpiration", "24h")
	v.SetDefault("auth.apiKeyHeader", "X-API-Key")

	v.SetDefault("logging.level", "info")
	v.SetDefault("logging.format", "console")
	v.SetDefault("logging.output", "stderr")

	// Read from environment
	v.SetEnvPrefix("CATWAF")
	v.SetEnvKeyReplacer(strings.NewReplacer(".", "_"))
	v.AutomaticEnv()

	// Read from config file if exists
	v.SetConfigName("config")
	v.SetConfigType("yaml")
	v.AddConfigPath(".")
	v.AddConfigPath("/etc/catwaf")
	if err := v.ReadInConfig(); err != nil {
		if _, ok := err.(viper.ConfigFileNotFoundError); !ok {
			return nil, err
		}
	}

	cfg := &Config{
		Server: ServerConfig{
			Address:         v.GetString("server.address"),
			ReadTimeout:     v.GetDuration("server.readTimeout"),
			WriteTimeout:    v.GetDuration("server.writeTimeout"),
			ShutdownTimeout: v.GetDuration("server.shutdownTimeout"),
			TrustedProxies:  v.GetStringSlice("server.trustedProxies"),
		},
		Database: DatabaseConfig{
			Host:            v.GetString("database.host"),
			Port:            v.GetInt("database.port"),
			User:            v.GetString("database.user"),
			Password:        v.GetString("database.password"),
			Database:        v.GetString("database.database"),
			SSLMode:         v.GetString("database.sslMode"),
			MaxOpenConns:    v.GetInt("database.maxOpenConns"),
			MaxIdleConns:    v.GetInt("database.maxIdleConns"),
			ConnMaxLifetime: v.GetDuration("database.connMaxLifetime"),
		},
		Redis: RedisConfig{
			Host:     v.GetString("redis.host"),
			Port:     v.GetInt("redis.port"),
			Password: v.GetString("redis.password"),
			DB:       v.GetInt("redis.db"),
		},
		Auth: AuthConfig{
			JWTSecret:     v.GetString("auth.jwtSecret"),
			JWTExpiration: v.GetDuration("auth.jwtExpiration"),
			APIKeyHeader:  v.GetString("auth.apiKeyHeader"),
			DefaultAPIKey: v.GetString("auth.defaultApiKey"),
		},
		Logging: LoggingConfig{
			Level:  v.GetString("logging.level"),
			Format: v.GetString("logging.format"),
			Output: v.GetString("logging.output"),
		},
	}

	return cfg, nil
}

// DSN returns the database connection string
func (c *DatabaseConfig) DSN() string {
	return "host=" + c.Host +
		" port=" + string(rune(c.Port)) +
		" user=" + c.User +
		" password=" + c.Password +
		" dbname=" + c.Database +
		" sslmode=" + c.SSLMode
}
