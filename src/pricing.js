import { compareSeatIds } from './data/layout.js';

// Same rules as api/lib/pricing.php (the server's price is authoritative):
// the last `children` seats are child seats, each discount code makes the most
// expensive seat free (adult seats first, then child seats).

export const KIND_LABELS = { adult: 'Dospělý', child: 'Dítě 4–17 let' };

const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWX23456789';

/** "abcd efgh" → "ABCDEFGH" */
export const normalizeCode = (input) =>
  String(input)
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, '');

/** "ABCDEFGH" → "ABCD-EFGH" */
export const formatCode = (code) => `${code.slice(0, 4)}-${code.slice(4)}`;

export const isCodeFormat = (code) => code.length === 8 && [...code].every((c) => CODE_ALPHABET.includes(c));

/** {items: [{seat, kind, price, free}], adults, children, discount, total} */
export function priceSeats(seats, children, codes, adultPrice, childPrice) {
  const sorted = [...seats].sort(compareSeatIds);
  const childSet = new Set(children > 0 ? sorted.slice(-children) : []);
  const price = (seat) => (childSet.has(seat) ? childPrice : adultPrice);
  const order = sorted.map((seat, i) => i).sort((a, b) => price(sorted[b]) - price(sorted[a]) || a - b);
  const freeSet = new Set(order.slice(0, codes).map((i) => sorted[i]));
  const items = sorted.map((seat) => ({
    seat,
    kind: childSet.has(seat) ? 'child' : 'adult',
    price: freeSet.has(seat) ? 0 : price(seat),
    free: freeSet.has(seat),
  }));
  const full = sorted.reduce((sum, seat) => sum + price(seat), 0);
  const total = items.reduce((sum, item) => sum + item.price, 0);
  return { items, adults: sorted.length - childSet.size, children: childSet.size, discount: full - total, total };
}
