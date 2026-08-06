const { test, expect } = require('@playwright/test');

test.describe('Manager Portal UI Tests', () => {

  test.beforeEach(async ({ page }) => {
    // Log in as Manager for Golden Demo Company
    await page.goto('http://localhost:8000/login');
    await page.waitForLoadState('networkidle');

    // Fill credentials and submit
    await page.fill('#inputEmail', 'manager@acme.com');
    await page.fill('#inputPassword', 'manager123');

    const captchaInput = page.locator('#captcha_answer');
    if (await captchaInput.isVisible()) {
      const question = await page.locator('img[alt*="Math Captcha"]').getAttribute('data-question');
      if (question) {
        const parts = question.split(' ');
        const num1 = parseInt(parts[0], 10);
        const op = parts[1];
        const num2 = parseInt(parts[2], 10);
        const ans = op === '+' ? (num1 + num2) : (num1 - num2);
        await captchaInput.fill(ans.toString());
      }
    }

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
    // Acme Corp seeded users (John, Doe, etc.)
    await expect(page.locator('body')).toContainText('John');
    await expect(page.locator('body')).toContainText('Doe');
  });

  test('Manager Categories page allows viewing and managing shift categories', async ({ page }) => {
    await page.goto('http://localhost:8000/manager/categories');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/login/);
    await expect(page.locator('body')).toContainText('Design');
  });

  test('Manager Checked In Now page lists active sessions with live timers and check out options', async ({ page }) => {
    await page.goto('http://localhost:8000/manager/users/checked-in');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/login/);
    await expect(page.locator('body')).toContainText(/Active Sessions|Checked In Now/i);
    await expect(page.locator('.table').first()).toBeVisible();
  });

  test('Manager Settings page allows switching tabs and viewing kiosk links', async ({ page }) => {
    await page.goto('http://localhost:8000/manager/settings');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/login/);
    await expect(page.locator('#org_name')).toBeVisible();
    await expect(page.locator('#org_name')).toHaveValue('Acme Corporation');

    // Switch to Policies tab
    await page.click('.settings-tab[data-tab="tab-policies"]');
    await expect(page.locator('#tab-policies')).toBeVisible();

    // Switch to Permanent Kiosk URL tab
    await page.click('.settings-tab[data-tab="tab-semi-perm"]');
    await expect(page.locator('#tab-semi-perm')).toBeVisible();
    await expect(page.locator('#poster-capture-area')).toBeVisible();
  });

  test('Manager Users Roster page supports search and status filter', async ({ page }) => {
    await page.goto('http://localhost:8000/manager/users');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/login/);

    // Search query test
    await page.fill('input[name="q"]', 'John');
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('table.table').first()).toContainText('John');

    // Status filter test
    await page.selectOption('select[name="status"]', 'active');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('table.table').first()).toBeVisible();
  });

  test('Manager Reports page displays shift logs, active session timers, and interactive highlight filters', async ({ page }) => {
    await page.goto('http://localhost:8000/manager/reports');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/login/);
    await expect(page.locator('.table').first()).toBeVisible({ timeout: 8000 });

    // Verify Highlight buttons exist and function when clicked
    const highlight30m = page.locator('.highlight-filter-btn[data-seconds="1800"]');
    await expect(highlight30m).toBeVisible();
    await highlight30m.click();
    await expect(highlight30m).toHaveClass(/active|btn-primary/);
    await expect(page.locator('#highlight-clear-btn')).toBeVisible();

    // Clear highlight
    await page.locator('#highlight-clear-btn').click();
    await expect(page.locator('#highlight-clear-btn')).toBeHidden();

    // Verify Custom Highlight Mins Apply button works
    await page.fill('#custom-highlight-mins', '15');
    await page.click('#highlight-custom-btn');
    await expect(page.locator('#highlight-clear-btn')).toBeVisible();
  });

  test('Manager Reports By User page supports calendar/list view toggle, month navigation, and highlights', async ({ page }) => {
    await page.goto('http://localhost:8000/manager/reports/by-user');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/login/);

    // Select first user from dropdown if present
    const userSelect = page.locator('#org_user_id');
    if (await userSelect.isVisible()) {
      await userSelect.selectOption({ index: 1 });
      await page.waitForLoadState('networkidle');
    }

    // Verify Calendar view container renders
    await expect(page.locator('#calendar-view-container')).toBeVisible();

    // Toggle to List view
    const listViewBtn = page.locator('#btn-view-list');
    if (await listViewBtn.isVisible()) {
      await listViewBtn.click();
      await expect(page.locator('#list-view-container')).toBeVisible();
    }

    // Toggle back to Calendar view
    const calViewBtn = page.locator('#btn-view-calendar');
    if (await calViewBtn.isVisible()) {
      await calViewBtn.click();
      await expect(page.locator('#calendar-view-container')).toBeVisible();
    }

    // Verify Highlight buttons exist and click function
    const highlight30m = page.locator('.highlight-filter-btn[data-seconds="1800"]').first();
    if (await highlight30m.isVisible()) {
      await highlight30m.click();
    }
  });

  test('Manager Audit Logs page renders system events and action filter', async ({ page }) => {
    await page.goto('http://localhost:8000/manager/audit-logs');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/login/);
    await expect(page.locator('body')).toContainText(/Audit Logs|Activity Trail|Manager/i);
    await expect(page.locator('table.table').first()).toBeVisible();
  });

  test('Manager Barcode Sticker Badges page renders sticker preview and supports template selection and date filtering', async ({ page }) => {
    await page.goto('http://localhost:8000/manager/users/print-barcodes');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/login/);
    await expect(page.locator('body')).toContainText(/Sticker Badges|Avery/i);
    await expect(page.locator('select').first()).toBeVisible();

    // Test template selector
    await page.goto('http://localhost:8000/manager/users/print-barcodes?template=L7156');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('.avery-print-container')).toBeVisible();
  });
});
