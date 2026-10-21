// Checks the rule CLAUDE.md asks to keep by hand: seat ticket codes made by
// api/lib/ticket.php are decoded by src/scanner/ticket.js. (The seating layout
// is no longer duplicated – the apps load it from the server, see layout_public().)
// Usage: node tests/consistency.mjs   (needs php on PATH)
import { execFileSync } from 'node:child_process';
import { decodeTicket } from '../src/scanner/ticket.js';

const php = (code) =>
  JSON.parse(execFileSync('php', ['-r', code], { encoding: 'utf8', env: { ...process.env, TICKET_SECRET: 'consistency-check-secret-0123456789ab' } }));

let failed = 0;
const check = (ok, message) => {
  console.log(`${ok ? '✓' : '✗'} ${message}`);
  if (!ok) failed++;
};

const reservation = {
  id: 42, variable_symbol: '1234567890', seat_count: 2, first_name: 'Jana', last_name: 'Nováková',
  seats: 'ML-1-1,ML-1-2', child_seats: 'ML-1-2', free_seats: '', adult_price: 300, child_price: 150,
  ticket_names: JSON.stringify({ 'ML-1-2': 'Petr Novák' }),
};
const codes = php(
  `require 'api/lib/bootstrap.php'; $r = json_decode(${JSON.stringify(JSON.stringify(reservation))}, true);` +
    ` echo json_encode([ticket_code($r, 'ML-1-1'), ticket_code($r, 'ML-1-2')]);`,
);
const [adult, child] = codes.map(decodeTicket);
check(
  adult?.id === 42 && adult.variableSymbol === '1234567890' && adult.seat === 'ML-1-1' && adult.kind === 'adult' &&
    adult.name === 'Jana Nováková',
  `adult seat ticket from PHP decoded by the scanner: ${codes[0]}`,
);
check(
  child?.seat === 'ML-1-2' && child.kind === 'child' && child.name === 'Petr Novák',
  `child seat ticket with its own name decoded by the scanner: ${codes[1]}`,
);

process.exit(failed ? 1 : 0);
