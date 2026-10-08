import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { SECTIONS, SEAT_PRICE, TOTAL_CAPACITY, capacity, compareSeatIds, parseSeatId } from '../data/layout.js';
import { createReservation, fetchSeats } from '../data/seatService.js';

const REFRESH_MS = 30_000;

export function useSeats() {
  const [taken, setTaken] = useState(() => new Set());
  const [selected, setSelected] = useState(() => new Set());
  const [price, setPrice] = useState(SEAT_PRICE);
  const [deadlineHours, setDeadlineHours] = useState(null);
  const formToken = useRef('');
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const active = useRef(true);

  const refresh = useCallback(async () => {
    try {
      const data = await fetchSeats();
      if (!active.current) return;
      const nextTaken = new Set(data.taken);
      setTaken(nextTaken);
      setPrice(data.price);
      setDeadlineHours(data.deadlineHours);
      formToken.current = data.formToken;
      // Drop seats someone else reserved in the meantime.
      setSelected((prev) => {
        const kept = [...prev].filter((id) => !nextTaken.has(id));
        return kept.length === prev.size ? prev : new Set(kept);
      });
      setLoadError(null);
    } catch (err) {
      if (active.current) setLoadError(err.message);
    } finally {
      if (active.current) setLoading(false);
    }
  }, []);

  useEffect(() => {
    active.current = true;
    refresh();
    const timer = setInterval(refresh, REFRESH_MS);
    const onFocus = () => refresh();
    window.addEventListener('focus', onFocus);
    return () => {
      active.current = false;
      clearInterval(timer);
      window.removeEventListener('focus', onFocus);
    };
  }, [refresh]);

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
    for (const id of taken) {
      const section = bySection[parseSeatId(id).sectionId];
      if (section) section.taken += 1;
    }
    for (const id of [...selected].sort(compareSeatIds)) bySection[parseSeatId(id).sectionId].mine.push(id);

    for (const s of Object.values(bySection)) {
      s.occupied = s.taken + s.mine.length;
      s.free = s.capacity - s.occupied;
      s.pct = Math.round((s.occupied / s.capacity) * 100);
    }
    const occupied = Object.values(bySection).reduce((sum, s) => sum + s.occupied, 0);
    return {
      bySection,
      free: TOTAL_CAPACITY - occupied,
      selectedCount: selected.size,
      price,
      total: selected.size * price,
    };
  }, [taken, selected, price]);

  /** Submits the selection with customer details; resolves with the reservation. */
  const reserve = useCallback(
    async (customer) => {
      const ids = [...selected].sort(compareSeatIds);
      if (!ids.length) return null;
      setSubmitting(true);
      try {
        const reservation = await createReservation({ ...customer, seats: ids, formToken: formToken.current });
        setTaken((prev) => new Set([...prev, ...reservation.seats]));
        setSelected(new Set());
        return reservation;
      } catch (err) {
        if (err.status === 409) await refresh();
        throw err;
      } finally {
        setSubmitting(false);
      }
    },
    [selected, refresh],
  );

  const seatState = useCallback(
    (id) => (selected.has(id) ? 'selected' : taken.has(id) ? 'taken' : 'available'),
    [selected, taken],
  );

  return { loading, loadError, submitting, deadlineHours, stats, seatState, toggleSeat, reserve, refresh };
}
