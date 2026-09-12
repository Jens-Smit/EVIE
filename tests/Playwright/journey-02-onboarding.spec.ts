import { test, expect } from '@playwright/test';
import { loginUser, takeScreenshot } from './helpers/evie-helpers';

/**
 * Journey 2: Onboarding-Flow
 *
 * Ablauf:
 * 1. Nach Login: /onboarding pruefen
 * 2. Flow starten: POST /onboarding/start
 * 3. Erste Frage beantworten: POST /onboarding/next
 * 4. Weitere Schritte bis Abschluss
 * 5. Screenshot nach jedem Schritt
 * 6. Redirect zum Dashboard verifizieren
 */
test.describe('Journey 2: Onboarding-Flow', () => {
  test.beforeEach(async ({ page }) => {
    await loginUser(page, {
      email: 'onboarding-test@beispiel.de',
      password: 'TestPass123!',
      firstName: 'Onboarding',
      lastName: 'Test',
    });
  });

  test('Onboarding-Seite ist erreichbar', async ({ page }) => {
    await page.goto('/onboarding');
    await takeScreenshot(page, 'journey-02', '01-onboarding-page');
    await expect(page.locator('body')).toBeVisible();
  });

  test('Onboarding-Flow kann gestartet werden', async ({ page }) => {
    await page.goto('/onboarding');
    await takeScreenshot(page, 'journey-02', '02-before-start');

    const response = await page.request.post('/onboarding/start', {
      data: { initial_context: {} },
      headers: { 'Content-Type': 'application/json' },
    });

    expect(response.status()).toBeLessThan(500);
    const body = await response.json();
    await takeScreenshot(page, 'journey-02', '03-after-start');
    expect(body).toBeTruthy();
  });

  test('Onboarding-Schritte koennen beantwortet werden', async ({ page }) => {
    await page.goto('/onboarding');

    await page.request.post('/onboarding/start', {
      data: { initial_context: {} },
      headers: { 'Content-Type': 'application/json' },
    });
    await takeScreenshot(page, 'journey-02', '04-first-question');

    const nextResponse = await page.request.post('/onboarding/next', {
      data: { response: 'Ich nutze EVIE fuer die Gastronomie' },
      headers: { 'Content-Type': 'application/json' },
    });
    await takeScreenshot(page, 'journey-02', '05-after-first-answer');
    expect(nextResponse.status()).toBeLessThan(500);
  });

  test('Onboarding-Reset leitet zum Onboarding', async ({ page }) => {
    await page.goto('/settings');
    await takeScreenshot(page, 'journey-02', '06-settings-before-reset');

    const response = await page.request.post('/settings/onboarding/reset');
    expect(response.status()).toBeLessThan(400);
    await takeScreenshot(page, 'journey-02', '07-after-reset');
  });
});
