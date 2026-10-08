# Moje židle 2026 – developer documentation

Technical reference of the application. It describes the code **1:1** –
every change of behaviour, texts, limits, API, database or formats must
update this file in the same commit (see `CLAUDE.md`). The light Czech
overview for stakeholders lives in `docs/prehled.html` and is published as
https://claude.ai/artifact/GXKiRvKYkzGQMnceZaTthV.

## Contents

1. [Overview](#1-overview)
2. [Repository layout](#2-repository-layout)
3. [Setup, build, deployment](#3-setup-build-deployment)
4. [Configuration](#4-configuration)
5. [Domain model](#5-domain-model)
6. [Database](#6-database)
7. [Customer app](#7-customer-app)
8. [Reservation lifecycle](#8-reservation-lifecycle)
9. [Payments](#9-payments)
10. [Cancellation and refunds](#10-cancellation-and-refunds)
11. [Tickets and check-in](#11-tickets-and-check-in)
12. [Scanner access (invites)](#12-scanner-access-invites)
13. [VIP guests](#13-vip-guests)
14. [Admin](#14-admin)
15. [E-mails](#15-e-mails)
16. [Scheduled jobs and GDPR](#16-scheduled-jobs-and-gdpr)
17. [Spam and abuse protection](#17-spam-and-abuse-protection)
18. [HTTP API](#18-http-api)
19. [Formats](#19-formats)
20. [Conventions](#20-conventions)

---

## 1. Overview

| Part | Entry | Users | Layout | Tech |
| --- | --- | --- | --- | --- |
| Customer app | `index.html` → `src/main.jsx` → `src/App.jsx` | public | mobile-first | React 19, Vite |
| Reservation page | same app, `/?r=<token>` | customer | mobile-first | React |
| Scanner | `scanner.html` → `src/scanner/main.jsx` | organizers | mobile only | React, `jsqr` |
| Admin | `api/admin.php` | accountant / admin | desktop-first | server-rendered PHP |
| API | `api/*.php` | apps above | – | PHP 8.1+, PDO MySQL |
| Jobs | `api/cron.php` (CLI) | cron, every 10 min | – | PHP |
| Database | `db/schema.sql` | – | – | MariaDB 10.5+ |

UI and e-mail texts are Czech. All times are stored in **UTC**; Europe/Prague
is used only for admin input and for display.

## 2. Repository layout

```
api/
  admin.php            admin UI (login, reservations, VIP, scanner invites, runs)
  seats.php            GET runs + taken seats of a run
  altcha.php           GET invisible ALTCHA challenge
  reservations.php     POST create reservation, GET reservation by token
  cancel.php           POST customer cancellation
  organizer.php        scanner API (access, verify, VIP)
  cron.php             scheduled jobs (CLI only); cron-expire.php = alias
  config.php           defaults (+ config.local.php / env overrides)
  lib/bootstrap.php    config(), db(), JSON helpers, expire_reservations(), QR payment, reservation_payload()
  lib/layout.php       SECTIONS (must match src/data/layout.js), total_capacity(), is_valid_seat_id()
  lib/settings.php     runs: loading, times, storno rules, check-in window, run_public()
  lib/cancellation.php cancellation_terms(), cancel_seats(), notices, reminders, GDPR purge
  lib/ticket.php       ticket QR code (sign/parse), qr_png(), ticket e-mail, seat_labels()
  lib/antispam.php     form token, rate limits, login limits
  lib/altcha.php       invisible ALTCHA: challenge, verification, replay protection
  lib/mail.php         deliver_mail() (SMTP via PHPMailer, else PHP mail()) and customer e-mails
  lib/scanner_access.php  scanner invites, device cookie, app_base_url()
db/schema.sql          full schema for a new database
db/migrations/0NN_*.sql  re-runnable upgrades of existing databases (002–009)
src/                   customer app
  App.jsx              views: run picker / map / section / reservation page; form; toasts
  hooks/useSeats.js    seat state of one run, polling, reserve()
  data/layout.js       sections, seat ids, occupancy levels, CZK formatting
  data/seatService.js  API client (seats, reservations, cancel)
  components/          RunPicker, Overview, SectionCard, SectionDetail, ReservationPanel,
                       ReservationForm, PaymentView
  altcha.js            invisible ALTCHA solver (altcha-lib, WebCrypto PBKDF2)
  runs.js, storno.js, plural.js   formatting helpers
src/scanner/           organizer scanner (ScannerApp, VipView, useQrCamera, ticket.js, api.js)
docs/DEVELOPER.md      this file
docs/prehled.html      stakeholder overview (Czech), published as an Artifact
```

## 3. Setup, build, deployment

```bash
# database
mariadb -e "CREATE DATABASE zidle CHARACTER SET utf8mb4"
mariadb -e "CREATE USER 'zidle'@'localhost' IDENTIFIED BY '…'; GRANT ALL ON zidle.* TO 'zidle'@'localhost'"
mariadb zidle < db/schema.sql
(cd api && composer install --no-dev)   # chillerlan/php-qrcode (needs GD), phpmailer/phpmailer, altcha-org/altcha
cp api/config.local.example.php api/config.local.php

npm install
php -S 127.0.0.1:8000 -t .   # API (dev)
npm run dev                  # Vite; /api is proxied to PHP_SERVER (default 127.0.0.1:8000)
npm run build                # dist/index.html, dist/scanner.html
```

Deployment: upload `dist/` and `api/` (including `api/vendor/`) side by side,
so the apps reach the API at `./api/`. HTTPS is required (camera access).
`.htaccess` files deny `config*.php`, `cron*.php`, `composer.*`, `api/lib/`,
`api/vendor/`. Behind a proxy/CDN the real client IP must reach
`REMOTE_ADDR` (e.g. Apache `mod_remoteip`), otherwise rate limits are shared.

Cron: `*/10 * * * * php /path/to/api/cron.php`.

Upgrading an existing database: run the not yet applied
`db/migrations/0NN_*.sql` in order. Migration 008 moves existing data into a
first run whose start is converted from the former event date with
`CONVERT_TZ(…, 'Europe/Prague', 'UTC')`, falling back to UTC+1 when MariaDB
has no time-zone tables – check that run's time in admin.

Build-time option: `VITE_API_URL` (default `api`) when the API is on another
origin; then set `CORS_ORIGIN`.

## 4. Configuration

`api/config.php` holds defaults; `api/config.local.php` (array) and
environment variables of the same name override them (local file wins).

| Key | Default | Meaning |
| --- | --- | --- |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | `127.0.0.1`, `3306`, `zidle`, `zidle`, `''` | PDO connection; session `time_zone = '+00:00'` |
| `SEAT_PRICE` | 300 | CZK per seat for new reservations |
| `PAYMENT_DEADLINE_HOURS` | 72 | due date = now + this, capped at the run start |
| `PAYMENT_GRACE_HOURS` | 48 | unpaid reservations expire this long after the due date |
| `REFUND_DAYS` | 14 | refund promise in e-mails |
| `DATA_RETENTION_DAYS` | 30 | personal data purged this long after the last run start |
| `MAX_SEATS_PER_RESERVATION` | 20 | also enforced in the UI |
| `RESERVATIONS_PER_IP_PER_HOUR` | 5 | |
| `PENDING_RESERVATIONS_PER_EMAIL` | 2 | unpaid reservations per e-mail **in one run** |
| `LOGIN_ATTEMPTS_PER_15_MIN` | 10 | admin login, scanner password and invite attempts, per IP |
| `FORM_MIN_SECONDS` | 3 | minimal age of the form token |
| `ALTCHA_ENABLED` | `true` | invisible ALTCHA on the reservation form |
| `ALTCHA_COST` | 1000 | PBKDF2 iterations per attempt |
| `ALTCHA_COUNTER_MAX` | 6000 | attempts needed: random `max/3 … max` (≈ 1–2 s on a computer, ≈ 4–5 s on a slow phone) |
| `BANK_IBAN` | **required** | reservations are refused (503) without it |
| `BANK_BIC` | `''` | appended to SPD `ACC` |
| `BANK_ACCOUNT_DISPLAY` | `''` | Czech account number shown to customers |
| `PAYMENT_RECIPIENT` | `Farnost` | SPD `RN` |
| `PAYMENT_MESSAGE` | `Moje zidle 2026` | SPD `MSG` prefix (+ last name) |
| `PAYMENT_SPECIFIC_SYMBOL` | `''` | SPD `X-SS`, digits only, max 10 |
| `ADMIN_PASSWORD` | **required** | admin.php returns 503 without it |
| `ORGANIZER_PASSWORD` | `''` | optional scanner master password (all runs); `''` = disabled |
| `SCAN_WINDOW_BEFORE_MINUTES` / `SCAN_WINDOW_AFTER_MINUTES` | 60 / 60 | check-in window around the run start |
| `TICKET_SECRET` | **required** | ≥ 16 chars; HMAC key for tickets, form tokens, master cookie; never change after tickets are sent |
| `MAIL_ENABLED` | `false` | all e-mails off when false |
| `MAIL_FROM` | `rezervace@example.com` | sender for PHP `mail()` (SMTP uses `SMTP_SENDER`) |
| `SMTP_HOST` | `''` | SMTP server; `''` = send with PHP `mail()` |
| `SMTP_PORT` | 587 | 465 = implicit TLS (SMTPS); other ports use STARTTLS when the server offers it |
| `SMTP_AUTH` | `true` | log in with `SMTP_SENDER` / `SMTP_PASSWORD` |
| `SMTP_SENDER` | `''` | sender address (`From`, name *Moje židle 2026*) and SMTP login |
| `SMTP_PASSWORD` | `''` | SMTP password |
| `PUBLIC_URL` | `''` | base URL for links in e-mails and invite links (else derived from the request) |
| `CORS_ORIGIN` | `''` | allowed origin when the apps run elsewhere |

## 5. Domain model

- **Run** (`runs`) – one performance. `starts_at`, optional `label`, optional
  `booking_closes_at`, `storno_rules` (JSON). Seats, reservations, VIP
  guests, check-in and scanner invites are per run.
- **Section / seat** – fixed layout, `SECTIONS` in `api/lib/layout.php` and
  `src/data/layout.js`:

  | Id | Name | Rows × seats | Capacity |
  | --- | --- | --- | --- |
  | WL | Levé křídlo | 4 × 6 | 24 |
  | ML | Levá hlavní | 10 × 8 | 80 |
  | MR | Pravá hlavní | 10 × 8 | 80 |
  | WR | Pravé křídlo | 6 × 6 | 36 |
  | BL | Balkon vlevo (rotated, row 1 right) | 4 × 12 | 48 |
  | BC | Balkon střed | 4 × 12 | 48 |
  | BR | Balkon vpravo (rotated, row 1 left) | 2 × 10 | 20 |
  | | total | | 336 |

  Seat id `SECTION-ROW-SEAT`, e.g. `ML-1-1`, `BC-4-12`.
- **Reservation** – one run, customer data, current seats, price, VS, status,
  history (cancelled seats, fees, refunds, check-in).
- **Held seat** – row in `reservation_seats` (`run_id`, `seat_id`) while the
  reservation is `pending` or `paid`.
- **VIP guest** – name, section, persons, note, per run; no seats, no payment.
- **Scanner invite** – named access for chosen runs.

## 6. Database

All `DATETIME` columns and JSON dates are UTC.

| Table | Purpose / key columns |
| --- | --- |
| `runs` | `id`, `label`, `starts_at`, `booking_closes_at` (NULL = start), `storno_rules` JSON `[{"from":"YYYY-MM-DDTHH:MM:SSZ","percent":50}]` |
| `reservations` | `id`, `token` (32 hex, unique), `run_id`, `first_name`, `last_name`, `email`, `seats` (CSV of current seats), `cancelled_seats` (CSV), `seat_count`, `amount` (price of current seats), `paid_amount`, `variable_symbol` (unique), `status` (`pending`/`paid`/`expired`/`cancelled`), `created_at`, `expires_at` (= due date shown to the customer), `paid_at`, `cancelled_at`, `cancelled_by` (`customer`/`admin`), `cancel_fee`, `refund_amount`, `refunded_amount`, `refunded_at`, `ticket_sent_at`, `reminder_sent_at`, `expiry_notice_sent_at`, `checked_in_at`, `checked_in_by` |
| `reservation_seats` | PK (`run_id`, `seat_id`), `reservation_id` (FK cascade). Prevents double booking per run. |
| `vip_guests` | `run_id`, `name`, `section`, `persons`, `note`, `created_at`, `checked_in_at`, `checked_in_by` |
| `scanner_invites` | `name`, `token_hash` (sha256, unique), `created_at`, `last_used_at`, `revoked_at` |
| `scanner_invite_runs` | PK (`invite_id`, `run_id`) |
| `rate_limits` | `bucket` (sha256 of key), `hits`, `window_start` |
| `settings` | reserved (unused) |

`runs()` rewrites legacy storno rule dates (Prague local `YYYY-MM-DD HH:MM`)
to UTC on load. Schema changes: update `db/schema.sql` and add a new
re-runnable migration.

## 7. Customer app

**Run picker** (`RunPicker.jsx`) is shown while no run is chosen
(`?termin=<id>` absent). Cards: weekday, date, time, label, free seats
(`"N volných míst"`), occupancy colour; disabled for `Vyprodáno` or
`Rezervace uzavřeny` (`bookingOpen` false). The chosen run is written to
`?termin=` (replaceState). A run missing from the list resets the choice.

**Map** (`Overview.jsx`, `SectionCard.jsx`): stage on top, `WL ML | MR WR`,
entrance at the bottom, balcony U (BL, BC, BR rotated). Card size =
seats × rows × `--u`; shows name (short on < 641 px) and occupancy %. Colour
by `occupancyLevel`: `< 50` low/green, `50–84` medium/amber, `≥ 85`
high/red. Red badge = number of own selected seats. Run bar above the map
with **Změnit termín** (asks `confirm()` when seats are selected; selection
is cleared on run change).

**Section detail** (`SectionDetail.jsx`): seat grid with row/seat numbers,
stage/entrance or balcony direction and railing, legend. Seat states:
`available` (light green), `selected` (red), `taken` (light red, disabled).

**Selection** (`useSeats(runId)`): local only; polling `seats.php?run=` every
30 s and on window focus; seats taken meanwhile are dropped with toast
`Místa … mezitím rezervoval někdo jiný.`; max `maxSeats` (`Najednou lze
rezervovat nejvýše N míst.`); when booking is closed seats cannot be
selected (`Rezervace na tento termín jsou uzavřeny.`).

**Top bar**: free seats, total price, **Rezervovat** (or `Rezervace uzavřeny`).
**Reservation panel** (`ReservationPanel.jsx`): selected seats per section,
remove chip, total, Reserve; shown on the map when something is selected and
in a section detail with own seats.

**Form** (`ReservationForm.jsx`): run, summary, first/last name, e-mail
(client validation), honeypot `hp`, invisible ALTCHA (solving starts when
the form opens; submit waits for it with the button text *Ověřuji…*; on an
`altcha` error it solves a new challenge and retries once), notes (due date, storno text from
`stornoText()`, GDPR sentence: *„Jméno a e-mail použijeme jen pro vyřízení
této rezervace a do N dnů po posledním představení je smažeme.“*), submit
**Rezervovat a zaplatit**. 409/403 close the form with a toast and refresh.

**Reservation page** (`PaymentView.jsx`, `/?r=<token>`): run, amount, status
chip; pending → SPD QR + copyable account/IBAN/VS/SS/amount + due date
(red overdue notice after it); paid → ticket QR; cancellation info
(`CancellationInfo`, also *„Platba dorazila až po představení.“* with the
refund for an expired reservation paid after its run) and cancel panel
(`CancelPanel`, §10).

## 8. Reservation lifecycle

```
pending ──"Zaplaceno" (admin)──────────────▶ paid
pending ──expires_at + GRACE passed────────▶ expired ──"Přijmout pozdní platbu"──▶ paid (if seats free)
pending / paid ──cancel (customer/admin)───▶ cancelled
```

`expire_reservations()` (every API request and cron): sets `expired` +
`cancelled_at` for `pending` with `expires_at <= now − PAYMENT_GRACE_HOURS`,
then deletes `reservation_seats` of `expired`/`cancelled` reservations.

**Create** (`POST reservations.php`), checks in order:

| Check | Response |
| --- | --- |
| `BANK_IBAN` set | 503 `Platby nejsou nastaveny (BANK_IBAN).` |
| run exists | 422 `Vyberte termín.` |
| `run_booking_open()` | 403 `Rezervace na tento termín jsou uzavřeny.` |
| honeypot empty, form token valid | 400 `Rezervaci se nepodařilo odeslat. Obnovte stránku a zkuste to znovu.` |
| ALTCHA payload valid and unused (if `ALTCHA_ENABLED`) | 400 `Ověření proti robotům se nezdařilo. Zkuste to prosím znovu.` + `code: "altcha"` |
| names, e-mail, 1–20 valid unique seats | 422 `Zkontrolujte zadané údaje.` / seat message, `fields` |
| IP rate limit | 429 `Příliš mnoho rezervací z tohoto zařízení. Zkuste to prosím později.` |
| < 2 pending for the e-mail in this run | 429 `Na tento e-mail už na toto představení čekají nezaplacené rezervace. Nejdříve je prosím uhraďte.` |
| seats free in the run (`FOR UPDATE`; duplicate key fallback) | 409 `Některá místa už mezitím někdo rezervoval.` + `conflict` |

Then in one transaction: insert reservation (`expires_at = min(now +
PAYMENT_DEADLINE_HOURS, run start)`), random unique 10-digit VS
(`generate_variable_symbol()`), insert seats; send payment e-mail; respond
201 with `reservation_payload()`.

## 9. Payments

- **SPD** (`spd_string()`): `SPD*1.0*ACC:<IBAN>[+BIC]*AM:<amount>*CC:CZK*X-VS:<VS>*DT:<due date Prague YYYYMMDD>*MSG:<PAYMENT_MESSAGE + last name, ASCII, ≤60>[*X-SS:<SS>][*RN:<recipient ≤35>]`.
- Payments are matched manually by VS. Admin **Zaplaceno** sets `paid`,
  `paid_at`, `paid_amount = amount` and sends the ticket.
- Reminder 24 h before the due date, expiry notice after expiry (§16).
- **Late payment** (`accept_late_payment()`, admin action `paid-late`): only
  `expired` without `paid_at`.
  - run not started yet: re-inserts the seats if all are free in the run
    (else message naming the taken seats), sets `paid`, sends the ticket
    (button *Přijmout pozdní platbu*);
  - run already started: the reservation stays `expired`; sets `paid_at`,
    `paid_amount = amount`, `cancel_fee = 0`, `refund_amount = amount` (shows
    in refunds due) and sends *Platba po představení* (button *Platba po
    představení – vrátit*).

## 10. Cancellation and refunds

`cancellation_terms($r)` (in every reservation payload): `allowed` false
(`inactive`, `checked_in`, `event_started` = run started); else `percent`
(`storno_percent(run, now)` for paid, 0 for pending), `seatPrice`, `fee`,
`refund` for all seats.

Storno rules (per run): before the first rule 0 %; from each rule's `from`
its percent applies, the highest started rule wins.
`fee = round(seatPrice × count × percent / 100)`.

`cancel_seats(id, seatIds|null, by)`:

| | customer (`cancel.php`) | admin |
| --- | --- | --- |
| allowed when | `cancellation_terms().allowed` | status pending/paid |
| fee | storno percent | 0 |
| refund | value − fee | full value |

- seats must belong to the reservation (`Vyberte místa z této rezervace.`);
- all remaining seats → status `cancelled`, `cancelled_by`, seats deleted;
- part → `seats`, `seat_count`, `amount` reduced, `cancelled_seats` appended;
- paid: `cancel_fee` and `refund_amount` accumulate; refunds go to the
  paying account (no account is collected);
- admin **Vráceno** sets `refunded_amount = refund_amount`, `refunded_at`;
  due = `refund_amount − refunded_amount`.

E-mails (`send_cancellation_notice()`): paid part → *Nová vstupenka* (ticket
e-mail with intro: cancelled seats, fee, refund line, *„Původní vstupenka už
neplatí.“*); pending part → *Změna rezervace* (new payment details); whole →
*Rezervace zrušena* with fee/refund line; pending whole by admin → *Rezervace
zrušena* + *„Pokud jste platbu už odeslali, pošleme Vám ji zpět na účet, ze
kterého přišla.“*
Refund line: *„Částku X Kč Vám do 14 dnů pošleme zpět na účet, ze kterého
platba přišla.“*

## 11. Tickets and check-in

- **Code** (`ticket_code()`): `Z26|<reservation id>|<VS>|<seat count>|<name>|<seats CSV>|<sig>`;
  `sig` = first 22 chars of base64url HMAC-SHA256(`TICKET_SECRET`, payload).
  `parse_ticket_code()` also accepts legacy 6-part codes without the id.
  JS mirror: `src/scanner/ticket.js` (display/offline only).
- Ticket e-mail: inline PNG (`qr_png()`), run, name, seats, VS; resend in
  admin. Paid reservation page shows the same QR.
- **Verify** (`organizer.php` `verify {code, runId, confirmOutside}`): access
  to run required (403 `Na tento termín nemáte přístup.`); load reservation
  by id + VS (legacy: VS) `FOR UPDATE`; result:

  | result | when | check-in recorded |
  | --- | --- | --- |
  | `invalid` | bad signature/format or not found | no |
  | `wrong_run` | pending/paid of another run | no |
  | `used` | paid, already checked in | no |
  | `outside_window` | paid, first scan, outside window, not confirmed | no |
  | `valid` | paid, first scan | yes (`checked_in_at`, `checked_in_by`) |
  | `unpaid` / `cancelled` | status pending / expired or cancelled | no |

  Response contains current DB data (seats, count, name, run label,
  `changed` when seats differ from the code, `checkedInBy`).
- **Scanner UI** results: `Platná vstupenka`, `Už odbaveno` (+ first time),
  `Nezaplaceno`, `Rezervace zrušena`, `Neplatný kód`, `To je platební QR kód`
  (code starts with `SPD*`), `Jiný termín` (+ ticket run),
  `Mimo čas odbavení – neodbaveno`, `Neověřeno – bez spojení` (offline).
  Camera: rear camera, centre square decoded by `jsqr` every 120 ms, torch
  when supported, stopped while the page is hidden.
- **Check-in window**: `run_scan_window()` = start − BEFORE … start + AFTER.
  Default run in the scanner: run whose window is open, else remembered
  (localStorage `zidle-scanner-run`), else next upcoming, else last. Outside
  the window the camera is off and `WindowWarning` offers switching to an
  open run or **Přesto odbavovat tento termín** (confirmation kept until
  reload; state re-checked every 30 s); the server enforces the same.

## 12. Scanner access (invites)

- Admin creates an invite (name + runs) → `save_invite()` returns a
  48-hex token, only `sha256` is stored; link
  `app_base_url()/scanner.html?pozvanka=<token>` and QR are shown once.
- `invite {token}` → cookie `zidle_scanner = "i:<token>"` (HttpOnly,
  SameSite=Strict, Secure on HTTPS, path = API dir, 30 days). The scanner
  removes `pozvanka` from the URL.
- Every request: `scanner_access()` looks up the token hash (not revoked),
  updates `last_used_at`, loads allowed runs. Revocation or a new link
  takes effect immediately (401 `Přihlaste se pozvánkou od správce.`).
- Master password (if `ORGANIZER_PASSWORD`): cookie
  `"m:<expiry>:<hmac>"`, all runs, name `hlavní heslo`.
- Errors: invalid invite 401 `Pozvánka neplatí – je zrušená nebo nahrazená
  novou. Požádejte správce o nový odkaz.`; password disabled 403.

## 13. VIP guests

Admin VIP tab: add (run, name, section, persons 1–50, note), delete,
reset arrival; filter by run. Scanner VIP mode: list of the selected run
(refresh 20 s), diacritics/word-order-insensitive search over name + note,
section filter, **Vpustit** (records `checked_in_at`/`by`; first wins),
**Vrátit**; outside the window without confirmation 409 `Termín je mimo čas
odbavení. Potvrďte odbavení mimo čas.`; empty search result *„Není na
seznamu VIP“*. VIP guests do not hold seats.

## 14. Admin

`api/admin.php`, session login with `ADMIN_PASSWORD` (CSRF token on every
POST, login rate-limited). Tabs:

- **Rezervace** – cards per status (pending/paid), refunds due, per run
  occupancy; filters run/status (incl. `refund`)/search (VS, name, e-mail,
  seat). Actions: `paid`, `paid-late`, `ticket` (send/resend), `cancel`,
  `cancel-seats`, `refunded`, `email` (change).
- **VIP** – `vip-add`, `vip-delete`, `vip-reset`.
- **Pořadatelé** – `invite-save` (create / edit name+runs / new link),
  `invite-revoke`.
- **Nastavení** – runs: `run-save` (start required, label, booking cut-off
  before start, storno rows), `run-delete` (only without reservations/VIP;
  removes invite links to the run). Shows the GDPR deletion date.
  **Lock:** once a run has any reservation (`run_has_reservations()`, any
  status), its start and storno rules can no longer be changed – the form
  shows them disabled with *„Představení už má rezervace – začátek a storno
  podmínky nelze měnit (zákazníci rezervovali za těchto podmínek).“*; a POST
  containing `starts_at` or `rule_from` is rejected with *„Představení už má
  rezervace – začátek a storno podmínky nelze měnit.“* Label and booking
  cut-off stay editable. Hence storno terms and the run time never change for
  existing customers.

`expire_reservations()` runs on every admin render.

## 15. E-mails

Only when `MAIL_ENABLED`. All mail goes through `deliver_mail(to, subject,
text, html?, inline[])` (PHPMailer, UTF-8): with `SMTP_HOST` via SMTP
(`SMTP_PORT`, `SMTP_AUTH`, login `SMTP_SENDER` / `SMTP_PASSWORD`; port 465 =
SMTPS, otherwise opportunistic STARTTLS; connect timeout and per-reply limit
15 s), otherwise PHP `mail()` from `MAIL_FROM`. Sender name *Moje židle 2026*.
Failures return false and are logged as `[zidle] Mail to … failed: <reason>`;
mail is sent synchronously within the request. Texts are plain
(`send_customer_email()`) except the ticket (HTML + text alternative with the
QR as inline image `cid:ticket-qr`). All contain `Termín: <run>`.

| Subject | Trigger |
| --- | --- |
| Rezervace míst | reservation created (payment details, link if `PUBLIC_URL`) |
| Připomínka platby | cron: pending, due within 24 h, created ≥ 24 h before due, once |
| Rezervace zrušena | cron: expired within the last 3 days, once |
| Vstupenka | Zaplaceno / late payment / resend |
| Nová vstupenka | partial cancellation of a paid reservation |
| Změna rezervace | partial cancellation of a pending reservation |
| Rezervace zrušena | whole cancellation (customer or admin) |
| Platba po představení | admin records a late payment for a run that already started |

## 16. Scheduled jobs and GDPR

`php api/cron.php`: `expire_reservations()`, `send_due_notifications()`,
`purge_personal_data()`, `cleanup_rate_limits()`; prints a summary line.
Purge after `last_run_start() + DATA_RETENTION_DAYS`: empties first/last
name and e-mail of all reservations, deletes all VIP guests; VS, amounts,
seats and statuses stay.

## 17. Spam and abuse protection

- Honeypot field `hp` (hidden from people and assistive tech).
- Invisible ALTCHA (`api/lib/altcha.php`, `src/altcha.js`, protocol v2,
  `PBKDF2/SHA-256`): `altcha.php` issues a signed challenge (HMAC secrets
  derived from `TICKET_SECRET`, expires in 15 min, deterministic counter with
  key signature for fast verification; 60 challenges/h per IP). The browser
  solves it in the background (`altcha-lib`, WebCrypto) and sends the base64
  payload `{challenge, solution}` as `altcha`. The server verifies signature,
  expiry and derived key, and accepts each challenge signature only once
  (`rate_limit('altcha|<signature>', 1, …)`). The frontend re-solves a
  payload older than 12 min.
- Form token from `seats.php`: `<unix time>.<hmac>` (secret derived from
  `TICKET_SECRET`), valid from `FORM_MIN_SECONDS` to 12 h.
- `rate_limit(key, max, window)` fixed windows in `rate_limits` (keys
  hashed): reservations per IP (counted after validation), cancellations
  20/h per IP, logins/invites per IP.
- Pending reservations per e-mail and run.

## 18. HTTP API

| Method | Endpoint | Body / query | Response |
| --- | --- | --- | --- |
| GET | `seats.php?run=<id>` | – | `runs[]` (id, label, startsAt, bookingClosesAt, bookingOpen, free, stornoRules), `runId`, `taken[]`, `price`, `deadlineHours`, `maxSeats`, `bookingOpen`, `formToken`, `altcha` (enabled), `dataRetentionDays` |
| GET | `altcha.php` | – | `{enabled, challenge}` (ALTCHA v2 challenge: `parameters`, `signature`) |
| POST | `reservations.php` | `runId, firstName, lastName, email, seats[], formToken, hp, altcha` | 201 reservation payload |
| GET | `reservations.php?token=` | – | reservation payload (see `reservation_payload()`: status, seats, amounts, run, payment {iban, account, recipient, variableSymbol, specificSymbol, amount, spd}, ticket, cancellation, refunds) |
| POST | `cancel.php` | `token, seats?` | reservation payload |
| GET | `organizer.php` | – | `loggedIn, name, passwordLogin, runs[]` (+ `scanFrom`, `scanTo`) |
| POST | `organizer.php` | `action`: `invite {token}`, `login {password}`, `logout`, `verify {code, runId, confirmOutside?}`, `vip-list {runId}`, `vip-checkin {id, runId, confirmOutside?}`, `vip-undo {id, runId}` | see §11–13 |

Errors: `{"error": "<Czech message>", …}` with HTTP status; uncaught
exceptions are logged (`[zidle]`) and return 500 `Chyba serveru. Zkuste to
prosím znovu.`

## 19. Formats

- Seat id: `^[A-Z]{2}-\d{1,2}-\d{1,2}$`, validated against `SECTIONS`.
- VS: random 10 digits (first digit non-zero), unique.
- Ticket: §11. Payment: §9.
- Invite token: 48 hex; device cookie values §12.
- Times in API responses: ISO 8601 UTC with `Z`.

## 20. Conventions

See `CLAUDE.md`: docs in sync with code, UTC in DB, mobile-first customer
CSS / desktop-first admin / mobile-only scanner, duplicated layout and
ticket format kept identical, schema + migration for DB changes, Czech UI.
