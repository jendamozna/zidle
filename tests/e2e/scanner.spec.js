import { test, expect } from '@playwright/test';
import QRCode from 'qrcode';
import { ENV, seed } from './helpers.js';

/**
 * Replaces the camera with a canvas stream that shows the given QR code
 * (only while window.__showQr is true; hidden = true starts with an empty picture).
 */
async function fakeCamera(page, text, hidden = false) {
  const dataUrl = await QRCode.toDataURL(text, { width: 480, margin: 4 });
  await page.addInitScript(({ src, hidden }) => {
    window.__showQr = !hidden;
    navigator.mediaDevices.getUserMedia = async () => {
      const canvas = document.createElement('canvas');
      canvas.width = 640;
      canvas.height = 480;
      const ctx = canvas.getContext('2d');
      const img = new Image();
      img.src = src;
      await img.decode();
      const draw = () => {
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, 640, 480);
        if (window.__showQr) ctx.drawImage(img, 80, 0, 480, 480);
        requestAnimationFrame(draw);
      };
      draw();
      return canvas.captureStream(15);
    };
  }, { src: dataUrl, hidden });
}

const snapshotLoaded = (page) =>
  page.waitForResponse((r) => r.url().includes('organizer.php') && (r.request().postData() ?? '').includes('"snapshot"'));

async function login(page) {
  await page.goto('/scanner.html');
  await page.locator('input[type=password]').fill(ENV.ORGANIZER_PASSWORD);
  await page.getByRole('button', { name: /Přihlásit/ }).click();
}

test('scanner checks a ticket in and warns on the second scan', async ({ page }) => {
  await fakeCamera(page, seed().ticket);
  await login(page);
  await expect(page.locator('.scan-result h2')).toHaveText('Platná vstupenka', { timeout: 20_000 });
  await expect(page.locator('.scan-ticket')).toContainText('Test Vstupenka');
  await page.getByRole('button', { name: 'Skenovat další' }).click();
  await expect(page.locator('.scan-result h2')).toHaveText('Už odbaveno', { timeout: 20_000 });
});

test('scanner lets a VIP guest in and shows the seats', async ({ page }) => {
  await login(page);
  await page.getByRole('tab', { name: 'VIP' }).click();
  const card = page.locator('.vip-card', { hasText: 'Mons. Testovací' });
  await expect(card).toContainText('ř. 1 m. 1, 2');
  await card.getByRole('button', { name: 'Vpustit' }).click();
  await expect(card.locator('.vip-arrived')).toBeVisible();
});

test('without a connection the scanner checks tickets against the downloaded list and sends the check-ins later', async ({
  page,
  context,
}) => {
  await fakeCamera(page, seed().offlineTicket, true);
  const loaded = snapshotLoaded(page);
  await login(page);
  await loaded;

  await context.setOffline(true);
  await page.evaluate(() => (window.__showQr = true));
  await expect(page.locator('.scan-result h2')).toHaveText('Platná vstupenka', { timeout: 20_000 });
  await expect(page.locator('.scan-ticket')).toContainText('bez spojení, podle seznamu z');
  await expect(page.locator('.offline-strip')).toContainText('k odeslání: 1');
  await page.getByRole('button', { name: 'Skenovat další' }).click();
  await expect(page.locator('.scan-result h2')).toHaveText('Už odbaveno', { timeout: 20_000 });

  await page.getByRole('tab', { name: 'VIP' }).click();
  const card = page.locator('.vip-card', { hasText: 'Paní Offline' });
  await card.getByRole('button', { name: 'Vpustit' }).click();
  await expect(card.locator('.vip-arrived')).toBeVisible();
  await expect(page.locator('.offline-strip')).toContainText('k odeslání: 2');

  await context.setOffline(false);
  await expect(page.locator('.offline-strip')).toContainText('Odesláno 2 odbavení bez spojení.', { timeout: 20_000 });

  const snapshot = await (await page.request.post('/api/organizer.php', { data: { action: 'snapshot', runId: 2 } })).json();
  expect(snapshot.tickets.find((t) => t.name === 'Test Offline').checkedInBy).toContain('(offline)');
  expect(snapshot.vips.find((v) => v.name === 'Paní Offline').checkedInAt).not.toBeNull();
});

test('the scanner opens without a connection after it was used online once', async ({ page, context }) => {
  await login(page);
  await expect(page.locator('.run-select select')).toBeVisible();
  await page.evaluate(async () => {
    const registration = await navigator.serviceWorker.ready;
    if (!navigator.serviceWorker.controller) await new Promise((r) => navigator.serviceWorker.addEventListener('controllerchange', r));
    return registration.active?.state;
  });

  await context.setOffline(true);
  await page.reload();
  await expect(page.locator('.run-select select')).toBeVisible();
  await expect(page.locator('.offline-strip')).toContainText('Bez spojení');
  await context.setOffline(false);
});
