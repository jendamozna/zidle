// Seating layout of the church. It is defined only on the server
// (api/lib/layout.php) and loaded with seats.php / organizer.php; setLayout()
// fills the bindings below before the first map is drawn. Everything else
// (seat ids, capacities, occupancy) is derived from it.
//
// Section: {id, name, short, level, group, rows, seatsPerRow, rotated, rowSide}
//   group       – floor plan place: left / right (main floor), balcony
//   rows        – number of rows (row 1 is closest to the stage / railing)
//   seatsPerRow – chairs in each row
//   rotated     – section is turned 90° in the floor plan (rows run vertically)
//   rowSide     – for rotated sections: side on which row 1 lies

/** Level names, e.g. {main: 'Hlavní loď', balcony: 'Balkon'}. */
export let LEVELS = {};
export let SECTIONS = [];
export let SECTION_BY_ID = {};
export let TOTAL_CAPACITY = 0;

export const capacity = (section) => section.rows * section.seatsPerRow;

/** Takes the layout from the server ({levels, sections}). */
export function setLayout(layout) {
  LEVELS = layout.levels;
  SECTIONS = layout.sections;
  SECTION_BY_ID = Object.fromEntries(SECTIONS.map((s) => [s.id, s]));
  TOTAL_CAPACITY = SECTIONS.reduce((sum, s) => sum + capacity(s), 0);
}

export const seatId = (sectionId, row, seat) => `${sectionId}-${row}-${seat}`;

export function parseSeatId(id) {
  const [sectionId, row, seat] = id.split('-');
  return { sectionId, row: Number(row), seat: Number(seat) };
}

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
