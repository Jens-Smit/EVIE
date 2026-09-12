import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright-Konfiguration fuer EVIE-User-Journeys.
 *
 * Jede Journey simuliert einen echten Nutzer, der das Frontend bedient.
 * Screenshots werden bei Fehlern und an expliziten Checkpunkten aufgenommen.
 *
 * Basis-URL: EVIE_BASE_URL (Default: http://localhost:8000)
 */
export default defineConfig({
  testDir: './tests/Playwright',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  reporter: [
    ['html', { outputFolder: 'playwright-report' }],
    ['list'],
  ],
  use: {
    baseURL: process.env.EVIE_BASE_URL || 'http://localhost:8000',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    locale: 'de-DE',
    timezone: 'Europe/Berlin',
    actionTimeout: 15000,
    navigationTimeout: 30000,
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  outputDir: 'tests/Playwright/test-results',
  snapshotPathTemplate: '{testDir}/screenshots/{testFilePath}/{arg}{ext}',
});
