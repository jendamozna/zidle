import { test, expect } from '@playwright/test';
import { adminLogin, inviteLink, paymentMails, seed } from './helpers.js';

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

test('a bank statement CSV pairs a transfer by SS and records it once', async ({ page }) => {
  const { bankSs } = seed();
  await adminLogin(page);
  await page.getByRole('link', { name: 'Platby' }).click();
  const header =
    '"Datum splatnosti";"Protiúčet a kód banky";"Název protiúčtu";"Částka";"VS";"SS";"Identifikace transakce";"AV pole 1"';
  const csv = [
    '"Číslo účtu";"123/0100"',
    header,
    `"19.10.2026";"19-123/0100";"Jan Převodem";"+600,00";"3211";"${bankSs}";"E2E-1";"vstupenky"`,
    '"20.10.2026";"";"Neznámý";"+400,00";"321";"0";"E2E-2";"Marie"',
  ].join('\r\n');
  await page
    .locator('input[name=statement]')
    .setInputFiles({ name: 'vypis.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
  await page.getByRole('button', { name: 'Načíst výpis' }).click();

  const matched = page.locator('tr', { hasText: 'Jan Převodem' });
  await expect(matched).toContainText(`SS ${bankSs}`);
  await expect(matched).toContainText('Celá částka → Zaplaceno');
  await expect(matched.locator('input[type=checkbox]')).toBeChecked();
  await expect(page.locator('tr', { hasText: 'Neznámý' })).toContainText('Platba nemá SS');
  await page.getByRole('button', { name: 'Zapsat vybrané platby' }).click();
  await expect(page.locator('.flash')).toContainText('Zapsáno plateb: 1 z 1.');
  await expect(page.locator('.bank-results')).toContainText('Přijato 600 Kč – zaplaceno.');
  await expect(matched).toContainText('Už zapsáno');
  await expect(matched.locator('input[type=checkbox]')).toHaveCount(0);

  await page.getByRole('link', { name: 'Rezervace' }).click();
  await expect(page.locator('tr', { hasText: bankSs }).locator('.badge')).toHaveText('Zaplaceno');
});

test('bank e-mails waiting for review are recorded or marked resolved', async ({ page }) => {
  seed();
  const ss = paymentMails();
  await adminLogin(page);
  const tab = page.getByRole('link', { name: /Platby/ });
  await expect(tab.locator('.tab-alert')).toHaveText('2');
  await tab.click();
  // Mailbox on or off depends on the local configuration; the card is there either way.
  await expect(page.locator('.sync')).toContainText('Platby z e-mailu banky');

  const payment = page.locator('.mails tr', { hasText: `SS ${ss}` });
  await expect(payment).toContainText('Ke kontrole');
  await expect(payment).toContainText('Emil Emailový');
  await expect(payment).toContainText('Rezervace už je zaplacená');
  page.once('dialog', (d) => d.accept());
  await payment.getByRole('button', { name: 'Zapsat platbu' }).click();
  await expect(page.locator('.flash')).toContainText(
    'Přijato 300 Kč – rezervace už byla zaplacená, jde o platbu navíc.',
  );
  await expect(payment).toContainText('Zapsáno');
  await expect(payment.getByRole('button')).toHaveCount(0);

  const unread = page.locator('.mails tr', { hasText: 'Nerozpoznáno' });
  await unread.getByText('Text e-mailu').click();
  await expect(unread.locator('pre')).toContainText('Výpis z účtu za říjen');
  await unread.getByRole('button', { name: 'Vyřízeno' }).click();
  await expect(page.locator('.flash')).toContainText('E-mail označen jako vyřízený.');
  await expect(unread).toContainText('vyřízeno (spravce@example.cz)');
  await expect(tab.locator('.tab-alert')).toHaveCount(0);
});

test('status and run cards toggle their filters', async ({ page }) => {
  seed();
  await adminLogin(page);
  const pending = page.locator('.stats a.card', { hasText: 'Čeká na platbu' });
  const premiere = page.locator('.stats a.card', { hasText: 'Premiéra' });

  await pending.click();
  await expect(pending).toHaveClass(/selected/);
  await expect(page.locator('select[name=status]')).toHaveValue('pending');
  for (const badge of await page.locator('tbody .badge').allInnerTexts()) expect(badge).toBe('Čeká na platbu');

  await premiere.click();
  await expect(page).toHaveURL(/status=pending/);
  await expect(page).toHaveURL(/run=1/);
  await expect(premiere).toHaveClass(/selected/);

  await pending.click();
  await expect(page).not.toHaveURL(/status=/);
  await expect(page).toHaveURL(/run=1/);
  await premiere.click();
  await expect(page).not.toHaveURL(/run=/);
  await expect(premiere).not.toHaveClass(/selected/);
});

test('search by a seat finds exactly that seat, a row prefix the whole row', async ({ page }) => {
  const { pendingVs } = seed();
  await adminLogin(page);
  await page.goto('http://127.0.0.1:8000/api/admin.php?q=PH-5-1&sort=seats');
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await expect(page.locator('tbody tr')).toContainText(pendingVs);
  await page.goto('http://127.0.0.1:8000/api/admin.php?q=PH-5-2');
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await page.goto('http://127.0.0.1:8000/api/admin.php?q=PH-5');
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await page.goto('http://127.0.0.1:8000/api/admin.php?q=PH-5-3');
  await expect(page.locator('tbody')).toContainText('Žádné rezervace.');
});

test('reservations sort by a clicked column, a second click reverses it', async ({ page }) => {
  seed();
  await adminLogin(page);
  const amounts = async () =>
    (await page.locator('tbody tr td:nth-child(5)').allInnerTexts()).map((t) => parseInt(t.replace(/\s/g, ''), 10));
  const header = page.locator('th a.sort', { hasText: 'Částka' });

  await header.click();
  await expect(page.locator('th[aria-sort="ascending"]')).toContainText('Částka');
  const asc = await amounts();
  expect(asc).toEqual([...asc].sort((a, b) => a - b));

  await header.click();
  await expect(page.locator('th[aria-sort="descending"]')).toContainText('Částka');
  expect(await amounts()).toEqual([...asc].sort((a, b) => b - a));
});

test('admin invites an accountant, who sets a password, sees only reservations and signs in with the e-mail', async ({
  page,
  browser,
}) => {
  await adminLogin(page);
  await page.goto('http://127.0.0.1:8000/api/admin.php?view=users');
  await page.fill('input[name=email]', 'jana.ucetni@example.cz');
  await page.locator('form', { hasText: 'Pozvat uživatele' }).getByLabel('Účetní').check();
  await page.getByRole('button', { name: 'Poslat pozvánku' }).click();
  await expect(page.locator('.flash')).toContainText('Pozvánka pro jana.ucetni@example.cz vytvořena.');
  await expect(page.locator('.flash')).toContainText('E-maily jsou vypnuté (MAIL_ENABLED) – pozvánka nebyla dodaná.');
  const link = inviteLink('jana.ucetni@example.cz');
  await expect(page.locator('tr', { hasText: 'jana.ucetni@example.cz' })).toContainText('Nepřijata');

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
  await expect(jana.locator('.flash')).toContainText(
    'Vítejte. Heslo je nastavené, příště se přihlásíte e-mailem jana.ucetni@example.cz.',
  );
  await expect(jana.locator('.me')).toContainText('jana.ucetni@example.cz');
  await expect(jana.locator('.tabs a')).toHaveText(['Rezervace', 'Platby']);
  await jana.goto('http://127.0.0.1:8000/api/admin.php?view=users');
  await expect(jana.locator('.tabs a.active')).toHaveText('Rezervace');
  await page.reload();
  await expect(page.locator('tr', { hasText: 'jana.ucetni@example.cz' })).toContainText('Přijata');

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

test('admin sends discount codes to e-mails and revokes an unused one', async ({ page }) => {
  await adminLogin(page);
  await page.getByRole('link', { name: 'Slevové kódy' }).click();
  await page.fill('textarea[name=emails]', 'rodina@example.com\nrodina@example.com\nspatny-email');
  await page.getByRole('button', { name: 'Vytvořit a poslat kódy' }).click();
  await expect(page.locator('.flash')).toContainText('Neplatné e-maily: spatny-email. Nic nebylo vytvořeno.');

  await page.fill('textarea[name=emails]', 'rodina@example.com\nrodina@example.com');
  await page.getByRole('button', { name: 'Vytvořit a poslat kódy' }).click();
  await expect(page.locator('.flash')).toContainText('Vytvořeno 2 kódy');
  const rows = page.locator('tr', { hasText: 'rodina@example.com' });
  await expect(rows).toHaveCount(2);
  await expect(rows.first().locator('.code')).toHaveText(
    /^(?:[B-DF-HJ-NP-TV-X][AEU]|[2-9]{2}){2}-(?:[B-DF-HJ-NP-TV-X][AEU]|[2-9]{2}){2}$/,
  );

  page.once('dialog', (dialog) => dialog.accept());
  await rows.first().getByRole('button', { name: 'Zrušit' }).click();
  await expect(page.locator('.flash')).toContainText('Kód zrušen');
  await expect(
    page.locator('tr', { hasText: 'rodina@example.com' }).locator('.badge', { hasText: 'Zrušený' }),
  ).toHaveCount(1);
});

test('admin sets a shorter payment deadline for reservations from a date', async ({ page, request }) => {
  await adminLogin(page);
  await page.goto('http://127.0.0.1:8000/api/admin.php?view=settings');
  await page.locator('input[name="deadline_from[]"]').first().fill('2020-01-01T00:00');
  await page.locator('input[name="deadline_hours[]"]').first().fill('24');
  await page.getByRole('button', { name: 'Uložit splatnost' }).click();
  await expect(page.locator('.flash')).toContainText('Splatnost uložena.');
  await expect(page.getByText('Nyní platí: 24 hodin')).toBeVisible();
  expect((await (await request.get('http://127.0.0.1:8000/api/seats.php')).json()).deadlineHours).toBe(24);

  await page.locator('input[name="deadline_from[]"]').first().fill('');
  await page.locator('input[name="deadline_hours[]"]').first().fill('');
  await page.getByRole('button', { name: 'Uložit splatnost' }).click();
  await expect(page.getByText('Nyní platí: 72 hodin')).toBeVisible();
});

test('admin switches seats of a run between for sale and "Záložní" on the plan', async ({ page, request }) => {
  await adminLogin(page);
  await page.getByRole('link', { name: 'Plánek' }).click();
  const premiere = await page.locator('select[name=run] option', { hasText: 'Premiéra' }).getAttribute('value');
  await page.locator('select[name=run]').selectOption(premiere);
  await expect(page.locator('select[name=run] option:checked')).toContainText('Premiéra');
  const seat = page.locator('.ps:has(input[value="LH-1-1"])');
  await seat.click();
  await expect(seat.locator('input')).toBeChecked();
  await page.getByRole('button', { name: 'Uložit plánek' }).click();
  await expect(page.locator('.flash')).toContainText('Plánek uložen. V prodeji 266 míst, záložních 35.');
  let runs = (await (await request.get('http://127.0.0.1:8000/api/seats.php')).json()).runs;
  expect(runs.find((r) => r.label === 'Premiéra').reserveSeats).toContain('LH-1-1');

  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Výchozí rozložení' }).click();
  await expect(page.locator('.flash')).toContainText('Obnoveno výchozí rozložení. V prodeji 267 míst, záložních 34.');
  runs = (await (await request.get('http://127.0.0.1:8000/api/seats.php')).json()).runs;
  expect(runs.find((r) => r.label === 'Premiéra').reserveSeats).not.toContain('LH-1-1');
});

test('forgotten password: the login page sends a link without telling whether the e-mail exists', async ({ page }) => {
  await page.goto('http://127.0.0.1:8000/api/admin.php');
  await page.getByRole('link', { name: 'Zapomněl jsem heslo' }).click();
  await page.fill('input[name=email]', 'nikdo@example.cz');
  await page.getByRole('button', { name: 'Poslat odkaz' }).click();
  await expect(page.locator('.login')).toContainText(
    'Pokud e-mail u nás má účet, poslali jsme na něj odkaz na nové heslo.',
  );
  await page.goto('http://127.0.0.1:8000/api/admin.php?heslo=' + 'a'.repeat(48));
  await expect(page.locator('.error')).toHaveText('Odkaz neplatí nebo vypršel. Požádejte o nový.');
});
