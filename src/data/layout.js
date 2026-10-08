// Static description of the church seating. Everything else (seat ids,
// capacities, occupancy) is derived from this, so it can later be replaced
// by data loaded from a database without touching the UI.

export const SEAT_PRICE = 300; // Kč

export const LEVELS = {
  main: 'Hlavní loď',
  balcony: 'Balkon',
};

/**
 * rows        – number of rows (row 1 is closest to the stage / railing)
 * seatsPerRow – chairs in each row
 * rotated     – section is turned 90° in the floor plan (rows run vertically)
 * rowSide     – for rotated sections: side on which row 1 lies
 */
export const SECTIONS = [
  { id: 'WL', name: 'Levé křídlo', short: 'L. křídlo', level: 'main', rows: 4, seatsPerRow: 6 },
  { id: 'ML', name: 'Levá hlavní', short: 'L. hlavní', level: 'main', rows: 10, seatsPerRow: 8 },
  { id: 'MR', name: 'Pravá hlavní', short: 'P. hlavní', level: 'main', rows: 10, seatsPerRow: 8 },
  { id: 'WR', name: 'Pravé křídlo', short: 'P. křídlo', level: 'main', rows: 6, seatsPerRow: 6 },
  { id: 'BL', name: 'Balkon vlevo', short: 'Balkon L', level: 'balcony', rows: 4, seatsPerRow: 12, rotated: true, rowSide: 'right' },
  { id: 'BC', name: 'Balkon střed', short: 'Balkon S', level: 'balcony', rows: 4, seatsPerRow: 12 },
  { id: 'BR', name: 'Balkon vpravo', short: 'Balkon P', level: 'balcony', rows: 2, seatsPerRow: 10, rotated: true, rowSide: 'left' },
];

export const SECTION_BY_ID = Object.fromEntries(SECTIONS.map((s) => [s.id, s]));

export const seatId = (sectionId, row, seat) => `${sectionId}-${row}-${seat}`;

export function parseSeatId(id) {
  const [sectionId, row, seat] = id.split('-');
  return { sectionId, row: Number(row), seat: Number(seat) };
}

export const capacity = (section) => section.rows * section.seatsPerRow;

export const TOTAL_CAPACITY = SECTIONS.reduce((sum, s) => sum + capacity(s), 0);

export function sectionSeatIds(section) {
  const ids = [];
  for (let r = 1; r <= section.rows; r++) {
    for (let s = 1; s <= section.seatsPerRow; s++) ids.push(seatId(section.id, r, s));
  }
  return ids;
}

export function compareSeatIds(a, b) {
  const pa = parseSeatId(a);
  const pb = parseSeatId(b);
  const order = SECTIONS.findIndex((s) => s.id === pa.sectionId) - SECTIONS.findIndex((s) => s.id === pb.sectionId);
  return order || pa.row - pb.row || pa.seat - pb.seat;
}

export function occupancyLevel(pct) {
  if (pct >= 85) return 'high';
  if (pct >= 50) return 'medium';
  return 'low';
}

export const formatCzk = (amount) =>
  `${new Intl.NumberFormat('cs-CZ').format(amount)} Kč`;
