import { test, expect } from '@playwright/test';
import { registerUser, takeScreenshot } from './helpers/evie-helpers';

/**
 * Journey 1: Registrierung und Login
 *
 * Ablauf:
 * 1. /register aufrufen
 * 2. Formular ausfuellen (Vorname, Nachname, E-Mail, Passwort, AGB)
 * 3. Submit -> Redirect zu /login
 * 4. Login-Daten eingeben
 * 5. Submit -> Redirect zu /dashboard
 * 6. Screenshot: Dashboard
 */
test.describe('Journey 1: Registrierung und Login', () => {
  test('Registrierung leitet zum Login weiter', async ({ page }) => {
    await page.goto('/register');
    await takeScreenshot(page, 'journey-01', '01-register-page');

    await expect(page.locator('h2')).toContainText('Konto erstellen');

    await page.fill('input[name="registration_form[firstName]"]', 'Playwright');
    await page.fill('input[name="registration_form[lastName]"]', 'Test');
    await page.fill('input[name="registration_form[email]"]', 'pw-test@beispiel.de');
    await page.fill('input[name="registration_form[plainPassword]"]', 'SicheresPass123!');
    await page.check('input[name="registration_form[agreeTerms]"]');

    await takeScreenshot(page, 'journey-01', '02-register-filled');

    await page.click('button[type="submit"], input[type="submit"]');

    await page.waitForURL('**/login', { timeout: 10000 });
    await takeScreenshot(page, 'journey-01', '03-redirect-to-login');
    expect(page.url()).toContain('/login');
  });

  test('Login mit gueltigen Credentials leitet zum Dashboard', async ({ page }) => {
    await registerUser(page, {
      email: 'login-test@beispiel.de',
      password: 'TestPass123!',
      firstName: 'Login',
      lastName: 'Test',
    });

    await page.goto('/login');
    await takeScreenshot(page, 'journey-01', '04-login-page');

    await page.fill('#inputEmail', 'login-test@beispiel.de');
    await page.fill('#inputPassword', 'TestPass123!');
    await takeScreenshot(page, 'journey-01', '05-login-filled');

    await page.click('button[type="submit"], input[type="submit"]');

    await page.waitForURL(/\/(dashboard|onboarding)/, { timeout: 10000 });
    await takeScreenshot(page, 'journey-01', '06-dashboard-after-login');
  });

  test('Login mit falschem Passwort bleibt auf Login-Seite', async ({ page }) => {
    await page.goto('/login');
    await page.fill('#inputEmail', 'login-test@beispiel.de');
    await page.fill('#inputPassword', 'FalschesPasswort!');
    await page.click('button[type="submit"], input[type="submit"]');

    await expect(page.locator('.text-red-700, .text-red-400, [class*="red"]')).toBeVisible({ timeout: 5000 });
    await takeScreenshot(page, 'journey-01', '07-login-error');
    expect(page.url()).toContain('/login');
  });

  test('Anonymer Zugriff auf Dashboard leitet zum Login', async ({ page }) => {
    await page.goto('/dashboard');
    await page.waitForURL('**/login', { timeout: 5000 });
    await takeScreenshot(page, 'journey-01', '08-anonymous-redirect');
    expect(page.url()).toContain('/login');
  });
});
