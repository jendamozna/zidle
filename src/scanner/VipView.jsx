import { useCallback, useEffect, useMemo, useState } from 'react';
import { SECTIONS, SECTION_BY_ID } from '../data/layout.js';
import { vipCheckIn, vipList, vipUndo } from './api.js';

const REFRESH_MS = 20_000;

const normalize = (text) =>
  text
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase();

const formatTime = (iso) =>
  new Intl.DateTimeFormat('cs-CZ', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Prague' }).format(new Date(iso));

const personsLabel = (n) => (n === 1 ? '1 osoba' : n < 5 ? `${n} osoby` : `${n} osob`);

export default function VipView({ runId, confirmOutside, onUnauthorized }) {
  const [vips, setVips] = useState(null);
  const [query, setQuery] = useState('');
  const [section, setSection] = useState('');
  const [busyId, setBusyId] = useState(null);
  const [error, setError] = useState(null);

  const handle = useCallback(
    async (request) => {
      try {
        const data = await request();
        setVips(data.vips);
        setError(null);
      } catch (err) {
        if (err.status === 401) onUnauthorized();
        else setError(err.message);
      }
    },
    [onUnauthorized],
  );

  useEffect(() => {
    setVips(null);
    handle(() => vipList(runId));
    const timer = setInterval(() => handle(() => vipList(runId)), REFRESH_MS);
    return () => clearInterval(timer);
  }, [handle, runId]);

  const act = async (id, request) => {
    setBusyId(id);
    await handle(() => request(id, runId, confirmOutside));
    setBusyId(null);
  };

  const sections = useMemo(
    () => SECTIONS.filter((s) => vips?.some((v) => v.section === s.id)),
    [vips],
  );

  const filtered = useMemo(() => {
    const words = normalize(query).split(/\s+/).filter(Boolean);
    return (vips ?? []).filter((v) => {
      if (section && v.section !== section) return false;
      const name = normalize(`${v.name} ${v.note}`);
      return words.every((w) => name.includes(w));
    });
  }, [vips, query, section]);

  const total = (vips ?? []).reduce((sum, v) => sum + v.persons, 0);
  const arrived = (vips ?? []).reduce((sum, v) => sum + (v.checkedInAt ? v.persons : 0), 0);

  return (
    <div className="vip">
      <div className="vip-search">
        <input
          type="search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Hledat jméno"
          aria-label="Hledat VIP podle jména"
          autoComplete="off"
          autoFocus
        />
        <span className="vip-count" title="Přišlo osob / celkem">
          {arrived}/{total}
          <small>přišlo</small>
        </span>
      </div>

      {sections.length > 1 && (
        <div className="vip-filters" role="group" aria-label="Sekce">
          <button type="button" className={!section ? 'is-active' : ''} onClick={() => setSection('')}>
            Vše
          </button>
          {sections.map((s) => (
            <button
              key={s.id}
              type="button"
              className={section === s.id ? 'is-active' : ''}
              onClick={() => setSection(section === s.id ? '' : s.id)}
            >
              {s.short}
            </button>
          ))}
        </div>
      )}

      {error && <p className="form-error">{error}</p>}
      {vips === null && !error && <p className="muted">Načítám…</p>}
      {vips && filtered.length === 0 && (
        <div className="vip-empty">
          <strong>Není na seznamu VIP</strong>
          {query && <span className="muted">„{query}“</span>}
        </div>
      )}

      <ul className="vip-list">
        {filtered.map((v) => (
          <li key={v.id} className={`vip-card ${v.checkedInAt ? 'is-in' : ''}`}>
            <div className="vip-info">
              <strong>{v.name}</strong>
              <span className="vip-meta">
                <span className="vip-section">{SECTION_BY_ID[v.section]?.name ?? v.section}</span>
                <span>{personsLabel(v.persons)}</span>
              </span>
              {v.seats?.length > 0 && (
                <span className="vip-seats small">
                  {/* One section: its name is already shown above. */}
                  {v.seats.length === 1 ? v.seats[0].replace(/^[^:]+:\s*/, '') : v.seats.join(' · ')}
                </span>
              )}
              {v.note && <span className="muted small">{v.note}</span>}
            </div>
            {v.checkedInAt ? (
              <div className="vip-done">
                <span className="vip-arrived">✓ {formatTime(v.checkedInAt)}</span>
                <button type="button" className="link small" disabled={busyId === v.id} onClick={() => act(v.id, vipUndo)}>
                  Vrátit
                </button>
              </div>
            ) : (
              <button
                type="button"
                className="btn btn-primary"
                disabled={busyId === v.id}
                onClick={() => act(v.id, vipCheckIn)}
              >
                Vpustit
              </button>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
