// Ticket QR format – keep in sync with api/lib/ticket.php. One code = one seat:
//   Z26M|<reservation id>|<variable symbol>|<seat>|<kind A|C>|<name>|<signature>
// Decoding here is only an offline fallback; the scanner shows the current state loaded from the server.

const KINDS = { A: 'adult', C: 'child' };

export function decodeTicket(text) {
  const parts = String(text).trim().split('|');
  if (parts.length !== 7 || parts[0] !== 'Z26M') return null;
  const [, id, variableSymbol, seat, kind, name] = parts;
  return {
    id: Number(id),
    variableSymbol,
    seat,
    kind: KINDS[kind] ?? 'adult',
    name,
  };
}
