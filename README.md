# Moje židle 2026

Responsive church chair reservation app (React + Vite).

```bash
npm install
npm run dev     # development server
npm run build   # production build in dist/
```

## Structure

- `src/data/layout.js` – seating layout (sections, rows, seats), seat price, seat IDs (`SECTION-ROW-SEAT`, e.g. `ML-1-1`, `BC-4-12`).
- `src/data/seatService.js` – mock data source (`fetchTakenSeats`, `reserveSeats`). Replace with real API/database calls.
- `src/hooks/useSeats.js` – seat state (taken seats, my selection), per-section statistics and reservation.
- `src/components/` – floor plan overview, section detail seat map, reservation panel.

Section IDs: `WL` Left Wing, `ML` Left Main, `MR` Right Main, `WR` Right Wing, `BL` Balcony left, `BC` Balcony center, `BR` Balcony right.
