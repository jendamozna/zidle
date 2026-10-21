# Vstupenky na TZG 2026 – developer documentation

Technical reference of the application. It describes the code **1:1** –
every change of behaviour, texts, limits, API, database or formats must
update this file in the same commit (see `CLAUDE.md`). The light Czech
overview for stakeholders lives in `docs/prehled/` and is published as
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
13. [Discount codes](#13-discount-codes)
14. [Admin](#14-admin)
15. [E-mails](#15-e-mails)
16. [Scheduled jobs and GDPR](#16-scheduled-jobs-and-gdpr)
17. [Spam and abuse protection](#17-spam-and-abuse-protection)
18. [HTTP API](#18-http-api)
19. [Formats](#19-formats)
20. [Conventions](#20-conventions)
21. [Tests and CI](#21-tests-and-ci)

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
  admin.php            admin UI (login, invitation page, reservations, discount codes, scanner invites, accountants, settings)
  seats.php            GET runs + taken seats of a run + seating layout
  tickets.php          GET printable tickets (PDF) of a paid reservation
  altcha.php           GET invisible ALTCHA challenge
  reservations.php     POST create reservation, GET reservation by token
  cancel.php           POST customer cancellation
  organizer.php        scanner API (access, verify, offline, on-site sale)
  cron.php             scheduled jobs (CLI only); cron-expire.php = alias
  config.php           defaults (+ config.local.php / environment / api/.env overrides); .env.example
  lib/bootstrap.php    config(), db(), db_query(), JSON helpers (json_response/json_error end the request), expire_reservations(), QR payment, reservation_payload()
  lib/layout.php       LEVELS, PLAN, SECTIONS (the only layout definition), layout_public(), total_capacity(),
                       section_rows(), section_capacity(), reserve_section_ids(), seat_is_reserve(), section_name(),
                       is_valid_seat_id(), compare_seat_ids()
  lib/settings.php     runs: loading, times, storno rules, open sections (run_capacity()), check-in window,
                       run_public(); payment deadline rules
  lib/pricing.php      adult / child seats, discount-free seats, seat_value(), reservation_seat_items()
  lib/discounts.php    discount codes: create + e-mail, revoke, lock for a reservation
  lib/booking.php      book_reservation() (prices, codes, seats, VS, first e-mail), generate_variable_symbol()
  lib/checkin.php      verify_ticket() – online check-in of one seat ticket
  lib/onsite.php       selling at the entrance (scanner tab Prodej)
  lib/cancellation.php cancellation_terms(), cancel_seats(), notices, reminders, GDPR purge
  lib/payments.php     record_payment() (received transfers), amount_due(), send_ticket_for()
  lib/offline.php      scanning without a connection: scanner_snapshot(), apply_offline_scans(), conflicts
  lib/admin_users.php  admin accounts: admin_login(), admin_invite(), admin_accept_invite(), admin_set_disabled()
  lib/ticket.php       seat ticket QR code (sign/parse), qr_png(), ticket e-mail + calendar event (ticket_ics()), seat_label(s)()
  lib/ticket_pdf.php   printable tickets vstupenky.pdf (dompdf)
  lib/antispam.php     form token, rate limits, login limits
  lib/altcha.php       invisible ALTCHA: challenge, verification, replay protection
  lib/mail.php         deliver_mail() (SMTP via PHPMailer, else PHP mail()) and customer e-mails
  lib/scanner_access.php  scanner invites, device cookie, app_base_url()
db/schema.sql          full schema for a new database
db/migrations/0NN_*.sql  re-runnable upgrades of existing databases (002–015)
src/                   customer app
  App.jsx              views: run picker / map / section / reservation page; form; toasts
  hooks/useSeats.js    seat state of one run, polling, reserve()
  data/layout.js       layout loaded from the server (setLayout()), seat ids and positions, occupancy levels, CZK formatting
  data/seatService.js  API client (seats, reservations, cancel)
  components/          RunPicker, Overview (map), SectionDetail, SeatPlan (seats of a section, also in the
                       scanner), ReservationPanel, ReservationForm, PaymentView, Stepper
  pricing.js           seat prices / discount preview (mirror of lib/pricing.php), code formatting
  altcha.js            invisible ALTCHA solver (altcha-lib, WebCrypto PBKDF2)
  runs.js, storno.js, plural.js   formatting helpers
src/scanner/           organizer scanner (ScannerApp, SaleView, useQrCamera, ticket.js, api.js,
                       offline.js + useOffline.js = offline mode)
public/sw.js           service worker: keeps the scanner on the device for offline start
docs/DEVELOPER.md      this file
docs/prehled/          stakeholder overview (Czech, index.html + screenshots), published as an Artifact
tests/php/run.php      PHP integration tests (needs a *_test database); fixtures.php = shared helpers
tests/e2e/             Playwright tests (customer, admin, scanner), seed.php, global-setup.js
tests/consistency.mjs  layout and ticket format identical in PHP and JS
phpstan.neon           PHPStan config (level 8)
eslint.config.js       ESLint config (src/ + tests)
playwright.config.js   Playwright config (starts php -S and vite preview)
.github/workflows/ci.yml  CI
```

## 3. Setup, build, deployment

```bash
# database
mariadb -e "CREATE DATABASE zidle CHARACTER SET utf8mb4"
mariadb -e "CREATE USER 'zidle'@'localhost' IDENTIFIED BY '…'; GRANT ALL ON zidle.* TO 'zidle'@'localhost'"
mariadb zidle < db/schema.sql
(cd api && composer install --no-dev)   # chillerlan/php-qrcode (needs GD), phpmailer/phpmailer, altcha-org/altcha, dompdf/dompdf
cp api/config.local.example.php api/config.local.php   # and/or: cp api/.env.example api/.env

npm install
php -S 127.0.0.1:8000 -t .   # API (dev)
npm run dev                  # Vite; /api is proxied to PHP_SERVER (default 127.0.0.1:8000)
npm run build                # dist/index.html, dist/scanner.html
```

Deployment: upload `dist/` and `api/` (including `api/vendor/`) side by side,
so the apps reach the API at `./api/`. HTTPS is required (camera access).
`.htaccess` files deny `.env*`, `config*.php`, `cron*.php`, `composer.*`, `api/lib/`,
`api/vendor/`. Behind a proxy/CDN the real client IP must reach
`REMOTE_ADDR` (e.g. Apache `mod_remoteip`), otherwise rate limits are shared.

Cron: `*/10 * * * * php /path/to/api/cron.php`.

Checks before a commit (the same as CI, §21): `npm run lint`, `npm run build`,
`npm run test:consistency`, `api/vendor/bin/phpstan analyse -c phpstan.neon`,
`DB_NAME=zidle_test php tests/php/run.php`, `npm run test:e2e`.

Upgrading an existing database: run the not yet applied
`db/migrations/0NN_*.sql` in order. Migration 008 moves existing data into a
first run whose start is converted from the former event date with
`CONVERT_TZ(…, 'Europe/Prague', 'UTC')`, falling back to UTC+1 when MariaDB
has no time-zone tables – check that run's time in admin. Migration 013
adds seat kinds, discount codes, per-seat check-ins and on-site sales.
Migration 014 removes VIP guests: their held seats are released and the
table dropped (free seats are given with discount codes, §13). Migration 015
adds `runs.open_reserves` for the new layout (§5); seat ids of the former
layout that no longer exist (e.g. `ML-10-1`) stay in old reservations but
are not valid for new ones.

Build-time option: `VITE_API_URL` (default `api`) when the API is on another
origin; then set `CORS_ORIGIN`.

## 4. Configuration

`api/config.php` holds defaults; they are overridden by, first wins:
`api/config.local.php` (array), environment variables of the same name, and
`api/.env` (not committed; template `api/.env.example` with the SMTP / e-mail
keys). `.env` format: `KEY=value` per line, `#` comments (also after an
unquoted value), optional `export ` prefix, `"…"` (backslash escapes) or
`'…'` quoting; any key of the table below can be set there.

| Key | Default | Meaning |
| --- | --- | --- |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | `127.0.0.1`, `3306`, `zidle`, `zidle`, `''` | PDO connection; session `time_zone = '+00:00'` |
| `SEAT_PRICE` | 300 | CZK per adult seat (from 18 years) for new reservations |
| `CHILD_SEAT_PRICE` | 150 | CZK per child seat (4–17 years); children up to 3 years sit on a lap for free (no seat, no ticket) |
| `PAYMENT_DEADLINE_HOURS` | 72 | hours to pay before the first payment deadline rule (§8); due date capped at the run start |
| `PAYMENT_GRACE_HOURS` | 48 | unpaid reservations expire this long after the due date |
| `REFUND_DAYS` | 14 | refund promise in e-mails |
| `DATA_RETENTION_DAYS` | 30 | personal data purged this long after the last run start |
| `MAX_SEATS_PER_RESERVATION` | 20 | also enforced in the UI |
| `EVENT_LOCATION` | `''` | `LOCATION` of the calendar event in the ticket e-mail (`udalost.ics`); `''` = omitted |
| `EVENT_DURATION_MINUTES` | 120 | calendar event end = run start + this (min. 1) |
| `RESERVATIONS_PER_IP_PER_HOUR` | 5 | used when ALTCHA is disabled |
| `RESERVATIONS_PER_IP_PER_HOUR_ALTCHA` | 30 | used when ALTCHA is enabled (each reservation costs proof-of-work; lets several people book from one shared network) |
| `ALTCHA_CHALLENGES_PER_IP_PER_HOUR` | 300 | challenges issued by `altcha.php` per IP |
| `PENDING_RESERVATIONS_PER_EMAIL` | 2 | unpaid reservations per e-mail **in one run** |
| `LOGIN_ATTEMPTS_PER_15_MIN` | 10 | admin login, admin invitation (password set), scanner password and invite attempts, per IP and area |
| `FORM_MIN_SECONDS` | 3 | minimal age of the form token |
| `ALTCHA_ENABLED` | `true` | invisible ALTCHA on the reservation form |
| `ALTCHA_COST` | 1000 | PBKDF2 iterations per attempt |
| `ALTCHA_COUNTER_MAX` | 6000 | attempts needed: random `max/3 … max` (≈ 1–2 s on a computer, ≈ 4–5 s on a slow phone) |
| `BANK_IBAN` | **required** | reservations and on-site sales are refused (503) without it |
| `BANK_BIC` | `''` | appended to SPD `ACC` |
| `BANK_ACCOUNT_DISPLAY` | `''` | Czech account number shown to customers |
| `PAYMENT_RECIPIENT` | `Farnost` | SPD `RN` |
| `PAYMENT_MESSAGE` | `Vstupenky TZG 2026` | SPD `MSG` prefix (+ last name) |
| `PAYMENT_SPECIFIC_SYMBOL` | `''` | SPD `X-SS`, digits only, max 10 |
| `ADMIN_PASSWORD` | `''` | master admin login (empty e-mail); needed for the first sign-in – admin.php returns 503 *„Nastavte ADMIN_PASSWORD v config.local.php.“* while it is empty and no active account has a password; can be removed once accountants are invited |
| `ORGANIZER_PASSWORD` | `''` | optional scanner master password (all runs); `''` = disabled |
| `SCAN_WINDOW_BEFORE_MINUTES` / `SCAN_WINDOW_AFTER_MINUTES` | 60 / 60 | check-in window around the run start |
| `TICKET_SECRET` | **required** | ≥ 16 chars; HMAC key for tickets, form tokens, master cookie; never change after tickets are sent |
| `MAIL_ENABLED` | `false` | all e-mails off when false |
| `MAIL_FROM` | `rezervace@example.com` | sender for PHP `mail()` (SMTP uses `SMTP_SENDER`) |
| `SMTP_HOST` | `''` | SMTP server; `''` = send with PHP `mail()` |
| `SMTP_PORT` | 587 | 465 = implicit TLS (SMTPS); other ports use STARTTLS when the server offers it |
| `SMTP_AUTH` | `true` | log in with `SMTP_USER` (or `SMTP_SENDER`) / `SMTP_PASSWORD` |
| `SMTP_SENDER` | `''` | sender address (`From`, name *Vstupenky na TZG 2026*) |
| `SMTP_USER` | `''` | SMTP login when it differs from the sender (e.g. `apikey`); `''` = `SMTP_SENDER` |
| `SMTP_PASSWORD` | `''` | SMTP password |
| `CONTACT_EMAIL` | `''` | organizers' contact: website footer, last line of every e-mail, `Reply-To` of e-mails |
| `CONTACT_PHONE` | `''` | organizers' phone: website footer and e-mails |
| `PUBLIC_URL` | `''` | base URL for links in e-mails and invite links (else derived from the request) |
| `CORS_ORIGIN` | `''` | allowed origin when the apps run elsewhere |

## 5. Domain model

- **Run** (`runs`) – one performance. `starts_at`, optional `label`, optional
  `booking_closes_at`, `storno_rules` (JSON), `open_reserves` (sections whose
  reserve seats are for sale). Seats, reservations, check-in and scanner
  invites are per run.
- **Section / seat** – fixed layout, defined only in `api/lib/layout.php`
  after the hand-drawn plan of the church: `LEVELS` (main *Hlavní loď* /
  balcony *Balkon*), `PLAN` (per level a map 100 units wide, `height` units
  tall, with decorations `items`: main – *Pódium* on top, *Kapela* box above
  the left wing; balcony – the open middle `void` drawn as a U with *↑ k
  pódiu*) and `SECTIONS` id → name, short name, level, `label` [x, y, align]
  (where the name stands on the map) and `rows`: one `[seats, x1, y1, x2, y2]`
  per row (row 1 first – nearest the stage or the balcony railing; the first
  seat at (x1, y1), the last at (x2, y2), the others evenly between, so rows
  may be horizontal, vertical or slanted); a row split by a gap is `['parts'
  => [[seats, x1, y1, x2, y2], …]]` (numbering continues across the parts);
  `'reserve' => true` marks a row of reserve seats. `section_rows()` brings
  both forms to `{seats, reserve, parts}`. The apps
  get it as `layout` from `seats.php` (customer) and `GET organizer.php`
  (scanner): `layout_public()` = `{levels, plan, sections: [{id, name, short,
  level, label: {x, y, align}, rows: [{seats, reserve, parts: [{seats, x1, y1,
  x2, y2}]}]}]}`
  in `SECTIONS` order. `src/data/layout.js` holds no data: `setLayout(layout)`
  fills its live exports `LEVELS`, `PLAN`, `SECTIONS`, `SECTION_BY_ID`,
  `TOTAL_CAPACITY` before the first map/scanner view is drawn (`useSeats` on
  every `seats.php` response, `ScannerApp` from the session or the cached
  one); `seatPositions(section)` (with `reserve` per seat), `reserveSeatIds()`
  and `seatSpacing(section)` derive the seat positions:

  | Id | Name (short) | Level | Seats per row (row 1 first) | Capacity |
  | --- | --- | --- | --- | --- |
  | WL | Levé křídlo (L. křídlo) – below the band | main | 5, 5, 5, 5 | 20 |
  | ML | Levá hlavní (L. hlavní) | main | 9 × 8 | 72 |
  | MR | Pravá hlavní (P. hlavní) | main | 9 × 8 | 72 |
  | WR | Pravé křídlo (P. křídlo) | main | 4, 4, 4, 5, 5, 5, 4 (the last row set apart) | 31 |
  | BL | Balkon vlevo (Balkon L) | balcony | 12 (bench along the railing, seats 1–5 and 6–12 split by a gap); reserve rows 2–6: 5, 5, 6, 6, 6 | 40 (28 reserve) |
  | BC | Balkon střed (Balkon S) | balcony | 7, 14, 14, 7, 7 (rows 4–5 shifted by 5 seats) | 49 |
  | BR | Balkon vpravo (Balkon P) | balcony | 6 (vertical), 5 (slanted); reserve rows 3–4: 3, 3 | 17 (6 reserve) |
  | | total (main 195, balcony 106) | | | 301 |

  Seat id `SECTION-ROW-SEAT`, e.g. `ML-1-1`, `BC-2-14`; `is_valid_seat_id()`
  checks the row and the seat count of that row.
- **Reserve seats** („Záložní“; reserve rows of `BL` and `BR` –
  `reserve_section_ids()`, `section_reserve_capacity()`): shown on the map but
  not for sale until the admin opens the section's reserve seats for a run
  (Nastavení, §14; e.g. when the rest of the balcony is full).
  `run_closed_reserves(run)`, `seat_closed_in_run(run, seat)`,
  `run_capacity(run)` = seats for sale (267 with both closed). Closed reserve
  seats cannot be booked or sold on site (§8).
- **Reservation** – one run, customer data, current seats, price, VS, status,
  history (cancelled seats, fees, refunds, check-in). Each seat is an
  **adult** (from 18, `adult_price`) or **child** seat (4–17, `child_price`;
  listed in `child_seats`); the prices are stored on the reservation when it
  is made. Children up to 3 years may sit on an adult's lap for free; they
  are not recorded (the form only says so). Seats paid by a discount code are in `free_seats` (value 0).
  `seat_value()` = 0 for a free seat, else the kind's price; `amount` = sum
  over the current seats.
- **Ticket** – one per seat (1 QR code = 1 chair, §11); checked in per seat
  (`ticket_checkins`).
- **Discount code** – sent by the admin to an e-mail; any run, used once,
  makes the most expensive seat of the reservation free (§8).
- **On-site sale** – reservation sold at the entrance by an organizer
  (`sold_by`, §9).
- **Held seat** – row in `reservation_seats` (`run_id`, `seat_id`) while the
  reservation is `pending` or `paid`.
- **Money on a reservation** – `paid_amount` = everything received,
  `refund_amount` = everything to send back, `refunded_amount` = already sent
  back; kept = `paid_amount − refund_amount`; still to pay
  (`amount_due()`) = `amount − kept` (≥ 0).
- **Scanner invite** – named access for chosen runs.

## 6. Database

All `DATETIME` columns and JSON dates are UTC.

| Table | Purpose / key columns |
| --- | --- |
| `runs` | `id`, `label`, `starts_at`, `booking_closes_at` (NULL = start), `storno_rules` JSON `[{"from":"YYYY-MM-DDTHH:MM:SSZ","percent":50}]`, `open_reserves` JSON `["BL"]` (sections whose reserve seats are for sale) |
| `reservations` | `id`, `token` (32 hex, unique), `run_id`, `first_name`, `last_name`, `email`, `seats` (CSV of current seats), `cancelled_seats` (CSV), `child_seats` (CSV, child tickets incl. cancelled ones), `free_seats` (CSV, paid by discount codes), `seat_count`, `adult_price`, `child_price` (CZK per seat when booked; migration 013 sets both to `amount / seat_count` for older rows), `amount` (price of current seats), `paid_amount` (sum of all received transfers), `variable_symbol` (unique), `status` (`pending`/`paid`/`expired`/`cancelled`), `created_at`, `expires_at` (= due date shown to the customer), `paid_at`, `cancelled_at`, `cancelled_by` (`customer`/`admin`), `cancel_fee`, `refund_amount`, `refunded_amount`, `refunded_at`, `ticket_sent_at`, `reminder_sent_at`, `expiry_notice_sent_at`, `checked_in_at` / `checked_in_by` (first seat checked in), `sold_by` (organizer of an on-site sale, NULL = website), `payment_checked_at` (accountant verified an on-site payment) |
| `ticket_checkins` | PK (`reservation_id`, `seat_id`), `checked_in_at`, `checked_in_by`; FK cascade. One row per checked-in seat ticket (migration 013 adds all seats of reservations checked in before). |
| `discount_codes` | `code` (8 chars, unique, shown `XXXX-XXXX`), `email`, `created_at`, `created_by`, `emailed_at`, `used_at`, `reservation_id`, `seat_id` (the seat made free), `revoked_at` |
| `reservation_seats` | PK (`run_id`, `seat_id`), `reservation_id` (FK cascade). Prevents double booking per run. |
| `scanner_invites` | `name`, `token_hash` (sha256, unique), `created_at`, `last_used_at`, `revoked_at` |
| `scanner_invite_runs` | PK (`invite_id`, `run_id`) |
| `scan_conflicts` | offline check-ins that could not be applied (§11): `run_id`, `reservation_id` (NULL when unknown), `label` (VS + name + seat), `reason` (`already_checked_in` / `not_paid` / `unknown`), `scanned_at` (device time), `scanned_by`, `other_at` / `other_by` (the check-in that won), `created_at` |
| `rate_limits` | `bucket` (sha256 of key), `hits`, `window_start` |
| `settings` | `name`, `value` (JSON): `payment_deadlines` = `[{"from":"YYYY-MM-DDTHH:MM:SSZ","hours":24}]` (§8) |
| `admin_users` | admin accounts (§14): `email` (unique, lower case, login), `name`, `password_hash` (`password_hash()`, NULL until the invitation is accepted), `invite_hash` (sha256 of the open link token, unique), `invite_expires_at`, `invited_by` (name), `created_at`, `accepted_at`, `last_login_at`, `disabled_at` |

`runs()` rewrites legacy storno rule dates (Prague local `YYYY-MM-DD HH:MM`)
to UTC on load. Schema changes: update `db/schema.sql` and add a new
re-runnable migration.

## 7. Customer app

**Run picker** (`RunPicker.jsx`) is shown while no run is chosen
(`?termin=<id>` absent). Cards: weekday, date, time, label, free seats
(`"N volných míst"`), occupancy colour (`capacity` = seats for sale in the run); disabled for `Vyprodáno` or
`Rezervace uzavřeny` (`bookingOpen` false). The chosen run is written to
`?termin=` (replaceState). A run missing from the list resets the choice.

Until the first `seats.php` answer (layout) the app shows *Načítám…*.

**Map** (`Overview.jsx`): one card per level (*Balkon* with its heading),
each a `LevelMap` drawn from `PLAN` and the section rows with % positions
(aspect ratio 100 : `height`): decorations (stage, band, U-shaped balcony
void), every seat as a small square (white = free, light red = taken, red =
own selection, grey dashed = reserve seat not for sale; size = 0.78 × the
section's seat spacing), the section's area as a button coloured by `occupancyLevel` (`< 50`
low/green, `50–84` medium/amber, `≥ 85` high/red) that opens the section, and
the label (short name + *N volných*, red count of own seats). Run bar above the map
(one line: run, **Změnit termín**; on phones < 641 px the words *Termín* /
*termín* are hidden – `.wide-only`) (asks `confirm()` when seats are
selected; selection is cleared on run change).

**Section detail** (`SectionDetail.jsx`): one header row – back button (**←**,
*Zpět na mapu* shown from 641 px), level + section name, chip *N volných*
coloured by occupancy; *Pódium* (main floor) or *↑ Směr k pódiu* (balcony);
the seats laid out as on the map (`SeatPlan.jsx`: the same row geometry,
scaled so a seat is 30–44 px – wider sections such as *Balkon střed* scroll
sideways – row numbers before seat 1 of each row, seat number on each
seat); *↓ Vchod* on the main floor; legend (+ *Záložní* when the section has
closed reserve seats). Seat states: `available` (light green), `selected`
(red), `taken` (light red, disabled), `reserve` (grey, dashed, disabled).

**Selection** (`useSeats(runId)`): local only; polling `seats.php?run=` every
30 s and on window focus; seats taken meanwhile are dropped with toast
`Místa … mezitím rezervoval někdo jiný.`; max `maxSeats` (`Najednou lze
rezervovat nejvýše N míst.`); when booking is closed seats cannot be
selected (`Rezervace na tento termín jsou uzavřeny.`); seats of closed
reserve seats (`run.closedReserves`) have the state `reserve` and cannot be
selected; free seats and section capacities leave them out.

**Top bar**: free seats, total price (selected seats × adult price), **Rezervovat** (or `Rezervace uzavřeny`).
**Footer** (all customer views): *Kontakt na pořadatele* with `CONTACT_EMAIL`
(`mailto:`) and `CONTACT_PHONE` (`tel:`) from `seats.php` → `contact`; hidden
when neither is configured.
**Reservation panel** (`ReservationPanel.jsx`): selected seats per section,
remove chip, total (× adult price), note *„Dětské vstupenky a slevový kód v
dalším kroku.“*,
Reserve; shown on the map when something is selected and in a section detail
with own seats.

**Form** (`ReservationForm.jsx`; on phones < 521 px a full-width bottom
sheet): *„<run> · N míst“*; *Dospělí · 300 Kč* (seats − children), stepper
(`Stepper.jsx`, − / +) *Děti 4–17 let · 150 Kč* (0…seats), note *„Děti do 3
let mohou sedět zdarma na klíně dospělého.“*; *Slevový kód*: input +
**Použít** (Enter too) → a line *„XXXX-XXXX · místo zdarma“* with × per
code; local checks *„Kód má 8 znaků, např. ABCD-EFGH.“*, *„Tento kód už je
zadaný.“*, *„Víc kódů než míst nelze použít.“*; validity is checked by the
server on submit. *Celkem* + total computed by `priceSeats()`
(`src/pricing.js`, same rules as the server); first/last name (side by
side), e-mail (client validation), honeypot `hp`, invisible ALTCHA (solving
starts when the form opens; submit waits for it with the button text
*Ověřuji…*; on an `altcha` error it solves a new challenge and retries once),
notes (*„Zaplaťte do N hodin, jinak se místa uvolní.“* with the hours in
effect now – only when there is something to pay, *„Vstupenky přijdou
e-mailem, na každé místo jedna.“*, storno text from `stornoText()`, *„Jméno a
e-mail smažeme N dnů po posledním představení.“*), submit **Rezervovat a
zaplatit** (total 0: **Rezervovat zdarma**). Sent as `children`, `codes`.
Server field errors (incl. `codes`) are shown at the fields. 409/403 close
the form with a toast and refresh.

**Reservation page** (`PaymentView.jsx`, `/?r=<token>`): header row like the
section detail (**←**, VS + name, status chip); run, price of the seats; list
of seats with kind and price (*„kód, zdarma“* for a seat paid by a code); pending → SPD QR + copyable account/IBAN/VS/SS/amount
(amount = still to pay, `payment.amount`) + due date (red overdue notice
after it); after a partial payment also *„Už jsme přijali X z Y. Doplaťte
prosím zbývajících Z se stejným variabilním symbolem.“*; paid → section
*Vstupenky* (*„1 QR kód = 1 židle. Ukažte je u vchodu v mobilu, nebo
vytiskněte.“*, button **PDF k tisku** → `tickets.php`): cards two per row on
phones (QR, short section name, *ř. · m.*, kind, *odbaveno* when checked in);
cancellation info (`CancellationInfo`: cancelled seats / fee / refund; for an
expired reservation with money to return *„Rezervace propadla, přijatá platba
se vrací.“*; for a paid one with a surplus *„Přišlo víc, než bylo potřeba.“*
+ refund line) and cancel panel (`CancelPanel`, §10; pending whole
cancellation says *„Už přijatých X pošleme zpět…“* when something arrived,
partial shows the new amount to pay minus what arrived; prices per seat from
`cancellation.seatPrices`; for seats worth 0 *„Za místo se slevovým kódem se
nic nevrací.“*; paid partial *„Na e-mail přijdou nové
vstupenky.“*).

## 8. Reservation lifecycle

```
pending ──payment recorded, whole price arrived──▶ paid
pending ──expires_at + GRACE passed──────────────▶ expired ──payment recorded──▶ paid (run not started, price covered, seats free)
(new, price 0 – all seats paid by discount codes) ──▶ paid at once
pending / paid ──cancel (customer/admin)─────────▶ cancelled
```

`expire_reservations()` (every API request and cron): sets `expired` +
`cancelled_at` for `pending` with `expires_at <= now − PAYMENT_GRACE_HOURS`
(a part of the price that already arrived becomes `refund_amount`), then
deletes `reservation_seats` of `expired`/`cancelled` reservations.

**Create** (`POST reservations.php`), checks in order:

| Check | Response |
| --- | --- |
| `BANK_IBAN` set | 503 `Platby nejsou nastaveny (BANK_IBAN).` |
| run exists | 422 `Vyberte termín.` |
| `run_booking_open()` | 403 `Rezervace na tento termín jsou uzavřeny.` |
| honeypot empty, form token valid | 400 `Rezervaci se nepodařilo odeslat. Obnovte stránku a zkuste to znovu.` |
| ALTCHA payload valid and unused (if `ALTCHA_ENABLED`) | 400 `Ověření proti robotům se nezdařilo. Zkuste to prosím znovu.` + `code: "altcha"` |
| names, e-mail, 1–20 valid unique seats | 422 `Zkontrolujte zadané údaje.` / seat message, `fields` |
| `children` integer 0…seats | 422 `Dětských vstupenek může být nejvýše tolik, kolik je míst.` |
| `codes` array, ≤ seats, each once, 8 chars of the code alphabet (after `normalize_discount_code()`) | 422 `Slevových kódů může být nejvýše tolik, kolik je míst.` / `Každý slevový kód lze zadat jen jednou.` / `Slevový kód X neplatí nebo už byl použit.` (field `codes`) |
| IP rate limit (`RESERVATIONS_PER_IP_PER_HOUR_ALTCHA` with ALTCHA, else `RESERVATIONS_PER_IP_PER_HOUR`) | 429 `Příliš mnoho rezervací z tohoto zařízení. Zkuste to prosím později.` |
| < 2 pending for the e-mail in this run (not for a reservation costing 0 or an on-site sale) | 429 `Na tento e-mail už na toto představení čekají nezaplacené rezervace. Nejdříve je prosím uhraďte.` |
| no closed reserve seat (`seat_closed_in_run()`) | 409 `Některá místa zatím nejsou v prodeji.` + `conflict` |
| seats free in the run (`FOR UPDATE`; duplicate key fallback) | 409 `Některá místa už mezitím někdo rezervoval.` + `conflict` |
| every code exists, unused, not revoked (`lock_discount_codes()`, `FOR UPDATE`) | 422 `Slevový kód XXXX-XXXX neplatí nebo už byl použit.` + `fields.codes` |

The last three checks and the rest are `book_reservation()`
(`lib/booking.php`). Prices: seats sorted, the last `children` seats are
child seats (`assign_child_seats()`); each code frees one seat, the most
expensive first – adult seats, then child seats, in seat order
(`assign_free_seats()`); `amount` = sum of `seat_value()`. In one
transaction: insert reservation (`adult_price`/`child_price` from config,
`expires_at = min(now + payment_deadline_hours(now), run start)`; amount 0 →
status `paid`, `paid_at` now), random unique 10-digit VS
(`generate_variable_symbol()`), mark the codes used (`used_at`,
`reservation_id`, `seat_id` = the freed seat), insert seats. Then amount 0 →
ticket e-mail *Vstupenky* (intro *„děkujeme za rezervaci. Všechna místa jsou
hrazená slevovými kódy, rezervace je potvrzena.“*), otherwise the payment
e-mail; respond 201 with `reservation_payload()`.

**Payment deadline rules** (admin → Nastavení → *Splatnost plateb*, settings
`payment_deadlines`): rows *Rezervace od* (Prague input, stored UTC) +
*Splatnost (hodin)* 1–720. `payment_deadline_hours(at)` = hours of the latest
rule with `from ≤ at`, else `PAYMENT_DEADLINE_HOURS`. Applies to reservations
made from then on (existing ones keep their due date); `seats.php`
`deadlineHours` = hours in effect now.

## 9. Payments

- **SPD** (`spd_string()`): `SPD*1.0*ACC:<IBAN>[+BIC]*AM:<amount>*CC:CZK*X-VS:<VS>*DT:<due date Prague YYYYMMDD>*MSG:<PAYMENT_MESSAGE + last name, ASCII, ≤60>[*X-SS:<SS>][*RN:<recipient ≤35>]`.
  `AM` = `amount_due()` (what is still missing after a partial payment).
- Payments are matched manually by VS. The accountant records every received
  transfer with its real amount (admin action `payment`, field `received`
  1–1 000 000 Kč; pending rows: input prefilled with `amount_due()` + button
  **Zaplaceno**; other statuses: *přišla platba* → input + **Zapsat platbu**
  with a `confirm()` explaining the outcome). `record_payment(id, received)`
  adds it to `paid_amount` and by status:

  | Status | Result | Customer e-mail |
  | --- | --- | --- |
  | pending, kept ≥ `amount` (`complete_if_covered()`) | `paid`, `paid_at`; surplus `paid_amount − amount` → `refund_amount` | *Vstupenka* (intro adds the surplus refund line) |
  | pending, less | stays pending; admin row shows *zbývá doplatit X* | *Přijata část platby* (missing amount + payment details) |
  | expired, run not started, kept ≥ `amount`, all seats free | seats re-inserted, `paid` (surplus refunded as above; refund of an earlier partial payment cancelled unless already returned) | *Vstupenka* |
  | expired otherwise | stays expired; `refund_amount += received` | *Vrácení platby* |
  | cancelled | `refund_amount += received` | *Vrácení platby* |
  | paid (sent twice etc.) | `refund_amount += received` | *Vrácení platby* |

  Reasons in *Vrácení platby* and the admin message (`REFUND_REASONS`):
  `after_run` *platba dorazila až po představení*, `taken` *místa mezitím
  obsadil někdo jiný*, `short` *rezervace už propadla a platba nepokrývá celou
  částku*, `cancelled` *rezervace už byla zrušená*, `extra` *rezervace už byla
  zaplacená, jde o platbu navíc*. Admin messages: *„Přijato X – zaplaceno.
  [Přeplatek Y k vrácení.]“*, *„Přijato X, zbývá doplatit Y.“*, *„Přijato X,
  rezervace obnovena – zaplaceno.“*, *„Přijato X – <reason>. K vrácení na účet
  plátce: Y.“* + ticket/e-mail result.
- Reminder 24 h before the due date, expiry notice after expiry (§16).
- **On-site sale** (`lib/onsite.php`, scanner tab *Prodej*, §11): while seats
  are free, an organizer sells them at the entrance. `sale-create` → 
  `book_reservation()` with `sold_by` = device name, name *Prodej na místě*,
  no e-mail, due at once, no pending limit; the scanner shows the SPD QR
  (amount, VS, account). The customer pays in the banking app and shows the
  sent payment; **Zaplaceno – vpustit** (`sale-paid`, `confirm()` *„Zákazník
  ukázal odeslanou platbu X?“*) → `paid`, `paid_amount = amount`, all seats
  checked in by the organizer: *„Zaplaceno X – N místa odbavena.“*
  **Zrušit prodej** (`sale-cancel`) cancels it (admin cancellation, seats
  free): *„Prodej zrušen, místa jsou volná.“* A sale no longer pending → 409
  *„Prodej už není otevřený.“* The transfer is not verified at the entrance:
  admin shows such reservations as *na místě – ověřte, že platba dorazila*
  (card and filter *Na místě – ověřit platbu*) with **Platba dorazila**
  (`onsite-checked` → `payment_checked_at`, *„Platba za prodej na místě
  ověřena.“*) instead of *přišla platba*. A sale left pending (neither
  button) expires like any reservation.

## 10. Cancellation and refunds

`cancellation_terms($r)` (in every reservation payload): `allowed` false
(`inactive`, `checked_in`, `event_started` = run started); else `percent`
(`storno_percent(run, now)` for paid, 0 for pending), `seatPrices`, `fee`,
`refund` for all seats (pending: the part of the price that already arrived).

Storno rules (per run): before the first rule 0 %; from each rule's `from`
its percent applies, the highest started rule wins.

Money is per seat: `fee = round(value × percent / 100)` where value is the
sum of `seat_value()` of the cancelled seats – a seat paid by a discount code
is worth 0, so nothing is refunded for it and the code stays used.
`cancellation_terms()` also returns `seatPrices` (current seat → value) for
the frontend.

`cancel_seats(id, seatIds|null, by)`:

| | customer (`cancel.php`) | admin |
| --- | --- | --- |
| allowed when | `cancellation_terms().allowed` | status pending/paid |
| fee | storno percent | 0 |
| refund | value − fee | full value |

- seats must belong to the reservation (`Vyberte místa z této rezervace.`);
- all remaining seats → status `cancelled`, `cancelled_by`, seats deleted;
- part → `seats`, `seat_count`, `amount` (= value of the remaining seats)
  reduced, `cancelled_seats` appended;
- paid: `cancel_fee` and `refund_amount` accumulate; refunds go to the
  paying account (no account is collected);
- pending, whole: a part of the price that already arrived → `refund_amount`;
- pending, part: when what already arrived covers the lower price the
  reservation becomes `paid` (`complete_if_covered()`, surplus refunded; a
  remaining price of 0 – only seats paid by codes – counts as covered);
- admin **Vráceno** sets `refunded_amount = refund_amount`, `refunded_at`;
  due = `refund_amount − refunded_amount`.

E-mails (`send_cancellation_notice()`): paid part → *Nová vstupenka* (ticket
e-mail with intro: cancelled seats, fee, refund line, *„Posíláme novou
vstupenku na zbývající místa. Původní vstupenka už neplatí.“* – the tickets of
the cancelled seats stop working, §11); pending part now fully paid → *Vstupenka* (intro: cancelled
seats, *„Platba za zbývající místa je kompletní, rezervace je potvrzena.“*,
surplus refund line); other pending part → *Změna rezervace* (new payment
details); whole → *Rezervace zrušena* with fee/refund line; pending whole
(customer or admin) additionally *„Pokud jste platbu už odeslali, pošleme Vám
ji po připsání zpět na účet, ze kterého přišla.“*
Refund line: *„Částku X Kč Vám do 14 dnů pošleme zpět na účet, ze kterého
platba přišla.“*

## 11. Tickets and check-in

One ticket = one seat (1 QR code = 1 chair), so a printed sheet can be cut
and the tickets handed out; each seat is checked in separately.

- **Code** (`ticket_code(r, seat)`): `Z26M|<reservation id>|<VS>|<seat>|<kind A/C>|<name>|<sig>`
  (kind A = adult, C = child); `sig` = first 22 chars of base64url
  HMAC-SHA256(`TICKET_SECRET`, payload). `parse_ticket_code()` →
  `{id, variableSymbol, seat, kind, name}`; older formats (`Z26|…`, one code
  per reservation) are no longer accepted (`invalid`). JS mirror:
  `src/scanner/ticket.js` (display/offline only).
- **Ticket e-mail** (`send_ticket_email()`, HTML + text): run, name, summary
  (`seat_kinds_summary()`, e.g. *„2 dospělí, 1 dítě 4–17 let“*), *„Každé místo má vlastní QR kód – 1 QR kód = 1 židle.“*, then
  per seat an inline PNG (`cid:ticket-qr-<n>`), `seat_label()` (*„Levá hlavní ·
  řada 1 · místo 2“*) and *„<kind> · vstupenka n z N“*; footer *„V příloze je
  soubor vstupenky.pdf k vytištění – vstupenky rozstříhejte, každá platí pro
  jedno místo. Při vstupu můžete QR kódy ukázat i na mobilu.“*. Attachments `vstupenky.pdf` and
  `udalost.ics`. Resend in admin. The paid reservation page shows the same
  QR codes.
- **Printable PDF** (`ticket_pdf()`, dompdf, DejaVu fonts): A4, note
  *„Rozstříhejte podle čárkované čáry. Každá vstupenka platí pro jedno místo –
  při vstupu se načte její QR kód.“*, then the current seats in seat order,
  `TICKETS_PER_PAGE` = 4 per page, each 60 mm high with a dashed cut line:
  QR, *Vstupenky na TZG 2026*, run, section, *řada / místo*, kind, *„<name> · VS … ·
  vstupenka n z N“*. `GET tickets.php?token=` downloads it
  (`vstupenky-<VS>.pdf`) for paid reservations, else 404 *„Vstupenky nejsou
  k dispozici – rezervace není zaplacená.“*
- **Calendar event** (`ticket_ics()`): every ticket e-mail carries the
  attachment `udalost.ics` (`text/calendar; charset=UTF-8; method=REQUEST`),
  so Gmail/Outlook show an event card with *Add to calendar*. iCalendar 2.0,
  `METHOD:REQUEST`, CRLF, lines folded at 75 octets (UTF-8 safe), TEXT
  values escaped (`ics_text()`). One `VEVENT`: `UID:zidle-<reservation
  id>@<host of PUBLIC_URL, else of the sender, else moje-zidle>` (stable, so a
  new ticket after a partial cancellation updates the same event),
  `SEQUENCE` = current Unix time, `DTSTART` = run start, `DTEND` = start +
  `EVENT_DURATION_MINUTES` (both UTC `…Z`), `SUMMARY` *„TZG 2026[ · <run
  label>] – <seat labels>“*, `DESCRIPTION` *„Místa: …“*, *„Variabilní symbol:
  …“*, *„Při vstupu prosím ukažte QR kódy vstupenek z e-mailu (1 QR kód = 1
  místo).“*,
  *„Rezervace: <link>“* (with `PUBLIC_URL`) and the contact line,
  `LOCATION` = `EVENT_LOCATION` (omitted when empty), `ORGANIZER;CN="Moje
  židle 2026":mailto:<sender>`, `ATTENDEE;CN="<name>";ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;RSVP=FALSE:mailto:<customer>`,
  `STATUS:CONFIRMED`. No attachment when the run no longer exists.
- **Verify** (`organizer.php` `verify {code, runId, confirmOutside}` →
  `verify_ticket()` in `lib/checkin.php`): access to run required (403 `Na
  tento termín nemáte přístup.`); load reservation by id + VS `FOR UPDATE`;
  result for the seat of the code:

  | result | when | check-in recorded |
  | --- | --- | --- |
  | `invalid` | bad signature/format or not found | no |
  | `wrong_run` | pending/paid of another run | no |
  | `seat_cancelled` | paid, the seat was cancelled from the reservation | no |
  | `used` | paid, this seat already checked in | no |
  | `outside_window` | paid, first scan of the seat, outside window, not confirmed | no |
  | `valid` | paid, first scan of the seat | yes (`ticket_checkins` row; the reservation's `checked_in_at`/`by` keep the first one) |
  | `unpaid` / `cancelled` | status pending / expired or cancelled | no |

  Response: `ticket {id, variableSymbol, seat, kind, name, seats (current),
  count, checkedIn (seats checked in)}`, `checkedInAt` /
  `checkedInBy` of the seat, `status`, `run`.
- **Scanner UI** (tabs *Vstupenky* / *Prodej*) results: `Platná
  vstupenka`, `Už odbaveno` (+ first time), `Místo zrušeno` (*„Toto místo bylo
  z rezervace zrušeno, ostatní místa platí.“*), `Nezaplaceno`, `Rezervace
  zrušena`, `Neplatný kód`, `To je platební QR kód` (code starts with `SPD*`),
  `Jiný termín` (+ ticket run), `Mimo čas odbavení – neodbaveno`, `Není v
  seznamu termínu` (offline, see below), `Neověřeno – bez spojení` (no
  connection and no list). The card shows the seat in large type (section,
  *řada · místo*, kind chip *Dospělý* / *Dítě 4–17 let*), the name,
  *„rezervace N míst · odbaveno K z N“*. Ticket footnote: *„aktuální stav ze systému“*, *„bez spojení,
  podle seznamu z HH:MM“* (+ *„Odbavení se odešle, jakmile bude spojení.“* for
  a valid one) or *„údaje z QR kódu, neověřeno“*. Camera: rear camera, centre
  square decoded by `jsqr` every 120 ms, torch when supported, stopped while
  the page is hidden.
- **Prodej** tab (`SaleView.jsx`, on-site sale §9; needs a connection, else
  *„Prodej na místě potřebuje internet. Zkuste to znovu, až bude spojení.“*):
  `sale-seats` → one horizontally scrolling row of section chips with free
  counts, the section's seats as in the customer app (`SeatPlan`, held and
  closed reserve seats disabled), up to `maxSeats`;
  *Dospělí · 300 Kč* and stepper *Děti 4–17 let · 150 Kč*; button *„QR platba
  <total> · N míst“* → `sale-create` → QR payment (QR, amount, VS, account,
  seats *„P. křídlo ř. 2 m. 3 – Dospělý 300 Kč“*) with **Zaplaceno – vpustit** / **Zrušit prodej**; afterwards the
  result message and a fresh seat list. 409 reloads the seats.
- **Check-in window**: `run_scan_window()` = start − BEFORE … start + AFTER.
  Default run in the scanner: run whose window is open, else remembered
  (localStorage `zidle-scanner-run`), else next upcoming, else last. Outside
  the window the camera is off and `WindowWarning` offers switching to an
  open run or **Přesto odbavovat tento termín** (confirmation kept until
  reload; state re-checked every 30 s); the server enforces the same.

### Scanning without a connection

- **No connection** = `fetch` fails, HTTP 5xx or a non-JSON answer
  (`src/scanner/api.js` sets `error.offline`); 401 signs the device out.
- **Offline start**: `public/sw.js` (registered by `scanner/main.jsx` in
  production builds, scope = app directory) caches `scanner.html` and the
  `assets/` it references on install; `scanner.html` network-first (cached
  copy offline, the cache is refreshed and assets of older builds removed on
  every online load), `assets/*` cache-first; API, customer pages and fonts
  are not touched. `getSession` failing offline → the last session
  (`{name, runs, layout}`) from localStorage (`saveSession()` on every online
  load; a stored session without `layout` is ignored); without one *„Bez
  spojení. Poprvé se scanner musí přihlásit s internetem.“*
- **Snapshot** (`organizer.php` `snapshot {runId}` → `scanner_snapshot()`):
  `{runId, at, tickets: [{id, variableSymbol, name, seats, childSeats,
  status, checkedIn: {seat: {at, by}}}]}` – all reservations of the
  run (any status), no e-mails or money. `useOffline(runId)` loads it for the
  selected run on start, every 60 s and on the browser `online` event, and
  keeps it in localStorage key `zidle-scanner-offline` (`src/scanner/offline.js`:
  `session`, `snapshots` per run, `queue`). An online `valid` result also
  marks the seat checked in in the stored snapshot (`markTicketCheckedIn()`).
- **Offline ticket check** (`verifyOffline()`): find by id, VS must match →
  not found `unknown` (*„Vstupenka není v seznamu tohoto termínu – může být na
  jiný termín, nebo neplatná.“*); pending `unpaid`; cancelled/expired
  `cancelled`; seat not in the reservation `seat_cancelled`; seat checked in
  (snapshot or this device's queue) `used` with the time; else `valid` and an
  event `{uid, runId, type: "ticket", id, variableSymbol, seat, at}` is
  queued. Kind, seats and counts come from the snapshot. The check-in window
  works as online (run times from the cached session).
- **Sending** (`sync {runId, events}` → `apply_offline_scans()`, max 1000
  events): run by run when the server is reachable again (any successful
  request, snapshot refresh, `online` event); sent events leave the queue;
  403 for a run drops its events. Server, in order, each in a transaction:
  `ticket` – reservation by id + VS + run `FOR UPDATE`: not found → conflict
  `unknown`; not paid or the seat not in the reservation → `not_paid`; seat
  not checked in → `ticket_checkins` row with the device time and
  `<device name> (offline)` (the reservation keeps its earliest check-in); the
  same time and device again → ok (resend); otherwise → `already_checked_in`
  (the first check-in stays). Conflict labels name the seat
  (`reservation_label()`: *„VS … <name> · <seat_label()>“*); other event
  types → conflict `invalid` (not recorded). Device time is limited to the last 48 hours
  (`OFFLINE_MAX_AGE_HOURS`) and never later than now. Conflicts go to
  `scan_conflicts` (the same conflict only once). Response
  `{results: [{status: "ok"|"conflict", reason?}], conflicts}`.
- **Strip** (`OfflineStrip`, hidden when online with nothing to send):
  offline *„Bez spojení – ověřuji podle seznamu z HH:MM“* (+ *„(starší než 30
  min)“*, + *„· k odeslání: N“*), red without a list (*„Bez spojení a bez
  staženého seznamu – vstupenky nelze ověřit.“*) or when stale; online with a
  queue *„Odesílám odbavení bez spojení: N…“*; after sending for 8 s
  *„Odesláno N odbavení bez spojení.“* (+ *„Konflikty: K – uvidí je
  správce.“*).
- **Logout** asks *„N odbavení bez spojení ještě nebylo odesláno. Odhlášením
  se ztratí. Přesto odhlásit?“* when the queue is not empty, then removes all
  stored data; a 401 removes it too. Snapshots of runs no longer in the
  session or ended more than a day ago are removed on the next online start.
- Limit: devices offline at the same time do not see each other's
  check-ins; the same seat ticket shown at two such entrances passes both and
  appears as a conflict after sending. On-site sales need a connection.

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

## 13. Discount codes

Free seats are given with discount codes (`lib/discounts.php`; VIP guests
were removed by migration 014). The admin enters e-mail addresses (tab
*Slevové kódy*, §14); each listed address gets one code by e-mail (§15), an
address listed twice gets two. A code: 8 characters (§19), any run, used
once (`used_at`, `reservation_id`, `seat_id`), can be revoked while unused.
In a reservation each code makes one seat free – the most expensive one,
adult seats first, then child seats (§8). Several codes can be used in one
reservation (at most one per seat); a reservation paid fully by codes is
confirmed at once. Storno of a free seat returns nothing and the code stays
used; the code also stays used when the reservation expires or is cancelled.

## 14. Admin

`api/admin.php` (CSRF token on every POST). **Sign-in** form: *E-mail* +
*Heslo* (+ hint *„Hlavní heslo: e-mail nechte prázdný.“* when
`ADMIN_PASSWORD` is set). `admin_login()`: empty e-mail → master password
(`hash_equals`), name *hlavní heslo*; otherwise the account by e-mail (case
insensitive) with a password, not disabled, `password_verify()` (a dummy
hash is verified for unknown e-mails; rehash when needed), `last_login_at`
set. Wrong → 1 s delay, *„Nesprávný e-mail nebo heslo.“*; rate limit area
`admin` → *„Příliš mnoho pokusů. Zkuste to za 15 minut.“* The session keeps
`admin_id` (account id, 0 = master); `admin_from_session()` re-checks the
account on every request, so a disabled account is signed out at once. The
header shows *Přihlášen: <name>*.

**Accountants** (`admin_users`, all admins have the same rights):
- **Invite** (tab *Účetní*, `user-invite {name, email}` → `admin_invite()`):
  name 1–100 chars (*„Vyplňte jméno.“*), valid e-mail ≤ 190 (*„Neplatný
  e-mail.“*), not existing yet (*„Účet s tímto e-mailem už existuje. Pošlete
  mu nový odkaz v seznamu.“*); creates the account and a 48-hex token (only
  its sha256 stored) valid `ADMIN_INVITE_DAYS` = 7 days. E-mail *Pozvánka do
  správy rezervací* (§15) with `admin_invite_link()` =
  `app_base_url()/api/admin.php?pozvanka=<token>`; the link is also shown
  once in the tab (*Kopírovat odkaz*). Flash *„Pozvánka pro X vytvořena.
  E-mail odeslán.“* / *„… E-mail se neodeslal – předejte odkaz sami.“*
- **Invitation page** (`admin.php?pozvanka=<token>`, also when signed in):
  valid link (`admin_invited_user()`: hash, not expired, account not
  disabled) → *„Dobrý den, <name>. Vytvořte si heslo do správy rezervací.
  Přihlašovací jméno je Váš e-mail <email>.“*, *Nové heslo* + *Heslo znovu*,
  **Uložit heslo a přihlásit** (`accept-invite`, rate limit area
  `admin-invite`); `admin_accept_invite()`: ≥ `ADMIN_PASSWORD_MIN` = 10
  chars (*„Heslo musí mít alespoň 10 znaků.“*), equal (*„Hesla se
  neshodují.“*) → password stored, link cleared, `accepted_at` (first time),
  signed in, redirect with *„Vítejte, <name>. Heslo je nastavené, příště se
  přihlásíte e-mailem <email>.“* Invalid/used/expired link: *„Pozvánka
  neplatí nebo vypršela. Požádejte o novou.“* + link to sign-in.
- **Tab Účetní** list (active first, by name): name (*(vy)* for yourself),
  e-mail, status *Aktivní* / *Čeká na heslo (do …)* / *Pozvánka vypršela* /
  *Vypnuto* (+ *odkaz na nové heslo platí do …*), invited by + created,
  last sign-in. Actions: `user-link` – **Poslat znovu** (pending) / **Nové
  heslo** (active; forgotten password – the old password works until the new
  one is set): new token + e-mail, the previous link stops working;
  `user-disable` **Vypnout** (not for yourself: *„Svůj vlastní účet nelze
  vypnout.“*; also clears an open link) → *„Účet X vypnut – už se
  nepřihlásí.“*; `user-enable` **Zapnout**. The intro mentions that the
  master password still works and can be removed from the configuration.

Tabs:

- **Rezervace** – cards per status (pending/paid), refunds due, on-site
  sales to verify (*Na místě – ověřit platbu*), per run occupancy (held / `run_capacity()`); filters run/status (incl. `refund`, `onsite`)/ search (VS,
  name, e-mail, seat). Name column: *prodal(a) <organizer>* for on-site sales.
  Seats column: one seat per line with *dítě* / *kód* chips and ✓ when
  checked in. Status column: *odbaveno K z N
  míst, poprvé <time> (<by>)*; on-site paid *na místě – ověřte, že platba
  dorazila* / *platba ověřena <time>*. Amount column: *přijato X* when it
  differs from the price, *zbývá doplatit Y* for partly paid pending.
  Actions: `payment` (§9), `ticket` (send/resend; not without an e-mail),
  **PDF vstupenek** (`tickets.php`), `cancel`, `cancel-seats` (confirm
  mentions that seats free by a code are not refunded), `refunded`, `email`
  (change), `onsite-checked` (**Platba dorazila**, §9; replaces *přišla
  platba* for unverified on-site sales). Within 14 days of the GDPR deletion date and
  while refunds are due, a warning: *„Osobní údaje se mažou <date>. U N rez.
  zbývá vrátit peníze – jejich jméno a e-mail se smažou až po označení
  „Vráceno“.“*
- **Slevové kódy** – cards *Kódů celkem* / *Použito* / *Nepoužito*; form
  *Rozeslat slevové kódy* (textarea of e-mails separated by new lines,
  commas, semicolons or spaces; an address listed N times gets N codes) →
  `code-create` → `create_discount_codes()`: max 200
  (*„Najednou lze poslat nejvýše 200 kódů.“*), any invalid address →
  *„Neplatné e-maily: … Nic nebylo vytvořeno.“*; else one random code per
  address (8 chars of `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`) e-mailed at once:
  *„Vytvořeno N kódů, e-mailem odesláno M.“* (+ *„Některé e-maily se
  neodeslaly – zkuste „Poslat znovu“.“* / *„E-maily jsou vypnuté
  (MAIL_ENABLED).“*). Search (e-mail, code, VS); table (last 1000): code,
  e-mail, created (+ by), sent, state *Nepoužitý* / *Použitý* (time, VS link,
  run, seat) / *Zrušený*; for unused: **Poslat znovu** (`code-resend`) and
  **Zrušit** (`code-revoke`, *„Kód zrušen – už ho nelze použít.“*).
- **Účetní** – see above.
- **Pořadatelé** – `invite-save` (create / edit name+runs / new link),
  `invite-revoke`; below the invites *Odbavení bez spojení – konflikty*
  (last 500 `scan_conflicts`: time, run, label, reason *už odbaveno jinde* /
  *vstupenka neplatila (nezaplaceno / zrušeno / místo zrušeno)* / *neznámá vstupenka*
  (`CONFLICT_REASONS`), device, earlier check-in). The tab shows the number
  of conflicts in a red badge.
- **Nastavení** – *Splatnost plateb* (`deadline-save`, §8; errors
  *„Splatnost N: zadejte datum a počet hodin 1–720.“*, success *„Splatnost
  uložena. Platí pro nové rezervace, už vytvořené si splatnost ponechají.“*;
  shows *„Nyní platí: N hodin“*); runs: `run-save` (start required, label, booking cut-off
  before start, storno rows, checkboxes *„prodávat záložní místa – Balkon
  vlevo (28)“* / *… Balkon vpravo (6)* = `open_reserves`, hint *„Záložní
  místa na balkoně jsou v prodeji, jen když je tu zaškrtnete (třeba až se
  zaplní zbytek balkonu).“* – not locked by reservations), `run-delete` (only without reservations, else *„Termín má rezervace, nelze ho smazat.“*;
  removes invite links and offline scan conflicts of the run). Shows the GDPR deletion date.
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
text, html?, inline[], attachments[])` (PHPMailer, UTF-8; attachments base64): with `SMTP_HOST` via SMTP
(`SMTP_PORT`, `SMTP_AUTH`, login `SMTP_USER` or `SMTP_SENDER` / `SMTP_PASSWORD`; port 465 =
SMTPS, otherwise opportunistic STARTTLS; connect timeout and per-reply limit
15 s), otherwise PHP `mail()` from `MAIL_FROM` (`mail_sender()`). Sender name *Vstupenky na TZG 2026*;
`Reply-To` = `CONTACT_EMAIL` when set. Every e-mail ends with *„Kontakt: <e-mail> ·
<phone>“* (`contact_line()`) when a contact is configured.
Failures return false and are logged as `[zidle] Mail to … failed: <reason>`;
mail is sent synchronously within the request. Texts are plain
(`send_customer_email()`) except the ticket (HTML + text alternative with one
QR per seat as inline images `cid:ticket-qr-<n>`, `vstupenky.pdf` and the
calendar event `udalost.ics`, §11). All reservation e-mails contain `Termín: <run>`.

| Subject | Trigger |
| --- | --- |
| Rezervace míst | reservation created (payment details, link if `PUBLIC_URL`) |
| Pozvánka do správy rezervací | accountant invited or new link (`send_admin_invite_email()`: *„<inviter or Správce rezervací> Vás zve ke správě vstupenek na TZG 2026 (potvrzování plateb, vracení peněz, slevové kódy).“*, link valid 7 days, *„Přihlašovací jméno je Váš e-mail: …“*) |
| Připomínka platby | cron: pending, due within 24 h, created ≥ 24 h before due, once |
| Rezervace zrušena | cron: expired within the last 3 days, once (refund line for money that already arrived; *„Pokud platba ještě dorazí a místa budou stále volná, rezervaci obnovíme; jinak Vám peníze pošleme zpět na účet, ze kterého přišly.“*) |
| Vstupenka | recorded payment completes the price / expired restored / pending partial cancellation now covered / resend |
| Vstupenky | new reservation paid fully by discount codes |
| Slevový kód | admin created or resent a code (`send_discount_code_email()`: *„posíláme Vám slevový kód na jedno místo zdarma na představení TZG 2026: XXXX-XXXX“*, where to enter it, *„Platí na libovolný termín a lze ho použít jednou. Z rezervace se odečte cena jednoho místa (nejdražšího – nejdřív dospělého, pak dětského).“*); `emailed_at` set |
| Přijata část platby | recorded payment below the price |
| Vrácení platby | recorded payment that can't be used (§9 reasons) |
| Nová vstupenka | partial cancellation of a paid reservation |
| Změna rezervace | partial cancellation of a pending reservation |
| Rezervace zrušena | whole cancellation (customer or admin) |

## 16. Scheduled jobs and GDPR

`php api/cron.php`: `expire_reservations()`, `send_due_notifications()`,
`purge_personal_data()`, `cleanup_rate_limits()`; prints a summary line.
Purge after `last_run_start() + DATA_RETENTION_DAYS`: empties first/last
name and e-mail of all reservations except those with money still to return
(`refund_amount > refunded_amount`; they are emptied by the first cron run
after **Vráceno**), deletes all `scan_conflicts` and empties the e-mails of `discount_codes`; VS,
amounts, seats and statuses stay.

## 17. Spam and abuse protection

- Honeypot field `hp` (hidden from people and assistive tech).
- Invisible ALTCHA (`api/lib/altcha.php`, `src/altcha.js`, protocol v2,
  `PBKDF2/SHA-256`): `altcha.php` issues a signed challenge (HMAC secrets
  derived from `TICKET_SECRET`, expires in 15 min, deterministic counter with
  key signature for fast verification; `ALTCHA_CHALLENGES_PER_IP_PER_HOUR`
  challenges per IP). The browser
  solves it in the background (`altcha-lib`, WebCrypto) and sends the base64
  payload `{challenge, solution}` as `altcha`. The server verifies signature,
  expiry and derived key, and accepts each challenge signature only once
  (`rate_limit('altcha|<signature>', 1, …)`). The frontend re-solves a
  payload older than 12 min.
- Form token from `seats.php`: `<unix time>.<hmac>` (secret derived from
  `TICKET_SECRET`), valid from `FORM_MIN_SECONDS` to 12 h.
- `rate_limit(key, max, window)` fixed windows in `rate_limits` (keys
  hashed): reservations per IP (counted after validation; higher limit with
  ALTCHA), cancellations
  20/h per IP, logins/invites per IP.
- Pending reservations per e-mail and run.
- Discount codes are guessed only through reservations (IP limit + ALTCHA);
  the code space is 32⁸.

## 18. HTTP API

| Method | Endpoint | Body / query | Response |
| --- | --- | --- | --- |
| GET | `seats.php?run=<id>` | – | `runs[]` (id, label, startsAt, bookingClosesAt, bookingOpen, free, capacity, stornoRules, closedReserves), `runId`, `taken[]`, `price` (adult seat), `childPrice`, `deadlineHours` (in effect now), `maxSeats`, `bookingOpen`, `formToken`, `contact` {email, phone}, `dataRetentionDays`, `layout` {levels, plan, sections} |
| GET | `altcha.php` | – | `{enabled, challenge}` (ALTCHA v2 challenge: `parameters`, `signature`) |
| POST | `reservations.php` | `runId, firstName, lastName, email, seats[], children, codes[], formToken, hp, altcha` | 201 reservation payload |
| GET | `reservations.php?token=` | – | reservation payload (see `reservation_payload()`: status, seats, `tickets[]` {seat, kind, price, free, code (paid only), checkedInAt}, amounts, run, payment {iban, account, recipient, variableSymbol, specificSymbol, amount (still to pay), received (kept so far), spd}, cancellation (+ `seatPrices`), refunds) |
| GET | `tickets.php?token=` | – | PDF of a paid reservation's tickets (§11), else 404 |
| POST | `cancel.php` | `token, seats?` | reservation payload |
| GET | `organizer.php` | – | `loggedIn, name, passwordLogin, runs[]` (+ `scanFrom`, `scanTo`), `layout` |
| POST | `organizer.php` | `action`: `invite {token}`, `login {password}`, `logout`, `verify {code, runId, confirmOutside?}`, `snapshot {runId}`, `sync {runId, events}`, `sale-seats {runId}` → `{taken, closedReserves, price, childPrice, maxSeats}`, `sale-create {runId, seats, children}` → 201 `{sale: {id, status, variableSymbol, amount, seats, tickets, spd, account, iban}}` (errors 422 *„Vyberte 1 až 20 volných míst.“* / *„Dětských vstupenek může být nejvýše tolik, kolik je míst.“*, 409 seats taken, 503 no IBAN), `sale-paid {runId, id}` → `{message}`, `sale-cancel {runId, id}` → `{message}` | see §9, §11, §12 |

Errors: `{"error": "<Czech message>", …}` with HTTP status; uncaught
exceptions are logged (`[zidle]`) and return 500 `Chyba serveru. Zkuste to
prosím znovu.`

## 19. Formats

- Seat id: `^[A-Z]{2}-\d{1,2}-\d{1,2}$`, validated against `SECTIONS`.
- VS: random 10 digits (first digit non-zero), unique.
- Ticket: §11 (one per seat). Payment: §9.
- Discount code: 8 chars `[A-HJ-NP-Z2-9]`, shown `XXXX-XXXX`; input is
  normalized (upper case, anything but letters/digits removed).
- Invite token: 48 hex; device cookie values §12.
- Times in API responses: ISO 8601 UTC with `Z`.

## 20. Conventions

See `CLAUDE.md`: docs in sync with code, UTC in DB, mobile-first customer
CSS / desktop-first admin / mobile-only scanner, layout only on the server,
ticket format kept identical, schema + migration for DB changes, Czech UI.

## 21. Tests and CI

`.github/workflows/ci.yml` runs on pull requests and pushes to `main`
(older runs of the same ref are cancelled). PHP 8.3, Node 22, MariaDB 10.11
service (`root`/`root`, database `zidle_test`); the php job sets `ADMIN_PASSWORD=ci-admin-master` for the master login test and `BANK_IBAN` for the on-site sale test.

| Job | Steps |
| --- | --- |
| **php** | `composer install` (with dev: PHPStan), `composer audit`, `php -l` on `api/` and `tests/` (without vendor), PHPStan, `db/schema.sql` + all migrations applied twice to an empty database (they must stay re-runnable), `php tests/php/run.php` |
| **frontend** | `npm ci`, `npm audit --omit=dev --audit-level=high`, `npm run lint`, `npm run build`, `npm run test:consistency` |
| **e2e** (after both) | `composer install --no-dev`, `npm ci`, Playwright Chromium, `npm run test:e2e`; on failure the HTML report and traces are uploaded as artifact `playwright-report` (7 days) |

**PHPStan** (`phpstan.neon`): level 8 over `api/` (without `vendor/`,
`config.local.php`) and `tests/php/`; `missingType.iterableValue` is ignored
(database rows and payloads are plain arrays). `db_query()` wraps
`db()->query()` for fixed SQL so the result is typed `PDOStatement`.

**ESLint** (`eslint.config.js`): `@eslint/js` recommended +
`eslint-plugin-react-hooks` recommended for `src/` (browser globals,
capitalised unused variables allowed for JSX); tests and configs with Node +
browser globals.

**PHP integration tests** (`tests/php/run.php`, no framework: `test()`,
`same()`, `contains()`; exit code 1 on failure). `tests/php/fixtures.php`
refuses to run unless `DB_NAME` ends with `_test`, recreates all tables from
`db/schema.sql` (`reset_database()`: run 1 in 30 days with a 50 % storno rule
from yesterday, run 2 started an hour ago) and creates reservations directly
(`reservation()`: adult seats 300 Kč, optional child seats 150 Kč and seats
free by a code). Covered: partial payment → paid with surplus refund; SPD
and payload ask for the missing amount; late payment restoring an expired
reservation / refunded when seats are taken, after the run, or short;
expiry refunding a partial payment and a later full payment restoring it;
payment for cancelled and already paid reservations; invalid amount;
customer storno fee; admin full refund; cancelling a partly paid pending
reservation; partial cancellation completing a covered reservation; double
booking rejected by the primary key; GDPR purge keeping contacts while a refund is due; ticket code
round trip per seat with its kind, tamper detection and one ticket per seat
in the payload; printable PDF (four tickets per page); child seats and free
seats (most expensive first); booking with a child seat and a code
(450 Kč, code marked used for the seat); a code used once, revoked and
unknown codes refused; a reservation paid fully by codes is paid at once;
codes per listed e-mail, invalid address creates nothing; storno of a free
seat returns nothing, of a child seat its price minus the fee; pending with
only free seats left becomes paid; payment deadline rules by booking time;
online check-in per seat (outside window, used, child kind, cancelled seat,
other run, old format); offline check-in of separate seats; on-site sale
(QR amount, seat held, paid → all seats checked in, only once, cancel frees
the seat); ticket calendar event (run start/end,
UID, SEQUENCE, escaping, line folding, missing run); admin accounts (invite, short/mismatched
password, accept, used link, case-insensitive login, wrong password,
duplicate and invalid e-mail, new link = new password while the old one
works until then, expired link, disabled account cannot sign in and loses
its session, nobody disables themselves, master password with an empty
e-mail); layout (order, seats per row of the
wings, main sections and balcony, the bench split 5 | 7, 195 + 106 = 301
seats, reserve rows, a plan per level, last seat of each section valid, one more
invalid); reserve seats closed by default (267 for sale, booking refused,
the bench of the same section bookable) and opened for a run; offline: snapshot content (no e-mails),
offline check-ins with device time and first-wins, resend
without a new conflict, second device → `already_checked_in` recorded once,
unpaid / unknown / other-run tickets → conflicts, device time limits. Locally:
`DB_NAME=zidle_test MAIL_ENABLED=0 BANK_IBAN=CZ6508000000192000145399 php tests/php/run.php` (values in
`config.local.php` take precedence over the environment).

**Consistency** (`tests/consistency.mjs`): seat ticket codes made by
`ticket_code()` (adult and child seat) are decoded by `src/scanner/ticket.js`
(id, VS, seat, kind, name).

**Playwright** (`playwright.config.js`, `tests/e2e/`): one worker, project
*mobile* (Pixel 7, `cs-CZ`, Europe/Prague); admin tests use a 1360 × 900
desktop viewport. Web servers: `php -S 127.0.0.1:8000 -t .` with `ENV`
(`DB_NAME=zidle_test`, `ADMIN_PASSWORD=e2e-admin`,
`ORGANIZER_PASSWORD=e2e-organizer`, test IBAN and ticket secret,
`MAIL_ENABLED=0`; the process environment overrides them) and `npm run build
&& vite preview` on 127.0.0.1:4173 (proxies `/api`). `global-setup.js` runs
`tests/e2e/seed.php` (fresh database: run 1 *Premiéra* in 30 days with a
pending reservation, run 2 *Dnes* in 20 minutes with a paid reservation
(BC-1-1 adult, BC-1-2 child) and a second one for the offline test, three discount codes
for *zakaznik@example.com*) and stores its JSON output (`ticket` /
`offlineTicket` = seat ticket codes, `codes`) in `tests/e2e/.seed.json`.
Tests:

- `customer.spec.js` – choose *Premiéra*, the map has as many section areas
  (`.plan-hit`) as the server layout, all reserve seats shown as reserve, and its balcony
  heading, two seats, form (waits for
  `FORM_MIN_SECONDS`, real invisible ALTCHA), payment page with QR, 600 Kč
  and VS, seats taken in `seats.php`, cancel all → *Rezervaci jste zrušili.*
  and seats free; run picker without horizontal scroll; three seats with one
  child (the lap note shown) and a seeded discount code (bad format message,
  *„<code> · místo zdarma“*, 450 Kč on the payment page with the seat kinds) and the used code refused next time; two seats paid by two
  codes → *Rezervovat zdarma*, *Zaplaceno*, two ticket QR codes and the PDF
  download.
- `admin.spec.js` – record 200 Kč (*zbývá doplatit 400 Kč*, input prefilled
  400), then the rest → *Zaplaceno*; discount codes (invalid e-mail →
  nothing created, the same e-mail twice → two codes, revoke one); payment
  deadline rule (*Nyní platí: 24 hodin*, `seats.php` `deadlineHours`, removed
  again); invite an accountant (link shown
  in the tab, status *Čeká na heslo*), in another browser context the link →
  mismatched passwords error → password set and signed in (*Vítejte*,
  *Přihlášen*), sign out and in with e-mail + password, the used link is
  invalid, the admin disables the account → signed out on the next load.
- `scanner.spec.js` – password login; the camera is replaced by a canvas
  stream showing the seeded seat ticket QR (`getUserMedia` stub) → *Platná
  vstupenka* with the seat, *Dospělý* and *rezervace 2 místa · odbaveno 1 z
  2*, next scan *Už odbaveno*; offline (`context.setOffline`, after the snapshot was
  loaded): ticket *Platná vstupenka* *„bez spojení, podle seznamu z“*, strip
  *k odeslání: 1*, next scan *Už odbaveno* (still *k odeslání: 1*);
  back online → *„Odesláno 1 odbavení bez spojení.“* and the seat is checked
  in on the server (`(offline)`); the scanner opens offline after one online visit
  (service worker) with the cached session and the offline strip; tab
  *Prodej*: two seats of the left wing with one child → QR payment 450 Kč → **Zaplaceno –
  vpustit** → *„Zaplaceno 450 Kč – 2 místa odbavena.“* and the seats taken.

Locally `npm run test:e2e` needs PHP, MariaDB with a `zidle_test` database
and the `DB_*` variables; move `api/config.local.php` aside or make sure it
does not set keys the tests rely on. `PLAYWRIGHT_CHROMIUM` can point to an
existing Chromium binary instead of `npx playwright install chromium`.
