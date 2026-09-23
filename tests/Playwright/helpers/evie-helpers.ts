import { Page, expect } from '@playwright/test';

/**
 * EVIE Playwright-Hilfsfunktionen.
 *
 * Diese Funktionen kapseln wiederkehrende Aktionen wie Login, Screenshot
 * und Formular-Ausfuellung, damit die Journey-Skripte lesbar bleiben.
 */

const TEST_USER = {
  email: process.env.EVIE_TEST_EMAIL || 'test@beispiel.de',
  password: process.env.EVIE_TEST_PASSWORD || 'TestPass123!',
  firstName: 'Test',
  lastName: 'Nutzer',
};

/**
 * Registriert einen neuen Nutzer. Gibt true zurueck bei Erfolg (Redirect zum Login).
 * Bei Duplikat-E-Mail wird false zurueckgegeben.
 */
export async function registerUser(page: Page, user = TEST_USER): Promise<boolean> {
  await page.goto('/register');
  await expect(page.locator('h2')).toContainText('Konto erstellen');

  await page.fill('input[name="registration_form[firstName]"]', user.firstName);
  await page.fill('input[name="registration_form[lastName]"]', user.lastName);
  await page.fill('input[name="registration_form[email]"]', user.email);
  await page.fill('input[name="registration_form[plainPassword]"]', user.password);
  await page.check('input[name="registration_form[agreeTerms]"]');

  await page.click('button[type="submit"], input[type="submit"]');

  try {
    await page.waitForURL('**/login', { timeout: 5000 });
    return true;
  } catch {
    return false;
  }
}

/**
 * Loggt einen Nutzer ein und wartet auf das Dashboard.
 */
export async function loginUser(page: Page, user = TEST_USER): Promise<void> {
  await page.goto('/login');
  await page.fill('#inputEmail', user.email);
  await page.fill('#inputPassword', user.password);
  await page.click('button[type="submit"], input[type="submit"]');

  await page.waitForURL(/\/(dashboard|onboarding)/, { timeout: 10000 });

  if (page.url().includes('/onboarding')) {
    await page.goto('/dashboard');
  }

  await expect(page).toHaveURL(/\/dashboard/);
}

/**
 * Nimmt einen Screenshot auf.
 */
export async function takeScreenshot(page: Page, journey: string, step: string): Promise<void> {
  const path = 'screenshots/' + journey + '/' + step + '.png';
  await page.screenshot({ path, fullPage: true });
}

/**
 * Prueft, ob die Sidebar vorhanden und sichtbar ist.
 */
export async function expectSidebarPresent(page: Page): Promise<void> {
  const sidebar = page.locator('#sidebar, aside#sidebar');
  await expect(sidebar).toBeVisible();
  await expect(page.locator('#nav-menu')).toBeVisible();
}

/**
 * Klickt einen Sidebar-Navigationslink anhand des Textes.
 */
export async function clickSidebarLink(page: Page, linkText: string): Promise<void> {
  await page.locator('#nav-menu a', { hasText: linkText }).first().click();
}

export { TEST_USER };
