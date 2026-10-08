import { useCallback, useEffect, useState } from 'react';
import { SECTION_BY_ID, formatCzk } from './data/layout.js';
import { fetchReservation } from './data/seatService.js';
import { useSeats } from './hooks/useSeats.js';
import { seatsLabel } from './plural.js';
import Overview from './components/Overview.jsx';
import SectionDetail from './components/SectionDetail.jsx';
import ReservationPanel from './components/ReservationPanel.jsx';
import ReservationForm from './components/ReservationForm.jsx';
import PaymentView from './components/PaymentView.jsx';

function setReservationParam(token) {
  const url = new URL(window.location.href);
  if (token) url.searchParams.set('r', token);
  else url.searchParams.delete('r');
  window.history.replaceState(null, '', url);
}

export default function App() {
  const seats = useSeats();
  const { stats } = seats;
  const [sectionId, setSectionId] = useState(null);
  const [reservation, setReservation] = useState(null);
  const [formOpen, setFormOpen] = useState(false);
  const [toast, setToast] = useState(null);
  const section = sectionId ? SECTION_BY_ID[sectionId] : null;

  // Reopen a reservation's payment page from a link (?r=token).
  useEffect(() => {
    const token = new URLSearchParams(window.location.search).get('r');
    if (!token) return;
    fetchReservation(token)
      .then(setReservation)
      .catch((err) => {
        setReservationParam(null);
        setToast({ kind: 'error', text: err.message });
      });
  }, []);

  useEffect(() => {
    window.scrollTo({ top: 0 });
  }, [sectionId, reservation]);

  useEffect(() => {
    if (!toast) return undefined;
    const t = setTimeout(() => setToast(null), 4000);
    return () => clearTimeout(t);
  }, [toast]);

  useEffect(() => {
    if (seats.loadError) setToast({ kind: 'error', text: seats.loadError });
  }, [seats.loadError]);

  useEffect(() => {
    if (seats.notice) setToast({ kind: 'error', text: seats.notice.text });
  }, [seats.notice]);

  const openForm = () => stats.selectedCount && setFormOpen(true);
  const closeForm = useCallback(() => setFormOpen(false), []);

  const handleSubmit = async (customer) => {
    try {
      const created = await seats.reserve(customer);
      setFormOpen(false);
      setSectionId(null);
      setReservation(created);
      setReservationParam(created.token);
    } catch (err) {
      if (err.status === 409 || err.status === 403) {
        setFormOpen(false);
        setToast({ kind: 'error', text: err.message });
        return;
      }
      throw err;
    }
  };

  const closePayment = () => {
    setReservation(null);
    setReservationParam(null);
    seats.refresh();
  };

  const showPanel =
    !reservation && (section ? stats.bySection[section.id].mine.length > 0 : stats.selectedCount > 0);

  let view;
  if (reservation) view = <PaymentView reservation={reservation} onBack={closePayment} />;
  else if (section) view = <SectionDetail section={section} seats={seats} onBack={() => setSectionId(null)} />;
  else view = <Overview stats={stats} loading={seats.loading} onOpen={setSectionId} />;

  return (
    <div className="app">
      <header className="topbar">
        <div className="topbar-inner">
          <h1 className="brand">
            Moje židle <span>2026</span>
          </h1>
          {!section && !reservation && (
            <div className="summary">
              <div className="summary-stat">
                <span className="summary-value">{seats.loading ? '–' : stats.free}</span>
                <span className="summary-label">volných míst</span>
              </div>
              <div className="summary-stat summary-sum">
                <span className="summary-value">{formatCzk(stats.total)}</span>
                <span className="summary-label">{seatsLabel(stats.selectedCount)}</span>
              </div>
              {seats.bookingOpen ? (
                <button
                  type="button"
                  className="btn btn-primary"
                  disabled={!stats.selectedCount || seats.submitting}
                  onClick={openForm}
                >
                  Rezervovat
                </button>
              ) : (
                <span className="closed-badge">Rezervace uzavřeny</span>
              )}
            </div>
          )}
        </div>
      </header>

      <main className={`content ${showPanel ? 'with-panel' : ''}`}>
        <div className="content-main">{view}</div>
        {showPanel && (
          <ReservationPanel
            stats={stats}
            currentSectionId={section?.id}
            submitting={seats.submitting}
            onRemove={seats.toggleSeat}
            onOpen={setSectionId}
            onReserve={openForm}
          />
        )}
      </main>

      {formOpen && (
        <ReservationForm
          stats={stats}
          deadlineHours={seats.deadlineHours}
          submitting={seats.submitting}
          onSubmit={handleSubmit}
          onClose={closeForm}
        />
      )}

      <div className={`toast ${toast ? 'is-visible' : ''} ${toast?.kind === 'error' ? 'is-error' : ''}`} role="status">
        {toast?.text}
      </div>
    </div>
  );
}
