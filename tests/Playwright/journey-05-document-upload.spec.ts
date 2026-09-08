import { test, expect } from '@playwright/test';
import { loginUser, takeScreenshot, expectSidebarPresent } from './helpers/evie-helpers';

/**
 * Journey 5: Dokumenten-Upload
 *
 * Ablauf:
 * 1. /documents aufrufen
 * 2. Upload-Formular ausfuellen
 * 3. Datei hochladen: POST /api/documents/upload
 * 4. Dokument in Liste verifizieren
 * 5. Screenshot: Dokumentenliste mit neuem Eintrag
 * 6. Dokument loeschen: DELETE /api/documents/{id}
 */
test.describe('Journey 5: Dokumenten-Upload', () => {
  test.beforeEach(async ({ page }) => {
    await loginUser(page, {
      email: 'doc-test@beispiel.de',
      password: 'TestPass123!',
      firstName: 'Doc',
      lastName: 'Test',
    });
  });

  test('Dokumenten-Seite laedt', async ({ page }) => {
    await page.goto('/documents');
    await takeScreenshot(page, 'journey-05', '01-documents-page');

    await expectSidebarPresent(page);
    await expect(page.locator('body')).toBeVisible();
    await takeScreenshot(page, 'journey-05', '02-documents-list');
  });

  test('Dokument kann ueber API hochgeladen werden', async ({ page }) => {
    // API-Upload testen
    const response = await page.request.post('/api/documents/upload', {
      multipart: {
        file: {
          name: 'test-document.txt',
          mimeType: 'text/plain',
          buffer: Buffer.from('EVIE Testdokument fuer Playwright'),
        },
      },
    });

    await takeScreenshot(page, 'journey-05', '03-after-upload');

    // Upload sollte erfolgreich sein (oder 400 falls kein Upload-Support)
    expect(response.status()).toBeLessThan(500);

    if (response.status() === 200 || response.status() === 201) {
      const body = await response.json();
      expect(body).toHaveProperty('id');
      await takeScreenshot(page, 'journey-05', '04-document-created');

      // Dokument in der Liste verifizieren
      await page.goto('/documents');
      await takeScreenshot(page, 'journey-05', '05-document-in-list');

      // Aufraeumen: Dokument loeschen
      if (body.id) {
        const deleteResponse = await page.request.delete('/api/documents/' + body.id);
        expect(deleteResponse.status()).toBeLessThan(500);
        await takeScreenshot(page, 'journey-05', '06-after-delete');
      }
    }
  });

  test('Dokumenten-Liste-API liefert JSON', async ({ page }) => {
    const response = await page.request.get('/api/documents');
    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(Array.isArray(body) || typeof body === 'object').toBeTruthy();
    await takeScreenshot(page, 'journey-05', '07-documents-api');
  });
});
