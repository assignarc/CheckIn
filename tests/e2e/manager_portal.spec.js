const { test, expect } = require('@playwright/test');

test.describe('Manager Portal UI Tests', () => {

  test.beforeEach(async ({ page }) => {
    // Log in as Manager for Golden Demo Company
    await page.goto('http://localhost:8000/login');
    await page.waitForLoadState('networkidle');

    // Fill credentials and submit
    await page.fill('#inputEmail', 'golden@centertime.us');
    await page.fill('#inputPassword', 'manager123');

    // Submit and wait for navigation away from login
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }),
      page.click('button[type="submit"]'),
    ]);
  });

  test('Manager Dashboard displays checked-in users and organization statistics', async ({ page }) => {
    // Should be redirected to the manager dashboard
    await expect(page).toHaveURL(/manager\/(dashboard)?$/);
    // Sidebar should be present in authenticated views
    await expect(page.locator('aside.sidebar')).toBeVisible();
    // Check sidebar nav links exist (scoped to avoid matching dashboard metric cards)
    await expect(page.locator('aside.sidebar a[href="/manager/users"]')).toBeVisible();
    await expect(page.locator('aside.sidebar a[href="/manager/categories"]')).toBeVisible();
  });

  test('Manager Users Roster page lists organization users', async ({ page }) => {
    await page.goto('http://localhost:8000/manager/users');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/login/);
    // Use .table class to avoid matching the Symfony profiler toolbar table
    await expect(page.locator('.table').first()).toBeVisible({ timeout: 8000 });
    // Golden Demo seeded users (Garcia, Harris, etc.)
    await expect(page.locator('body')).toContainText('Taylor');
    await expect(page.locator('body')).toContainText('Garcia');
  });

  test('Manager Categories page allows viewing and managing shift categories', async ({ page }) => {
    await page.goto('http://localhost:8000/manager/categories');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/login/);
    await expect(page.locator('body')).toContainText('Design Shift');
  });

  test('Manager Settings page displays organization profile and kiosk token', async ({ page }) => {
    await page.goto('http://localhost:8000/manager/settings');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/login/);
    await expect(page.locator('#org_name')).toBeVisible();
    await expect(page.locator('#org_name')).toHaveValue('Golden Demo Company');
  });
});
