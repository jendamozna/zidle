// Seating layout of the church. It is defined only on the server
// (api/lib/layout.php) and loaded with seats.php / organizer.php; setLayout()
// fills the bindings below before the first map is drawn. Everything else
// (seat ids, positions, capacities, occupancy) is derived from it.
//
// PLAN: {main|balcony: {height, items: [{kind: stage|band|void, label, x, y, w, h}]}} –
//   one map per level, 100 units wide.
// Section: {id, name, short, level, optional, label: {x, y, align}, rows: [{seats, x1, y1, x2, y2}]}
//   rows – row 1 first (nearest the stage / balcony railing); seat 1 sits at (x1, y1),
//          the last seat at (x2, y2), the others evenly between (plan units)
//   optional – extra seats, sold only in runs where the admin opened them

/** Level names, e.g. {main: 'Hlavní loď', balcony: 'Balkon'}. */
export let LEVELS = {};
export let PLAN = {};
export let SECTIONS = [];
export let SECTION_BY_ID = {};
export let TOTAL_CAPACITY = 0;

export const capacity = (section) => section.rows.reduce((sum, row) => sum + row.seats, 0);

/** Takes the layout from the server ({levels, plan, sections}). */
export function setLayout(layout) {
  LEVELS = layout.levels;
  PLAN = layout.plan;
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
  return section.rows.flatMap((row, r) => Array.from({ length: row.seats }, (_, i) => seatId(section.id, r + 1, i + 1)));
}

/** Seats of the section with their plan position: [{id, row, seat, x, y}]. */
export function seatPositions(section) {
  return section.rows.flatMap((row, r) =>
    Array.from({ length: row.seats }, (_, i) => {
      const t = row.seats > 1 ? i / (row.seats - 1) : 0;
      return {
        id: seatId(section.id, r + 1, i + 1),
        row: r + 1,
        seat: i + 1,
        x: row.x1 + (row.x2 - row.x1) * t,
        y: row.y1 + (row.y2 - row.y1) * t,
      };
    }),
  );
}

/** Distance between neighbouring seats in the section (plan units), for the seat size. */
export function seatSpacing(section) {
  const gaps = section.rows
    .filter((row) => row.seats > 1)
    .map((row) => Math.hypot(row.x2 - row.x1, row.y2 - row.y1) / (row.seats - 1));
  return gaps.length ? Math.min(...gaps) : 2.7;
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
