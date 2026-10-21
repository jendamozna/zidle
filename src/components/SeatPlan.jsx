import { seatPositions, seatSpacing } from '../data/layout.js';

const STATE_LABEL = { available: 'volné', selected: 'moje', taken: 'obsazené', reserve: 'záložní' };
const pct = (value) => `${value}%`;

/**
 * Seats of one section laid out as in the church (rows from the server layout), large
 * enough to tap. Row numbers stand before seat 1 of each row. seatState(id) →
 * available | selected | taken | reserve (not for sale); minSeat / maxSeat = seat size range in px.
 */
export default function SeatPlan({ section, seatState, onToggle, minSeat = 30, maxSeat = 44 }) {
  const spacing = seatSpacing(section);
  const seats = seatPositions(section);
  const labels = section.rows.map((row, r) => {
    const first = row.parts[0];
    const length = Math.hypot(first.x2 - first.x1, first.y2 - first.y1);
    const [dx, dy] = length > 0 ? [(first.x2 - first.x1) / length, (first.y2 - first.y1) / length] : [1, 0];
    return { row: r + 1, x: first.x1 - dx * spacing, y: first.y1 - dy * spacing };
  });
  const points = [...seats, ...labels];
  const pad = spacing * 0.6;
  const minX = Math.min(...points.map((p) => p.x)) - pad;
  const minY = Math.min(...points.map((p) => p.y)) - pad;
  const width = Math.max(...points.map((p) => p.x)) + pad - minX;
  const height = Math.max(...points.map((p) => p.y)) + pad - minY;
  // px per plan unit: seats at least minSeat (the plan scrolls sideways if needed), at most maxSeat.
  const seatShare = 0.86;
  const unitMin = minSeat / (spacing * seatShare);
  const unitMax = maxSeat / (spacing * seatShare);
  const x = (value) => pct(((value - minX) / width) * 100);
  const y = (value) => pct(((value - minY) / height) * 100);

  return (
    <div className="seatmap-scroll">
      <div
        className="seatplan"
        style={{
          width: `clamp(${Math.round(width * unitMin)}px, 100%, ${Math.round(width * unitMax)}px)`,
          aspectRatio: `${width} / ${height}`,
          '--seat': (spacing * seatShare * 100) / width, // seat size in % of the plan width
        }}
      >
        {labels.map((l) => (
          <span key={`r${l.row}`} className="row-label" style={{ left: x(l.x), top: y(l.y) }}>
            {l.row}
          </span>
        ))}
        {seats.map((seat) => {
          const state = seatState(seat.id);
          return (
            <button
              key={seat.id}
              type="button"
              className={`seat seat-${state}`}
              style={{ left: x(seat.x), top: y(seat.y) }}
              disabled={state === 'taken' || state === 'reserve'}
              aria-pressed={state === 'selected'}
              aria-label={`Řada ${seat.row}, místo ${seat.seat}, ${STATE_LABEL[state]}`}
              title={seat.id}
              onClick={() => onToggle(seat.id)}
            >
              {seat.seat}
            </button>
          );
        })}
      </div>
    </div>
  );
}
