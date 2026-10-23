import { LEVELS, PLAN, occupancyLevel, sectionSeatIds, seatPositions } from '../data/layout.js';
import { freeLabel } from '../plural.js';
import SeatPlan from './SeatPlan.jsx';

export default function SectionDetail({ section, seats, onBack }) {
  const stat = seats.stats.bySection[section.id];
  const isBalcony = section.level === 'balcony';
  const hasReserve = sectionSeatIds(section).some((id) => seats.seatState(id) === 'reserve');
  // sections beside the stage (main) or the balcony's open middle look at the stage diagonally
  const ahead = PLAN[section.level].items.find((item) => item.kind === 'stage' || item.kind === 'void');
  const xs = seatPositions(section).map((s) => s.x);
  const centre = (Math.min(...xs) + Math.max(...xs)) / 2;
  const arrow = !ahead ? '' : centre < ahead.x ? '↗ ' : centre > ahead.x + ahead.w ? '↖ ' : isBalcony ? '↑ ' : '';

  return (
    <div className="detail">
      <div className="detail-head">
        <button type="button" className="btn btn-ghost btn-back" onClick={onBack} aria-label="Zpět na mapu">
          ←<span className="wide-only"> Zpět na mapu</span>
        </button>
        <div className="detail-title">
          <span className="detail-level">{LEVELS[section.level]}</span>
          <h2>{section.name}</h2>
        </div>
        <div className="detail-stats">
          <div className={`chip chip-occ occ-${occupancyLevel(stat.pct)}`}>
            <strong>{seats.loading ? '–' : freeLabel(stat.free)}</strong>
          </div>
        </div>
      </div>

      <div className="seatmap-card">
        <div className={`stage-dir ${isBalcony ? 'is-balcony' : ''}`}>{arrow}Pódium</div>
        <SeatPlan section={section} seatState={seats.seatState} onToggle={seats.toggleSeat} />
        {!isBalcony && <div className="back-dir">↓ Vchod</div>}
        <div className="legend">
          <span>
            <i className="seat-dot seat-available" /> Volné
          </span>
          <span>
            <i className="seat-dot seat-selected" /> Moje
          </span>
          <span>
            <i className="seat-dot seat-taken" /> Obsazené
          </span>
          {hasReserve && (
            <span>
              <i className="seat-dot seat-reserve" /> Záložní
            </span>
          )}
        </div>
      </div>
    </div>
  );
}
