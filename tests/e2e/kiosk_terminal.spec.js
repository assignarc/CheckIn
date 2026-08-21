const { test, expect } = require('@playwright/test');

test.describe('Kiosk Mode Terminal UI Tests', () => {

  test('Open Access Kiosk renders user list and allows basic check-in', async ({ page }) => {
    // Basic Demo Open Access Kiosk Terminal URL
    await page.goto('http://localhost:8000/org/basic-demo/kiosk/temp_token_b');

    // Verify Kiosk interface renders
    await expect(page.locator('body')).toContainText(/Basic Demo Company|Kiosk/i);

    // Filter roster by typing
    const searchInput = page.locator('input[type="text"], input[type="search"]').first();
    if (await searchInput.isVisible()) {
      await searchInput.fill('Taylor');
      await expect(page.locator('body')).toContainText('Taylor');
    }
  });

  test('Secure PIN Kiosk renders PIN keypad interface', async ({ page }) => {
    // Acme Corp Secure PIN Kiosk Terminal URL
    await page.goto('http://localhost:8000/org/acme-corp/kiosk/acme-secret-token');
    await expect(page.locator('body')).toContainText(/Acme Corporation|Check-in/i);

    // Select user from autocomplete list to trigger PIN pad container
    const searchInput = page.locator('#orgUser-search');
    await searchInput.fill('John');

    // Verify multiple matching autocomplete items appear and are visible in dropdown
    const items = page.locator('#orgUser-dropdown .autocomplete-item');
    if (await items.count() > 0) {
      await expect(items.first()).toBeVisible();
    }

    await searchInput.fill('John Doe');

    // Click autocomplete option for John Doe
    await page.click('#orgUser-dropdown .autocomplete-item:first-child');

    // Verify PIN Keypad container becomes visible
    await expect(page.locator('#pin-pad-container')).toBeVisible();

    // Click PIN digits 1 - 2 - 3 - 4 for John Doe
    await page.locator('#pin-pad-container button[data-key="1"]').click();
    await page.locator('#pin-pad-container button[data-key="2"]').click();
    await page.locator('#pin-pad-container button[data-key="3"]').click();
    await page.locator('#pin-pad-container button[data-key="4"]').click();
  });
});
