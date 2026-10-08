# Moje židle 2026

Church chair reservation app – React (Vite) frontend, PHP 8 backend, MariaDB.

**Kompletní dokumentace v češtině: [docs/DOKUMENTACE.md](docs/DOKUMENTACE.md)** (rule: keep it in sync with every code change – see `CLAUDE.md`; shared page: https://claude.ai/artifact/GXKiRvKYkzGQMnceZaTthV, regenerate with `npm run docs`) – všechny procesy, e-maily, správa, odbavení, konfigurace a provoz.

## How it works

1. The visitor picks seats on the floor plan and clicks **Rezervovat**.
2. They enter name, surname and e-mail. The seats are stored in the database
   as a *pending* reservation and immediately shown as occupied to everyone.
3. A payment page shows a Czech **QR Platba** code (amount, account, variable
   symbol, specific symbol, due date). The page stays reachable at `?r=<token>`.
   Each reservation gets a random, unique 10-digit variable symbol; the
   specific symbol is the same for all payments (`PAYMENT_SPECIFIC_SYMBOL`).
4. The accountant confirms received payments in `api/admin.php`. This e-mails
   the customer a ticket with a QR code containing the orderer's name, seat
   count and seat numbers (signed, so it cannot be forged or altered). The
   ticket QR is also shown on the customer's reservation page.
5. The customer sees a due date `PAYMENT_DEADLINE_HOURS` (72 h) after
   reserving. Unpaid reservations are cancelled and their seats freed only
   `PAYMENT_GRACE_HOURS` (48 h) after the due date, so a transfer sent on the
   last day still arrives in time. If money arrives even later, the accountant
   can **accept the late payment** in admin – the reservation is restored if
   its seats are still free; otherwise admin names the resold seats and the
   payment has to be refunded or other seats agreed.
   Admin can also fix a mistyped customer e-mail and resend the ticket.
   New reservations stop at `BOOKING_CLOSES_AT` (optional).
   E-mails: a payment **reminder 24 h before the due date** and an **expiry
   notice** when an unpaid reservation is cancelled (sent by `cron.php`).
8. **Cancellation by the customer** on the reservation page (`?r=<token>`).
   Unpaid reservations are cancelled free of charge. For paid ones the
   **storno fee** set in admin → *Nastavení* applies (e.g. 50 % from one date,
   100 % from another; free before the first date). Refunds always go back
   to the account the payment came from. Customers can cancel the whole reservation or
   individual seats (unpaid: the amount shrinks; paid: a new ticket is
   e-mailed). Cancelling is possible until the event starts and not after
   check-in. Refunds due are listed in admin (*K vrácení*) and
   marked **Vráceno** once paid out. A paid reservation (or seats) cancelled by
   admin is refunded in full and the customer is e-mailed that the amount
   will be sent back to the paying account within `REFUND_DAYS` (14).
9. **GDPR**: the form says that names and e-mails are used only for the
   reservation and deleted `DATA_RETENTION_DAYS` (30) after the event.
   `cron.php` then removes names and e-mails (VS, amounts
   and seats stay for accounting) and deletes the VIP list. Requires the
   event date in admin → *Nastavení*.
6. At the entrance, organizers open `scanner.html` on a phone, log in with
   `ORGANIZER_PASSWORD` and scan tickets with the rear camera. The scanner
   shows the name and seats and whether the ticket is valid, already used
   (with the time of the first scan), unpaid, cancelled or invalid.
7. **VIP guests** (free entry) are entered by the management in the *VIP* tab
   of `api/admin.php` – name, section, number of persons and an optional
   note. They do not pay and do not block specific seats. At the entrance,
   organizers switch the scanner to *VIP*, search the name the guest says
   (diacritics and word order don't matter) and tap **Vpustit**; arrivals can
   be undone.

### Ticket QR format

```
Z26|<reservation id>|<variable symbol>|<seat count>|<name>|<seat,seat,...>|<signature>
Z26|30|7736065879|2|Marta Dušková|WL-3-1,WL-3-2|yx3MH4ga00ig1fOUdjZnSV
```

The signature is a truncated HMAC-SHA256 of the rest using `TICKET_SECRET`;
the server (`api/organizer.php`) checks it, loads the reservation by its id
and returns its current state (status, valid seats), and records the first
check-in.

## Spam and bot protection

No captcha or third-party service; all checks are server-side
(`api/lib/antispam.php`) and configurable in `api/config.php`:

- **Honeypot** – a hidden `website` field in the reservation form; filled = bot.
- **Form token** – `seats.php` issues a signed, timestamped token; a reservation
  without it, with a forged one, or sent less than `FORM_MIN_SECONDS` (3 s)
  after loading the page is rejected.
- **Rate limits** (table `rate_limits`, IPs stored only as hashes):
  `RESERVATIONS_PER_IP_PER_HOUR` (5), `PENDING_RESERVATIONS_PER_EMAIL` (2 unpaid
  reservations at once – stops seat blocking with one address) and
  `LOGIN_ATTEMPTS_PER_15_MIN` (10) for the admin and organizer logins.

Behind a reverse proxy / CDN, `REMOTE_ADDR` is the proxy's address – make the
web server pass the real client IP (e.g. Apache `mod_remoteip`), otherwise all
visitors share one limit.

## Setup

```bash
# database
mariadb -e "CREATE DATABASE zidle CHARACTER SET utf8mb4"
mariadb -e "CREATE USER 'zidle'@'localhost' IDENTIFIED BY '…'; GRANT ALL ON zidle.* TO 'zidle'@'localhost'"
mariadb zidle < db/schema.sql
# (existing database from an older version: run the files in db/migrations/ in order, 002–007)

# PHP dependencies (QR code images for ticket e-mails)
(cd api && composer install --no-dev)

# configuration – DB credentials, bank account, admin password, e-mail
cp api/config.local.example.php api/config.local.php
```

All options and defaults are in `api/config.php`; they can also be set as
environment variables. Required: `BANK_IBAN`, `ADMIN_PASSWORD`,
`ORGANIZER_PASSWORD`, `TICKET_SECRET` (generate with
`php -r "echo bin2hex(random_bytes(32));"` and never change it once tickets
are sent) and `MAIL_ENABLED` + `MAIL_FROM` for sending tickets.

### Development

```bash
npm install
php -S 127.0.0.1:8000 -t .   # PHP API
npm run dev                  # Vite, proxies /api to the PHP server
```

### Production

```bash
npm run build
```

Upload the contents of `dist/` together with the `api/` folder including
`api/vendor/` (so the app finds the API at `./api/`) to a PHP 8.1+ host with
MariaDB and the GD extension. The site must run on **HTTPS** – phone browsers
only allow camera access for the scanner on secure pages. Keep
`api/config.local.php` and `api/lib/` non-public (`.htaccess` files are
included for Apache). Add a cron job – it frees seats of unpaid reservations, sends reminders and
expiry notices and deletes personal data after the event:

```
*/10 * * * * php /path/to/api/cron.php
```

If the API runs on a different domain, build with `VITE_API_URL=https://…/api`
and set `CORS_ORIGIN`.

## API

| Method | Endpoint | |
| --- | --- | --- |
| GET | `api/seats.php` | `{taken: [seatId], price, deadlineHours}` |
| POST | `api/reservations.php` | body `{firstName, lastName, email, seats}` → reservation with payment details (201), `409` with `conflict` when a seat is already taken, `422` with `fields` on validation errors |
| GET | `api/reservations.php?token=` | reservation status, payment details and `ticket` (QR text) once paid |
| POST | `api/cancel.php` | body `{token, seats?}` – customer cancellation of the given seats (omitted = whole reservation) |
| GET/POST | `api/organizer.php` | organizer session (`login`, `logout`), `verify` of a scanned ticket code, `vip-list`, `vip-checkin`, `vip-undo` |

Seat IDs: `SECTION-ROW-SEAT`, e.g. `ML-1-1`, `BC-4-12`. Sections: `WL` Left
Wing, `ML` Left Main, `MR` Right Main, `WR` Right Wing, `BL` Balcony left,
`BC` Balcony center, `BR` Balcony right. The layout is defined in both
`src/data/layout.js` and `api/lib/layout.php` – keep them in sync.

## Database

- `reservations` – one row per reservation incl. history (`pending`, `paid`, `expired`, `cancelled`), ticket e-mail time and check-in time.
- `settings` – admin-editable event date and storno rules (JSON).
- `vip_guests` – VIP guests (name, section, persons, note, arrival time).
- `reservation_seats` – currently held seats; the primary key on `seat_id` prevents double booking. Rows are removed when a reservation expires or is cancelled.
