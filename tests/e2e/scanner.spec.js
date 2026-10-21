import { test, expect } from '@playwright/test';
import QRCode from 'qrcode';
import { ENV, adminLogin, seed } from './helpers.js';

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

test('scanner checks a seat ticket in and warns on the second scan', async ({ page }) => {
  await fakeCamera(page, seed().ticket);
  await login(page);
  await expect(page.locator('.scan-result h2')).toHaveText('Platná vstupenka', { timeout: 20_000 });
  await expect(page.locator('.scan-ticket')).toContainText('Test Vstupenka');
  await expect(page.locator('.scan-seat-big')).toContainText('řada 1 · místo 1');
  await expect(page.locator('.scan-kind')).toHaveText('Dospělý');
  await expect(page.locator('.scan-count')).toHaveText('rezervace 2 místa · odbaveno 1 z 2');
  await page.getByRole('button', { name: 'Skenovat další' }).click();
  await expect(page.locator('.scan-result h2')).toHaveText('Už odbaveno', { timeout: 20_000 });
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
  await expect(page.locator('.offline-strip')).toContainText('k odeslání: 1');

  await context.setOffline(false);
  await expect(page.locator('.offline-strip')).toContainText('Odesláno 1 odbavení bez spojení.', { timeout: 20_000 });

  const snapshot = await (await page.request.post('/api/organizer.php', { data: { action: 'snapshot', runId: 2 } })).json();
  expect(snapshot.tickets.find((t) => t.name === 'Test Offline').checkedIn['BC-2-1'].by).toContain('(offline)');
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

test('organizer sells free seats at the entrance: QR payment, then the seats are checked in', async ({ page }) => {
  await login(page);
  await page.getByRole('tab', { name: 'Prodej' }).click();
  await page.locator('.sale-sections button').first().click();
  await page.getByRole('button', { name: 'Řada 4, místo 4' }).click();
  await page.getByRole('button', { name: 'Řada 4, místo 5' }).click();
  await page.getByRole('button', { name: 'Děti 4–17 let: více' }).click();
  await page.getByRole('button', { name: /QR platba 450 Kč/ }).click();
  await expect(page.locator('.sale-pay img')).toBeVisible();
  await expect(page.locator('.sale-amount')).toHaveText('450 Kč');
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Zaplaceno – vpustit' }).click();
  await expect(page.locator('.sale-done')).toHaveText('Zaplaceno 450 Kč – 2 místa odbavena.');
  // The section stays open; the sold seats are taken now.
  await expect(page.getByRole('button', { name: /Řada 4, místo 5/ })).toBeDisabled();
});

test('an invite link makes the organizer create a password; a forwarded link alone signs nobody in', async ({ page, browser }) => {
  const admin = await browser.newPage({ viewport: { width: 1360, height: 900 } });
  await adminLogin(admin);
  await admin.goto('http://127.0.0.1:8000/api/admin.php?view=scanners');
  const form = admin.locator('form', { hasText: 'Nová pozvánka' });
  await form.locator('input[name=name]').fill('Vchod E2E');
  await form.locator('input[name="runs[]"]').first().check();
  await form.getByRole('button', { name: 'Vytvořit pozvánku' }).click();
  const token = new URL(await admin.locator('.invite-link').inputValue()).searchParams.get('pozvanka');
  await expect(admin.locator('tr', { hasText: 'Vchod E2E' })).toContainText('Čeká na heslo');

  // The organizer opens the link and creates the password.
  await page.goto(`/scanner.html?pozvanka=${token}`);
  await expect(page.locator('.scan-login')).toContainText('Vytvořte si heslo');
  await page.getByLabel('Nové heslo (alespoň 8 znaků)').fill('vchod-heslo');
  await page.getByLabel('Heslo znovu').fill('vchod-heslo');
  await page.getByRole('button', { name: 'Vytvořit heslo a přihlásit' }).click();
  await expect(page.locator('.scan-login')).toHaveCount(0);
  await expect(page).not.toHaveURL(/pozvanka/);

  // Someone else with the forwarded link.
  const other = await browser.newPage();
  await other.goto(`http://127.0.0.1:4173/scanner.html?pozvanka=${token}`);
  await expect(other.locator('.scan-login')).toContainText('Zadejte heslo');
  await other.getByLabel('Heslo').fill('hadam-heslo');
  await other.getByRole('button', { name: 'Přihlásit' }).click();
  await expect(other.locator('.form-error')).toContainText('Nesprávné heslo.');
  await other.getByLabel('Heslo').fill('vchod-heslo');
  await other.getByRole('button', { name: 'Přihlásit' }).click();
  await expect(other.locator('.scan-login')).toHaveCount(0);

  await admin.reload();
  await expect(admin.locator('tr', { hasText: 'Vchod E2E' })).toContainText('Aktivní');
});
