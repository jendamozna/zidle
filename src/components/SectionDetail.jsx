import { LEVELS, occupancyLevel, seatId } from '../data/layout.js';

const STATE_LABEL = { available: 'volné', selected: 'moje', taken: 'obsazené' };

function Seat({ id, row, seat, state, onToggle, style }) {
  return (
    <button
      type="button"
      className={`seat seat-${state}`}
      style={style}
      disabled={state === 'taken'}
      aria-pressed={state === 'selected'}
      aria-label={`Řada ${row}, místo ${seat}, ${STATE_LABEL[state]}`}
      title={id}
      onClick={() => onToggle(id)}
    >
      <span className="seat-row">{row}</span>
      <span className="seat-num">{seat}</span>
    </button>
  );
}

export default function SectionDetail({ section, seats, onBack }) {
  const stat = seats.stats.bySection[section.id];
  const { rows, seatsPerRow, rotated } = section;
  const isBalcony = section.level === 'balcony';

  // Grid placement: normal sections have rows top→bottom (row 1 nearest the
  // stage). Rotated balcony wings have rows as columns, row 1 towards the
  // balcony railing, seat 1 nearest the stage.
  const cells = [];
  const labels = [];
  for (let r = 1; r <= rows; r++) {
    const rowCol = rotated ? (section.rowSide === 'right' ? rows - r + 1 : r) : 1;
    labels.push(
      <span
        key={`l${r}`}
        className="row-label"
        style={rotated ? { gridColumn: rowCol, gridRow: 1 } : { gridColumn: 1, gridRow: r }}
      >
        {rotated ? `Ř. ${r}` : r}
      </span>,
    );
    for (let s = 1; s <= seatsPerRow; s++) {
      const id = seatId(section.id, r, s);
      cells.push(
        <Seat
          key={id}
          id={id}
          row={r}
          seat={s}
          state={seats.seatState(id)}
          onToggle={seats.toggleSeat}
          style={rotated ? { gridColumn: rowCol, gridRow: s + 1 } : { gridColumn: s + 1, gridRow: r }}
        />,
      );
    }
  }

  const cols = rotated ? rows : seatsPerRow;
  const gridStyle = rotated
    ? { gridTemplateColumns: `repeat(${cols}, var(--seat))`, gridTemplateRows: `auto repeat(${seatsPerRow}, var(--seat))` }
    : { gridTemplateColumns: `auto repeat(${cols}, var(--seat))` };

  return (
    <div className="detail">
      <div className="detail-head">
        <button type="button" className="btn btn-ghost" onClick={onBack}>
          ← Zpět na mapu
        </button>
        <div className="detail-title">
          <span className="detail-level">{LEVELS[section.level]}</span>
          <h2>{section.name}</h2>
        </div>
        <div className="detail-stats">
          <div className="chip">
            <strong>{seats.loading ? '–' : stat.free}</strong> volných
          </div>
          <div className={`chip chip-occ occ-${occupancyLevel(stat.pct)}`}>
            <strong>{stat.pct} %</strong> obsazeno
          </div>
        </div>
      </div>

      <div className="seatmap-card">
        <div className={`stage-dir ${isBalcony ? 'is-balcony' : ''}`}>
          {isBalcony ? '↑ Směr k pódiu' : 'Pódium'}
        </div>
        <div className="seatmap-scroll">
          <div
            className={`seatmap ${rotated ? 'is-rotated' : ''}`}
            style={{ ...gridStyle, '--cols': cols + (rotated ? 0 : 1) }}
          >
            {labels}
            {cells}
          </div>
        </div>
        {rotated && (
          <div className={`railing railing-${section.rowSide}`}>
            {section.rowSide === 'right' ? 'Zábradlí →' : '← Zábradlí'}
          </div>
        )}
        {!isBalcony && <div className="back-dir">↓ Vchod</div>}
        <div className="legend">
          <span><i className="seat-dot seat-available" /> Volné</span>
          <span><i className="seat-dot seat-selected" /> Moje</span>
          <span><i className="seat-dot seat-taken" /> Obsazené</span>
        </div>
      </div>
    </div>
  );
}
