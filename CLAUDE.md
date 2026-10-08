# Moje židle 2026 – project rules

## Documentation must match the code (mandatory)

`docs/DOKUMENTACE.md` is the Czech documentation that describes every process
of the app 1:1. **Every code change must update it in the same commit**:

- any change of behaviour, texts shown to users, e-mails, limits, defaults,
  configuration keys, statuses, admin/scanner actions, API, database or QR
  formats → update the matching chapter(s);
- new feature → describe the whole process step by step, including error
  messages and e-mails;
- removed feature → remove it from the documentation.

Before committing, re-read the affected chapters against the code. After
pushing, republish the shared documentation page (Artifact
`docs/DOKUMENTACE.md` → HTML) so the shared link stays current.

## Other rules

- UI and e-mail texts are in Czech.
- The seating layout exists twice – `src/data/layout.js` and
  `api/lib/layout.php` – keep them identical.
- Ticket QR format exists twice – `api/lib/ticket.php` and
  `src/scanner/ticket.js` – keep them identical.
- Database changes: update `db/schema.sql` **and** add a new numbered,
  re-runnable migration in `db/migrations/`.
