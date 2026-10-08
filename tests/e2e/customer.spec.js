import { test, expect } from '@playwright/test';

test('customer books two seats, sees the QR payment and cancels', async ({ page, request }) => {
  await page.goto('/');
  await page.locator('.run-card', { hasText: 'Premiéra' }).click();
  await page.locator('.section-card').first().click();
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

  // The seats are taken for everybody else.
  const token = new URL(page.url()).searchParams.get('r');
  const reservation = await (await request.get(`/api/reservations.php?token=${token}`)).json();
  const map = await (await request.get('/api/seats.php?run=1')).json();
  expect(reservation.seats.every((s) => map.taken.includes(s))).toBe(true);

  await page.getByRole('button', { name: 'Zrušit rezervaci nebo jednotlivá místa' }).click();
  await page.locator('.cancel-panel').getByRole('button', { name: 'Zrušit rezervaci' }).click();
  await expect(page.getByText('Rezervaci jste zrušili.')).toBeVisible();
  const after = await (await request.get('/api/seats.php?run=1')).json();
  expect(reservation.seats.some((s) => after.taken.includes(s))).toBe(false);
});

test('the footer and the run picker show without horizontal scroll on a phone', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('.run-card')).toHaveCount(2);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});
