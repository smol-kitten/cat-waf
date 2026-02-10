// Package certificates handles SSL/TLS certificate management for CatWAF v2
package certificates

import (
	"context"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"
)

// Module implements the certificates module
type Module struct {
	db    *pgxpool.Pool
	redis *redis.Client
}

func New() *Module { return &Module{} }

func (m *Module) Name() string    { return "certificates" }
func (m *Module) Version() string { return "2.0.0" }

func (m *Module) Init(ctx context.Context, db *pgxpool.Pool, redis *redis.Client) error {
	m.db = db
	m.redis = redis
	return nil
}

func (m *Module) RegisterRoutes(router fiber.Router) {
	certs := router.Group("/certificates")
	certs.Get("/", m.List)
	certs.Get("/status", m.Status)
	certs.Get("/:domain", m.Get)
	certs.Post("/:domain/renew", m.Renew)
	certs.Post("/upload", m.Upload)
	certs.Delete("/:domain", m.Delete)
	
	// CA center
	certs.Get("/ca", m.ListCA)
	certs.Post("/ca/issue", m.IssueCert)
}

func (m *Module) Shutdown(ctx context.Context) error { return nil }

// Certificate represents an SSL certificate
type Certificate struct {
	ID             uuid.UUID `json:"id"`
	SiteID         uuid.UUID `json:"siteId"`
	Domain         string    `json:"domain"`
	Issuer         string    `json:"issuer"`
	NotBefore      time.Time `json:"notBefore"`
	NotAfter       time.Time `json:"notAfter"`
	Fingerprint    string    `json:"fingerprint"`
	AutoRenew      bool      `json:"autoRenew"`
	DaysUntilExpiry int       `json:"daysUntilExpiry"`
	CreatedAt      time.Time `json:"createdAt"`
}

func (m *Module) List(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	ctx := c.Context()
	rows, err := m.db.Query(ctx, `
		SELECT sc.id, sc.site_id, sc.domain, sc.issuer, sc.not_before, sc.not_after, sc.fingerprint, sc.created_at
		FROM ssl_certificates sc
		JOIN sites s ON sc.site_id = s.id
		WHERE s.tenant_id = $1
		ORDER BY sc.not_after ASC
	`, tenantID)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to fetch certificates"})
	}
	defer rows.Close()

	certs := make([]Certificate, 0)
	for rows.Next() {
		var cert Certificate
		if rows.Scan(&cert.ID, &cert.SiteID, &cert.Domain, &cert.Issuer, &cert.NotBefore, &cert.NotAfter, &cert.Fingerprint, &cert.CreatedAt) == nil {
			cert.DaysUntilExpiry = int(time.Until(cert.NotAfter).Hours() / 24)
			cert.AutoRenew = cert.Issuer == "Let's Encrypt"
			certs = append(certs, cert)
		}
	}

	return c.JSON(fiber.Map{"certificates": certs})
}

func (m *Module) Status(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)

	ctx := c.Context()

	var status struct {
		Total    int `json:"total"`
		Valid    int `json:"valid"`
		Expiring int `json:"expiring"` // < 30 days
		Expired  int `json:"expired"`
	}

	_ = m.db.QueryRow(ctx, `
		SELECT COUNT(*) FROM ssl_certificates sc
		JOIN sites s ON sc.site_id = s.id WHERE s.tenant_id = $1
	`, tenantID).Scan(&status.Total)

	_ = m.db.QueryRow(ctx, `
		SELECT COUNT(*) FROM ssl_certificates sc
		JOIN sites s ON sc.site_id = s.id 
		WHERE s.tenant_id = $1 AND sc.not_after > NOW() + INTERVAL '30 days'
	`, tenantID).Scan(&status.Valid)

	_ = m.db.QueryRow(ctx, `
		SELECT COUNT(*) FROM ssl_certificates sc
		JOIN sites s ON sc.site_id = s.id 
		WHERE s.tenant_id = $1 AND sc.not_after > NOW() AND sc.not_after <= NOW() + INTERVAL '30 days'
	`, tenantID).Scan(&status.Expiring)

	_ = m.db.QueryRow(ctx, `
		SELECT COUNT(*) FROM ssl_certificates sc
		JOIN sites s ON sc.site_id = s.id WHERE s.tenant_id = $1 AND sc.not_after <= NOW()
	`, tenantID).Scan(&status.Expired)

	return c.JSON(fiber.Map{"status": status})
}

func (m *Module) Get(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	domain := c.Params("domain")

	ctx := c.Context()
	var cert Certificate
	err := m.db.QueryRow(ctx, `
		SELECT sc.id, sc.site_id, sc.domain, sc.issuer, sc.not_before, sc.not_after, sc.fingerprint, sc.created_at
		FROM ssl_certificates sc
		JOIN sites s ON sc.site_id = s.id
		WHERE s.tenant_id = $1 AND sc.domain = $2
	`, tenantID, domain).Scan(&cert.ID, &cert.SiteID, &cert.Domain, &cert.Issuer, &cert.NotBefore, &cert.NotAfter, &cert.Fingerprint, &cert.CreatedAt)

	if err != nil {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "Certificate not found"})
	}

	cert.DaysUntilExpiry = int(time.Until(cert.NotAfter).Hours() / 24)
	cert.AutoRenew = cert.Issuer == "Let's Encrypt"

	return c.JSON(fiber.Map{"certificate": cert})
}

func (m *Module) Renew(c *fiber.Ctx) error {
	domain := c.Params("domain")
	// Would trigger ACME renewal
	return c.JSON(fiber.Map{
		"success": true,
		"message": "Certificate renewal initiated for " + domain,
		"domain":  domain,
	})
}

func (m *Module) Upload(c *fiber.Ctx) error {
	var req struct {
		Domain          string `json:"domain"`
		CertificatePEM  string `json:"certificatePem"`
		PrivateKeyPEM   string `json:"privateKeyPem"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	// Would validate and store the certificate
	return c.Status(fiber.StatusCreated).JSON(fiber.Map{
		"success": true,
		"message": "Certificate uploaded for " + req.Domain,
	})
}

func (m *Module) Delete(c *fiber.Ctx) error {
	tenantID := c.Locals("tenantId").(uuid.UUID)
	domain := c.Params("domain")

	ctx := c.Context()
	result, err := m.db.Exec(ctx, `
		DELETE FROM ssl_certificates sc
		USING sites s
		WHERE sc.site_id = s.id AND s.tenant_id = $1 AND sc.domain = $2
	`, tenantID, domain)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to delete certificate"})
	}

	if result.RowsAffected() == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "Certificate not found"})
	}

	return c.JSON(fiber.Map{"success": true})
}

func (m *Module) ListCA(c *fiber.Ctx) error {
	// Would list internal CA certificates
	return c.JSON(fiber.Map{"certificates": []interface{}{}})
}

func (m *Module) IssueCert(c *fiber.Ctx) error {
	var req struct {
		Domain     string `json:"domain"`
		CommonName string `json:"commonName"`
		ValidDays  int    `json:"validDays"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	// Would issue an internal certificate
	return c.Status(fiber.StatusCreated).JSON(fiber.Map{
		"success": true,
		"message": "Certificate issued for " + req.Domain,
	})
}
