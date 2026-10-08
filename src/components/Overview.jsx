import { SECTION_BY_ID } from '../data/layout.js';
import SectionCard from './SectionCard.jsx';

export default function Overview({ stats, loading, onOpen }) {
  const card = (id) => (
    <SectionCard
      key={id}
      section={SECTION_BY_ID[id]}
      stat={stats.bySection[id]}
      loading={loading}
      onOpen={onOpen}
    />
  );

  return (
    <div className="plan">
      <section className="level level-main" aria-label="Hlavní loď">
        <div className="stage">Pódium</div>
        <div className="floor">
          <div className="floor-group">
            {card('WL')}
            {card('ML')}
          </div>
          <div className="floor-group">
            {card('MR')}
            {card('WR')}
          </div>
        </div>
        <div className="entrance">
          <span>Vchod</span>
        </div>
      </section>

      <section className="level level-balcony" aria-label="Balkon">
        <div className="level-head">
          <h2>Balkon</h2>
          <span className="to-stage">↑ Pódium</span>
        </div>
        <div className="balcony">
          {card('BL')}
          {card('BC')}
          {card('BR')}
        </div>
      </section>
    </div>
  );
}
