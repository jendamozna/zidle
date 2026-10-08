import { occupancyLevel } from '../data/layout.js';

export default function SectionCard({ section, stat, loading, onOpen }) {
  const cols = section.rotated ? section.rows : section.seatsPerRow;
  const rows = section.rotated ? section.seatsPerRow : section.rows;
  const level = loading ? 'loading' : occupancyLevel(stat.pct);
  const mine = stat.mine.length;

  return (
    <button
      type="button"
      className={`section-card occ-${level} ${section.rotated ? 'is-rotated' : ''}`}
      style={{ '--cols': cols, '--rows': rows }}
      onClick={() => onOpen(section.id)}
      aria-label={`${section.name}, obsazeno ${stat.pct} %${mine ? `, moje místa: ${mine}` : ''}`}
    >
      <span className="section-card-body">
        <span className="section-name">
          <span className="name-long">{section.name}</span>
          <span className="name-short">{section.short}</span>
        </span>
        <span className="section-pct">{loading ? '–' : `${stat.pct} %`}</span>
      </span>
      {mine > 0 && <span className="mine-badge">{mine}</span>}
    </button>
  );
}
