import { TOTAL_CAPACITY, occupancyLevel } from '../data/layout.js';
import { runParts } from '../runs.js';

const freeLabel = (n) => (n === 1 ? '1 volné místo' : n >= 2 && n <= 4 ? `${n} volná místa` : `${n} volných míst`);

/** First step: choose the run (date) before choosing seats. */
export default function RunPicker({ runs, onSelect }) {
  if (runs === null) return <p className="muted">Načítám termíny…</p>;
  if (!runs.length) return <p className="muted">Termíny zatím nejsou vypsané.</p>;

  return (
    <section className="runs" aria-labelledby="runs-title">
      <h2 id="runs-title" className="runs-title">
        Vyberte termín
      </h2>
      <ul className="runs-list">
        {runs.map((run) => {
          const { weekday, date, time } = runParts(run);
          const soldOut = run.free === 0;
          const closed = !run.bookingOpen;
          const pct = Math.round(((TOTAL_CAPACITY - run.free) / TOTAL_CAPACITY) * 100);
          return (
            <li key={run.id}>
              <button
                type="button"
                className={`run-card occ-${occupancyLevel(pct)}`}
                disabled={closed || soldOut}
                onClick={() => onSelect(run.id)}
              >
                <span className="run-weekday">{weekday}</span>
                <span className="run-date">{date}</span>
                <span className="run-time">{time}</span>
                {run.label && <span className="run-label">{run.label}</span>}
                <span className="run-spacer" aria-hidden="true" />
                <span className="run-status">{closed ? 'Rezervace uzavřeny' : soldOut ? 'Vyprodáno' : freeLabel(run.free)}</span>
              </button>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
