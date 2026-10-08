# Moje židle 2026 – project rules

## Documentation (mandatory)

There are exactly two documents – do not add others:

1. **`docs/DEVELOPER.md`** (English, for programmers) describes the code
   **1:1**. Every code change must update it in the same commit: behaviour,
   texts shown to users, e-mails, limits, defaults, configuration keys,
   statuses, admin/scanner actions, API, database, formats. New feature →
   describe it; removed feature → remove it. Re-read the affected sections
   against the code before committing.
2. **`docs/prehled/`** (Czech, light, for stakeholders) – `index.html` +
   screenshots: roles, screens, main flow, rules, launch checklist. Update it
   when something stakeholders see changes (roles, screens, flow, rules,
   numbers like price/deadlines). Refresh affected screenshots with demo
   data. Publish it as an update of the existing Artifact
   **https://claude.ai/artifact/GXKiRvKYkzGQMnceZaTthV** (pass it as `url`
   with the images in `files`, never create a new one).

## Other rules

- **Times: UTC in the database** – every DATETIME column and every date inside
  JSON (e.g. storno rules, `…Z` ISO strings). Europe/Prague is used only for
  input (admin forms) and display. Use `db_time()`, `iso_utc()`,
  `prague_time()`/`prague_input()` – never store local time.
- **Layouts:** customer app (`src/`, except `src/scanner/`) is **mobile-first**
  – base CSS for phones, wider screens via `min-width` queries; admin
  (`api/admin.php`) is **desktop-first**; organizer scanner (`src/scanner/`) is
  **mobile only**.
- UI and e-mail texts are in Czech.
- The seating layout exists twice – `src/data/layout.js` and
  `api/lib/layout.php` – keep them identical.
- Ticket QR format exists twice – `api/lib/ticket.php` and
  `src/scanner/ticket.js` – keep them identical.
- Database changes: update `db/schema.sql` **and** add a new numbered,
  re-runnable migration in `db/migrations/`.
- CI (`.github/workflows/ci.yml`) must stay green: before pushing run the
  checks listed in `docs/DEVELOPER.md` §21 (lint, build, consistency,
  PHPStan, PHP integration tests, Playwright). Changed money, seat or VIP
  logic gets a test in `tests/php/run.php`; changed user flows get a
  Playwright test in `tests/e2e/`.
