import { test, expect } from '@playwright/test';
import { adminLogin, seed } from './helpers.js';

test.use({ viewport: { width: 1360, height: 900 }, isMobile: false, hasTouch: false });

test('accountant records a partial payment and then the rest', async ({ page }) => {
  const { pendingVs } = seed();
  await adminLogin(page);
  const row = page.locator('tr', { hasText: pendingVs });

  await row.locator('input[name=received]').fill('200');
  await row.getByRole('button', { name: 'Zaplaceno' }).click();
  await expect(page.locator('.flash')).toContainText('Přijato 200 Kč, zbývá doplatit 400 Kč.');
  await expect(row).toContainText('zbývá doplatit 400 Kč');
  await expect(row.locator('input[name=received]')).toHaveValue('400');

  await row.getByRole('button', { name: 'Zaplaceno' }).click();
  await expect(page.locator('.flash')).toContainText('Přijato 400 Kč – zaplaceno.');
  await expect(row.locator('.badge')).toHaveText('Zaplaceno');
});

test('admin gives a VIP guest seats on the plan; they become taken on the website', async ({ page, request }) => {
  await adminLogin(page);
  await page.goto('http://127.0.0.1:8000/api/admin.php?view=vip&run=1');
  await expect(page.locator('.vip-seat:has(input[value="MR-5-1"])')).toHaveClass(/is-taken/);

  await page.fill('.vip-add input[name=name]', 'Paní Testová');
  await page.locator('.vip-seat:has(input[value="WR-6-5"])').click();
  await page.locator('.vip-seat:has(input[value="WR-6-6"])').click();
  await expect(page.locator('#vip-count')).toHaveText('2');
  await page.getByRole('button', { name: 'Přidat VIP' }).click();
  await expect(page.locator('.flash')).toContainText('VIP host Paní Testová přidán (2 místa).');
  await expect(page.locator('.vip-seat:has(input[value="WR-6-5"])')).toHaveClass(/is-vip/);

  const map = await (await request.get('http://127.0.0.1:8000/api/seats.php?run=1')).json();
  expect(map.taken).toEqual(expect.arrayContaining(['WR-6-5', 'WR-6-6']));
});

test('admin invites an accountant, who sets a password and signs in with the e-mail', async ({ page, browser }) => {
  await adminLogin(page);
  await page.goto('http://127.0.0.1:8000/api/admin.php?view=users');
  await page.fill('input[name=name]', 'Jana Účetní');
  await page.fill('input[name=email]', 'jana.ucetni@example.cz');
  await page.getByRole('button', { name: 'Poslat pozvánku' }).click();
  await expect(page.locator('.flash')).toContainText('Pozvánka pro jana.ucetni@example.cz vytvořena.');
  const link = await page.locator('.invite-link').inputValue();
  await expect(page.locator('tr', { hasText: 'jana.ucetni@example.cz' })).toContainText('Čeká na heslo');

  // The invitee, on another computer.
  const other = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const jana = await other.newPage();
  await jana.goto(link);
  await expect(jana.locator('.login')).toContainText('jana.ucetni@example.cz');
  await jana.fill('input[name=password]', 'janino-heslo-1');
  await jana.fill('input[name=password_again]', 'janino-heslo-2');
  await jana.getByRole('button', { name: 'Uložit heslo a přihlásit' }).click();
  await expect(jana.locator('.error')).toHaveText('Hesla se neshodují.');
  await jana.fill('input[name=password]', 'janino-heslo-1');
  await jana.fill('input[name=password_again]', 'janino-heslo-1');
  await jana.getByRole('button', { name: 'Uložit heslo a přihlásit' }).click();
  await expect(jana.locator('.flash')).toContainText('Vítejte, Jana Účetní.');
  await expect(jana.locator('.me')).toContainText('Jana Účetní');

  await jana.getByRole('button', { name: 'Odhlásit' }).click();
  await jana.fill('input[name=email]', 'jana.ucetni@example.cz');
  await jana.fill('input[name=password]', 'janino-heslo-1');
  await jana.getByRole('button', { name: 'Přihlásit' }).click();
  await expect(jana.locator('table')).toBeVisible();

  // The used link does not work again.
  await jana.goto(link);
  await expect(jana.locator('.error')).toHaveText('Pozvánka neplatí nebo vypršela. Požádejte o novou.');

  // Disabled by the admin: signed out at the next page load.
  await page.goto('http://127.0.0.1:8000/api/admin.php?view=users');
  page.once('dialog', (d) => d.accept());
  await page.locator('tr', { hasText: 'jana.ucetni@example.cz' }).getByRole('button', { name: 'Vypnout' }).click();
  await expect(page.locator('.flash')).toContainText('vypnut');
  await jana.goto('http://127.0.0.1:8000/api/admin.php');
  await expect(jana.locator('input[name=email]')).toBeVisible();
  await other.close();
});
