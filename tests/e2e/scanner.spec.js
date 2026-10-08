import { test, expect } from '@playwright/test';
import QRCode from 'qrcode';
import { ENV, seed } from './helpers.js';

/** Replaces the camera with a canvas stream that shows the given QR code. */
async function fakeCamera(page, text) {
  const dataUrl = await QRCode.toDataURL(text, { width: 480, margin: 4 });
  await page.addInitScript((src) => {
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
        ctx.drawImage(img, 80, 0, 480, 480);
        requestAnimationFrame(draw);
      };
      draw();
      return canvas.captureStream(15);
    };
  }, dataUrl);
}

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
