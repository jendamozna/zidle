import { useEffect, useState } from 'react';
import { SECTION_BY_ID, formatCzk } from './data/layout.js';
import { useSeats } from './hooks/useSeats.js';
import { seatsLabel } from './plural.js';
import Overview from './components/Overview.jsx';
import SectionDetail from './components/SectionDetail.jsx';
import ReservationPanel from './components/ReservationPanel.jsx';

export default function App() {
  const seats = useSeats();
  const { stats } = seats;
  const [sectionId, setSectionId] = useState(null);
  const [toast, setToast] = useState(null);
  const section = sectionId ? SECTION_BY_ID[sectionId] : null;

  useEffect(() => {
    window.scrollTo({ top: 0 });
  }, [sectionId]);

  useEffect(() => {
    if (!toast) return undefined;
    const t = setTimeout(() => setToast(null), 3500);
    return () => clearTimeout(t);
  }, [toast]);

  const handleReserve = async () => {
    const count = stats.selectedCount;
    const total = stats.total;
    try {
      await seats.reserve();
      setToast({ kind: 'ok', text: `Rezervováno ${seatsLabel(count)} · ${formatCzk(total)}` });
    } catch (err) {
      setToast({ kind: 'error', text: err.message });
    }
  };

  const showPanel = section ? stats.bySection[section.id].mine.length > 0 : stats.selectedCount > 0;

  return (
    <div className="app">
      <header className="topbar">
        <div className="topbar-inner">
          <h1 className="brand">
            Moje židle <span>2026</span>
          </h1>
          {!section && (
            <div className="summary">
              <div className="summary-stat">
                <span className="summary-value">{seats.loading ? '–' : stats.free}</span>
                <span className="summary-label">volných míst</span>
              </div>
              <div className="summary-stat summary-sum">
                <span className="summary-value">{formatCzk(stats.total)}</span>
                <span className="summary-label">{seatsLabel(stats.selectedCount)}</span>
              </div>
              <button
                type="button"
                className="btn btn-primary"
                disabled={!stats.selectedCount || seats.submitting}
                onClick={handleReserve}
              >
                {seats.submitting ? 'Rezervuji…' : 'Rezervovat'}
              </button>
            </div>
          )}
        </div>
      </header>

      <main className={`content ${showPanel ? 'with-panel' : ''}`}>
        <div className="content-main">
          {section ? (
            <SectionDetail section={section} seats={seats} onBack={() => setSectionId(null)} />
          ) : (
            <Overview stats={stats} loading={seats.loading} onOpen={setSectionId} />
          )}
        </div>
        {showPanel && (
          <ReservationPanel
            stats={stats}
            currentSectionId={section?.id}
            submitting={seats.submitting}
            onRemove={seats.toggleSeat}
            onOpen={setSectionId}
            onReserve={handleReserve}
          />
        )}
      </main>

      <div className={`toast ${toast ? 'is-visible' : ''} ${toast?.kind === 'error' ? 'is-error' : ''}`} role="status">
        {toast?.text}
      </div>
    </div>
  );
}
