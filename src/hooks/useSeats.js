import { useCallback, useEffect, useMemo, useState } from 'react';
import { SECTIONS, SEAT_PRICE, TOTAL_CAPACITY, capacity, compareSeatIds, parseSeatId } from '../data/layout.js';
import { fetchTakenSeats, reserveSeats } from '../data/seatService.js';

export function useSeats() {
  const [taken, setTaken] = useState(() => new Set());
  const [selected, setSelected] = useState(() => new Set());
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    let active = true;
    fetchTakenSeats().then((ids) => {
      if (!active) return;
      setTaken(new Set(ids));
      setLoading(false);
    });
    return () => {
      active = false;
    };
  }, []);

  const toggleSeat = useCallback(
    (id) => {
      if (taken.has(id)) return;
      setSelected((prev) => {
        const next = new Set(prev);
        if (next.has(id)) next.delete(id);
        else next.add(id);
        return next;
      });
    },
    [taken],
  );

  const stats = useMemo(() => {
    const bySection = Object.fromEntries(
      SECTIONS.map((s) => [s.id, { capacity: capacity(s), taken: 0, mine: [] }]),
    );
    for (const id of taken) bySection[parseSeatId(id).sectionId].taken += 1;
    for (const id of [...selected].sort(compareSeatIds)) bySection[parseSeatId(id).sectionId].mine.push(id);

    for (const s of Object.values(bySection)) {
      s.occupied = s.taken + s.mine.length;
      s.free = s.capacity - s.occupied;
      s.pct = Math.round((s.occupied / s.capacity) * 100);
    }
    const occupied = taken.size + selected.size;
    return {
      bySection,
      free: TOTAL_CAPACITY - occupied,
      selectedCount: selected.size,
      total: selected.size * SEAT_PRICE,
    };
  }, [taken, selected]);

  const reserve = useCallback(async () => {
    const ids = [...selected];
    if (!ids.length) return null;
    setSubmitting(true);
    try {
      const result = await reserveSeats(ids);
      setTaken((prev) => new Set([...prev, ...ids]));
      setSelected(new Set());
      return result;
    } finally {
      setSubmitting(false);
    }
  }, [selected]);

  const seatState = useCallback(
    (id) => (selected.has(id) ? 'selected' : taken.has(id) ? 'taken' : 'available'),
    [selected, taken],
  );

  return { loading, submitting, stats, seatState, toggleSeat, reserve };
}
