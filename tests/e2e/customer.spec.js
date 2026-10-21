import { test, expect } from '@playwright/test';
import { adminLogin, seed } from './helpers.js';

/** Opens Premiéra, picks `count` free seats in the first section and opens the reservation form. */
async function pickSeats(page, count) {
  await page.goto('/');
  await page.locator('.run-card', { hasText: 'Premiéra' }).click();
  await page.locator('.plan-hit').first().click();
  const seats = page.locator('button.seat-available');
  for (let i = 0; i < count; i++) await seats.first().click();
  await page.getByRole('button', { name: /Zpět na mapu/ }).click();
  await page.locator('.topbar .btn-primary').click();
  return page.locator('.modal');
}

async function fillCustomer(form) {
  await form.locator('input[name=firstName]').fill('Jana');
  await form.locator('input[name=lastName]').fill('Testová');
  await form.locator('input[name=email]').fill('jana.testova@example.com');
}

test('customer books two seats and sees the QR payment; only the admin can cancel', async ({ page, request, browser }) => {
  await page.goto('/');
  await page.locator('.run-card', { hasText: 'Premiéra' }).click();
  // The plan is drawn from the layout the server sends.
  const layout = (await (await request.get('/api/seats.php')).json()).layout;
  await expect(page.locator('.plan-hit')).toHaveCount(layout.sections.length);
  // Reserve balcony seats ("Záložní") are shown but not for sale until the admin opens them.
  const reserve = layout.sections.flatMap((s) => s.rows).filter((r) => r.reserve).reduce((sum, r) => sum + r.seats, 0);
  await expect(page.locator('.plan-seat.seat-reserve')).toHaveCount(reserve);
  await expect(page.locator('.level-balcony h2')).toHaveText(layout.levels.balcony);
  await page.locator('.plan-hit').first().click();
  const seats = page.locator('button.seat-available');
  await seats.first().click();
  await seats.first().click();
  await page.getByRole('button', { name: /Zpět na mapu/ }).click();
  await page.locator('.topbar .btn-primary').click();

  const form = page.locator('.modal');
  await form.locator('input[name=firstName]').fill('Jana');
  await form.locator('input[name=lastName]').fill('Testová');
  await form.locator('input[name=email]').fill('jana.testova@example.com');
  await page.waitForTimeout(3200); // FORM_MIN_SECONDS
  await form.locator('button[type=submit]').click();

  await expect(page.locator('.payment-qr img')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText('Čeká na platbu').first()).toBeVisible();
  await expect(page.locator('.payment-amount')).toContainText('600');
  await expect(page.getByText('Variabilní symbol')).toBeVisible();
  await expect(page.getByText('IBAN')).toHaveCount(0); // the account number is enough, IBAN is in the QR code

  // The seats are taken for everybody else.
  const token = new URL(page.url()).searchParams.get('r');
  const reservation = await (await request.get(`/api/reservations.php?token=${token}`)).json();
  const map = await (await request.get('/api/seats.php?run=1')).json();
  expect(reservation.seats.every((s) => map.taken.includes(s))).toBe(true);

  // Customers can't cancel: no button, no endpoint.
  await expect(page.getByRole('button', { name: /Zrušit/ })).toHaveCount(0);
  expect((await request.post('/api/cancel.php', { data: { token } })).status()).toBe(404);

  // The admin cancels it; the customer sees it on the reservation page.
  const admin = await browser.newPage({ viewport: { width: 1360, height: 900 } });
  await adminLogin(admin);
  admin.once('dialog', (d) => d.accept());
  await admin.locator('tr', { hasText: reservation.payment.variableSymbol }).getByRole('button', { name: 'Zrušit', exact: true }).click();
  await expect(admin.locator('.flash')).toContainText('Rezervace zrušena, místa uvolněna.');
  await page.reload();
  await expect(page.getByText('Rezervace byla zrušena.')).toBeVisible();
  const after = await (await request.get('/api/seats.php?run=1')).json();
  expect(reservation.seats.some((s) => after.taken.includes(s))).toBe(false);
});

test('the footer and the run picker show without horizontal scroll on a phone', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('.run-card')).toHaveCount(2);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});

test('child ticket and a discount code: 2 adults + 1 child − 1 adult = 450 Kč', async ({ page }) => {
  const form = await pickSeats(page, 3);
  await fillCustomer(form);
  await form.getByRole('button', { name: 'Děti 4–17 let: více' }).click();
  await expect(form.getByText('Děti do 3 let mohou sedět zdarma na klíně dospělého.')).toBeVisible();
  await form.getByLabel('Slevový kód').fill('nesmysl');
  await form.getByRole('button', { name: 'Použít' }).click();
  await expect(form.getByText('Kód má 8 znaků, např. ABCD-EFGH.')).toBeVisible();
  await form.getByLabel('Slevový kód').fill(seed().codes[0].toLowerCase());
  await form.getByRole('button', { name: 'Použít' }).click();
  await expect(form.locator('.code-list')).toContainText(`${seed().codes[0]} · místo zdarma`);
  await expect(form.locator('.modal-summary strong')).toHaveText('450 Kč');
  await page.waitForTimeout(3200); // FORM_MIN_SECONDS
  await form.locator('button[type=submit]').click();

  await expect(page.locator('.payment-qr img')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('.payment-amount')).toContainText('450');
  await expect(page.locator('.pay-seats')).toContainText('kód, zdarma');
  await expect(page.locator('.pay-seats')).toContainText('Dítě 4–17 let · 150 Kč');

  // The code is used up: the same code is refused for the next reservation.
  const again = await pickSeats(page, 1);
  await fillCustomer(again);
  await again.getByLabel('Slevový kód').fill(seed().codes[0]);
  await again.getByRole('button', { name: 'Použít' }).click();
  await page.waitForTimeout(3200);
  await again.locator('button[type=submit]').click();
  await expect(again.getByText(`Slevový kód ${seed().codes[0]} neplatí nebo už byl použit.`).first()).toBeVisible({ timeout: 30_000 });
});

test('a reservation paid by discount codes is confirmed at once with one ticket per seat and a PDF', async ({ page, request }) => {
  const form = await pickSeats(page, 2);
  await fillCustomer(form);
  for (const code of seed().codes.slice(1, 3)) {
    await form.getByLabel('Slevový kód').fill(code);
    await form.getByRole('button', { name: 'Použít' }).click();
  }
  await expect(form.locator('.modal-summary strong')).toHaveText('0 Kč');
  await page.waitForTimeout(3200);
  await form.getByRole('button', { name: 'Rezervovat zdarma' }).click();

  await expect(page.getByText('Zaplaceno').first()).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('.ticket-card img')).toHaveCount(2);

  // Own names on the tickets.
  await page.getByRole('button', { name: 'Jména na vstupenkách' }).click();
  await page.locator('.pay-seats input').first().fill('Petr Testový');
  await page.getByRole('button', { name: 'Uložit jména' }).click();
  await expect(page.getByText('QR kódy i PDF už jména obsahují.')).toBeVisible();
  await expect(page.locator('.pay-seats li').first()).toContainText('Petr Testový');
  await expect(page.locator('.ticket-card').first()).toContainText('Petr Testový');
  const pdf = await request.get(await page.getByRole('link', { name: 'PDF k tisku' }).getAttribute('href'));
  expect(pdf.headers()['content-type']).toBe('application/pdf');
  expect((await pdf.body()).subarray(0, 5).toString()).toBe('%PDF-');
});
