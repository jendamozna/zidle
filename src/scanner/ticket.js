// Ticket QR format – keep in sync with api/lib/ticket.php:
//   Z26|<reservation id>|<variable symbol>|<seat count>|<name>|<seat,seat,...>|<signature>
// (older tickets: without the reservation id). Decoding here is only an
// offline fallback; the scanner shows the current state loaded from the server.

export function decodeTicket(text) {
  const parts = String(text).trim().split('|');
  if (parts.length === 6) parts.splice(1, 0, '');
  if (parts.length !== 7 || parts[0] !== 'Z26') return null;
  const [, id, variableSymbol, count, name, seats] = parts;
  return {
    id: id ? Number(id) : null,
    variableSymbol,
    count: Number(count),
    name,
    seats: seats ? seats.split(',') : [],
  };
}
