// Ticket QR format – keep in sync with api/lib/ticket.php:
//   Z26|<variable symbol>|<seat count>|<name>|<seat,seat,...>|<signature>
// Decoding here is for display only; authenticity is checked by the server.

export function decodeTicket(text) {
  const parts = String(text).trim().split('|');
  if (parts.length !== 6 || parts[0] !== 'Z26') return null;
  const [, variableSymbol, count, name, seats] = parts;
  return {
    variableSymbol,
    count: Number(count),
    name,
    seats: seats ? seats.split(',') : [],
  };
}
