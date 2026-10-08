import { readFileSync } from 'node:fs';
import { ENV } from '../../playwright.config.js';

export const seed = () => JSON.parse(readFileSync(new URL('./.seed.json', import.meta.url), 'utf8'));
export { ENV };

export async function adminLogin(page) {
  await page.goto('http://127.0.0.1:8000/api/admin.php');
  await page.fill('input[name=password]', ENV.ADMIN_PASSWORD);
  await page.click('button:has-text("Přihlásit")');
  await page.waitForSelector('table');
}
