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
import RunPicker from './components/RunPicker.jsx';
import { runLabel } from './runs.js';

function setParam(name, value) {
  const url = new URL(window.location.href);
  if (value) url.searchParams.set(name, value);
  else url.searchParams.delete(name);
  window.history.replaceState(null, '', url);
}
const setReservationParam = (token) => setParam('r', token);

export default function App() {
  // Chosen run (date) – kept in the URL (?termin=<id>) so a reload keeps it.
  const [runId, setRunId] = useState(() => Number(new URLSearchParams(window.location.search).get('termin')) || null);
  const seats = useSeats(runId);
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
  }, [sectionId, reservation, runId]);

  // A run that no longer exists in the list: back to choosing.
  useEffect(() => {
    if (runId && seats.runs && !seats.runs.some((r) => r.id === runId)) chooseRun(null);
  }, [runId, seats.runs]); // eslint-disable-line react-hooks/exhaustive-deps

  const chooseRun = (id) => {
    if (id !== runId && stats.selectedCount && !window.confirm('Změnou termínu se zruší vybraná místa. Pokračovat?')) return;
    setSectionId(null);
    setRunId(id);
    setParam('termin', id);
  };

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

  const choosing = !reservation && !runId;
  const showPanel =
    !reservation && !choosing && (section ? stats.bySection[section.id].mine.length > 0 : stats.selectedCount > 0);

  let view;
  if (!seats.layoutReady) view = <p className="muted plan-loading">{seats.loadError ? '' : 'Načítám…'}</p>;
  else if (reservation) view = <PaymentView reservation={reservation} onChange={setReservation} onBack={closePayment} />;
  else if (choosing) view = <RunPicker runs={seats.runs} onSelect={chooseRun} />;
  else if (section) view = <SectionDetail section={section} seats={seats} onBack={() => setSectionId(null)} />;
  else view = <Overview stats={stats} loading={seats.loading} onOpen={setSectionId} />;

  return (
    <div className="app">
      <header className="topbar">
        <div className="topbar-inner">
          <h1 className="brand">
            Moje židle <span>2026</span>
          </h1>
          {!section && !reservation && !choosing && (
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
        <div className="content-main">
          {!reservation && seats.run && (
            <div className="run-bar">
              <span>
                <span className="muted">Termín</span> <strong>{runLabel(seats.run)}</strong>
              </span>
              <button type="button" className="link" onClick={() => chooseRun(null)}>
                Změnit termín
              </button>
            </div>
          )}
          {view}
        </div>
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
          run={seats.run}
          dataRetentionDays={seats.dataRetentionDays}
          submitting={seats.submitting}
          onSubmit={handleSubmit}
          onClose={closeForm}
        />
      )}

      {(seats.contact?.email || seats.contact?.phone) && (
        <footer className="site-footer">
          <span className="muted">Kontakt na pořadatele</span>
          {seats.contact.email && <a href={`mailto:${seats.contact.email}`}>{seats.contact.email}</a>}
          {seats.contact.phone && <a href={`tel:${seats.contact.phone.replace(/\s+/g, '')}`}>{seats.contact.phone}</a>}
        </footer>
      )}

      <div className={`toast ${toast ? 'is-visible' : ''} ${toast?.kind === 'error' ? 'is-error' : ''}`} role="status">
        {toast?.text}
      </div>
    </div>
  );
}
