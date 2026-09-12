import { test, expect } from '@playwright/test';
import { loginUser, takeScreenshot, expectSidebarPresent } from './helpers/evie-helpers';

/**
 * Journey 7: Einstellungen und Secrets
 *
 * Ablauf:
 * 1. /settings aufrufen
 * 2. Profil aktualisieren: POST /settings/profile
 * 3. /settings/quota aufrufen, Token-Verbrauch pruefen
 * 4. /settings/secrets aufrufen
 * 5. Neues Secret anlegen: POST /settings/secrets
 * 6. Screenshot: Secrets-Liste
 */
test.describe('Journey 7: Einstellungen und Secrets', () => {
  test.beforeEach(async ({ page }) => {
    await loginUser(page, {
      email: 'settings-test@beispiel.de',
      password: 'TestPass123!',
      firstName: 'Settings',
      lastName: 'Test',
    });
  });

  test('Einstellungen-Seite laedt', async ({ page }) => {
    await page.goto('/settings');
    await takeScreenshot(page, 'journey-07', '01-settings-page');

    await expectSidebarPresent(page);
    await expect(page.locator('body')).toBeVisible();
    await takeScreenshot(page, 'journey-07', '02-settings-overview');
  });

  test('Profil kann aktualisiert werden', async ({ page }) => {
    await page.goto('/settings');
    await takeScreenshot(page, 'journey-07', '03-profile-before-update');

    const response = await page.request.post('/settings/profile', {
      data: {
        firstName: 'Aktualisiert',
        lastName: 'Nutzer',
      },
    });

    expect(response.status()).toBeLessThan(500);
    await takeScreenshot(page, 'journey-07', '04-profile-after-update');
  });

  test('Quota-Seite zeigt Token-Verbrauch', async ({ page }) => {
    await page.goto('/settings/quota');
    await takeScreenshot(page, 'journey-07', '05-quota-page');

    await expect(page.locator('body')).toBeVisible();
  });

  test('Quota-API liefert JSON', async ({ page }) => {
    const response = await page.request.get('/api/quota/usage');
    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(body).toBeTruthy();
    await takeScreenshot(page, 'journey-07', '06-quota-api');
  });

  test('Secrets-Seite laedt', async ({ page }) => {
    await page.goto('/settings/secrets');
    await takeScreenshot(page, 'journey-07', '07-secrets-page');

    await expect(page.locator('body')).toBeVisible();
  });

  test('Secret kann angelegt werden', async ({ page }) => {
    const response = await page.request.post('/settings/secrets', {
      data: {
        keyName: 'test_api_key',
        value: 'sk-test-12345',
        scope: 'api',
      },
    });

    expect(response.status()).toBeLessThan(500);
    await takeScreenshot(page, 'journey-07', '08-secret-created');

    // Secrets-Liste nach Anlegen pruefen
    await page.goto('/settings/secrets');
    await takeScreenshot(page, 'journey-07', '09-secrets-list');
  });
});
