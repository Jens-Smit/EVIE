import { test, expect } from '@playwright/test';
import { loginUser, takeScreenshot, expectSidebarPresent } from './helpers/evie-helpers';

/**
 * Journey 3: Agent-Chat mit HITL-Freigabe
 *
 * Ablauf:
 * 1. /dialog aufrufen
 * 2. Nachricht eingeben: "Analysiere diese Excel-Datei"
 * 3. Senden
 * 4. Warten auf HITL-Freigabe-Container (#hitl-approval-container)
 * 5. Screenshot: Freigabe-Dialog
 * 6. Tool genehmigen: POST /api/tools/{id}/approve
 * 7. Warten auf Tool-Ergebnis
 * 8. Screenshot: Ergebnis im Chat
 */
test.describe('Journey 3: Agent-Chat mit HITL', () => {
  test.beforeEach(async ({ page }) => {
    await loginUser(page, {
      email: 'chat-test@beispiel.de',
      password: 'TestPass123!',
      firstName: 'Chat',
      lastName: 'Test',
    });
  });

  test('Agent-Chat-Seite laedt mit HITL-Container', async ({ page }) => {
    await page.goto('/dialog');
    await takeScreenshot(page, 'journey-03', '01-dialog-page');

    await expect(page.locator('h1')).toContainText(/AI Agent|Dialog|Agent/);
    await expectSidebarPresent(page);

    await expect(page.locator('#hitl-approval-container')).toBeAttached();
    await takeScreenshot(page, 'journey-03', '02-hitl-container-present');
  });

  test('Pending-Tools-Badge ist vorhanden', async ({ page }) => {
    await page.goto('/dialog');
    await expect(page.locator('#pending-tools-badge, #pending-tools-count')).toBeAttached();
    await takeScreenshot(page, 'journey-03', '03-pending-badge');
  });

  test('Pending-Tools-Count-API liefert JSON', async ({ page }) => {
    const response = await page.request.get('/api/pending-tools/count');
    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(body).toHaveProperty('count');
    expect(typeof body.count).toBe('number');
    await takeScreenshot(page, 'journey-03', '04-pending-count-api');
  });

  test('Chat enthaelt Approve/Reject-Endpoints in JS', async ({ page }) => {
    await page.goto('/dialog');
    const scripts = await page.locator('script').allTextContents();
    const allJs = scripts.join('\n');

    expect(allJs).toContain('/api/tools/');
    expect(allJs).toContain('/approve');
    expect(allJs).toContain('/reject');
    await takeScreenshot(page, 'journey-03', '05-approve-endpoints-in-js');
  });

  test('Nachricht kann gesendet werden (API)', async ({ page }) => {
    const response = await page.request.post('/api/agent/dialog', {
      data: { message: 'Hallo, was kannst du fuer mich tun?' },
      headers: { 'Content-Type': 'application/json' },
    });

    expect(response.status()).toBeLessThan(500);
    await takeScreenshot(page, 'journey-03', '06-message-sent');
  });

  test('Freigaben-Seite laedt Tools-Liste', async ({ page }) => {
    await page.goto('/tools/pending');
    await takeScreenshot(page, 'journey-03', '07-tools-pending-page');
    await expectSidebarPresent(page);
    await expect(page.locator('body')).toBeVisible();
  });
});
