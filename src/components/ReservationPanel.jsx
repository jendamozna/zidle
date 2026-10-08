import { SECTIONS, SEAT_PRICE, formatCzk, parseSeatId } from '../data/layout.js';
import { seatsLabel } from '../plural.js';

export default function ReservationPanel({ stats, currentSectionId, submitting, onRemove, onOpen, onReserve }) {
  const groups = SECTIONS.filter((s) => stats.bySection[s.id].mine.length > 0);

  return (
    <aside className="panel" aria-label="Rezervace">
      <h2 className="panel-title">Rezervace</h2>
      <ul className="panel-groups">
        {groups.map((section) => {
          const mine = stats.bySection[section.id].mine;
          return (
            <li key={section.id} className={`panel-group ${section.id === currentSectionId ? 'is-current' : ''}`}>
              <div className="panel-group-head">
                <button type="button" className="link" onClick={() => onOpen(section.id)}>
                  {section.name}
                </button>
                <span className="muted">{formatCzk(mine.length * SEAT_PRICE)}</span>
              </div>
              <div className="seat-chips">
                {mine.map((id) => {
                  const { row, seat } = parseSeatId(id);
                  return (
                    <button
                      key={id}
                      type="button"
                      className="seat-chip"
                      title={`Zrušit ${id}`}
                      aria-label={`Zrušit řadu ${row}, místo ${seat}`}
                      onClick={() => onRemove(id)}
                    >
                      ř. {row} · {seat}
                      <span aria-hidden="true">×</span>
                    </button>
                  );
                })}
              </div>
            </li>
          );
        })}
      </ul>
      <div className="panel-total">
        <div>
          <span className="muted">
            {seatsLabel(stats.selectedCount)} × {formatCzk(SEAT_PRICE)}
          </span>
          <strong>{formatCzk(stats.total)}</strong>
        </div>
        <button type="button" className="btn btn-primary btn-block" disabled={submitting} onClick={onReserve}>
          {submitting ? 'Rezervuji…' : 'Rezervovat'}
        </button>
      </div>
    </aside>
  );
}
