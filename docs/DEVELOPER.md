# Koncert TZG 2026 – developer documentation

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
10. [Cancellation (admin) and refunds](#10-cancellation-admin-and-refunds)
11. [Tickets and check-in](#11-tickets-and-check-in)
12. [Accounts, roles and sign-in](#12-accounts-roles-and-sign-in)
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

| Part             | Entry                                         | Users                | Layout        | Tech                |
| ---------------- | --------------------------------------------- | -------------------- | ------------- | ------------------- |
| Customer app     | `index.html` → `src/main.jsx` → `src/App.jsx` | public               | mobile-first  | React 19, Vite      |
| Reservation page | same app, `/?r=<token>`                       | customer             | mobile-first  | React               |
| Scanner          | `scanner.html` → `src/scanner/main.jsx`       | Odbavovatel, Správce | mobile only   | React, `jsqr`       |
| Admin            | `api/admin.php`                               | Správce, Účetní      | desktop-first | server-rendered PHP |
| API              | `api/*.php`                                   | apps above           | –             | PHP 8.4+, PDO MySQL |
| Jobs             | `api/cron.php` (CLI)                          | cron, every 10 min   | –             | PHP                 |
| Database         | `db/schema.sql`                               | –                    | –             | MariaDB 10.5+       |

UI and e-mail texts are Czech. All times are stored in **UTC**; Europe/Prague
is used only for admin input and for display.

Look: customer app light (palette and fonts after tesinzpivagospel.cz, variables
in `src/styles.css`); the scanner is dark – `src/scanner/scanner.css` overrides
the same variables on `:root` (`color-scheme: dark`). The admin uses the same
light palette and fonts (its own inline `<style>` in `admin.php`, same variable
names and values, accent `#c42aae`).

## 2. Repository layout

```
api/
  admin.php            admin UI (login, forgotten password, invitation page, reservations, plan, discount codes, users, settings)
  deploy.php           one-time creation of the first Správce (DEPLOY_TOKEN)
  seats.php            GET runs + taken seats of a run + seating layout
  tickets.php          GET printable tickets (PDF) of a paid reservation
  altcha.php           GET invisible ALTCHA challenge
  reservations.php     POST create reservation, GET reservation by token
  ticket-names.php     POST names on the seat tickets
  organizer.php        scanner API (access, verify, offline, on-site sale)
  cron.php             scheduled jobs (CLI only); cron-expire.php = alias
  config.php           defaults (+ config.local.php / env overrides)
  logs/                PHP error log php-error-YYYY-MM.log (default LOG_DIR; .htaccess denies all, *.log git-ignored)
  lib/bootstrap.php    setup_error_log(), app_log(), log_file(), config(), db(), db_query(), JSON helpers (json_response/json_error end the request), expire_reservations(), QR payment, reservation_payload()
  lib/layout.php       LEVELS, PLAN, SECTIONS (the only layout definition), layout_public(), total_capacity(),
                       section_rows(), section_capacity(), section_seat_ids(), default_reserve_seats(), section_name(),
                       is_valid_seat_id(), compare_seat_ids()
  lib/settings.php     runs: loading, times, open sections (run_capacity()), check-in window,
                       run_public(); payment deadline rules
  lib/pricing.php      reservation_items (load, current/cancelled seats), adult / child seats, discount-free seats, reservation_seat_items()
  lib/discounts.php    discount codes: create + e-mail, revoke, lock for a reservation
  lib/booking.php      book_reservation() (prices, codes, seats, SS, first e-mail), generate_specific_symbol()
  lib/checkin.php      verify_ticket() – online check-in of one seat ticket
  lib/onsite.php       selling at the entrance (scanner tab Prodej)
  lib/cancellation.php cancel_seats() (admin), notices, reminders, GDPR purge
  lib/payments.php     record_payment() (received transfers), amount_due(), send_ticket_for()
  lib/bank.php         bank statement CSV import: parse_bank_csv(), bank_match(), bank_record()
  lib/offline.php      scanning without a connection: scanner_snapshot(), apply_offline_scans(), conflicts
  lib/admin_users.php  accounts and roles: user_can(), admin_login(), create_first_admin(), admin_invite(), admin_accept_invite(), request_password_reset(), reset_password()
  lib/ticket.php       seat ticket QR code (sign/parse), qr_png(), ticket e-mail + calendar event (ticket_ics()), seat_label(s)()
  lib/ticket_pdf.php   printable tickets TZG_2026_vstupenky_<SS>.pdf (dompdf)
  lib/antispam.php     form token, rate limits, login limits
  lib/altcha.php       invisible ALTCHA: challenge, verification, replay protection
  lib/mail.php         deliver_mail() (SMTP via PHPMailer, else PHP mail()) and customer e-mails
  lib/scanner_access.php  scanner sign-in (accounts), device cookie, app_base_url()
db/schema.sql          full schema for a new database
compose.yaml           local Docker stack: web (Vite), app (PHP 8.4 + Apache), db (MariaDB 10.11), adminer, mailpit; test (prepare.sh)
docker/                Dockerfile (stages app, test) + php.ini, config.local.php (empty, mounted over api/config.local.php), db init (zidle_test)
prepare.sh             format + all checks + production build into build/ in Docker (§21)
src/                   customer app
  App.jsx              views: run picker / map / section / reservation page; form; toasts
  hooks/useSeats.js    seat state of one run, polling, reserve()
  data/layout.js       layout loaded from the server (setLayout()), seat ids and positions, occupancy levels, CZK formatting
  data/seatService.js  API client (seats, reservations, cancel)
  components/          RunPicker, Overview (map), SectionDetail, SeatPlan (seats of a section, also in the
                       scanner), ReservationPanel, ReservationForm, PaymentView, Stepper
  pricing.js           seat prices / discount preview (mirror of lib/pricing.php), code formatting
  altcha.js            invisible ALTCHA solver (altcha-lib, WebCrypto PBKDF2)
  runs.js, plural.js   formatting helpers
src/scanner/           organizer scanner (ScannerApp, SaleView, useQrCamera, ticket.js, api.js,
                       offline.js + useOffline.js = offline mode)
public/sw.js           service worker: keeps the scanner on the device for offline start
docs/DEVELOPER.md      this file
docs/prehled/          stakeholder overview (Czech, index.html + screenshots), published as an Artifact
tests/php/run.php      PHP integration tests (needs a *_test database); fixtures.php = shared helpers
tests/e2e/             Playwright tests (customer, admin, scanner), seed.php, invite-link.php, global-setup.js
tests/consistency.mjs  layout and ticket format identical in PHP and JS
phpstan.neon           PHPStan config (level 8)
eslint.config.js       ESLint config (src/ + tests)
.prettierrc.json       Prettier config (.prettierignore)
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
```

`api/composer.json` requires PHP ≥ 8.4 with `ext-gd`, `ext-pdo_mysql` and
`ext-mbstring` (`config.platform.php` 8.4.0, so the lock resolves for 8.4);
`intl` is optional (`Transliterator` for ASCII names, with a fallback).

```bash
cp api/config.local.example.php api/config.local.php   # fill in TICKET_SECRET, DEPLOY_TOKEN, …

npm install
php -S 127.0.0.1:8000 -t .   # API (dev)
npm run dev                  # Vite; /api is proxied to PHP_SERVER (default 127.0.0.1:8000)
npm run build                # dist/index.html, dist/scanner.html
```

**Docker (local development only).** `docker compose up -d` starts:

| Service   | Host URL                               | Notes                                                                                                                                                                                                                                                                                             |
| --------- | -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `web`     | http://localhost:5180 (`WEB_PORT`)     | `node:22`: `npm ci` (Linux `node_modules` in volume `node-modules`), then Vite dev server with `PHP_SERVER=http://app`; open the customer app here (`/scanner.html` = scanner)                                                                                                                    |
| `app`     | http://127.0.0.1:8100 (`APP_PORT`)     | `php:8.4-apache` with gd, intl, pdo_mysql, zip, Composer; repo mounted at `/var/www/html` (document root = repo root, `.htaccess` applies); runs `composer install -d api` on start when `api/vendor/` is missing; API and `api/admin.php` only (`src/*.jsx` is not served correctly – use `web`) |
| `db`      | 127.0.0.1:3307 (`DB_PORT`)             | MariaDB 10.11, utf8mb4; on the first start (empty volume `db-data`) creates `zidle` from `db/schema.sql` and an empty `zidle_test`; user `zidle` / `zidle` (root `root`)                                                                                                                          |
| `adminer` | http://127.0.0.1:8180 (`ADMINER_PORT`) | server `db`                                                                                                                                                                                                                                                                                       |
| `mailpit` | http://127.0.0.1:8125 (`MAILPIT_PORT`) | catches all e-mails (SMTP `mailpit:1025` inside the network)                                                                                                                                                                                                                                      |

Configuration comes from the `environment` of `app` in `compose.yaml` (DB,
test IBAN, dev-only `TICKET_SECRET` / `DEPLOY_TOKEN`, `MAIL_ENABLED` with
`SMTP_HOST=mailpit`, `SMTP_AUTH=0`, `PUBLIC_URL` default
`http://localhost:5180/`); `docker/config.local.php` (returns `[]`) is mounted
over `api/config.local.php` so a host config does not override it.

```bash
docker compose up -d
docker compose exec app php api/cron.php
docker compose exec -e DB_NAME=zidle_test -e MAIL_ENABLED=0 -e SMTP_HOST= app php tests/php/run.php
docker compose down -v   # also deletes the database (schema change → recreate)
```

Deployment: upload `dist/` and `api/` (including `api/vendor/`) side by side
(`./prepare.sh` prepares both in `build/`, §21; add `api/config.local.php` on the server),
so the apps reach the API at `./api/`. HTTPS is required (camera access).
`.htaccess` files deny `config*.php`, `cron*.php`, `composer.*`, `api/lib/`,
`api/vendor/`, `api/logs/`. Behind a proxy/CDN the real client IP must reach
`REMOTE_ADDR` (e.g. Apache `mod_remoteip`), otherwise rate limits are shared.

First Správce (once, while no account has the Správce role; §12):

```bash
curl -X POST https://example.com/zidle/api/deploy.php -H 'Content-Type: application/json' \
  -d '{"token":"<DEPLOY_TOKEN>","email":"spravce@example.com","password":"…"}'
```

Then sign in at `api/admin.php` and invite everybody else (tab _Uživatelé_).
`DEPLOY_TOKEN` may be removed from the configuration afterwards.

Cron: `*/10 * * * * php /path/to/api/cron.php`.

Checks before a commit (the same as CI, §21): `./prepare.sh` – or one by one `npx prettier --check .`, `npm run lint`, `npm run build`,
`npm run test:consistency`, `api/vendor/bin/phpstan analyse -c phpstan.neon`,
`DB_NAME=zidle_test php tests/php/run.php`, `npm run test:e2e`.

Database: the app is not deployed yet, so there are no migrations – a database
is created from `db/schema.sql`; a schema change means recreating it.

Build-time option: `VITE_API_URL` (default `api`) when the API is on another
origin; then set `CORS_ORIGIN`.

## 4. Configuration

`api/config.php` holds defaults; `api/config.local.php` (array, template
`config.local.example.php` – database, bank, passwords, secrets, SMTP,
contact e-mail and phone, `PUBLIC_URL`) and environment variables of the same
name override them (local file wins).

| Key                                                        | Default                                     | Meaning                                                                                                                                                                                    |
| ---------------------------------------------------------- | ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`      | `127.0.0.1`, `3306`, `zidle`, `zidle`, `''` | PDO connection; session `time_zone = '+00:00'`                                                                                                                                             |
| `SEAT_PRICE`                                               | 300                                         | CZK per adult seat (from 18 years) for new reservations                                                                                                                                    |
| `CHILD_SEAT_PRICE`                                         | 150                                         | CZK per child seat (4–17 years); children up to 3 years sit on a lap for free (no seat, no ticket)                                                                                         |
| `PAYMENT_DEADLINE_HOURS`                                   | 72                                          | hours to pay before the first payment deadline rule (§8); due date capped at the run start                                                                                                 |
| `PAYMENT_GRACE_HOURS`                                      | 48                                          | unpaid reservations expire this long after the due date                                                                                                                                    |
| `REFUND_DAYS`                                              | 14                                          | refund promise in e-mails                                                                                                                                                                  |
| `DATA_RETENTION_DAYS`                                      | 30                                          | personal data purged this long after the last run start                                                                                                                                    |
| `MAX_SEATS_PER_RESERVATION`                                | 20                                          | also enforced in the UI                                                                                                                                                                    |
| `EVENT_LOCATION`                                           | `''`                                        | `LOCATION` of the calendar event in the ticket e-mail (`udalost.ics`); `''` = omitted                                                                                                      |
| `EVENT_DURATION_MINUTES`                                   | 120                                         | calendar event end = run start + this (min. 1)                                                                                                                                             |
| `SEATS_PER_IP_PER_HOUR`                                    | 40                                          | seats one IP may reserve per hour (`seat_quota_take()`; a quarter of it when ALTCHA is disabled); generous for families booking from one shared network                                    |
| `SEATS_PER_IP_PER_DAY`                                     | 80                                          | the same per 24 hours                                                                                                                                                                      |
| `ALTCHA_CHALLENGES_PER_IP_PER_HOUR`                        | 300                                         | challenges issued by `altcha.php` per IP                                                                                                                                                   |
| `PENDING_RESERVATIONS_PER_EMAIL`                           | 2                                           | unpaid reservations per e-mail **in one run**                                                                                                                                              |
| `LOGIN_ATTEMPTS_PER_15_MIN`                                | 10                                          | admin login, invitation (password set), forgotten password (request and new password), scanner login, per IP and area                                                                      |
| `FORM_MIN_SECONDS`                                         | 3                                           | minimal age of the form token                                                                                                                                                              |
| `ALTCHA_ENABLED`                                           | `true`                                      | invisible ALTCHA on the reservation form                                                                                                                                                   |
| `ALTCHA_COST`                                              | 1000                                        | PBKDF2 iterations per attempt                                                                                                                                                              |
| `ALTCHA_COUNTER_MAX`                                       | 6000                                        | attempts needed: random `max/3 … max` (≈ 1–2 s on a computer, ≈ 4–5 s on a slow phone)                                                                                                     |
| `BANK_IBAN`                                                | **required**                                | reservations and on-site sales are refused (503) without it                                                                                                                                |
| `BANK_BIC`                                                 | `''`                                        | appended to SPD `ACC`                                                                                                                                                                      |
| `BANK_ACCOUNT_DISPLAY`                                     | `''`                                        | Czech account number shown to customers                                                                                                                                                    |
| `PAYMENT_RECIPIENT`                                        | `Farnost`                                   | SPD `RN`                                                                                                                                                                                   |
| `PAYMENT_MESSAGE`                                          | `Koncert TZG 2026`                          | SPD `MSG` prefix (+ last name)                                                                                                                                                             |
| `PAYMENT_VARIABLE_SYMBOL`                                  | `3211`                                      | variable symbol (VS) of every payment (SPD `X-VS`, e-mails, payment page), digits only, max 10; reservations differ by their own specific symbol (SS)                                      |
| `DEBUG`                                                    | `false`                                     | the 500 page also shows the uncaught exception and the log file; only briefly for diagnosis                                                                                                |
| `LOG_DIR`                                                  | `api/logs`                                  | folder of the PHP error log (see below); best outside the web root; `''` = the server's own log                                                                                            |
| `DEPLOY_TOKEN`                                             | `''`                                        | token for `POST api/deploy.php` that creates the first Správce (§12); ≥ 32 characters, `''` = disabled; may be removed afterwards                                                          |
| `SCAN_WINDOW_BEFORE_MINUTES` / `SCAN_WINDOW_AFTER_MINUTES` | 60 / 60                                     | check-in window around the run start                                                                                                                                                       |
| `TICKET_SECRET`                                            | **required**                                | ≥ 32 chars without spaces (`ticket_secret()` throws otherwise, so the placeholder can't be left in); HMAC key for tickets, form tokens, master cookie; never change after tickets are sent |
| `MAIL_ENABLED`                                             | `false`                                     | all e-mails off when false                                                                                                                                                                 |
| `MAIL_FROM`                                                | `rezervace@example.com`                     | sender for PHP `mail()` (SMTP uses `SMTP_SENDER`)                                                                                                                                          |
| `SMTP_HOST`                                                | `''`                                        | SMTP server; `''` = send with PHP `mail()`                                                                                                                                                 |
| `SMTP_PORT`                                                | 587                                         | 465 = implicit TLS (SMTPS); other ports use STARTTLS when the server offers it                                                                                                             |
| `SMTP_AUTH`                                                | `true`                                      | log in with `SMTP_USER` (or `SMTP_SENDER`) / `SMTP_PASSWORD`                                                                                                                               |
| `SMTP_SENDER`                                              | `''`                                        | sender address (`From`, name _Koncert TZG 2026_)                                                                                                                                           |
| `SMTP_USER`                                                | `''`                                        | SMTP login when it differs from the sender (e.g. `apikey`); `''` = `SMTP_SENDER`                                                                                                           |
| `SMTP_PASSWORD`                                            | `''`                                        | SMTP password                                                                                                                                                                              |
| `CONTACT_EMAIL`                                            | `''`                                        | organizers' contact: website footer, last line of every e-mail, `Reply-To` of e-mails                                                                                                      |
| `CONTACT_PHONE`                                            | `''`                                        | organizers' phone: website footer and e-mails                                                                                                                                              |
| `PUBLIC_URL`                                               | `''`                                        | base URL for links in e-mails, invitations and the admin's _Odbavení_ link (else derived from the request)                                                                                 |
| `CORS_ORIGIN`                                              | `''`                                        | allowed origin when the apps run elsewhere                                                                                                                                                 |

**Error log.** `setup_error_log()` (first thing `lib/bootstrap.php` runs, so
every entry point and `cron.php`) sets `log_errors`, turns `display_errors` off
outside the CLI and installs its own handlers, so the log does not depend on
PHP's `error_log` setting (hosts often fix it with `php_admin_value`). The app
writes the file itself: `app_log()` appends `[YYYY-MM-DD HH:MM:SS UTC] [zidle]
<message>` to `log_file()` = `LOG_DIR/php-error-YYYY-MM.log` (UTC month, one
file per month, never rotated or deleted by the app). The folder is created
when missing, with a `.htaccess` `Require all denied`; when `LOG_DIR` is `''`
or not writable, lines go to PHP's `error_log()` (the server's log). Logged:
warnings, notices and deprecations as `PHP Warning: <message> in
<file>:<line> (<request>)` (the CLI still prints them too), fatal errors at
shutdown as `PHP Fatal error: …`, uncaught exceptions as `[zidle] Uncaught on
<request>: <exception with stack trace>`, errors caught by `run_api()` as
`[zidle] Error on <request>: …`, and the app's own lines (failed e-mails,
rejected bots, …). `<request>` is `<METHOD URI>`, or `CLI <command line>`.
An uncaught exception in the CLI is also printed to stderr (exit code 255);
on the web the visitor gets a plain-text 500 _„Chyba serveru. Zkuste to
prosím znovu, případně kontaktujte pořadatele.“_ (with `DEBUG` on followed by
`[DEBUG] <exception>` and `Log: <file>` or _log serveru (LOG_DIR nelze
použít)_); JSON endpoints keep their own 500 from `run_api()`. Nginx ignores
`.htaccess`: there set `LOG_DIR` outside the web root or deny `/api/logs/`.

## 5. Domain model

- **Run** (`runs`) – one performance. `starts_at`, optional `label`, optional
  `booking_closes_at`, `reserve_seats` (seats that are
  „Záložní“ in this run), `is_test`. Seats, reservations and check-in are per run.
- **Test run** (`is_test` = 1, admin checkbox _Testovací termín_) – for
  trying the scanner before the event: not in the public run list of
  `seats.php` (only when requested as `?run=<id>`, i.e. the customer link
  `PUBLIC_URL/?termin=<id>` – the id is guessable, the link is not a secret);
  `run_label()` / `runLabel()` end with _„ · TEST“_ (picker card badge
  _TEST_); online bookings are paid at once, discount codes are refused
  (§8); e-mail subjects start with _„[TEST] “_ (§15); same scan window and
  check-in as a real run; left out of the admin summary cards (§14,
  `NOT_TEST_RUN_SQL`) and of `last_run_start()` (§16); can be deleted with
  its reservations (`delete_run()`).
- **Section / seat** – fixed layout, defined only in `api/lib/layout.php`
  after the hand-drawn plan of the church: `LEVELS` (main _Hlavní sál_ /
  balcony _Balkon_), `PLAN` (per level a map 100 units wide, `height` units
  tall, with decorations `items`: main – _Pódium_ on top as wide as both main
  sections, _Kapela_ box above the left wing with its top level with the first
  rows of the main sections and the right wing; balcony – the open middle `void` drawn as a U with _↑ k
  pódiu_) and `SECTIONS` id → name, short name, level, `label` [x, y, align] (align `center` / `left` / `right` – x is the centre / left / right edge)
  (where the name stands on the map) and `rows`: one `[seats, x1, y1, x2, y2]`
  per row (row 1 first – nearest the stage or the balcony railing; the first
  seat at (x1, y1), the last at (x2, y2), the others evenly between, so rows
  may be horizontal, vertical or slanted); a row split by a gap is `['parts'
=> [[seats, x1, y1, x2, y2], …]]` (numbering continues across the parts);
  `'reserve' => true` marks a reserve row (its seats are „Záložní“ in a new
  run). `section_rows()` brings
  both forms to `{seats, reserve, parts}`. The apps
  get it as `layout` from `seats.php` (customer) and `GET organizer.php`
  (scanner): `layout_public()` = `{levels, plan, sections: [{id, name, short,
level, label: {x, y, align}, rowLabel, rows: [{seats, reserve, parts: [{seats, x1, y1,
x2, y2}]}]}]}`
  in `SECTIONS` order. `src/data/layout.js` holds no data: `setLayout(layout)`
  fills its live exports `LEVELS`, `PLAN`, `SECTIONS`, `SECTION_BY_ID`,
  `TOTAL_CAPACITY` before the first map/scanner view is drawn (`useSeats` on
  every `seats.php` response, `ScannerApp` from the session or the cached
  one); `seatPositions(section)` and `seatSpacing(section)` derive the seat
  positions:

  | Id | Name (short) | Level | Seats per row (row 1 first) | Capacity |
  | --- | ------------------------------------------------------------------------------------------------- | ------- | -------------------------------------------------------------------------------------------------------------------- | -------------------- | --------------- |
  | LK | Levé křídlo (L. křídlo) – just below the band, its name above the band level with the other names; aisle to LH 7 units | main | 5, 5, 5, 5 | 20 |
  | LH | Levá hlavní (L. hlavní) – a narrow aisle to PH | main | 9 × 8 | 72 |
  | PH | Pravá hlavní (P. hlavní) | main | 9 × 8 | 72 |
  | PK | Pravé křídlo (P. křídlo) – aisle to PH 7 units | main | 4, 4, 4, 5, 5, 5, 4 (the last row set apart) | 31 |
  | BL | Balkon vlevo (Balkon L) | balcony | 12 (bench along the railing, seats 1–5 and 6–12 split by a gap); reserve rows 2–4: 11, 11, 6 (rows 2 and 3 split 1–5 | 6–11 like the bench) | 40 (28 reserve) |
  | BS | Balkon střed (Balkon S) | balcony | 7, 14, 14, 7, 7 (rows 4–5 shifted by 5 seats) | 49 |
  | BP | Balkon vpravo (Balkon P) | balcony | 11 (bench along the railing, seats 1–6 and 7–11 split by a gap, mirroring the left part); reserve rows 2–3: 3, 3 | 17 (6 reserve) |
  | | total (main 195, balcony 106) | | | 301 |

  Seat id `SECTION-ROW-SEAT`, e.g. `LH-1-1`, `BS-2-14`; `is_valid_seat_id()`
  accepts only this exact form (no leading zeros, nothing after it – one chair
  has one id) and checks the row and the seat count of that row;
  `book_reservation()` checks it again.

- **„Záložní“ seats** (reserve): shown on the map but not for sale. Per run
  `runs.reserve_seats` (JSON seat ids; NULL = `default_reserve_seats()` = the
  reserve rows of `BL` and `BP`, 34 seats); the admin switches any seat of a
  run between for sale and „Záložní“ on the plan (tab _Plánek_, §14).
  `run['reserve_seats']`, `seat_closed_in_run(run, seat)`, `run_capacity(run)`
  = all seats − „Záložní“ (267 by default). „Záložní“ seats cannot be booked
  or sold on site (§8).
- **Reservation** – one run, customer data, price (`amount`), SS, status,
  money history (refunds, check-in). Its seats are **items**
  (`reservation_items`, one row per seat ever in it): each seat is an
  **adult** (from 18, `SEAT_PRICE`) or **child** seat (4–17,
  `CHILD_SEAT_PRICE`); the item stores the price when booked (`price`; 0 and
  `free` = 1 for a seat paid by a discount code), the name on the ticket
  (`holder_name`), `cancelled_at` when the seat was cancelled on its own and
  its check-in. Children up to 3 years may sit on an adult's lap for free; they
  are not recorded (the form only says so). Current seats = items without
  `cancelled_at` (`current_items()`, `current_seat_ids()`); `amount` = sum of
  their prices. A whole cancelled or expired reservation keeps its items
  unchanged (history, e-mails, restore by a late payment). PHP reads them with
  `reservation_items(r)` (`[seat => {seat, kind, price, free, holder, cancelled,
checkedInAt, checkedInBy}]` in seat order; one query, or `r['items']` when
  preloaded – `with_items()`, `load_reservation_items(ids)` for lists).
- **Ticket** – one per seat (1 QR code = 1 chair, §11); checked in per seat
  (`reservation_items.checked_in_at` / `checked_in_by`).
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
- **Account** (`admin_users`) – a person with one or more roles: Správce,
  Účetní, Odbavovatel (§12).

## 6. Database

All `DATETIME` columns and JSON dates are UTC.

| Table               | Purpose / key columns                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `runs`              | `id`, `label`, `starts_at`, `booking_closes_at` (NULL = start), `reserve_seats` JSON `["BL-2-1", …]` (seats „Záložní“ in the run; NULL = default reserve rows), `is_test` (1 = test run, §5)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| `reservations`      | `id`, `token` (32 hex, unique), `run_id`, `first_name`, `last_name`, `email`, `amount` (price of current seats = sum of their items), `paid_amount` (sum of all received transfers), `specific_symbol` (SS of the payment, unique), `status` (`pending`/`paid`/`expired`/`cancelled`), `created_at`, `expires_at` (= due date shown to the customer), `paid_at`, `cancelled_at`, `refund_amount`, `refunded_amount`, `refunded_at`, `ticket_sent_at`, `reminder_sent_at`, `expiry_notice_sent_at`, `checked_in_at` / `checked_in_by` (first seat checked in), `sold_by` (e-mail of the account that sold it at the entrance, NULL = website), `payment_checked_at` (accountant verified an on-site payment) |
| `reservation_items` | PK (`reservation_id`, `seat_id`), `kind` (`adult`/`child`), `price` (CZK when booked, 0 = discount code), `free` (1 = paid by a discount code), `holder_name` (name on the ticket, NULL = reservation name), `cancelled_at` (seat cancelled on its own), `checked_in_at` / `checked_in_by`; key `seat_id`; FK cascade. One row per seat ever in the reservation.                                                                                                                                                                                                                                                                                                                                            |
| `discount_codes`    | `code` (8 chars, unique, shown `XXXX-XXXX`), `email`, `created_at`, `created_by`, `emailed_at`, `used_at`, `reservation_id`, `seat_id` (the seat made free), `revoked_at`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| `reservation_seats` | PK (`run_id`, `seat_id`), `reservation_id` (FK cascade). Prevents double booking per run.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| `scan_conflicts`    | offline check-ins that could not be applied (§11): `run_id`, `reservation_id` (NULL when unknown), `label` (SS + name + seat), `reason` (`already_checked_in` / `not_paid` / `unknown`), `scanned_at` (device time), `scanned_by`, `other_at` / `other_by` (the check-in that won), `created_at`                                                                                                                                                                                                                                                                                                                                                                                                            |
| `bank_payments`     | transfers recorded from a bank statement (§9), so none is recorded twice: `transaction_id` (PK, the bank's _Identifikace transakce_ or `h:` + sha1 of the row), `reservation_id` (FK, SET NULL), `amount` (CZK), `booked_on` (DATE from the statement), `imported_at`, `imported_by` (e-mail); no personal data                                                                                                                                                                                                                                                                                                                                                                                             |
| `rate_limits`       | `bucket` (sha256 of key), `hits`, `window_start`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| `settings`          | `name`, `value` (JSON): `payment_deadlines` = `[{"from":"YYYY-MM-DDTHH:MM:SSZ","hours":24}]` (§8)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| `admin_users`       | accounts (§12): `email` (unique, lower case, login – the account has no other name), `roles` (CSV of `admin` / `accountant` / `scanner`), `password_hash` (`password_hash()`, NULL until the invitation is accepted), `invite_hash` (sha256 of the open invitation token, unique), `invite_expires_at`, `reset_hash` (sha256 of the open forgotten-password token, unique), `reset_expires_at`, `invited_by` (e-mail of the inviting Správce, `deploy` for the first one), `created_at`, `accepted_at`, `last_login_at`, `disabled_at`                                                                                                                                                                      |
| `user_runs`         | PK (`user_id`, `run_id`), FKs to `admin_users` and `runs` (cascade): runs an Odbavovatel checks in (§12)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |

Schema changes: update `db/schema.sql` (no migrations, §3).

## 7. Customer app

**Run picker** (`RunPicker.jsx`) is shown while no run is chosen
(`?termin=<id>` absent). Cards: weekday, date, time, label, free seats
(`"N volných míst"`), occupancy colour (`capacity` = seats for sale in the run); disabled for `Vyprodáno` or
`Rezervace uzavřeny` (`bookingOpen` false). The chosen run is written to
`?termin=` (replaceState). A run missing from the list resets the choice.
Test runs (§5) are not listed unless opened by their link; their card has a
_TEST_ badge.

Until the first `seats.php` answer (layout) the app shows _Načítám…_.

**Map** (`Overview.jsx`): one card per level (_Balkon_ with its heading),
each a `LevelMap` drawn from `PLAN` and the section rows with % positions
(aspect ratio 100 : `height`): decorations (stage, band, U-shaped balcony
void with _↑ pódium_ at its top), every seat as a small square (white = free, light red = taken, red =
own selection, neutral grey `#d9d8dc` dashed = reserve seat not for sale; size = 0.78 × the
section's seat spacing), the section's area as a button coloured by `occupancyLevel` (`< 50`
low/green, `50–84` medium/amber, `≥ 85` high/red) that opens the section, and
the label (short name + _N volných_, red count of own seats; _Balkon vlevo_
right-aligned to its bench and _Balkon vpravo_ left-aligned to its bench
(`align` `right` / `left`, so they stay clear of the void) in the top 8 units of the balcony plan,
_Balkon střed_ below its area). Run bar above the map
(one line: run, **Změnit termín**; on phones < 641 px the words _Termín_ /
_termín_ are hidden – `.wide-only`) (asks `confirm()` when seats are
selected; selection is cleared on run change).

**Section detail** (`SectionDetail.jsx`): one header row – back button (**←**,
_Zpět na mapu_ shown from 641 px), level + section name, chip _N volných_
coloured by occupancy; _Pódium_ with a direction arrow from the plan: a
section whose seats centre left of the main stage / the balcony void (`PLAN`
item `stage` / `void`) gets _↗ Pódium_ (L. křídlo, Balkon L), right of it
_↖ Pódium_ (P. křídlo, Balkon P), within it no arrow on the main floor and
_↑ Pódium_ on the balcony;
the seats laid out as on the map (`SeatPlan.jsx`: the same row geometry,
scaled so a seat is 30–44 px – wider sections such as _Balkon střed_ scroll
sideways – row numbers before seat 1 of each row, or after the last seat when
the section has `row_label` `end` → `rowLabel: "end"` (L. křídlo, Levá
hlavní: on the right), seat number on each
seat); _↓ Vchod_ on the main floor; legend (+ _Záložní_ when the section has
„Záložní“ seats). Seat states: `available` (light green), `selected`
(violet), `taken` (light pink, disabled), `reserve` (neutral grey `--seat-reserve` #d9d8dc, dashed edge #8e8b94, disabled – clearly apart from the pink taken seats).

**Selection** (`useSeats(runId)`): local only; polling `seats.php?run=` every
30 s and on window focus; seats taken meanwhile are dropped with toast
`Místa … mezitím rezervoval někdo jiný.`; max `maxSeats` (`Najednou lze
rezervovat nejvýše N míst.`); when booking is closed seats cannot be
selected (`Rezervace na tento termín jsou uzavřeny.`); „Záložní“ seats
(`run.reserveSeats`) have the state `reserve` and cannot be selected; free
seats and section capacities leave them out.

**Top bar**: free seats, total price (selected seats × adult price), **Rezervovat** (or `Rezervace uzavřeny`).
**Footer** (all customer views): _Kontakt na pořadatele_ with `CONTACT_EMAIL`
(`mailto:`) and `CONTACT_PHONE` (`tel:`) from `seats.php` → `contact`; hidden
when neither is configured.
**Reservation panel** (`ReservationPanel.jsx`): selected seats per section,
remove chip, total (× adult price), note _„Dětské vstupenky a slevový kód v
dalším kroku.“_,
Reserve; shown on the map when something is selected and in a section detail
with own seats.

**Form** (`ReservationForm.jsx`; on phones < 521 px a full-width bottom
sheet): _„<run> · N míst“_; _Dospělí · 300 Kč_ (seats − children), stepper
(`Stepper.jsx`, − / +) _Děti 4–17 let · 150 Kč_ (0…seats), note _„Děti do 3
let mohou sedět zdarma na klíně dospělého.“_; _Slevový kód_: input +
**Použít** (Enter too) → a line _„XXXX-XXXX · místo zdarma“_ with × per
code; local checks _„Kód má 8 znaků, např. ABCD-EFGH.“_, _„Tento kód už je
zadaný.“_, _„Víc kódů než míst nelze použít.“_; validity is checked by the
server on submit. _Celkem_ + total computed by `priceSeats()`
(`src/pricing.js`, same rules as the server); first/last name (side by
side), e-mail (client validation), honeypot `hp`, invisible ALTCHA (solving
starts when the form opens; submit waits for it with the button text
_Ověřuji…_; on an `altcha` error it solves a new challenge and retries once),
notes (_„Zaplaťte do N hodin, jinak se místa uvolní.“_ with the hours in
effect now – only when there is something to pay, _„Vstupenky přijdou
e-mailem.“_), submit **Rezervovat a
zaplatit** (total 0: **Rezervovat zdarma**). Sent as `children`, `codes`.
On a test run (`run.test`) the code field is hidden, the deadline note is
_„Testovací termín – rezervace bude rovnou zaplacená.“_ and the button
**Rezervovat (test)**.
Server field errors (incl. `codes`) are shown at the fields. 409/403 close
the form with a toast and refresh.

**Reservation page** (`PaymentView.jsx`, `/?r=<token>`): header row like the
section detail (**←**, SS + name, status chip); run, price of the seats; list
of seats with kind and price (_„kód, zdarma“_ for a seat paid by a code); pending → SPD QR + copyable account (`BANK_ACCOUNT_DISPLAY`, when set)/VS (`PAYMENT_VARIABLE_SYMBOL`)/SS (the reservation's)/amount (no IBAN – it is in the QR code)
(amount = still to pay, `payment.amount`) + due date (red overdue notice
after it); after a partial payment also _„Už jsme přijali X z Y. Doplaťte
prosím zbývajících Z se stejným variabilním a specifickým symbolem.“_; paid → section
_Vstupenky_ (_„1 QR kód = 1 místo. Ukažte je u vchodu v mobilu, nebo
vytiskněte.“_, button **PDF k tisku** → `tickets.php`): cards two per row on
phones (QR, full section name, _řada · místo_, kind, _odbaveno_ when checked
in); while `ticketNamesEditable` (run not started) each card ends with
the input _Jméno na vstupence_ (always editable, placeholder = the
reservation name, max 100), saved on blur / Enter when changed →
`ticket-names.php` with that one seat, then _„Ukládám…“_ → _„Uloženo“_ or
the error under it; otherwise the card shows the name when set; the seat list
(full section name · _řada_ · _místo_) shows the name after each seat;
cancellation info after the organizers cancelled (`CancellationInfo`:
_„Rezervace byla zrušena.“_ / _„Zrušená místa: …“_, _Vráceno X_ and the refund
line; for an expired reservation with money to return _„Rezervace propadla,
přijatá platba se vrací.“_; for a paid one with a surplus _„Přišlo víc, než
bylo potřeba.“_ + refund line). Customers cannot cancel – there is no cancel
button and no endpoint (§10).

## 8. Reservation lifecycle

```
pending ──payment recorded, whole price arrived──▶ paid
pending ──expires_at + GRACE passed──────────────▶ expired ──payment recorded──▶ paid (run not started, price covered, seats free)
(new, price 0 – all seats paid by discount codes) ──▶ paid at once
(new online booking on a test run) ──▶ paid at once (paid_amount = amount)
pending / paid ──cancel (customer/admin)─────────▶ cancelled
```

`expire_reservations()` (every API request and cron): sets `expired` +
`cancelled_at` for `pending` with `expires_at <= now − PAYMENT_GRACE_HOURS`
(a part of the price that already arrived becomes `refund_amount`), then
deletes `reservation_seats` of `expired`/`cancelled` reservations.

**Create** (`POST reservations.php`), checks in order:

| Check | Response |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| `BANK_IBAN` set | 503 `Platby nejsou nastaveny (BANK_IBAN).` |
| run exists | 422 `Vyberte termín.` |
| `run_booking_open()` | 403 `Rezervace na tento termín jsou uzavřeny.` |
| honeypot empty, form token valid | 400 `Rezervaci se nepodařilo odeslat. Obnovte stránku a zkuste to znovu.` |
| ALTCHA payload valid and unused (if `ALTCHA_ENABLED`) | 400 `Ověření proti robotům se nezdařilo. Zkuste to prosím znovu.` + `code: "altcha"` |
| names (after `clean_name()`: line breaks, control and invisible format characters, `                                                                                              |` → one space), e-mail, 1–20 valid unique seats | 422 `Zkontrolujte zadané údaje.` / seat message, `fields` |
| `children` integer 0…seats | 422 `Dětských vstupenek může být nejvýše tolik, kolik je míst.` |
| `codes` array, ≤ seats, each once, 8 chars of the code alphabet (after `normalize_discount_code()`) | 422 `Slevových kódů může být nejvýše tolik, kolik je míst.` / `Každý slevový kód lze zadat jen jednou.` / `Slevový kód X neplatí nebo už byl použit.` (field `codes`) |
| seats of the IP within `SEATS_PER_IP_PER_HOUR` and `SEATS_PER_IP_PER_DAY` (`seat_quota_take()`; a refused request counts nothing, a booking that fails later gives the seats back) | 429 `Z tohoto zařízení už bylo rezervováno příliš mnoho míst. Zkuste to prosím později.` |
| < 2 pending for the e-mail in this run (not for a reservation costing 0, a test run or an on-site sale) | 429 `Na tento e-mail už na toto představení čekají nezaplacené rezervace. Nejdříve je prosím uhraďte.` |
| no „Záložní“ seat (`seat_closed_in_run()`) | 409 `Některá místa zatím nejsou v prodeji.` + `conflict` |
| seats free in the run (`FOR UPDATE`; duplicate key fallback; a deadlock of simultaneous bookings, SQLSTATE 40001, retries the transaction up to 3 times) | 409 `Některá místa už mezitím někdo rezervoval.` + `conflict` |
| every code exists, unused, not revoked (`lock_discount_codes()`, `FOR UPDATE`) | 422 `Slevový kód XXXX-XXXX neplatí nebo už byl použit.` + `fields.codes` |

The last three checks and the rest are `book_reservation()`
(`lib/booking.php`). Prices: seats sorted, the last `children` seats are
child seats (`assign_child_seats()`); each code frees one seat, the most
expensive first – adult seats, then child seats, in seat order
(`assign_free_seats()`); per seat kind, price and `free`
(`new_reservation_items()`), `amount` = sum of the prices. In one
transaction: insert reservation (`expires_at = min(now + payment_deadline_hours(now), run start)`; amount 0 →
status `paid`, `paid_at` now; online booking on a test run → status `paid`,
`paid_amount` = amount, `paid_at` now), random unique 10-digit SS
(`generate_specific_symbol()`), mark the codes used (`used_at`,
`reservation_id`, `seat_id` = the freed seat), insert items
(`insert_reservation_items()`) and held seats. Then test run →
ticket e-mail (intro _„děkujeme za rezervaci. Jde o testovací termín –
rezervace je automaticky zaplacená.“_), amount 0 →
ticket e-mail _Vstupenky_ (intro _„děkujeme za rezervaci. Všechna místa jsou
hrazená slevovými kódy, rezervace je potvrzena.“_), otherwise the payment
e-mail; respond 201 with `reservation_payload()`. Codes on a test run (also
an on-site sale) → 422 _„Na testovacím termínu nelze použít slevové kódy.“_
(field `codes`) before anything else; on-site sales on a test run keep the
normal flow (QR payment, §11).

**Payment deadline rules** (admin → Nastavení → _Splatnost plateb_, settings
`payment_deadlines`): rows _Rezervace od_ (Prague input, stored UTC) +
_Splatnost (hodin)_ 1–720. `payment_deadline_hours(at)` = hours of the latest
rule with `from ≤ at`, else `PAYMENT_DEADLINE_HOURS`. Applies to reservations
made from then on (existing ones keep their due date); `seats.php`
`deadlineHours` = hours in effect now.

## 9. Payments

- **SPD** (`spd_string()`): `SPD*1.0*ACC:<IBAN>[+BIC]*AM:<amount>*CC:CZK*X-VS:<PAYMENT_VARIABLE_SYMBOL>*X-SS:<SS>*DT:<due date Prague YYYYMMDD>*MSG:<PAYMENT_MESSAGE + last name, ASCII, ≤60>[*RN:<recipient ≤35>]`.
  `AM` = `amount_due()` (what is still missing after a partial payment).
- Payments are matched by SS – manually or from an uploaded bank statement
  (below). The accountant records every received
  transfer with its real amount (admin action `payment`, field `received`
  1–1 000 000 Kč; pending rows: input prefilled with `amount_due()` + button
  **Zaplaceno**; other statuses: _přišla platba_ → input + **Zapsat platbu**
  with a `confirm()` explaining the outcome). The pending form also sends
  `paid_before` (the amount received when the page was shown) and ignores a
  second submit; `record_payment(id, received, paidBefore)` refuses the payment
  when `paid_amount` differs (_„Platba u této rezervace už byla mezitím zapsána
  – nic dalšího se nezapsalo. Zkontrolujte stav rezervace.“_), so a double
  click or a resent form can't create a refund for money that never came.
  Otherwise it adds it to `paid_amount` and by status:

  | Status                                                    | Result                                                                                                                        | Customer e-mail                                                        |
  | --------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------- |
  | pending, kept ≥ `amount` (`complete_if_covered()`)        | `paid`, `paid_at`; surplus `paid_amount − amount` → `refund_amount`                                                           | _Platba přijata – Vaše vstupenky_ (intro adds the surplus refund line) |
  | pending, less                                             | stays pending; admin row shows _zbývá doplatit X_                                                                             | _Přijata část platby_ (missing amount + payment details)               |
  | expired, run not started, kept ≥ `amount`, all seats free | seats re-inserted, `paid` (surplus refunded as above; refund of an earlier partial payment cancelled unless already returned) | _Platba přijata – Vaše vstupenky_                                      |
  | expired otherwise                                         | stays expired; `refund_amount += received`                                                                                    | _Vrácení platby_                                                       |
  | cancelled                                                 | `refund_amount += received`                                                                                                   | _Vrácení platby_                                                       |
  | paid (sent twice etc.)                                    | `refund_amount += received`                                                                                                   | _Vrácení platby_                                                       |

  Reasons in _Vrácení platby_ and the admin message (`REFUND_REASONS`):
  `after_run` _platba dorazila až po představení_, `taken` _místa mezitím
  obsadil někdo jiný_, `short` _rezervace už propadla a platba nepokrývá celou
  částku_, `cancelled` _rezervace už byla zrušená_, `extra` _rezervace už byla
  zaplacená, jde o platbu navíc_. Admin messages: _„Přijato X – zaplaceno.
  [Přeplatek Y k vrácení.]“_, _„Přijato X, zbývá doplatit Y.“_, _„Přijato X,
  rezervace obnovena – zaplaceno.“_, _„Přijato X – <reason>. K vrácení na účet
  plátce: Y.“_ + ticket/e-mail result.

- **Bank statement** (`lib/bank.php`, admin tab _Výpis z banky_, Správce and
  Účetní): instead of recording transfers one by one, upload the CSV export of
  the account (KB MojeBanka; Fio column names work too).
  `parse_bank_csv()`: max 2 MB (`BANK_CSV_MAX_BYTES`, _„Soubor je příliš velký
  (nejvýš 2 MB).“_), empty → _„Soubor je prázdný.“_; UTF-8 (BOM dropped) or
  Windows-1250 (converted by `iconv`); lines before the header are skipped –
  the header is the first line (separator `;`, else `,`) with an amount and an
  SS column (`BANK_COLUMNS`: amount _Částka_/_Objem_, _VS_/_Variabilní symbol_,
  _SS_/_Specifický symbol_, id _Identifikace transakce_/_ID pohybu_/_ID
  transakce_, date _Datum splatnosti_/_Datum zaúčtování_/_Datum_ (`d.m.Y` or
  `Y-m-d`), _Protiúčet a kód banky_/_Protiúčet_, _Název protiúčtu_; message =
  non-empty `BANK_MESSAGE_COLUMNS` _Popis pro příjemce_, _Zpráva pro příjemce_,
  _AV pole 1–4_ joined), else _„Ve souboru chybí řádek se záhlavím (sloupce
  Částka a SS). Nahrajte výpis z internetového bankovnictví ve formátu CSV.“_
  Amounts like `+1 200,50`; rows without a valid amount are ignored, outgoing
  (≤ 0) counted and skipped. VS/SS keep digits without leading zeros. A row
  without a transaction id gets `h:` + sha1 of its values.
  `bank_match(tx)` → `state`:
  - `done` – the transaction is in `bank_payments`: _„Už zapsáno <time>
    (<e-mail>).“_;
  - reservation by `specific_symbol` = SS, else = VS (when not the common
    `PAYMENT_VARIABLE_SYMBOL`, note _„Nalezeno podle VS.“_), else any 10-digit
    number in the message (_„Nalezeno podle zprávy pro příjemce.“_); none →
    `none` _„Platba nemá SS / Rezervace se SS X neexistuje – zapište ručně,
    pokud k rezervaci patří.“_; an amount with haléře (_„Částka s haléři –
    zapište ručně.“_) or over 1 000 000 Kč → `none`;
  - otherwise `match`, a VS other than the common one is noted (_„VS X (má
    být 3211).“_), then by status: pending _„Celá částka → Zaplaceno,
    vstupenka e-mailem.“_ / _„Částečná platba, zbude doplatit X.“_ /
    _„Zaplaceno, přeplatek X k vrácení.“_; expired _„Rezervace propadla –
    obnoví se jako zaplacená, pokud to jde, jinak půjde platba k vrácení.“_;
    paid / cancelled _„Rezervace už je zaplacená / je zrušená – platba půjde
    k vrácení. Pokud jste ji už zapsali ručně, nezapisujte ji znovu.“_
    Pre-checked (`select`) only pending and expired; a pending one that
    already has `paid_amount` > 0 adds _„Už evidováno X – pokud jste tuto
    platbu zapsali ručně, nezapisujte ji znovu.“_ and is not pre-checked.

  `bank_record(tx, by)` re-matches, inserts the `bank_payments` row (`INSERT
IGNORE`; already there → _„Už zapsáno.“_) and calls `record_payment(id,
amount)` (all outcomes and e-mails as above); a refused payment or an
  exception removes the row again. Returns `{ok, message}`.

- Reminder 24 h before the due date, expiry notice after expiry (§16).
- **On-site sale** (`lib/onsite.php`, scanner tab _Prodej_, §11): while seats
  are free, an organizer sells them at the entrance. `sale-create` →
  `book_reservation()` with `sold_by` = device name, name _Prodej na místě_,
  no e-mail, due at once, no pending limit; the scanner shows the SPD QR
  (amount, VS and SS, account). The customer pays in the banking app and shows the
  sent payment; **Zaplaceno – vpustit** (`sale-paid`, `confirm()` _„Zákazník
  ukázal odeslanou platbu X?“_) → `paid`, `paid_amount = amount`, all seats
  checked in by the organizer: _„Zaplaceno X – N místa odbavena.“_
  **Zrušit prodej** (`sale-cancel`) cancels it (admin cancellation, seats
  free): _„Prodej zrušen, místa jsou volná.“_ A sale no longer pending → 409
  _„Prodej už není otevřený.“_ The transfer is not verified at the entrance:
  admin shows such reservations as _na místě – ověřte, že platba dorazila_
  (card and filter _Na místě – ověřit platbu_) with **Platba dorazila**
  (`onsite-checked` → `payment_checked_at`, _„Platba za prodej na místě
  ověřena.“_) instead of _přišla platba_. A sale left pending (neither
  button) expires like any reservation.

## 10. Cancellation (admin) and refunds

Customers cannot cancel reservations or seats themselves (no storno rules or
fees). The admin cancels a whole reservation or single seats (tab
_Rezervace_), an organizer cancels an open on-site sale (§9).

`cancel_seats(id, seatIds|null)` – status pending/paid (else _„Rezervaci už
nelze zrušit.“_):

- seats must belong to the reservation (`Vyberte místa z této rezervace.`);
- refund `cancellation_refund()`: paid → the value of the cancelled seats
  (the item `price`; a seat paid by a discount code is worth 0, so nothing comes
  back for it and the code stays used); pending, all seats → the part of the
  price that already arrived; pending, part → 0;
- all remaining seats → status `cancelled`, `cancelled_at`, held seats deleted
  (the items stay as they were);
- part → the items get `cancelled_at`, `amount` (= value of the remaining
  seats) reduced, their held seats deleted;
- `refund_amount` accumulates; refunds go to the paying account (no account
  is collected);
- pending, part: when what already arrived covers the lower price the
  reservation becomes `paid` (`complete_if_covered()`, surplus refunded; a
  remaining price of 0 – only seats paid by codes – counts as covered);
- admin **Vráceno** sets `refunded_amount = refund_amount`, `refunded_at`;
  due = `refund_amount − refunded_amount`.

E-mails (`send_cancellation_notice()`): paid part → _Nové vstupenky_ (ticket
e-mail with intro: cancelled seats, refund line, _„Posíláme novou
vstupenku na zbývající místa. Původní vstupenka už neplatí.“_ – the tickets of
the cancelled seats stop working, §11); pending part now fully paid → _Platba přijata – Vaše vstupenky_ (intro: cancelled
seats, _„Platba za zbývající místa je kompletní, rezervace je potvrzena.“_,
surplus refund line); other pending part → _Změna rezervace_ (new payment
details); whole → _Rezervace zrušena_ with the refund line; pending whole
additionally _„Pokud jste platbu už odeslali, pošleme Vám
ji po připsání zpět na účet, ze kterého přišla.“_
Refund line: _„Částku X Kč Vám do 14 dnů pošleme zpět na účet, ze kterého
platba přišla.“_

## 11. Tickets and check-in

One ticket = one seat (1 QR code = 1 chair), so a printed sheet can be cut
and the tickets handed out; each seat is checked in separately.

- **Code** (`ticket_code(r, seat)`): `Z26M|<reservation id>|<SS>|<seat>|<kind A/C>|<name>|<sig>`
  (kind A = adult, C = child; name = the name on the ticket, `ticket_holder()`); `sig` = first 22 chars of base64url
  HMAC-SHA256(`TICKET_SECRET`, payload). `parse_ticket_code()` →
  `{id, specificSymbol, seat, kind, name}`; anything else is `invalid`. JS mirror:
  `src/scanner/ticket.js` (display/offline only).
- **Ticket e-mail** (`send_ticket_email()`, HTML + text): run, name, summary
  (`seat_kinds_summary()`, e.g. _„2 dospělí, 1 dítě 4–17 let“_), _„Každé místo má vlastní QR kód – 1 QR kód = 1 místo.“_, then
  per seat the name on the ticket (when set), an inline PNG (`cid:ticket-qr-<n>`), `seat_label()` (_„Levá hlavní ·
  řada 1 · místo 2“_) and _„<kind> · vstupenka n z N“_; footer _„V příloze je
  soubor TZG_2026_vstupenky_<SS>.pdf k vytištění – vstupenky rozstříhejte, každá platí pro
  jedno místo. Při vstupu můžete QR kódy ukázat i na mobilu.“_. Attachments `TZG_2026_vstupenky_<SS>.pdf` (`ticket_pdf_name()`) and
  `udalost.ics`. Resend in admin. The paid reservation page shows the same
  QR codes.
- **Printable PDF** (`ticket_pdf()`, dompdf, DejaVu fonts): A4, note
  _„Rozstříhejte podle čárkované čáry. Každá vstupenka platí pro jedno místo –
  při vstupu se načte její QR kód.“_, then the current seats in seat order,
  `TICKETS_PER_PAGE` = 4 per page, each 60 mm high with a dashed cut line:
  QR, _Koncert TZG 2026_, run, section, _řada / místo_, kind, the name on
  the ticket (`ticket_holder()`), _„SS … · vstupenka n z N“_. `GET tickets.php?token=` downloads it
  (`TZG_2026_vstupenky_<SS>.pdf`) for paid reservations, else 404 _„Vstupenky nejsou
  k dispozici – rezervace není zaplacená.“_
- **Calendar event** (`ticket_ics()`): every ticket e-mail carries the
  attachment `udalost.ics` (`text/calendar; charset=UTF-8; method=REQUEST`),
  so Gmail/Outlook show an event card with _Add to calendar_. iCalendar 2.0,
  `METHOD:REQUEST`, CRLF, lines folded at 75 octets (UTF-8 safe), TEXT
  values escaped (`ics_text()`). One `VEVENT`: `UID:zidle-<reservation
id>@<host of PUBLIC_URL, else of the sender, else koncert-tzg>` (stable, so a
  new ticket after a partial cancellation updates the same event),
  `SEQUENCE` = current Unix time, `DTSTART` = run start, `DTEND` = start +
  `EVENT_DURATION_MINUTES` (both UTC `…Z`), `SUMMARY` _„TZG 2026[ · <run
  label>] – <seat labels>“_, `DESCRIPTION` _„Místa: …“_, _„Specifický symbol:
  …“_, _„Při vstupu prosím ukažte QR kódy vstupenek z e-mailu (1 QR kód = 1
  místo).“_,
  _„Rezervace: <link>“_ (with `PUBLIC_URL`) and the contact line,
  `LOCATION` = `EVENT_LOCATION` (omitted when empty), `ORGANIZER;CN="Koncert TZG
2026":mailto:<sender>`, `ATTENDEE;CN="<name>";ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;RSVP=FALSE:mailto:<customer>`,
  `STATUS:CONFIRMED`. No attachment when the run no longer exists.
- **Verify** (`organizer.php` `verify {code, runId, confirmOutside}` →
  `verify_ticket()` in `lib/checkin.php`): access to run required (403 `Na
tento termín nemáte přístup.`); load reservation by id + SS `FOR UPDATE`;
  result for the seat of the code:

  | result                 | when                                                        | check-in recorded                                                                                |
  | ---------------------- | ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
  | `invalid`              | bad signature/format or not found                           | no                                                                                               |
  | `wrong_run`            | pending/paid of another run                                 | no                                                                                               |
  | `seat_cancelled`       | paid, the seat was cancelled from the reservation           | no                                                                                               |
  | `used`                 | paid, this seat already checked in                          | no                                                                                               |
  | `outside_window`       | paid, first scan of the seat, outside window, not confirmed | no                                                                                               |
  | `valid`                | paid, first scan of the seat                                | yes (the item's `checked_in_at`/`by`; the reservation's `checked_in_at`/`by` keep the first one) |
  | `unpaid` / `cancelled` | status pending / expired or cancelled                       | no                                                                                               |

  Response: `ticket {id, specificSymbol, seat, kind, name (on the ticket:
`ticket_holder()`), reservedBy (reservation name), seats (current),
count, checkedIn (seats checked in)}`, `checkedInAt` /
  `checkedInBy` of the seat, `status`, `run`.

- **Scanner UI** (tabs _Vstupenky_ / _Prodej_) results: `Platná
vstupenka`, `Už odbaveno` (+ first time), `Místo zrušeno` (_„Toto místo bylo
  z rezervace zrušeno, ostatní místa platí.“_), `Nezaplaceno`, `Rezervace
zrušena`, `Neplatný kód`, `To je platební QR kód` (code starts with `SPD*`),
  `Jiný termín` (+ ticket run), `Mimo čas odbavení – neodbaveno`, `Není v
seznamu termínu` (offline, see below), `Neověřeno – bez spojení` (no
  connection and no list). The card shows the seat in large type (section,
  _řada · místo_, kind chip _Dospělý_ / _Dítě 4–17 let_), the name on the
  ticket (+ _„Rezervace: <name>“_ when it differs),
  _„rezervace N míst · odbaveno K z N“_. Ticket footnote: _„aktuální stav ze systému“_, _„bez spojení,
  podle seznamu z HH:MM“_ (+ _„Odbavení se odešle, jakmile bude spojení.“_ for
  a valid one) or _„údaje z QR kódu, neověřeno“_. Camera: rear camera, centre
  square decoded by `jsqr` every 120 ms, torch when supported, stopped while
  the page is hidden.
- **Prodej** tab (`SaleView.jsx`, on-site sale §9; needs a connection, else
  _„Prodej na místě potřebuje internet. Zkuste to znovu, až bude spojení.“_):
  `sale-seats` → one horizontally scrolling row of section chips with free
  counts, the section's seats as in the customer app (`SeatPlan`, held and
  „Záložní“ seats disabled), up to `maxSeats`;
  _Dospělí · 300 Kč_ and stepper _Děti 4–17 let · 150 Kč_; button _„QR platba
  <total> · N míst“_ → `sale-create` → QR payment (QR, amount, VS and SS, account,
  seats _„P. křídlo ř. 2 m. 3 – Dospělý 300 Kč“_) with **Zaplaceno – vpustit** / **Zrušit prodej**; afterwards the
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
  load; a stored session without `layout` is ignored); without one _„Bez
  spojení. Poprvé se scanner musí přihlásit s internetem.“_
- **Snapshot** (`organizer.php` `snapshot {runId}` → `scanner_snapshot()`):
  `{runId, at, tickets: [{id, specificSymbol, name, seats (current), childSeats (current child seats),
ticketNames, status, checkedIn: {seat: {at, by}}}]}` – all reservations of the
  run (any status), no e-mails or money. `useOffline(runId)` loads it for the
  selected run on start, every 60 s and on the browser `online` event, and
  keeps it in localStorage key `zidle-scanner-offline` (`src/scanner/offline.js`:
  `session`, `snapshots` per run, `queue`). An online `valid` result also
  marks the seat checked in in the stored snapshot (`markTicketCheckedIn()`).
- **Offline ticket check** (`verifyOffline()`): find by id, SS must match →
  not found `unknown` (_„Vstupenka není v seznamu tohoto termínu – může být na
  jiný termín, nebo neplatná.“_); pending `unpaid`; cancelled/expired
  `cancelled`; seat not in the reservation `seat_cancelled`; seat checked in
  (snapshot or this device's queue) `used` with the time; else `valid` and an
  event `{uid, runId, type: "ticket", id, specificSymbol, seat, at}` is
  queued. Kind, seats and counts come from the snapshot. The check-in window
  works as online (run times from the cached session).
- **Sending** (`sync {runId, events}` → `apply_offline_scans()`, max 1000
  events): run by run when the server is reachable again (any successful
  request, snapshot refresh, `online` event); sent events leave the queue;
  403 for a run drops its events. Server, in order, each in a transaction:
  `ticket` – reservation by id + SS + run `FOR UPDATE`: not found → conflict
  `unknown`; not paid or the seat not in the reservation → `not_paid`; seat
  not checked in → the item's `checked_in_at`/`by` = the device time and
  `<device name> (offline)` (the reservation keeps its earliest check-in); the
  same time and device again → ok (resend); otherwise → `already_checked_in`
  (the first check-in stays). Conflict labels name the seat
  (`reservation_label()`: _„SS … <name> · <seat_label()>“_); other event
  types → conflict `invalid` (not recorded). Device time is limited to the last 48 hours
  (`OFFLINE_MAX_AGE_HOURS`) and never later than now. Conflicts go to
  `scan_conflicts` (the same conflict only once). Response
  `{results: [{status: "ok"|"conflict", reason?}], conflicts}`.
- **Strip** (`OfflineStrip`, hidden when online with nothing to send):
  offline _„Bez spojení – ověřuji podle seznamu z HH:MM“_ (+ _„(starší než 30
  min)“_, + _„· k odeslání: N“_), red without a list (_„Bez spojení a bez
  staženého seznamu – vstupenky nelze ověřit.“_) or when stale; online with a
  queue _„Odesílám odbavení bez spojení: N…“_; after sending for 8 s
  _„Odesláno N odbavení bez spojení.“_ (+ _„Konflikty: K – uvidí je
  správce.“_).
- **Logout** asks _„N odbavení bez spojení ještě nebylo odesláno. Odhlášením
  se ztratí. Přesto odhlásit?“_ when the queue is not empty, then removes all
  stored data; a 401 removes it too. Snapshots of runs no longer in the
  session or ended more than a day ago are removed on the next online start.
- Limit: devices offline at the same time do not see each other's
  check-ins; the same seat ticket shown at two such entrances passes both and
  appears as a conflict after sending. On-site sales need a connection.

## 12. Accounts, roles and sign-in

Everybody signs in with an own account (`admin_users`, `lib/admin_users.php`);
the e-mail (case insensitive) is the login. Roles (`USER_ROLES`, an account may
have several; `user_can(me, what)`):

| Role        | `roles`      | May                                                                                                                                       | `user_can`                       |
| ----------- | ------------ | ----------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------- |
| Správce     | `admin`      | everything: all admin tabs and the scanner                                                                                                | `manage`, `reservations`, `scan` |
| Účetní      | `accountant` | admin tabs _Rezervace_ (reservations and payments, all its actions) and _Výpis z banky_                                                   | `reservations`                   |
| Odbavovatel | `scanner`    | only the scanner (check-in, on-site sale), only the runs assigned to the account (`user_runs`, 0..n; a run can have several Odbavovatelé) | `scan`                           |

- **Deploy** (`POST api/deploy.php {token, email, password}`, JSON or
  form fields; rate limit 10/h per IP): `deploy_token_ok()` – `DEPLOY_TOKEN`
  ≥ `DEPLOY_TOKEN_MIN` = 32 characters and equal (`hash_equals`), else 1 s
  delay and 403 _„Neplatný token (DEPLOY_TOKEN v config.local.php, alespoň 32
  znaků).“_; while any account has the Správce role (`admin_exists()`, any
  state) 409 _„Správce už existuje – deploy lze použít jen jednou. Další
  uživatele pozve správce.“_; `create_first_admin()` (under `GET_LOCK`): valid
  e-mail (422 _„Neplatný e-mail.“_), password ≥ `ADMIN_PASSWORD_MIN` = 10
  (422 _„Heslo musí mít alespoň 10 znaků.“_) → account
  with role `admin`, `invited_by = deploy` → 201 `{message: "Správce <email>
vytvořen. Přihlaste se na <app_base_url()>/api/admin.php"}`. Until then
  admin.php answers 503 _„Aplikace zatím nemá správce. Vytvořte ho jednorázově
  voláním POST api/deploy.php s tokenem DEPLOY_TOKEN z config.local.php (viz
  docs/DEVELOPER.md).“_
- **Invitation** (Správce, tab _Uživatelé_, `user-invite {email,
roles[], runs[]}` → `admin_invite()`; `runs[]` = runs the Odbavovatel checks
  in, `save_user_runs()`, unknown runs ignored): valid e-mail ≤ 190 (_„Neplatný e-mail.“_), at least one
  role (_„Vyberte alespoň jednu roli.“_), e-mail not used yet (_„Účet s tímto
  e-mailem už existuje.“_); creates the account without a password and a
  48-hex token (only its sha256 stored) valid `ADMIN_INVITE_DAYS` = 7 days.
  E-mail _Pozvánka_ (§15) with `admin_invite_link()` =
  `app_base_url()/api/admin.php?pozvanka=<token>`; the link is sent only by
  e-mail (the tab doesn't show it). Flash _„Pozvánka pro X vytvořena. E-mail
  odeslán.“_ / _„… E-mail se neodeslal (důvod je v logu) – zkuste „Poslat
  znovu“.“_ / with `MAIL_ENABLED` off _„… E-maily jsou vypnuté (MAIL_ENABLED) –
  pozvánka nebyla dodaná.“_
- **Accepting** (`admin.php?pozvanka=<token>`, also when signed in): valid
  link (`admin_invited_user()`: hash, not expired, account not disabled) →
  _„Dobrý den, vytvořte si heslo – tím vznikne Váš účet (role: …).
  Přihlašovací jméno je Váš e-mail <email>.“_, _Nové heslo_ + _Heslo znovu_,
  **Uložit heslo a přihlásit** (`accept-invite`, rate limit area
  `admin-invite`); `admin_accept_invite()`: `new_password_problem()` – ≥ 10
  chars (_„Heslo musí mít alespoň 10 znaků.“_), equal (_„Hesla se
  neshodují.“_) → password stored, link cleared, `accepted_at`, signed in with
  _„Vítejte. Heslo je nastavené, příště se přihlásíte e-mailem
  <email>.“_ Invalid/used/expired link: _„Pozvánka neplatí nebo vypršela.
  Požádejte o novou.“_ + link to sign-in.
- **Sign-in** (admin.php form _E-mail_ + _Heslo_ + link **Zapomněl jsem
  heslo**): `admin_login()` – active account with a password,
  `password_verify()` (a dummy hash is verified for unknown e-mails; rehash
  when needed), `last_login_at`. Wrong → 1 s delay, _„Nesprávný e-mail nebo
  heslo.“_; rate limit area `admin` → _„Příliš mnoho pokusů. Zkuste to za 15
  minut.“_ The session keeps `admin_id` and `admin_pw` =
  `password_fingerprint()` of the password hash; `admin_from_session()`
  re-checks the account on every request (exists, has a password, not
  disabled, same fingerprint), so a disabled account or a new password signs
  the old sessions out at once; roles are read fresh every request. An
  account without `reservations` (Odbavovatel only) is redirected from
  admin.php to `app_base_url()/scanner.html`.
- **Forgotten password**: `admin.php?zapomenute` – _„Zadejte e-mail, kterým se
  přihlašujete. Pošleme na něj odkaz na nové heslo.“_ → **Poslat odkaz**
  (`forgot`, rate limit area `reset`) → always _„Pokud e-mail u nás má účet,
  poslali jsme na něj odkaz na nové heslo. Platí 60 minut.“_;
  `request_password_reset()` only for an active account with a password (at
  most 3 per account and hour): 48-hex token (sha256 in `reset_hash`), valid
  `PASSWORD_RESET_MINUTES` = 60, e-mail _Nové heslo_ (§15) with
  `admin.php?heslo=<token>`; the old password works until the new one is set.
  The link page _Nové heslo_ (_„Účet <email>. Zadejte nové heslo.“_, two
  inputs, **Uložit heslo a přihlásit**, `reset-password`) → `reset_password()`
  (same rules) → stored, token cleared, signed in with _„Heslo změněno.“_;
  invalid/used/expired: _„Odkaz neplatí nebo vypršel. Požádejte o nový.“_ +
  link _Zapomněl jsem heslo_. A pending invitation is not reset this way – the
  Správce sends it again.
- **Scanner sign-in**: the scanner login screen (_E-mail_, _Heslo_,
  **Přihlásit**, _„Zapomněl jsem
  heslo“_ linking `api/admin.php?zapomenute`) → `organizer.php login {email,
password}` → `scanner_login()`: wrong → 1 s, 401 _„Nesprávný e-mail nebo
  heslo.“_; without `scan` 401 _„Tento účet nemá roli Odbavovatel. Požádejte
  správce o přidání role.“_; rate limit area `organizer`. A Správce signed in
  to the admin opens the scanner from the menu (_Odbavení_) without signing in
  again: `GET organizer.php` reads the admin session (read only,
  `scanner_access_from_admin_session()`) – also an invited Odbavovatel lands
  in the scanner signed in. Cookie `zidle_scanner = "u:<id>:<expiry>:<hmac>"`
  (HttpOnly, SameSite=Strict, Secure on HTTPS, path = API dir, 30 days; hmac
  over id, expiry and the password fingerprint with a key derived from
  `TICKET_SECRET`). Every request `scanner_access()` re-checks the account
  (active, role, same password) – a disabled account, removed role or new
  password gets 401 _„Přihlaste se svým e-mailem a heslem.“_ and the scanner
  drops its offline data. The scanner header shows **Správa** (link to the
  admin) when the account also has Správce or Účetní. Check-ins and on-site
  sales are recorded with the account's e-mail.
- **Runs of an Odbavovatel**: `scanner_access_of()` gives an Odbavovatel
  `runIds` = `user_run_ids()` (assigned runs in start order, may be none), a
  Správce `null` (every run). `scanner_runs()` / `require_run()` (403 _„Na
  tento termín nemáte přístup.“_) limit everything – the run select, verify,
  snapshot, sync and on-site sale – to them. With no run the scanner shows
  _„Nemáte přiřazený žádný termín k odbavení. Požádejte správce, aby Vám
  termín přiřadil, a pak stránku obnovte.“_, the e-mail and **Odhlásit**.
  Deleting a run removes its assignments (FK cascade).

## 13. Discount codes

Free seats are given with discount codes (`lib/discounts.php`). The admin enters e-mail addresses (tab
_Slevové kódy_, §14); each listed address gets one code, an address listed N
times gets N codes – all codes of one address in one e-mail (§15). A code: 8 characters (§19), any run, used
once (`used_at`, `reservation_id`, `seat_id`), can be revoked while unused.
In a reservation each code makes one seat free – the most expensive one,
adult seats first, then child seats (§8). Several codes can be used in one
reservation (at most one per seat); a reservation paid fully by codes is
confirmed at once. Cancelling a free seat (admin) returns nothing and the code stays
used; the code also stays used when the reservation expires or is cancelled.

## 14. Admin

`api/admin.php` (CSRF token on every POST). Sign-in, invitations and the
forgotten password: §12. The header shows the signed-in e-mail (shortened with … when long; tooltip _Přihlášen: <e-mail> · <roles>_)
and the menu by role: Správce _Rezervace_, _Výpis z banky_, _Plánek_, _Slevové kódy_,
_Uživatelé_, _Nastavení_, _Odbavení_; Účetní only _Rezervace_ and _Výpis z banky_ (other `view=`
values show Rezervace, other actions are ignored); _Odbavení_ (link to
`app_base_url()/scanner.html`) for accounts with `scan`. Actions are checked
by role: reservation actions need `reservations`, everything else `manage`.

Tabs:

- **Výpis z banky** (`view=bank`, Správce and Účetní, §9) – form _Nahrát výpis
  z banky_ (hint how to export it from KB MojeBanka and how matching works,
  file input `statement`, **Načíst výpis** → `bank-upload`: parse errors as
  flash, no file _„Vyberte soubor CSV s výpisem.“_; the parsed rows, file name
  and outgoing count are kept in `$_SESSION['bank_import']`). Preview: cards
  _Příchozích plateb_ (+ file, _odchozí přeskočeny: N_) / _Spárováno_ /
  _Nespárováno_ / _Už zapsáno_, table (matched again on every load): checkbox
  `tx[]` for `match` rows (pre-checked by `select`), date, payer name +
  account, amount, VS / SS, message, reservation (SS link to the search, name,
  status badge, price), _Co se stane_ (the note). **Zapsat vybrané platby**
  (`bank-apply` → `bank_record()` per checked row, by the signed-in e-mail):
  flash _„Zapsáno plateb: N z M.“_ (nothing checked: _„Nevybrali jste žádnou
  platbu.“_) and a card _Výsledek zápisu_ with date · amount · payer and the
  message of each row; **Zavřít výpis** (`bank-clear`) forgets the preview.
- **Plánek** (`view=plan`, run select, default the first run) – hint _„Klikem
  přepnete místo mezi v prodeji a záložní (zákazníci ho vidí, ale nemohou ho
  koupit). Platí jen pro vybraný termín. Obsazená místa nelze přepnout. Nový
  termín začíná s výchozím rozložením (záložní řady na balkoně).“_; a sticky
  bar _„V prodeji N · záložních M · obsazených K“_ (counts update while
  clicking), legend, **Uložit plánek** (`plan-save`, checkboxes `reserve[]` →
  `save_run_reserve_seats()`; taken seats stay for sale) and **Výchozí
  rozložení** (`plan-default`, `confirm()`, `reserve_seats` = NULL); both
  levels drawn from `PLAN` like the customer map with every seat as a
  numbered square (green = for sale, neutral grey #d9d8dc dashed = záložní, red = taken by a
  reservation, disabled), at each section name the links _vše v prodeji_ /
  _vše záložní_. Flash _„Plánek uložen. V prodeji N míst, záložních M.“_ /
  _„Obnoveno výchozí rozložení. …“_ (+ _„Obsazená místa zůstala v prodeji:
  …“_).

- **Rezervace** – one row of cards (`.stats`: no wrapping, cards share the
  width): per status (pending/paid: seats, amount, _N rezervace_ / _N rezervací_), refunds due (amount, the same count), on-site
  sales to verify (_Na místě – ověřit platbu_) – these three without test
  runs (`NOT_TEST_RUN_SQL`) –, per run occupancy (held / `run_capacity()`; a test run labelled _· TEST_). Test runs are blue (`.test-run`): their run card, their
  settings form and the table rows of their reservations (run name in dark blue). Every
  card is a toggle filter: a click sets its `status` (`pending`, `paid`, `refund`, `onsite`) or `run`, keeps the
  other filters, search and sort, and outlines the card (`.selected`); a second click removes that filter. Filters
  run/status (incl. `refund`, `onsite`)/ search (SS,
  name, e-mail contain the text; a seat id matches exactly – `LH-1-1` not
  `LH-1-10` – and a prefix like `LH-1` lists the row; cancelled seats count
  too). Table (max 500 rows, newest first, items loaded in one query) sorted on the
  server by clicking a column header (`?sort=ss|run|name|seats|amount|status|created|expires`
  & `dir=asc|desc`, a second click on the same column reverses it, ▲/▼ and
  `aria-sort` on the active one; run sorts by its start, name by surname,
  seats by the number of current seats;
  filters keep the sort). Name column: _prodal(a) <organizer>_ for on-site sales.
  Seats column: one current seat per line in seat order with _dítě_ / _kód_ chips, ✓ when
  checked in and the name on the ticket; then _zrušená místa: …_. Status column: _odbaveno K z N
  míst, poprvé <time> (<by>)_; on-site paid _na místě – ověřte, že platba
  dorazila_ / _platba ověřena <time>_. Amount column: _přijato X_ when it
  differs from the price, _zbývá doplatit Y_ for partly paid pending.
  Actions: `payment` (§9), `ticket` (send/resend; not without an e-mail),
  **PDF vstupenky** (`tickets.php`), `cancel`, `cancel-seats` (confirm
  mentions that seats free by a code are not refunded), `refunded`, `email`
  (change), `onsite-checked` (**Platba dorazila**, §9; replaces _přišla
  platba_ for unverified on-site sales). Within 14 days of the GDPR deletion date and
  while refunds are due, a warning: _„Osobní údaje se mažou <date>. U N rezervace/rezervací
  zbývá vrátit peníze – jejich jméno a e-mail se smažou až po označení
  „Vráceno“.“_
- **Slevové kódy** – cards _Kódů celkem_ / _Použito_ / _Nepoužito_; form
  _Rozeslat slevové kódy_ (textarea of e-mails separated by new lines,
  commas, semicolons or spaces; an address listed N times gets N codes) →
  `code-create` → `create_discount_codes()`: max 200
  (_„Najednou lze poslat nejvýše 200 kódů.“_), any invalid address →
  _„Neplatné e-maily: … Nic nebylo vytvořeno.“_; else one random code per
  address (4 pairs, each at random a syllable – consonant + vowel A/E/U – or
  two digits 2–9, in any order, e.g. `BA42-KU77`, `4827-BAKE`; accepted on input are any 8 chars of
  `ABCDEFGHJKLMNPQRSTUVWX23456789` – no 0/O, 1/I, Y/Z), e-mailed at once,
  one e-mail per address with all its codes (§15):
  _„Vytvořeno N kódů pro K adres, odeslané e-maily: M z K.“_ (+ _„Některé e-maily se
  neodeslaly – zkuste „Poslat znovu“.“_ / _„E-maily jsou vypnuté
  (MAIL_ENABLED).“_). Search (e-mail, code, SS); table (last 1000): code,
  e-mail, created (+ by), sent, state _Nepoužitý_ / _Použitý_ (time, SS link,
  run, seat) / _Zrušený_; for unused: **Poslat znovu** (`code-resend`) and
  **Zrušit** (`code-revoke`, _„Kód zrušen – už ho nelze použít.“_).
- **Uživatelé** (Správce) – intro: _„Uživatele zve správce zadáním e-mailu a
  rolí. … Správce může vše, Účetní vidí jen rezervace a platby, Odbavovatel jen
  odbavuje (scanner) – a to jen termíny, které mu tu přiřadíte (žádný, jeden i
  víc); správce odbavuje všechny termíny. Kdo má víc rolí, vidí všechny jejich
  možnosti v menu. Zapomenuté heslo si každý obnoví sám na přihlašovací
  stránce.“_; form _Pozvat uživatele_ (e-mail, role checkboxes, _Odbavuje
  termíny:_ run checkboxes, `user-invite`, §12); list (active first, by
  e-mail): e-mail (_(vy)_), roles (for an Odbavovatel without Správce also
  _odbavuje: <runs>_ / red _odbavuje: žádný termín_) with _změnit_ → role and
  run checkboxes + **Uložit** (`user-roles` → `admin_set_roles()`: at least one
  role, _„Svou vlastní roli Správce nelze odebrat.“_; on success
  `save_user_runs()` and _„Role a termíny účtu X uloženy.“_),
  column _Pozvánka_: state _Přijata <time>_ / _Nepřijata (platí do …)_ /
  _Nepřijata – vypršela_ / _Účet vypnut_ and below _pozval(a) <e-mail>, <created>_
  (_první správce (deploy), …_ for the deployed one), last sign-in. Actions:
  **Poslat znovu** (`user-link`, only not accepted: new token + e-mail, the
  previous link stops working), **Vypnout** (`user-disable`, not yourself:
  _„Svůj vlastní účet nelze vypnout.“_; also clears open links; _„Účet X
  vypnut – už se nepřihlásí.“_), **Zapnout** (`user-enable`). Below _Odbavení
  bez spojení – konflikty_ (last 500 `scan_conflicts`: time, run, label, reason
  _už odbaveno jinde_ / _vstupenka neplatila (nezaplaceno / zrušeno / místo
  zrušeno)_ / _neznámá vstupenka_ (`CONFLICT_REASONS`), device, earlier
  check-in); the tab shows the number of conflicts in a red badge.
- **Nastavení** – _Splatnost plateb_ (`deadline-save`, §8; errors
  _„Splatnost N: zadejte datum a počet hodin 1–720.“_, success _„Splatnost
  uložena. Platí pro nové rezervace, už vytvořené si splatnost ponechají.“_;
  shows _„Nyní platí: N hodin“_); runs: `run-save` (start required, label, booking cut-off
  before start, checkbox _Testovací termín_ `is_test` with the hint _„Pro
  vyzkoušení odbavení: termín není veřejně vidět (jen přes odkaz), rezervace
  jsou hned zaplacené, slevové kódy nejdou použít, nepočítá se do souhrnů a
  lze ho smazat i s rezervacemi.“_; a saved test run shows _„Odkaz na
  testovací termín: PUBLIC_URL/?termin=<id>“_), `run-delete` (`delete_run()`: a real run only without reservations, else _„Termín má rezervace, nelze ho smazat.“_;
  a test run together with its reservations, seats and check-ins, confirm
  _„Smazat testovací termín i se všemi jeho rezervacemi a odbaveními?“_;
  removes offline scan conflicts and Odbavovatel assignments of the run). Each
  saved run shows _„Odbavují: <e-mails> / zatím nikdo (a všichni správci) –
  přiřazení v záložce Uživatelé“_ (`run_scanner_emails()`, active accounts).
  Shows the GDPR deletion date.
  The intro says that customers cannot cancel themselves – only the Správce,
  with paid seats refunded in full. **Lock:** once a run has any reservation
  (`run_has_reservations()`, any status), its start can no longer be changed –
  the input is disabled with _„Představení už má rezervace – začátek nelze
  měnit (zákazníci rezervovali na tento čas).“_; a POST containing `starts_at`
  is rejected with _„Představení už má rezervace – začátek nelze měnit.“_
  The test flag is locked too (checkbox disabled, the stored value kept).
  Label and booking cut-off stay editable.

`expire_reservations()` runs on every admin render.

## 15. E-mails

Only when `MAIL_ENABLED`. All mail goes through `deliver_mail(to, subject,
text, html?, inline[], attachments[])` (PHPMailer, UTF-8; attachments base64): with `SMTP_HOST` via SMTP
(`SMTP_PORT`, `SMTP_AUTH`, login `SMTP_USER` or `SMTP_SENDER` / `SMTP_PASSWORD`; port 465 =
SMTPS, otherwise opportunistic STARTTLS; connect timeout and per-reply limit
15 s), otherwise PHP `mail()` from `MAIL_FROM` (`mail_sender()`). Sender name _Koncert TZG 2026_;
`Reply-To` = `CONTACT_EMAIL` when set. Every e-mail ends with _„Kontakt: <e-mail> ·
<phone>“_ (`contact_line()`) when a contact is configured.
Failures return false and are logged as `[zidle] Mail to … failed: <reason>`
(PHPMailer errors and any other error, e.g. `mail()` disabled by the host, so a
failed e-mail never turns into a 500; a failed invitation can be resent with
_Poslat znovu_);
mail is sent synchronously within the request. Every e-mail is HTML + the
text as alternative, all in one layout `mail_layout(content, subtitle, footer)`
(paper background `#fbf6f1`, header _Koncert TZG 2026_ with the year in the
accent `#c42aae`, the subject without _„[TEST] TZG 2026: “_ as subtitle, white card,
optional small footer; colours `MAIL_INK` / `MAIL_MUTED` / `MAIL_ACCENT`).
`deliver_mail()` with `html` = null wraps the text itself
(`mail_text_html()`: blank line = paragraph, newline = `<br>`, escaped,
`http(s)://` links clickable without a trailing `.,;:)`, lines indented by 4
spaces – discount codes – big monospace); that covers all e-mails
(`send_customer_email()`, invitations, passwords, discount codes) except
_Rezervace míst_ and the ticket. `send_customer_email(r, subject, lines, html?, inline[])`
with `html` puts greeting + `html` + closing (_Koncert TZG 2026_, contact) in the
layout instead of the text; the text part stays the plain lines. The
ticket puts its own content in the same layout (one QR per seat as
inline images `cid:ticket-qr-<n>`, subtitle _Vstupenky · SS …_, footer = the
print note + contact; attachments `TZG_2026_vstupenky_<SS>.pdf` and the
calendar event `udalost.ics`, §11). All reservation e-mails contain `Termín: <run>`.
Payment details (`payment_lines()`): _Už jsme přijali X z Y_ (after a partial
payment), _Částka_, _Účet: <BANK_ACCOUNT_DISPLAY>_ (no IBAN), _Variabilní
symbol: <PAYMENT_VARIABLE_SYMBOL>_, _Specifický symbol: <SS of the reservation>_,
_Splatnost_. Other e-mails name the reservation _(SS …)_.

Subjects are `mail_subject(subject, r?)` = _„TZG 2026: <subject>“_ (the sender name already says
_Koncert TZG 2026_); reservation e-mails (`send_customer_email()`, tickets)
on a test run get _„[TEST] TZG 2026: <subject>“_.

| Subject                              | Trigger                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Rezervace míst                       | reservation created (`send_payment_email()`; seats one per line in seat order as `seat_kind_lines()` _„Levá hlavní · řada 1 · místo 2 – Dospělý“_; text = _„děkujeme za rezervaci míst:“_ + _„- <seat>“_ lines, `Termín`, payment details, with `PUBLIC_URL` _„Rezervace a QR kód online: <link>“_; HTML `payment_email_html()` = two tables with bold values – _Termín_, _Místa_ (the seat lines), then _Platební údaje_: _Částka_ (bigger, accent colour), _Účet_ (when set), _Variabilní symbol_, _Specifický symbol_, _Splatnost_; with `BANK_IBAN` the QR Platba image inline (`payment_qr_image()`: `cid:payment-qr`, file `QR_platba.png`, same SPD as the reservation page, 200 px) with _„Načtěte QR kód v aplikaci banky.“_; then _„Pokud platba nedorazí…“_ and the online link) |
| Pozvánka                             | user invited or invitation sent again (`send_admin_invite_email()`: _„Dobrý den,“_, _„<inviter e-mail> Vás zve do systému Koncert TZG 2026 – role: <roles>.“_, _„Účet vznikne, až si na tomto odkazu vytvoříte heslo (platí 7 dní):“_ + link, _„Přihlašovací jméno je Váš e-mail: …“_)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| Nové heslo                           | forgotten password requested for an active account (`request_password_reset()`: _„někdo (nejspíš Vy) požádal o nové heslo…“_, link valid 60 minut, _„Pokud jste o nové heslo nežádali, e-mail ignorujte – dosavadní heslo platí dál.“_)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| Připomínka platby                    | cron: pending, due within 24 h, created ≥ 24 h before due, once                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| Rezervace zrušena                    | cron: expired within the last 3 days, once (refund line for money that already arrived; _„Pokud platba ještě dorazí a místa budou stále volná, rezervaci obnovíme; jinak Vám peníze pošleme zpět na účet, ze kterého přišly.“_)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| Platba přijata – Vaše vstupenky      | recorded payment completes the price / expired restored / pending partial cancellation now covered                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| Vaše vstupenky                       | resend from admin                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| Rezervace potvrzena – Vaše vstupenky | new reservation paid fully by discount codes, or an online booking on a test run                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| Slevový kód / Slevové kódy           | admin created codes (all codes of one address – compared case-insensitively – in one e-mail) or resent one code (`send_discount_codes_email()`, text `discount_codes_mail_text()`): one code _„posíláme Vám slevový kód na jedno místo zdarma na představení TZG 2026: XXXX-XXXX“_, _„Kód zadejte při rezervaci na … do pole „Slevové kódy“.“_, _„Platí na libovolný termín a lze ho použít jednou.“_; N codes _„posíláme Vám N slevové kódy / slevových kódů – každý na jedno místo zdarma …“_, one code per line, _„Kódy zadejte … – do jedné rezervace jich lze zadat víc, jeden na místo.“_, _„Každý platí na libovolný termín a lze ho použít jednou.“_; both with _„Na velikosti písmen nezáleží a pomlčku psát nemusíte.“_ after the „zadejte“ line; `emailed_at` set on all of them |
| Přijata část platby                  | recorded payment below the price                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| Vrácení platby                       | recorded payment that can't be used (§9 reasons)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| Nové vstupenky                       | partial cancellation of a paid reservation                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| Změna rezervace                      | partial cancellation of a pending reservation                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| Rezervace zrušena                    | whole cancellation by the admin                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |

## 16. Scheduled jobs and GDPR

`php api/cron.php`: `expire_reservations()`, `send_due_notifications()`,
`purge_personal_data()`, `cleanup_rate_limits()`; prints a summary line.
Purge after `last_run_start()` (the last run that is not a test run) `+ DATA_RETENTION_DAYS`: empties first/last
name and e-mail of all reservations and the names on their tickets (`reservation_items.holder_name`) except those with money still to return
(`refund_amount > refunded_amount`; they are emptied by the first cron run
after **Vráceno**), deletes all `scan_conflicts` and empties the e-mails of `discount_codes`; SS,
amounts, seats (items) and statuses stay.

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
  hashed): ticket names 60/h per IP, logins, invitations
  and forgotten passwords per IP and area (+ 3 reset e-mails per account and
  hour), deploy 10/h; `rate_limit_add()` counts reserved seats per IP
  (`seat_quota_take()`, §8).
- Pending reservations per e-mail and run.
- Discount codes are guessed only through reservations (IP limit + ALTCHA);
  the code space is 32⁸.

## 18. HTTP API

| Method | Endpoint                  | Body / query                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  | Response                                                                                                                                                                                                                                                                                                                                                                                                         |
| ------ | ------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET    | `seats.php?run=<id>`      | –                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             | `runs[]` (id, label, startsAt, bookingClosesAt, bookingOpen, free, capacity, reserveSeats, test; test runs only when they are the requested `run`), `runId`, `taken[]`, `price` (adult seat), `childPrice`, `deadlineHours` (in effect now), `maxSeats`, `bookingOpen`, `formToken`, `contact` {email, phone}, `dataRetentionDays`, `layout` {levels, plan, sections}                                            |
| GET    | `altcha.php`              | –                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             | `{enabled, challenge}` (ALTCHA v2 challenge: `parameters`, `signature`)                                                                                                                                                                                                                                                                                                                                          |
| POST   | `reservations.php`        | `runId, firstName, lastName, email, seats[], children, codes[], formToken, hp, altcha`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        | 201 reservation payload                                                                                                                                                                                                                                                                                                                                                                                          |
| GET    | `reservations.php?token=` | –                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             | reservation payload (see `reservation_payload()`: status, seats, `tickets[]` {seat, kind, price, free, holder (name on the ticket or null), code (paid only), checkedInAt}, `ticketNamesEditable`, amounts, run, payment {account, recipient, variableSymbol (`PAYMENT_VARIABLE_SYMBOL`), specificSymbol (the reservation's SS), amount (still to pay), received (kept so far), spd}, refunds, `cancelledSeats`) |
| GET    | `tickets.php?token=`      | –                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             | PDF of a paid reservation's tickets (§11), else 404                                                                                                                                                                                                                                                                                                                                                              |
| POST   | `ticket-names.php`        | `token, names {seat: name}` ('' = reservation name)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           | reservation payload; 422 _„Jména na vstupenkách už nelze měnit.“_ / _„Vyberte místa z této rezervace.“_ / _„Jméno na vstupence může mít nejvýše 100 znaků.“_; 60 requests/h per IP                                                                                                                                                                                                                               |
| GET    | `organizer.php`           | –                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             | `loggedIn, name, admin` (account may use the admin), `runs[]` (+ `scanFrom`, `scanTo`), `layout`; a signed-in admin session with `scan` signs the scanner in (§12)                                                                                                                                                                                                                                               |
| POST   | `deploy.php`              | `token, email, password`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | 201 `{message}`; 403 / 409 / 422 (§12)                                                                                                                                                                                                                                                                                                                                                                           |
| POST   | `organizer.php`           | `action`: `login {email, password}` (§12), `logout`, `verify {code, runId, confirmOutside?}`, `snapshot {runId}`, `sync {runId, events}`, `sale-seats {runId}` → `{taken, reserveSeats, price, childPrice, maxSeats}`, `sale-create {runId, seats, children}` → 201 `{sale: {id, status, variableSymbol, specificSymbol, amount, seats, tickets, spd, account}}` (errors 422 _„Vyberte 1 až 20 volných míst.“_ / _„Dětských vstupenek může být nejvýše tolik, kolik je míst.“_, 409 seats taken, 503 no IBAN), `sale-paid {runId, id}` → `{message}`, `sale-cancel {runId, id}` → `{message}` | see §9, §11, §12                                                                                                                                                                                                                                                                                                                                                                                                 |

Errors: `{"error": "<Czech message>", …}` with HTTP status; uncaught
exceptions are logged (`[zidle]`) and return 500 `Chyba serveru. Zkuste to
prosím znovu.`

## 19. Formats

- Seat id: `^[A-Z]{2}-[1-9]\d?-[1-9]\d?\z` (exact form), validated against `SECTIONS`.
- SS (`specific_symbol`): random 10 digits (first digit non-zero), unique per reservation; VS = `PAYMENT_VARIABLE_SYMBOL` (3211) for all.
- Ticket: §11 (one per seat). Payment: §9.
- Discount code: 8 chars `[A-HJ-NP-Z2-9]`, shown `XXXX-XXXX`; input is
  normalized (upper case, anything but letters/digits removed).
- Invitation and password reset tokens: 48 hex (sha256 stored); scanner cookie §12.
- Times in API responses: ISO 8601 UTC with `Z`.

## 20. Conventions

See `CLAUDE.md`: docs in sync with code, UTC in DB, mobile-first customer
CSS / desktop-first admin / mobile-only scanner, layout only on the server,
ticket format kept identical, DB changes only in `db/schema.sql`, Czech UI.

## 21. Tests and CI

`.github/workflows/ci.yml` runs on pull requests and pushes to `main`
(older runs of the same ref are cancelled). PHP 8.4, Node 22, MariaDB 10.11
service (`root`/`root`, database `zidle_test`); the php job sets `DEPLOY_TOKEN` for the deploy test and `BANK_IBAN` for the on-site sale test.

| Job                                                                                | Steps                                                                                                                                                                                      |
| ---------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **php**                                                                            | `composer install` (with dev: PHPStan), `composer audit`, `php -l` on `api/` and `tests/` (without vendor), PHPStan, `db/schema.sql` applied to an empty database, `php tests/php/run.php` |
| **frontend**                                                                       | `npm ci`, `npm audit --omit=dev --audit-level=high`, `npx prettier --check .`, `npm run lint`, `npm run build`, `npm run test:consistency`                                                 |
| **e2e** (after both, on `ubuntu-24.04`: Playwright 1.56 has no Chromium for 26.04) | `composer install --no-dev`, `npm ci`, Playwright Chromium, `npm run test:e2e`; on failure the HTML report and traces are uploaded as artifact `playwright-report` (7 days)                |

**`./prepare.sh`** (local, needs only Docker): builds the `test` image (stage `test` of
`docker/php/Dockerfile` = the PHP 8.4 app image + Debian `nodejs`, `npm`, `chromium`,
`mariadb-client`) and runs itself in the `test` service of `compose.yaml` (profile `test`, not
started by `docker compose up`; own volume `test-node-modules`; env `DB_NAME=zidle_test`,
`MAIL_ENABLED=0`, `SMTP_HOST=''`, test `TICKET_SECRET` / `BANK_IBAN`,
`PUBLIC_URL=http://127.0.0.1:4173`, `PLAYWRIGHT_CHROMIUM=/usr/bin/chromium`). Steps, stopping at
the first failure: `npm ci`, `composer install`, **Prettier `--write`** (`.prettierrc.json`:
single quotes, 120 columns; `.prettierignore`: `package-lock.json`, `api/vendor`,
`api/composer.*`, `build`; PHP is not formatted), `eslint --fix`, `npm audit` + `composer audit`,
`php -l`, PHPStan, `db/schema.sql` applied to an empty `zidle_schema` (dropped again), PHP
integration tests, consistency, Playwright, then the production build: `npm run build`, a fresh
`build/` with `dist/` and a copy of `api/` (without `vendor/`, `config.local.php`, `logs/*.log`)
and `composer install -d build/api --no-dev --optimize-autoloader`. `build/` is the deploy package
(git-ignored); the development `api/vendor/` is left untouched.

**PHPStan** (`phpstan.neon`): level 8, `phpVersion` 8.4, over `api/` (without `vendor/`,
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
`db/schema.sql` (`reset_database()`: run 1 in 30 days, run 2 started an hour ago) and creates reservations directly
(`reservation()`: adult seats 300 Kč, optional child seats 150 Kč and seats
free by a code). Covered: partial payment → paid with surplus refund; SPD
and payload ask for the missing amount; late payment restoring an expired
reservation / refunded when seats are taken, after the run, or short;
expiry refunding a partial payment and a later full payment restoring it;
payment for cancelled and already paid reservations; invalid amount;
admin cancellation of single seats (full refund, seat freed, foreign seat
refused) and of a whole paid reservation; cancelling a partly paid pending
reservation; partial cancellation completing a covered reservation; double
booking rejected by the primary key; GDPR purge keeping contacts while a refund is due and removing the names on the tickets; ticket code
round trip per seat with its kind, tamper detection and one ticket per seat
in the payload; printable PDF (four tickets per page); ticket names (saved per
seat, too long / foreign seat refused, '' back to the reservation name, carried
by the code, payload and PDF, locked once the run started); child seats and free
seats (most expensive first); booking with a child seat and a code
(450 Kč, code marked used for the seat); a code used once, revoked and
unknown codes refused; a reservation paid fully by codes is paid at once;
codes per listed e-mail, invalid address creates nothing; cancelling a free
seat returns nothing, a child seat its price; pending with
only free seats left becomes paid; payment deadline rules by booking time;
online check-in per seat (outside window, used, child kind, cancelled seat,
other run, old format); offline check-in of separate seats; on-site sale
(QR amount, seat held, paid → all seats checked in, only once, cancel frees
the seat); ticket calendar event (run start/end,
UID, SEQUENCE, escaping, line folding, missing run); accounts (deploy creates the first Správce once,
needs the token, short password; invitation with roles, unknown role dropped,
no role / duplicate / invalid e-mail refused, no login before accepting,
short/mismatched password, used and expired links, case-insensitive login;
roles and what they may, several roles add up, changing roles, nobody
removes their own Správce role; forgotten password: unknown e-mail does
nothing, the old password works until changed, new password signs old
sessions out, used and expired links, 3 e-mails an hour; disabled account
cannot sign in and loses its session, nobody disables themselves; runs of an
Odbavovatel (none, one or several, unknown run ignored, a run with two
Odbavovatelé, a Správce every run, a deleted run leaves the assignment); scanner
sign-in for Odbavovatel and Správce only, the cookie stops working after a
removed role or a new password, forged cookie refused); security:
a seat has one id (leading zeros / trailing new line refused, the same chair
written differently not bookable), seats per IP per hour and day (refused
requests and released seats not counted), names cleaned of line breaks and
invisible characters, the same payment submitted twice recorded once; a failing mail transport returns false; error log written by the app to `LOG_DIR` even when PHP's `error_log` points elsewhere (app line, warning, uncaught exception; monthly file, deny-all `.htaccess`); layout (order, seats per row of the
wings, main sections and balcony, the benches split 5 | 7 and 6 | 5, the
left reserve rows 5 | 6, 195 + 106 = 301
seats, reserve rows, a plan per level, last seat of each section valid, one more
invalid); „Záložní“ seats (the 34 reserve-row seats by default, 267 for
sale, booking refused, the bench of the same section bookable; any seats
saved for one run, a taken seat stays for sale, other runs keep the
default, back to the default); offline: snapshot content (no e-mails),
offline check-ins with device time and first-wins, resend
without a new conflict, second device → `already_checked_in` recorded once,
unpaid / unknown / other-run tickets → conflicts, device time limits; test
run (label _· TEST_, not the last run for the purge, code refused and not
used up, online booking paid at once, `[TEST]` subject, on-site sale still
pending, check-in, a real run with reservations not deletable, the test run
deleted with reservations, seats and check-ins); bank statement (a KB CSV in
Windows-1250 with lines before the header: outgoing skipped, leading zeros of
symbols dropped, matched by SS, by a number in the message with a wrong VS
noted, a paid reservation not pre-checked, unknown SS and an amount with
haléře not matched; full and partial payments recorded, the same transaction
refused the second time, a second payment of a partly paid reservation not
pre-checked, empty file and missing header). Locally:
`DB_NAME=zidle_test MAIL_ENABLED=0 BANK_IBAN=CZ6508000000192000145399 php tests/php/run.php` (values in
`config.local.php` take precedence over the environment).

**Consistency** (`tests/consistency.mjs`): seat ticket codes made by
`ticket_code()` (adult seat, child seat with its own ticket name) are decoded
by `src/scanner/ticket.js` (id, SS, seat, kind, name).

**Playwright** (`playwright.config.js`, `tests/e2e/`): one worker, project
_mobile_ (Pixel 7, `cs-CZ`, Europe/Prague); admin tests use a 1360 × 900
desktop viewport. Web servers: `php -S 127.0.0.1:8000 -t .` with `ENV`
(`DB_NAME=zidle_test`, `PUBLIC_URL=http://127.0.0.1:4173` (admin links lead to
the built apps), test IBAN and ticket secret,
`MAIL_ENABLED=0`, `LOGIN_ATTEMPTS_PER_15_MIN=100` – every test signs in from the same IP; the process environment overrides them) and `npm run build
&& vite preview` on 127.0.0.1:4173 (proxies `/api`). `global-setup.js` runs
`tests/e2e/seed.php` (fresh database: run 1 _Premiéra_ in 30 days with two
pending reservations (payment test, bank statement test), run 2 _Dnes_ in 20 minutes with a paid reservation
(BS-1-1 adult, BS-1-2 child) and a second one for the offline test, run 3
_Zkouška_ (test run) in 40 days, three discount codes
for *zakaznik@example.com*, the Správce *spravce@example.cz* created by
`create_first_admin()` and the Odbavovatel *vchod@example.cz*; passwords in
`helpers.js`) and stores its JSON output (`pendingVs` / `bankSs` = their SS, `ticket` /
`offlineTicket` = seat ticket codes, `codes`) in `tests/e2e/.seed.json`.
`inviteLink(email)` in `helpers.js` runs `tests/e2e/invite-link.php`, which
issues a fresh invitation token for the account and prints its link (e-mails
are off in the tests).
Tests:

- `customer.spec.js` – choose _Premiéra_, the map has as many section areas
  (`.plan-hit`) as the server layout, all reserve seats shown as reserve, and its balcony
  heading, two seats, form (waits for
  `FORM_MIN_SECONDS`, real invisible ALTCHA), payment page with QR, 600 Kč
  and VS/SS, seats taken in `seats.php`, no _Zrušit_ button and `cancel.php`
  404, the admin cancels the reservation → the page shows _Rezervace byla
  zrušena._; run picker without horizontal scroll; three seats with one
  child (the lap note shown) and a seeded discount code (bad format message,
  _„<code> · místo zdarma“_, 450 Kč on the payment page with the seat kinds) and the used code refused next time; two seats paid by two
  codes → _Rezervovat zdarma_, _Zaplaceno_, two ticket QR codes and the PDF
  download (`TZG_2026_vstupenky_<SS>.pdf`), a name typed under the first QR code (Enter) is saved
  (_Uloženo_) and shows in the seat list; the test run _Zkouška_ is not in the
  picker, opened by `/?termin=3` the form shows _· TEST_, no code field,
  **Rezervovat (test)** → _Zaplaceno_ with one ticket QR code.
- `admin.spec.js` – record 200 Kč (_zbývá doplatit 400 Kč_, input prefilled
  400), then the rest → _Zaplaceno_; _Výpis z banky_: an uploaded CSV with a
  transfer of 600 Kč with the seeded SS (pre-checked, _Celá částka →
  Zaplaceno_) and one without SS (_Platba nemá SS_) → **Zapsat vybrané
  platby** → _Zapsáno plateb: 1 z 1._, _Přijato 600 Kč – zaplaceno._, the row
  shows _Už zapsáno_ without a checkbox, the reservation is _Zaplaceno_;
  the cards _Čeká na platbu_ and _Premiéra_ filter together (only pending
  rows), a second click on each removes its filter only;
  search by seat: `PH-5-1` and `PH-5-2` find only their reservation (also
  sorted by seats), the row prefix `PH-5` too, `PH-5-3` _Žádné rezervace._;
  _Plánek_: LH-1-1 of _Premiéra_ switched
  to záložní and saved (_„V prodeji 266 míst, záložních 35.“_, in `seats.php`
  `reserveSeats`), then _Výchozí rozložení_; discount codes (invalid e-mail →
  nothing created, the same e-mail twice → two codes, revoke one); payment
  deadline rule (_Nyní platí: 24 hodin_, `seats.php` `deadlineHours`, removed
  again); invite an Účetní (role checkbox, link shown in the tab, _Nepřijata_),
  in another browser context the link → mismatched passwords error → password
  set and signed in (_Vítejte_, the e-mail in the header), the menu shows only _Rezervace_ and _Výpis z banky_
  (also for `view=users`), the admin sees _Přijata_, sign out and in with
  e-mail + password, the used link is invalid, the admin disables the account
  → signed out on the next load; _Zapomněl jsem heslo_ → the same answer for
  an unknown e-mail, an invalid reset link refused.
- `scanner.spec.js` – sign-in with the Odbavovatel's e-mail and password; the camera is replaced by a canvas
  stream showing the seeded seat ticket QR (`getUserMedia` stub) → _Platná
  vstupenka_ with the seat, _Dospělý_ and _rezervace 2 místa · odbaveno 1 z
  2_, next scan _Už odbaveno_; offline (`context.setOffline`, after the snapshot was
  loaded): ticket _Platná vstupenka_ _„bez spojení, podle seznamu z“_, strip
  _k odeslání: 1_, next scan _Už odbaveno_ (still _k odeslání: 1_);
  back online → _„Odesláno 1 odbavení bez spojení.“_ and the seat is checked
  in on the server (`(offline)`); the scanner opens offline after one online visit
  (service worker) with the cached session and the offline strip; tab
  _Prodej_: two seats of the left wing with one child → QR payment 450 Kč → **Zaplaceno –
  vpustit** → _„Zaplaceno 450 Kč – 2 místa odbavena.“_ and the seats taken;
  the admin invites an Odbavovatel without a run, the phone opens the link and
  sets a password → lands in the scanner with _Nemáte přiřazený žádný termín_,
  the admin sees _Přijata_ and _odbavuje: žádný termín_, assigns the run _Dnes_
  (_změnit_ → **Uložit**), the reloaded scanner offers only _Dnes_ (no
  _Správa_ link); a Správce signed in to the admin opens _Odbavení_ and is signed in
  to the scanner (with _Správa_).

Locally `npm run test:e2e` needs PHP, MariaDB with a `zidle_test` database
and the `DB_*` variables; move `api/config.local.php` aside or make sure it
does not set keys the tests rely on. `PLAYWRIGHT_CHROMIUM` can point to an
existing Chromium binary instead of `npx playwright install chromium`.
