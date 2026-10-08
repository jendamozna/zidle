# Moje židle 2026

Church chair reservation app – React (Vite) frontend, PHP 8 backend, MariaDB.

## How it works

1. The visitor picks seats on the floor plan and clicks **Rezervovat**.
2. They enter name, surname and e-mail. The seats are stored in the database
   as a *pending* reservation and immediately shown as occupied to everyone.
3. A payment page shows a Czech **QR Platba** code (amount, account, variable
   symbol, due date). The page stays reachable at `?r=<token>`.
4. An administrator confirms received payments in `api/admin.php`.
5. Reservations not paid by the deadline (`PAYMENT_DEADLINE_HOURS`, default
   72 h) are marked *expired* and their seats are freed.

## Setup

```bash
# database
mariadb -e "CREATE DATABASE zidle CHARACTER SET utf8mb4"
mariadb -e "CREATE USER 'zidle'@'localhost' IDENTIFIED BY '…'; GRANT ALL ON zidle.* TO 'zidle'@'localhost'"
mariadb zidle < db/schema.sql

# configuration – DB credentials, bank account, admin password, e-mail
cp api/config.local.example.php api/config.local.php
```

All options and defaults are in `api/config.php`; they can also be set as
environment variables. `BANK_IBAN` and `ADMIN_PASSWORD` are required.

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

Upload the contents of `dist/` together with the `api/` folder (so the app
finds the API at `./api/`) to a PHP host with MariaDB. Keep
`api/config.local.php` and `api/lib/` non-public (`.htaccess` files are
included for Apache). Add a cron job so seats are freed even without traffic:

```
*/10 * * * * php /path/to/api/cron-expire.php
```

If the API runs on a different domain, build with `VITE_API_URL=https://…/api`
and set `CORS_ORIGIN`.

## API

| Method | Endpoint | |
| --- | --- | --- |
| GET | `api/seats.php` | `{taken: [seatId], price, deadlineHours}` |
| POST | `api/reservations.php` | body `{firstName, lastName, email, seats}` → reservation with payment details (201), `409` with `conflict` when a seat is already taken, `422` with `fields` on validation errors |
| GET | `api/reservations.php?token=` | reservation status and payment details |

Seat IDs: `SECTION-ROW-SEAT`, e.g. `ML-1-1`, `BC-4-12`. Sections: `WL` Left
Wing, `ML` Left Main, `MR` Right Main, `WR` Right Wing, `BL` Balcony left,
`BC` Balcony center, `BR` Balcony right. The layout is defined in both
`src/data/layout.js` and `api/lib/layout.php` – keep them in sync.

## Database

- `reservations` – one row per reservation incl. history (`pending`, `paid`, `expired`, `cancelled`).
- `reservation_seats` – currently held seats; the primary key on `seat_id` prevents double booking. Rows are removed when a reservation expires or is cancelled.
