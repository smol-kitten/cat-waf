import { test, expect } from '@playwright/test';

test.describe('Authentication', () => {
	test('should display login page', async ({ page }) => {
		await page.goto('/login');
		
		await expect(page).toHaveTitle(/Login - CatWAF/);
		await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
		await expect(page.getByPlaceholder('Enter your API key')).toBeVisible();
	});

	test('should show error for invalid API key', async ({ page }) => {
		await page.goto('/login');
		
		await page.fill('[placeholder="Enter your API key"]', 'invalid-key');
		await page.click('button[type="submit"]');
		
		await expect(page.getByText('Invalid API key')).toBeVisible();
	});

	test('should redirect to dashboard on successful login', async ({ page }) => {
		// Mock the API response
		await page.route('/api/v2/auth/login', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					token: 'test-token',
					user: {
						id: '1',
						email: 'admin@example.com',
						name: 'Admin',
						role: 'admin'
					}
				})
			});
		});

		await page.goto('/login');
		await page.fill('[placeholder="Enter your API key"]', 'valid-api-key');
		await page.click('button[type="submit"]');

		await expect(page).toHaveURL('/');
	});
});

test.describe('Dashboard', () => {
	test.beforeEach(async ({ page }) => {
		// Set auth token in localStorage
		await page.addInitScript(() => {
			localStorage.setItem('catwaf_token', 'test-token');
		});

		// Mock API calls
		await page.route('/api/v2/stats/dashboard', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					totalRequests: 1000000,
					blockedAttacks: 5000,
					activeSites: 10,
					bannedIPs: 500
				})
			});
		});
	});

	test('should display dashboard stats', async ({ page }) => {
		await page.goto('/');

		await expect(page.getByText('Total Requests')).toBeVisible();
		await expect(page.getByText('Blocked Attacks')).toBeVisible();
		await expect(page.getByText('Active Sites')).toBeVisible();
		await expect(page.getByText('Banned IPs')).toBeVisible();
	});

	test('should navigate to sites page', async ({ page }) => {
		await page.goto('/');
		
		await page.click('a[href="/sites"]');
		
		await expect(page).toHaveURL('/sites');
	});
});

test.describe('Sites Management', () => {
	test.beforeEach(async ({ page }) => {
		await page.addInitScript(() => {
			localStorage.setItem('catwaf_token', 'test-token');
		});
	});

	test('should display sites list', async ({ page }) => {
		await page.route('/api/v2/sites*', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					sites: [
						{ id: '1', domain: 'example.com', enabled: true },
						{ id: '2', domain: 'test.com', enabled: false }
					],
					total: 2,
					page: 1,
					limit: 20,
					totalPages: 1
				})
			});
		});

		await page.goto('/sites');

		await expect(page.getByText('example.com')).toBeVisible();
		await expect(page.getByText('test.com')).toBeVisible();
	});
});

test.describe('Responsive Design', () => {
	test('should show mobile navigation on small screens', async ({ page }) => {
		await page.addInitScript(() => {
			localStorage.setItem('catwaf_token', 'test-token');
		});

		await page.setViewportSize({ width: 375, height: 667 });
		await page.goto('/');

		// Check that sidebar is hidden on mobile
		const sidebar = page.locator('aside');
		await expect(sidebar).toHaveCSS('position', 'fixed');
	});
});

test.describe('Accessibility', () => {
	test('should have proper heading hierarchy', async ({ page }) => {
		await page.goto('/login');

		const h1 = await page.locator('h1').count();
		expect(h1).toBeGreaterThan(0);
	});

	test('should have proper form labels', async ({ page }) => {
		await page.goto('/login');

		const label = page.locator('label[for="apiKey"]');
		await expect(label).toBeVisible();
	});
});
