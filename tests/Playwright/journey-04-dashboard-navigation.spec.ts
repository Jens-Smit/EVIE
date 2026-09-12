import { test, expect } from '@playwright/test';
import { loginUser, takeScreenshot, expectSidebarPresent, clickSidebarLink } from './helpers/evie-helpers';

/**
 * Journey 4: Dashboard-Navigation (alle Sidebar-Seiten)
 *
 * Ablauf:
 * 1. /dashboard aufrufen
 * 2. Jeden Sidebar-Link durchklicken:
 *    Dashboard, Agent Chat, Sub-Agenten, Streaming, Freigaben, Dokumente
 * 3. Pro Seite HTTP 200 verifizieren
 * 4. Pro Seite Screenshot aufnehmen
 * 5. Sidebar-Active-State pruefen
 */
test.describe('Journey 4: Dashboard-Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await loginUser(page, {
      email: 'nav-test@beispiel.de',
      password: 'TestPass123!',
      firstName: 'Nav',
      lastName: 'Test',
    });
  });

  test('Dashboard laedt mit Statistiken', async ({ page }) => {
    await page.goto('/dashboard');
    await takeScreenshot(page, 'journey-04', '01-dashboard');

    await expect(page.locator('h1')).toContainText('Dashboard');
    await expectSidebarPresent(page);
    await takeScreenshot(page, 'journey-04', '02-dashboard-stats');
  });

  test('Agent-Chat ueber Sidebar erreichbar', async ({ page }) => {
    await page.goto('/dashboard');
    await clickSidebarLink(page, 'Agent Chat');
    await page.waitForLoadState('networkidle');
    await takeScreenshot(page, 'journey-04', '03-agent-chat');
    expect(page.url()).toContain('/dialog');
  });

  test('Sub-Agenten ueber Sidebar erreichbar', async ({ page }) => {
    await page.goto('/dashboard');
    await clickSidebarLink(page, 'Sub-Agenten');
    await page.waitForLoadState('networkidle');
    await takeScreenshot(page, 'journey-04', '04-subagents');
    expect(page.url()).toContain('/subagents');
  });

  test('Freigaben ueber Sidebar erreichbar', async ({ page }) => {
    await page.goto('/dashboard');
    await clickSidebarLink(page, 'Freigaben');
    await page.waitForLoadState('networkidle');
    await takeScreenshot(page, 'journey-04', '05-tools-pending');
    expect(page.url()).toContain('/tools');
  });

  test('Dokumente ueber Sidebar erreichbar', async ({ page }) => {
    await page.goto('/dashboard');
    await clickSidebarLink(page, 'Dokumente');
    await page.waitForLoadState('networkidle');
    await takeScreenshot(page, 'journey-04', '06-documents');
    expect(page.url()).toContain('/documents');
  });

  test('Streaming ueber Sidebar erreichbar', async ({ page }) => {
    await page.goto('/dashboard');
    await clickSidebarLink(page, 'Streaming');
    await page.waitForLoadState('networkidle');
    await takeScreenshot(page, 'journey-04', '07-streaming');
    expect(page.url()).toContain('/streaming');
  });

  test('Verlauf-Seite erreichbar', async ({ page }) => {
    await page.goto('/history');
    await takeScreenshot(page, 'journey-04', '08-history');
    expect(page.url()).toContain('/history');
  });

  test('Jeder Sidebar-Link fuehrt zu ladbarer Seite', async ({ page }) => {
    await page.goto('/dashboard');

    const links = await page.locator('#nav-menu a').all();
    expect(links.length).toBeGreaterThanOrEqual(5);

    for (const link of links) {
      const href = await link.getAttribute('href');
      if (href && href !== '#' && !href.startsWith('http')) {
        const response = await page.request.get(href);
        expect(response.status(), 'Sidebar-Link ' + href + ' sollte HTTP 200 liefern').toBe(200);
      }
    }
    await takeScreenshot(page, 'journey-04', '09-all-links-ok');
  });
});
