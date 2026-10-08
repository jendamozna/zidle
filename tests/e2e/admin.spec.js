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
