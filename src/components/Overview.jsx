import { LEVELS, PLAN, SECTIONS, occupancyLevel, seatPositions, seatSpacing } from '../data/layout.js';
import { freeLabel } from '../plural.js';

const pct = (value) => `${value}%`;

/**
 * One level of the church drawn from the server layout (PLAN + section rows):
 * every seat is a dot coloured by its state (reserve seats grey), a section opens with a tap.
 */
function LevelMap({ level, stats, seatState, loading, onOpen }) {
  const { height, items } = PLAN[level];
  const y = (value) => pct((value / height) * 100);

  return (
    <div className="plan-map" style={{ aspectRatio: `100 / ${height}` }}>
      {items.map((item) => (
        <div
          key={item.kind}
          className={`plan-item plan-${item.kind}`}
          style={{ left: pct(item.x), top: y(item.y), width: pct(item.w), height: y(item.h) }}
        >
          <span>{item.label}</span>
        </div>
      ))}
      {SECTIONS.filter((s) => s.level === level).map((section) => {
        const stat = stats.bySection[section.id];
        const seats = seatPositions(section);
        const size = seatSpacing(section) * 0.78;
        const pad = size * 0.9;
        const xs = seats.map((s) => s.x);
        const ys = seats.map((s) => s.y);
        const box = { x: Math.min(...xs) - pad, y: Math.min(...ys) - pad, x2: Math.max(...xs) + pad, y2: Math.max(...ys) + pad };
        const open = () => onOpen(section.id);
        const label = loading ? '–' : freeLabel(stat.free);
        return (
          <div key={section.id} className={`plan-section occ-${occupancyLevel(stat.pct)}`}>
            <button
              type="button"
              className="plan-hit"
              style={{ left: pct(box.x), top: y(box.y), width: pct(box.x2 - box.x), height: y(box.y2 - box.y) }}
              onClick={open}
              aria-label={`${section.name}, ${label}${stat.mine.length ? `, moje místa: ${stat.mine.length}` : ''}`}
            />
            {seats.map((seat) => (
              <span
                key={seat.id}
                className={`plan-seat seat-${seatState(seat.id)}`}
                style={{ left: pct(seat.x), top: y(seat.y), width: pct(size) }}
              />
            ))}
            <div
              className={`plan-label align-${section.label.align}`}
              style={{ left: pct(section.label.x), top: y(section.label.y) }}
              onClick={open}
              aria-hidden="true"
            >
              <strong>{section.short}</strong>
              <span>
                {label}
                {stat.mine.length > 0 && <b className="mine-count">{stat.mine.length}</b>}
              </span>
            </div>
          </div>
        );
      })}
    </div>
  );
}

/** Floor plan: main floor (stage on top) and the U-shaped balcony. */
export default function Overview({ stats, seatState, loading, onOpen }) {
  return (
    <div className="plan">
      <section className="level level-main" aria-label={LEVELS.main}>
        <LevelMap level="main" stats={stats} seatState={seatState} loading={loading} onOpen={onOpen} />
      </section>
      <section className="level level-balcony" aria-label={LEVELS.balcony}>
        <div className="level-head">
          <h2>{LEVELS.balcony}</h2>
        </div>
        <LevelMap level="balcony" stats={stats} seatState={seatState} loading={loading} onOpen={onOpen} />
      </section>
    </div>
  );
}
