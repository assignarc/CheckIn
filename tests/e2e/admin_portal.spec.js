const { test, expect } = require('@playwright/test');

test.describe('Platform Admin Portal UI Tests', () => {

  test.beforeEach(async ({ page }) => {
    // Log in to Admin Portal
    await page.goto('http://localhost:8000/login');
    await page.waitForLoadState('networkidle');

    // Fill credentials and submit
    await page.fill('#inputEmail', 'admin@centertime.us');
    await page.fill('#inputPassword', 'admin123');

    // Submit and wait for navigation away from login
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }),
      page.click('button[type="submit"]'),
    ]);
  });

  test('Admin Dashboard displays platform metrics and navigation', async ({ page }) => {
    // Should be redirected to the admin dashboard
    await expect(page).toHaveURL(/admin\/(dashboard)?$/);
    await expect(page.locator('body')).toContainText(/Platform Overview|Total Organizations|Active Managers|Organizations|Managers/i);
    await expect(page.locator('a[href="/admin/organizations"]').first()).toBeVisible();
  });

  test('Admin Organizations page lists registered organizations', async ({ page }) => {
    await page.goto('http://localhost:8000/admin/organizations');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/login/);
    await expect(page.locator('body')).toContainText(/Organizations/i);
    await expect(page.locator('body')).toContainText('Acme Corporation');
  });

  test('Admin Managers page lists registered managers', async ({ page }) => {
    await page.goto('http://localhost:8000/admin/managers');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/login/);
    await expect(page.locator('body')).toContainText('manager@acme.com');
  });

  test('Admin Demo Leads page renders leads and contact inquiries tables', async ({ page }) => {
    await page.goto('http://localhost:8000/admin/demo-leads');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/login/);
    await expect(page.locator('body')).toContainText(/Demo Requests|Contact/i);
  });
});
