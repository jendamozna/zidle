import { LEVELS, SECTIONS } from '../data/layout.js';
import SectionCard from './SectionCard.jsx';

/** Floor plan; sections are placed by their `group` from the server layout. */
export default function Overview({ stats, loading, onOpen }) {
  const cards = (group) =>
    SECTIONS.filter((s) => s.group === group).map((s) => (
      <SectionCard key={s.id} section={s} stat={stats.bySection[s.id]} loading={loading} onOpen={onOpen} />
    ));

  return (
    <div className="plan">
      <section className="level level-main" aria-label={LEVELS.main}>
        <div className="stage">Pódium</div>
        <div className="floor">
          <div className="floor-group">{cards('left')}</div>
          <div className="floor-group">{cards('right')}</div>
        </div>
        <div className="entrance">
          <span>Vchod</span>
        </div>
      </section>

      <section className="level level-balcony" aria-label={LEVELS.balcony}>
        <div className="level-head">
          <h2>{LEVELS.balcony}</h2>
          <span className="to-stage">↑ Pódium</span>
        </div>
        <div className="balcony">{cards('balcony')}</div>
      </section>
    </div>
  );
}
