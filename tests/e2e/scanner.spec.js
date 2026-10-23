import { test, expect } from '@playwright/test';
import QRCode from 'qrcode';
import { SCANNER, adminLogin, seed } from './helpers.js';

/**
 * Replaces the camera with a canvas stream that shows the given QR code
 * (only while window.__showQr is true; hidden = true starts with an empty picture).
 */
async function fakeCamera(page, text, hidden = false) {
  const dataUrl = await QRCode.toDataURL(text, { width: 480, margin: 4 });
  await page.addInitScript(
    ({ src, hidden }) => {
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
    },
    { src: dataUrl, hidden },
  );
}

const snapshotLoaded = (page) =>
  page.waitForResponse(
    (r) => r.url().includes('organizer.php') && (r.request().postData() ?? '').includes('"snapshot"'),
  );

async function login(page) {
  await page.goto('/scanner.html');
  await page.getByLabel('E-mail').fill(SCANNER.email);
  await page.getByLabel('Heslo').fill(SCANNER.password);
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

  const snapshot = await (
    await page.request.post('/api/organizer.php', { data: { action: 'snapshot', runId: 2 } })
  ).json();
  expect(snapshot.tickets.find((t) => t.name === 'Test Offline').checkedIn['BC-2-1'].by).toContain('(offline)');
});

test('the scanner opens without a connection after it was used online once', async ({ page, context }) => {
  await login(page);
  await expect(page.locator('.run-select select')).toBeVisible();
  await page.evaluate(async () => {
    const registration = await navigator.serviceWorker.ready;
    if (!navigator.serviceWorker.controller)
      await new Promise((r) => navigator.serviceWorker.addEventListener('controllerchange', r));
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

test('an invited Odbavovatel lands in the scanner; without an assigned run it asks for one, then checks in only that run', async ({
  page,
  browser,
}) => {
  const admin = await browser.newPage({ viewport: { width: 1360, height: 900 } });
  await adminLogin(admin);
  await admin.goto('http://127.0.0.1:8000/api/admin.php?view=users');
  await admin.fill('input[name=email]', 'vchod.b@example.cz');
  await admin.locator('form', { hasText: 'Pozvat uživatele' }).getByLabel('Odbavovatel').check();
  await admin.getByRole('button', { name: 'Poslat pozvánku' }).click();
  const link = await admin.locator('.invite-link').inputValue();
  await expect(admin.locator('tr', { hasText: 'vchod.b@example.cz' })).toContainText('Nepřijata');

  // On the organizer's phone.
  await page.goto(link);
  await page.fill('input[name=password]', 'vchodove-heslo-b');
  await page.fill('input[name=password_again]', 'vchodove-heslo-b');
  await page.getByRole('button', { name: 'Uložit heslo a přihlásit' }).click();
  await expect(page).toHaveURL(/scanner\.html/);
  await expect(page.locator('.scan-no-runs')).toContainText('Nemáte přiřazený žádný termín');

  // The admin assigns one run.
  await admin.reload();
  const row = admin.locator('tr', { hasText: 'vchod.b@example.cz' });
  await expect(row).toContainText('Přijata');
  await expect(row).toContainText('odbavuje: žádný termín');
  await row.locator('summary').click();
  await row.getByLabel('Dnes').check();
  await row.getByRole('button', { name: 'Uložit' }).click();
  await expect(admin.locator('.flash')).toContainText('Role a termíny účtu vchod.b@example.cz uloženy.');
  await expect(admin.locator('tr', { hasText: 'vchod.b@example.cz' })).toContainText('odbavuje:');

  await page.reload();
  await expect(page.locator('.scan-who')).toHaveText('vchod.b@example.cz');
  await expect(page.locator('.run-select option')).toHaveCount(1);
  await expect(page.locator('.run-select option')).toContainText('Dnes');
  await expect(page.getByRole('link', { name: 'Správa' })).toHaveCount(0);
});

test('a Správce signed in to the admin opens the scanner from the menu without signing in again', async ({ page }) => {
  await adminLogin(page);
  await page.getByRole('link', { name: 'Odbavení' }).click();
  await expect(page.locator('.scan-who')).toHaveText('spravce@example.cz');
  await expect(page.getByRole('link', { name: 'Správa' })).toBeVisible();
});
