import { useCallback, useEffect, useState } from 'react';
import { SECTIONS, compareSeatIds, parseSeatId } from '../data/layout.js';
import { seatsLabel } from '../plural.js';
import { decodeTicket } from './ticket.js';
import { getSession, login, logout, verifyTicket } from './api.js';
import { useQrCamera } from './useQrCamera.js';

const RESULT = {
  valid: { tone: 'ok', title: 'Platná vstupenka' },
  used: { tone: 'warn', title: 'Už odbaveno' },
  unpaid: { tone: 'bad', title: 'Nezaplaceno' },
  cancelled: { tone: 'bad', title: 'Rezervace zrušena' },
  invalid: { tone: 'bad', title: 'Neplatný kód' },
  offline: { tone: 'warn', title: 'Neověřeno – bez spojení' },
  checking: { tone: 'neutral', title: 'Ověřuji…' },
};

const formatTime = (iso) =>
  new Intl.DateTimeFormat('cs-CZ', {
    day: 'numeric',
    month: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Europe/Prague',
  }).format(new Date(iso));

function groupSeats(seats) {
  const bySection = {};
  for (const id of [...seats].sort(compareSeatIds)) {
    const { sectionId, row, seat } = parseSeatId(id);
    ((bySection[sectionId] ??= {})[row] ??= []).push(seat);
  }
  return SECTIONS.filter((s) => bySection[s.id]).map((s) => ({
    section: s,
    rows: Object.entries(bySection[s.id]).map(([row, seats]) => ({ row, seats })),
  }));
}

function LoginScreen({ onLoggedIn }) {
  const [password, setPassword] = useState('');
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await login(password);
      onLoggedIn();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <form className="scan-login" onSubmit={submit}>
      <h1 className="brand">
        Moje židle <span>2026</span>
      </h1>
      <p className="muted">Odbavení vstupenek</p>
      <label className="field">
        <span>Heslo pořadatele</span>
        <input
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          autoComplete="current-password"
          autoFocus
          required
        />
      </label>
      {error && <p className="form-error">{error}</p>}
      <button type="submit" className="btn btn-primary btn-block" disabled={busy || !password}>
        {busy ? 'Přihlašuji…' : 'Přihlásit'}
      </button>
    </form>
  );
}

function ResultCard({ scan, onNext }) {
  const { ticket, result, checkedInAt } = scan;
  const meta = RESULT[result];

  return (
    <section className={`scan-result tone-${meta.tone}`} aria-live="assertive">
      <div className="scan-result-head">
        <span className="scan-result-icon" aria-hidden="true">
          {meta.tone === 'ok' ? '✓' : meta.tone === 'neutral' ? '…' : '!'}
        </span>
        <div>
          <h2>{meta.title}</h2>
          {result === 'used' && checkedInAt && <p>Poprvé načteno {formatTime(checkedInAt)}</p>}
        </div>
      </div>

      {ticket ? (
        <div className="scan-ticket">
          <div className="scan-ticket-name">
            <strong>{ticket.name}</strong>
            <span className="scan-count">{seatsLabel(ticket.count)}</span>
          </div>
          <ul className="scan-seats">
            {groupSeats(ticket.seats).map(({ section, rows }) => (
              <li key={section.id}>
                <span className="scan-section">{section.name}</span>
                <span className="scan-rows">
                  {rows.map(({ row, seats }) => (
                    <span key={row} className="scan-row">
                      <span className="muted">ř. {row}</span>
                      {seats.map((s) => (
                        <span key={s} className="scan-seat">
                          {s}
                        </span>
                      ))}
                    </span>
                  ))}
                </span>
              </li>
            ))}
          </ul>
          <p className="muted small">VS {ticket.variableSymbol}</p>
        </div>
      ) : (
        <p className="scan-raw">{scan.raw.length > 120 ? `${scan.raw.slice(0, 120)}…` : scan.raw}</p>
      )}

      <button type="button" className="btn btn-primary btn-block btn-large" onClick={onNext} disabled={result === 'checking'}>
        Skenovat další
      </button>
    </section>
  );
}

function Scanner({ onLogout }) {
  const [scan, setScan] = useState(null);

  const handleCode = useCallback(
    async (raw) => {
      const decoded = decodeTicket(raw);
      if (!decoded) {
        setScan({ raw, ticket: null, result: 'invalid' });
        return;
      }
      setScan({ raw, ticket: decoded, result: 'checking' });
      try {
        const res = await verifyTicket(raw);
        setScan({ raw, ticket: res.ticket ?? decoded, result: res.result, checkedInAt: res.checkedInAt });
      } catch (err) {
        if (err.status === 401) onLogout();
        else setScan({ raw, ticket: decoded, result: 'offline' });
      }
    },
    [onLogout],
  );

  const camera = useQrCamera(handleCode, true);

  const next = () => {
    setScan(null);
    camera.resume();
  };

  return (
    <div className="scanner">
      <header className="scan-bar">
        <h1 className="brand">
          Odbavení <span>2026</span>
        </h1>
        <div className="scan-bar-actions">
          {camera.torch.supported && (
            <button
              type="button"
              className={`icon-btn ${camera.torch.on ? 'is-on' : ''}`}
              onClick={camera.toggleTorch}
              aria-pressed={camera.torch.on}
              aria-label="Svítilna"
            >
              ☀
            </button>
          )}
          <button type="button" className="btn btn-ghost btn-small" onClick={onLogout}>
            Odhlásit
          </button>
        </div>
      </header>

      <div className={`scan-view ${scan ? 'has-result' : ''}`}>
        <video ref={camera.videoRef} className="scan-video" playsInline muted autoPlay />
        {camera.status !== 'error' && <div className="scan-frame" aria-hidden="true" />}
        {camera.status === 'starting' && <p className="scan-hint">Spouštím kameru…</p>}
        {camera.status === 'scanning' && !scan && <p className="scan-hint">Namiřte na QR kód vstupenky</p>}
        {camera.status === 'error' && (
          <div className="scan-error">
            <p>{camera.error}</p>
            <button type="button" className="btn btn-primary" onClick={camera.retry}>
              Zkusit znovu
            </button>
          </div>
        )}
      </div>

      {scan && <ResultCard scan={scan} onNext={next} />}
    </div>
  );
}

export default function ScannerApp() {
  const [session, setSession] = useState('loading'); // loading | in | out | error
  const [error, setError] = useState(null);

  useEffect(() => {
    getSession()
      .then((s) => setSession(s.loggedIn ? 'in' : 'out'))
      .catch((err) => {
        setError(err.message);
        setSession('error');
      });
  }, []);

  const handleLogout = useCallback(() => {
    logout().catch(() => {});
    setSession('out');
  }, []);

  if (session === 'loading') return <div className="scan-center muted">Načítám…</div>;
  if (session === 'error') return <div className="scan-center form-error">{error}</div>;
  if (session === 'out') return <LoginScreen onLoggedIn={() => setSession('in')} />;
  return <Scanner onLogout={handleLogout} />;
}
