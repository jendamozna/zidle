// Checks the rules CLAUDE.md asks to keep by hand:
//  - the seating layout is identical in src/data/layout.js and api/lib/layout.php
//  - a ticket code made by api/lib/ticket.php is decoded by src/scanner/ticket.js
// Usage: node tests/consistency.mjs   (needs php on PATH)
import { execFileSync } from 'node:child_process';
import { SECTIONS } from '../src/data/layout.js';
import { decodeTicket } from '../src/scanner/ticket.js';

const php = (code) =>
  JSON.parse(execFileSync('php', ['-r', code], { encoding: 'utf8', env: { ...process.env, TICKET_SECRET: 'consistency-check-secret' } }));

let failed = 0;
const check = (ok, message) => {
  console.log(`${ok ? '✓' : '✗'} ${message}`);
  if (!ok) failed++;
};

const phpSections = php(`require 'api/lib/layout.php'; echo json_encode(SECTIONS, JSON_UNESCAPED_UNICODE);`);
const jsSections = Object.fromEntries(SECTIONS.map((s) => [s.id, { name: s.name, rows: s.rows, seats: s.seatsPerRow }]));
check(
  JSON.stringify(Object.keys(phpSections)) === JSON.stringify(Object.keys(jsSections)),
  `same sections in the same order (${Object.keys(jsSections).join(', ')})`,
);
for (const [id, js] of Object.entries(jsSections)) {
  check(JSON.stringify(phpSections[id]) === JSON.stringify(js), `section ${id} identical: ${JSON.stringify(js)}`);
}

const reservation = { id: 42, variable_symbol: '1234567890', seat_count: 2, first_name: 'Jana', last_name: 'Nováková', seats: 'ML-1-1,ML-1-2' };
const code = php(
  `require 'api/lib/bootstrap.php'; echo json_encode(ticket_code(json_decode(${JSON.stringify(JSON.stringify(reservation))}, true)));`,
);
const decoded = decodeTicket(code);
check(
  decoded?.id === 42 && decoded.variableSymbol === '1234567890' && decoded.count === 2 && decoded.name === 'Jana Nováková' &&
    decoded.seats.join() === 'ML-1-1,ML-1-2',
  `ticket code from PHP decoded by the scanner: ${code}`,
);

process.exit(failed ? 1 : 0);
