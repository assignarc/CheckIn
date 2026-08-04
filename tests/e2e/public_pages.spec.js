const { test, expect } = require('@playwright/test');

test.describe('Public Pages & Navigation E2E UI Tests', () => {

  test('Homepage renders responsive navigation and hero section', async ({ page }) => {
    await page.goto('http://localhost:8000/');
    await expect(page).toHaveTitle(/CenterTime/i);

    // Verify Hero section content
    const heroTitle = page.locator('h1');
    await expect(heroTitle).toContainText('Next-Gen Time Tracking');

    // Verify desktop header navigation links if viewport is wide enough
    if (page.viewportSize() && page.viewportSize().width >= 768) {
      await expect(page.locator('header nav.desktop-nav-links a:has-text("Features")')).toBeVisible();
      await expect(page.locator('header nav.desktop-nav-links a:has-text("Pricing")')).toBeVisible();
      await expect(page.locator('header nav.desktop-nav-links a:has-text("FAQ")')).toBeVisible();
      await expect(page.locator('header nav.desktop-nav-links a:has-text("About Us")')).toBeVisible();
      await expect(page.locator('header nav.desktop-nav-links a:has-text("Contact Us")')).toBeVisible();
    }
  });

  test('Contact Us form displays validation errors for empty/invalid fields', async ({ page }) => {
    await page.goto('http://localhost:8000/contact');
    await expect(page.locator('h1')).toContainText("We'd Love to Hear From You");

    // Click submit without entering values
    await page.click('button[type="submit"]');

    // Verify real-time validation error feedback appears
    await expect(page.locator('#name-error')).toBeVisible();
    await expect(page.locator('#email-error')).toBeVisible();
    await expect(page.locator('#message-error')).toBeVisible();

    // Fill valid data
    await page.fill('#name', 'John Doe');
    await page.fill('#email', 'john.doe@example.com');
    await page.fill('#message', 'This is an automated end-to-end Playwright UI test message.');

    // Errors should clear dynamically
    await expect(page.locator('#name-error')).toBeHidden();
    await expect(page.locator('#email-error')).toBeHidden();
    await expect(page.locator('#message-error')).toBeHidden();
  });

  test('Mobile navigation drawer expands and collapses cleanly', async ({ page }) => {
    // Set viewport to mobile size
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('http://localhost:8000/contact');

    const drawer = page.locator('#mobileNavDrawer');
    await expect(drawer).toBeHidden();

    // Toggle menu open
    await page.click('#mobileNavToggle');
    await expect(drawer).toBeVisible();

    // Verify drawer options include About and Contact
    await expect(drawer.locator('a:has-text("About Us")')).toBeVisible();
    await expect(drawer.locator('a:has-text("Contact Us")')).toBeVisible();

    // Toggle menu close
    await page.click('#mobileNavToggle');
    await expect(drawer).toBeHidden();
  });

  test('Pricing page renders dynamic plan cards and matrix comparison', async ({ page }) => {
    await page.goto('http://localhost:8000/pricing');
    await expect(page.locator('h1')).toContainText('Simple, Transparent Subscription Tiers');

    // Verify comparison plan headers exist
    await expect(page.locator('.plan-col:has-text("Basic")')).toBeVisible();
    await expect(page.locator('.plan-col:has-text("Silver")')).toBeVisible();
    await expect(page.locator('.plan-col:has-text("Gold")')).toBeVisible();
    await expect(page.locator('.plan-col:has-text("Platinum")')).toBeVisible();
  });
});
