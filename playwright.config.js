// End-to-end tests: the built apps (vite preview) against the PHP API and a MariaDB test database.
//   npm run test:e2e   (needs php, a database named *_test and the env below; see docs/DEVELOPER.md)
import { defineConfig, devices } from '@playwright/test';

export const ENV = {
  DB_NAME: 'zidle_test',
  PUBLIC_URL: 'http://127.0.0.1:4173', // links from the admin (scanner, invitations) go to the built apps
  BANK_ACCOUNT: '19-2000145399/0800',
  TICKET_SECRET: 'e2e-ticket-secret-0123456789-abcdefgh',
  MAIL_ENABLED: '0',
  LOGIN_ATTEMPTS_PER_15_MIN: '100', // every test signs in from the same IP
  ...process.env,
};

export default defineConfig({
  testDir: 'tests/e2e',
  globalSetup: './tests/e2e/global-setup.js',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: !!process.env.CI,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: 'http://127.0.0.1:4173',
    locale: 'cs-CZ',
    timezoneId: 'Europe/Prague',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    ...(process.env.PLAYWRIGHT_CHROMIUM ? { launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM } } : {}),
  },
  projects: [{ name: 'mobile', use: { ...devices['Pixel 7'] } }],
  webServer: [
    {
      command: 'php -S 127.0.0.1:8000 -t .',
      url: 'http://127.0.0.1:8000/index.html', // static file: the database is seeded only after the servers start
      env: ENV,
      reuseExistingServer: false,
      stderr: process.env.CI ? 'pipe' : 'ignore', // php -S logs every request to stderr
    },
    {
      command: 'npm run build && npx vite preview --host 127.0.0.1 --port 4173 --strictPort',
      url: 'http://127.0.0.1:4173',
      reuseExistingServer: false,
      timeout: 120_000,
    },
  ],
});
