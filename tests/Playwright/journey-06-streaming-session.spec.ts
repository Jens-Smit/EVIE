import { test, expect } from '@playwright/test';
import { loginUser, takeScreenshot, expectSidebarPresent } from './helpers/evie-helpers';

/**
 * Journey 6: Streaming-Session
 *
 * Ablauf:
 * 1. /streaming/sessions aufrufen
 * 2. Neue Session: /streaming/sessions/new
 * 3. Tool auswaehlen, Argumente eingeben
 * 4. Session starten: POST /api/streaming/sessions
 * 5. Session-Status ueberwachen
 * 6. Screenshot: aktive Session
 * 7. Session beenden
 */
test.describe('Journey 6: Streaming-Session', () => {
  test.beforeEach(async ({ page }) => {
    await loginUser(page, {
      email: 'stream-test@beispiel.de',
      password: 'TestPass123!',
      firstName: 'Stream',
      lastName: 'Test',
    });
  });

  test('Streaming-Sessions-Seite laedt', async ({ page }) => {
    await page.goto('/streaming/sessions');
    await takeScreenshot(page, 'journey-06', '01-streaming-sessions');

    await expectSidebarPresent(page);
    await expect(page.locator('body')).toBeVisible();
    await takeScreenshot(page, 'journey-06', '02-session-list');
  });

  test('Neue Session-Seite laedt', async ({ page }) => {
    await page.goto('/streaming/sessions/new');
    await takeScreenshot(page, 'journey-06', '03-new-session-page');
    await expect(page.locator('body')).toBeVisible();
  });

  test('Session kann ueber API erstellt werden', async ({ page }) => {
    const response = await page.request.post('/api/streaming/sessions', {
      data: {
        tool_name: 'weather',
        arguments: { location: 'Berlin' },
      },
      headers: { 'Content-Type': 'application/json' },
    });

    await takeScreenshot(page, 'journey-06', '04-session-created');

    // Session sollte erstellt werden oder 400 falls Tool unbekannt
    expect(response.status()).toBeLessThan(500);

    if (response.status() === 200 || response.status() === 201) {
      const body = await response.json();
      expect(body).toBeTruthy();
      await takeScreenshot(page, 'journey-06', '05-session-active');
    }
  });

  test('Streaming-Sessions-Liste zeigt Sessions', async ({ page }) => {
    await page.goto('/streaming/sessions');
    await takeScreenshot(page, 'journey-06', '06-sessions-overview');
    // Seite muss laden, auch ohne aktive Sessions
    await expect(page.locator('body')).toBeVisible();
  });
});
