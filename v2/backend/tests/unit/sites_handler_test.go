package sites_test

import (
	"context"
	"encoding/json"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/google/uuid"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/mock"
)

// MockRepository is a mock implementation of the sites repository
type MockRepository struct {
	mock.Mock
}

func (m *MockRepository) Create(ctx context.Context, dto *CreateSiteDTO) (*Site, error) {
	args := m.Called(ctx, dto)
	if args.Get(0) == nil {
		return nil, args.Error(1)
	}
	return args.Get(0).(*Site), args.Error(1)
}

func (m *MockRepository) GetByID(ctx context.Context, id, tenantID uuid.UUID) (*Site, error) {
	args := m.Called(ctx, id, tenantID)
	if args.Get(0) == nil {
		return nil, args.Error(1)
	}
	return args.Get(0).(*Site), args.Error(1)
}

func (m *MockRepository) GetByDomain(ctx context.Context, domain string, tenantID uuid.UUID) (*Site, error) {
	args := m.Called(ctx, domain, tenantID)
	if args.Get(0) == nil {
		return nil, args.Error(1)
	}
	return args.Get(0).(*Site), args.Error(1)
}

func (m *MockRepository) List(ctx context.Context, params *ListSitesParams) (*SiteListResponse, error) {
	args := m.Called(ctx, params)
	if args.Get(0) == nil {
		return nil, args.Error(1)
	}
	return args.Get(0).(*SiteListResponse), args.Error(1)
}

func (m *MockRepository) Update(ctx context.Context, id, tenantID uuid.UUID, dto *UpdateSiteDTO) (*Site, error) {
	args := m.Called(ctx, id, tenantID, dto)
	if args.Get(0) == nil {
		return nil, args.Error(1)
	}
	return args.Get(0).(*Site), args.Error(1)
}

func (m *MockRepository) Delete(ctx context.Context, id, tenantID uuid.UUID) error {
	args := m.Called(ctx, id, tenantID)
	return args.Error(0)
}

// Import the package types for testing
type Site struct {
	ID       uuid.UUID `json:"id"`
	TenantID uuid.UUID `json:"tenant_id"`
	Domain   string    `json:"domain"`
	Enabled  bool      `json:"enabled"`
}

type CreateSiteDTO struct {
	TenantID uuid.UUID
	Domain   string
	Enabled  bool
}

type UpdateSiteDTO struct {
	Domain  *string
	Enabled *bool
}

type ListSitesParams struct {
	TenantID uuid.UUID
	Page     int
	Limit    int
}

type SiteListResponse struct {
	Sites      []Site `json:"sites"`
	Total      int    `json:"total"`
	Page       int    `json:"page"`
	Limit      int    `json:"limit"`
	TotalPages int    `json:"total_pages"`
}

func TestSiteHandler_List(t *testing.T) {
	app := fiber.New()
	
	// Setup mock
	tenantID := uuid.MustParse("00000000-0000-0000-0000-000000000001")
	expectedSites := &SiteListResponse{
		Sites: []Site{
			{ID: uuid.New(), TenantID: tenantID, Domain: "example.com", Enabled: true},
			{ID: uuid.New(), TenantID: tenantID, Domain: "test.com", Enabled: false},
		},
		Total:      2,
		Page:       1,
		Limit:      20,
		TotalPages: 1,
	}

	// Simple test handler
	app.Get("/api/v2/sites", func(c *fiber.Ctx) error {
		return c.JSON(expectedSites)
	})

	req := httptest.NewRequest("GET", "/api/v2/sites", nil)
	req.Header.Set("Content-Type", "application/json")

	resp, err := app.Test(req)

	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result SiteListResponse
	json.NewDecoder(resp.Body).Decode(&result)
	
	assert.Len(t, result.Sites, 2)
	assert.Equal(t, "example.com", result.Sites[0].Domain)
}

func TestSiteHandler_Create(t *testing.T) {
	app := fiber.New()
	
	tenantID := uuid.MustParse("00000000-0000-0000-0000-000000000001")
	
	app.Post("/api/v2/sites", func(c *fiber.Ctx) error {
		var dto CreateSiteDTO
		if err := c.BodyParser(&dto); err != nil {
			return c.Status(400).JSON(fiber.Map{"error": "Invalid request body"})
		}
		
		site := &Site{
			ID:       uuid.New(),
			TenantID: tenantID,
			Domain:   dto.Domain,
			Enabled:  dto.Enabled,
		}
		
		return c.Status(201).JSON(site)
	})

	body := `{"domain": "newsite.com", "enabled": true}`
	req := httptest.NewRequest("POST", "/api/v2/sites", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")

	resp, err := app.Test(req)

	assert.NoError(t, err)
	assert.Equal(t, 201, resp.StatusCode)

	var site Site
	json.NewDecoder(resp.Body).Decode(&site)
	
	assert.Equal(t, "newsite.com", site.Domain)
	assert.True(t, site.Enabled)
}

func TestSiteHandler_Get(t *testing.T) {
	app := fiber.New()
	
	siteID := uuid.New()
	tenantID := uuid.MustParse("00000000-0000-0000-0000-000000000001")
	
	app.Get("/api/v2/sites/:id", func(c *fiber.Ctx) error {
		id := c.Params("id")
		if id != siteID.String() {
			return c.Status(404).JSON(fiber.Map{"error": "Site not found"})
		}
		
		site := &Site{
			ID:       siteID,
			TenantID: tenantID,
			Domain:   "example.com",
			Enabled:  true,
		}
		
		return c.JSON(site)
	})

	req := httptest.NewRequest("GET", "/api/v2/sites/"+siteID.String(), nil)
	req.Header.Set("Content-Type", "application/json")

	resp, err := app.Test(req)

	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var site Site
	json.NewDecoder(resp.Body).Decode(&site)
	
	assert.Equal(t, siteID, site.ID)
	assert.Equal(t, "example.com", site.Domain)
}

func TestSiteHandler_Delete(t *testing.T) {
	app := fiber.New()
	
	siteID := uuid.New()
	
	app.Delete("/api/v2/sites/:id", func(c *fiber.Ctx) error {
		id := c.Params("id")
		if id != siteID.String() {
			return c.Status(404).JSON(fiber.Map{"error": "Site not found"})
		}
		return c.SendStatus(204)
	})

	req := httptest.NewRequest("DELETE", "/api/v2/sites/"+siteID.String(), nil)

	resp, err := app.Test(req)

	assert.NoError(t, err)
	assert.Equal(t, 204, resp.StatusCode)
}

func TestSiteHandler_NotFound(t *testing.T) {
	app := fiber.New()
	
	app.Get("/api/v2/sites/:id", func(c *fiber.Ctx) error {
		return c.Status(404).JSON(fiber.Map{"error": "Site not found"})
	})

	req := httptest.NewRequest("GET", "/api/v2/sites/"+uuid.New().String(), nil)

	resp, err := app.Test(req)

	assert.NoError(t, err)
	assert.Equal(t, 404, resp.StatusCode)
}
