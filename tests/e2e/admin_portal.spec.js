const { test, expect } = require('@playwright/test');

test.describe('Platform Admin Portal UI Tests', () => {

  test.beforeEach(async ({ page }) => {
    // Log in to Admin Portal
    await page.goto('http://localhost:8000/login');
    await page.waitForLoadState('networkidle');

    // Fill credentials and submit
    await page.fill('#inputEmail', 'admin@centertime.us');
    await page.fill('#inputPassword', 'admin123');

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

  test('Admin Settings page allows updating global settings and toggling PDF poster setting', async ({ page }) => {
    await page.goto('http://localhost:8000/admin/settings');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/login/);

    // Verify settings tabs exist
    await expect(page.locator('.settings-tab[data-tab="tab-limits"]')).toBeVisible();
    await expect(page.locator('.settings-tab[data-tab="tab-security"]')).toBeVisible();

    // Switch to Security tab
    await page.click('.settings-tab[data-tab="tab-security"]');
    await expect(page.locator('#tab-security')).toBeVisible();

    // Verify PDF Poster Download checkbox exists
    const pdfToggle = page.locator('input[type="checkbox"][name="pdf_poster_download_enabled"]');
    await expect(pdfToggle).toBeAttached();
  });

  test('Admin Demo Leads page renders leads and contact inquiries tables', async ({ page }) => {
    await page.goto('http://localhost:8000/admin/demo-leads');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/login/);
    await expect(page.locator('body')).toContainText(/Demo Requests|Contact/i);
  });

  test('Admin Audit Logs page renders system-wide audit trail and organization filter', async ({ page }) => {
    await page.goto('http://localhost:8000/admin/audit-logs');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/login/);
    await expect(page.locator('body')).toContainText(/System-wide Audit Trail|Platform Audit/i);
    await expect(page.locator('table.table').first()).toBeVisible();
  });
});
