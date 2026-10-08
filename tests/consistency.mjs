// Checks the rule CLAUDE.md asks to keep by hand: a ticket code made by
// api/lib/ticket.php is decoded by src/scanner/ticket.js. (The seating layout
// is no longer duplicated – the apps load it from the server, see layout_public().)
// Usage: node tests/consistency.mjs   (needs php on PATH)
import { execFileSync } from 'node:child_process';
import { decodeTicket } from '../src/scanner/ticket.js';

const php = (code) =>
  JSON.parse(execFileSync('php', ['-r', code], { encoding: 'utf8', env: { ...process.env, TICKET_SECRET: 'consistency-check-secret' } }));

let failed = 0;
const check = (ok, message) => {
  console.log(`${ok ? '✓' : '✗'} ${message}`);
  if (!ok) failed++;
};

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
