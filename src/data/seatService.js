// Mock data source. Swap these functions for real API / database calls
// (e.g. fetch('/api/seats')) – the rest of the app only depends on their
// signatures.

import { SECTIONS, sectionSeatIds } from './layout.js';

// Deterministic sample occupancy so the floor plan looks realistic.
const SAMPLE_FILL = { WL: 0.2, ML: 0.62, MR: 0.9, WR: 0.35, BL: 0.1, BC: 0.55, BR: 0.25 };

function seededRandom(seed) {
  let t = seed;
  return () => {
    t = (t * 1664525 + 1013904223) % 4294967296;
    return t / 4294967296;
  };
}

let takenSeats = (() => {
  const rand = seededRandom(2026);
  const ids = [];
  for (const section of SECTIONS) {
    for (const id of sectionSeatIds(section)) {
      if (rand() < SAMPLE_FILL[section.id]) ids.push(id);
    }
  }
  return new Set(ids);
})();

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Seats already reserved by other people. */
export async function fetchTakenSeats() {
  await delay(150);
  return [...takenSeats];
}

/** Reserve the given seats. Rejects if any of them is no longer available. */
export async function reserveSeats(ids) {
  await delay(400);
  const conflict = ids.filter((id) => takenSeats.has(id));
  if (conflict.length) {
    const error = new Error('Některá místa už jsou obsazená.');
    error.conflict = conflict;
    throw error;
  }
  takenSeats = new Set([...takenSeats, ...ids]);
  return { ids, reservedAt: new Date().toISOString() };
}
