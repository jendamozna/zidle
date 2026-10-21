import { readFileSync } from 'node:fs';
import { ENV } from '../../playwright.config.js';

export const seed = () => JSON.parse(readFileSync(new URL('./.seed.json', import.meta.url), 'utf8'));
export { ENV };

/** Seeded accounts (tests/e2e/seed.php). */
export const ADMIN = { email: 'spravce@example.cz', password: 'e2e-spravce-heslo' };
export const SCANNER = { email: 'vchod@example.cz', password: 'e2e-vchod-heslo' };

export async function adminLogin(page) {
  await page.goto('http://127.0.0.1:8000/api/admin.php');
  await page.fill('input[name=email]', ADMIN.email);
  await page.fill('input[name=password]', ADMIN.password);
  await page.click('button:has-text("Přihlásit")');
  await page.waitForSelector('table');
}
