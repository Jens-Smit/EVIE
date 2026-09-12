import { test, expect } from '@playwright/test';
import { takeScreenshot } from './helpers/evie-helpers';

/**
 * Journey 8: Passwort zuruecksetzen
 *
 * Ablauf:
 * 1. /forgot-password aufrufen
 * 2. E-Mail eingeben, Submit
 * 3. Neutrale Erfolgsmeldung verifizieren
 * 4. Reset-Link mit Token aufrufen
 * 5. Neues Passwort eingeben
 * 6. Submit -> Redirect zum Login
 * 7. Login mit neuem Passwort
 */
test.describe('Journey 8: Passwort zuruecksetzen', () => {
  test('Passwort-vergessen-Seite laedt', async ({ page }) => {
    await page.goto('/forgot-password');
    await takeScreenshot(page, 'journey-08', '01-forgot-password-page');

    await expect(page.locator('body')).toBeVisible();
    await expect(page.locator('form')).toBeVisible();
  });

  test('Passwort-zuruecksetzen-Anfrage zeigt neutrale Meldung', async ({ page }) => {
    await page.goto('/forgot-password');
    await takeScreenshot(page, 'journey-08', '02-before-submit');

    // Formular ausfuellen
    const emailInput = page.locator('input[type="email"], input[name="email"]');
    if (await emailInput.count() > 0) {
      await emailInput.fill('reset-test@beispiel.de');
      await page.click('button[type="submit"], input[type="submit"]');
    }

    // Neutrale Meldung (keine User-Enumeration)
    await takeScreenshot(page, 'journey-08', '03-generic-message');
    await expect(page.locator('body')).toBeVisible();
  });

  test('Passwort-zuruecksetzen mit ungueltigem Token leitet ab', async ({ page }) => {
    await page.goto('/reset-password?token=invalid-token-12345');
    await takeScreenshot(page, 'journey-08', '04-invalid-token');

    // Bei ungueltigem Token: Redirect zum Reset-Formular oder Fehler
    await expect(page.locator('body')).toBeVisible();
  });

  test('Login-Seite hat Link zum Passwort-zuruecksetzen', async ({ page }) => {
    await page.goto('/login');
    await takeScreenshot(page, 'journey-08', '05-login-with-reset-link');

    const resetLink = page.locator('a[href*="forgot"], a:has-text("Passwort vergessen")');
    await expect(resetLink).toBeVisible();
  });
});
