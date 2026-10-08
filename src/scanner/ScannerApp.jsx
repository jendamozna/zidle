import { useCallback, useEffect, useState } from 'react';
import { SECTIONS, compareSeatIds, parseSeatId } from '../data/layout.js';
import { seatsLabel } from '../plural.js';
import { decodeTicket } from './ticket.js';
import { getSession, login, logout, verifyTicket } from './api.js';
import { useQrCamera } from './useQrCamera.js';
import VipView from './VipView.jsx';
import { runLabel } from '../runs.js';

const RUN_KEY = 'zidle-scanner-run';

/** Run to check in by default: the first one that started at most 6 h ago or later, else the last. */
function defaultRunId(runs) {
  try {
    const saved = Number(localStorage.getItem(RUN_KEY));
    if (runs.some((r) => r.id === saved)) return saved;
  } catch {
    /* storage unavailable */
  }
  const from = Date.now() - 6 * 3600 * 1000;
  return (runs.find((r) => new Date(r.startsAt).getTime() >= from) ?? runs[runs.length - 1])?.id ?? null;
}

const RESULT = {
  valid: { tone: 'ok', title: 'Platná vstupenka' },
  used: { tone: 'warn', title: 'Už odbaveno' },
  unpaid: { tone: 'bad', title: 'Nezaplaceno' },
  cancelled: { tone: 'bad', title: 'Rezervace zrušena' },
  invalid: { tone: 'bad', title: 'Neplatný kód' },
  payment: { tone: 'bad', title: 'To je platební QR kód' },
  wrong_run: { tone: 'bad', title: 'Jiný termín' },
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
          {result === 'wrong_run' && scan.run && <p>Vstupenka platí na {scan.run.label}</p>}
          {scan.changed && <p>Část míst byla zrušena – platí jen uvedená místa.</p>}
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
          <p className="muted small">
            Rezervace č. {ticket.id ?? '–'} · VS {ticket.variableSymbol}
            {result === 'offline' ? ' · údaje z QR kódu, neověřeno' : ' · aktuální stav ze systému'}
          </p>
        </div>
      ) : (
        <p className="scan-raw">
          {result === 'payment'
            ? 'Vstupenka přijde e-mailem po zaplacení. Lze ji najít i na stránce rezervace.'
            : scan.raw.length > 120
              ? `${scan.raw.slice(0, 120)}…`
              : scan.raw}
        </p>
      )}

      <button type="button" className="btn btn-primary btn-block btn-large" onClick={onNext} disabled={result === 'checking'}>
        Skenovat další
      </button>
    </section>
  );
}

function Scanner({ runs, onLogout }) {
  const [mode, setMode] = useState('scan'); // scan | vip
  const [scan, setScan] = useState(null);
  const [runId, setRunId] = useState(() => defaultRunId(runs));

  const changeRun = (id) => {
    setRunId(id);
    setScan(null);
    try {
      localStorage.setItem(RUN_KEY, String(id));
    } catch {
      /* storage unavailable */
    }
  };

  const handleCode = useCallback(
    async (raw) => {
      const decoded = decodeTicket(raw);
      if (!decoded) {
        // Customers sometimes show the bank payment QR instead of the ticket.
        setScan({ raw, ticket: null, result: raw.startsWith('SPD*') ? 'payment' : 'invalid' });
        return;
      }
      setScan({ raw, ticket: decoded, result: 'checking' });
      try {
        const res = await verifyTicket(raw, runId);
        setScan({
          raw,
          ticket: res.ticket ?? decoded,
          result: res.result,
          checkedInAt: res.checkedInAt,
          changed: res.changed,
          run: res.run,
        });
      } catch (err) {
        if (err.status === 401) onLogout();
        else setScan({ raw, ticket: decoded, result: 'offline' });
      }
    },
    [onLogout, runId],
  );

  const camera = useQrCamera(handleCode, mode === 'scan' && runId !== null);

  const next = () => {
    setScan(null);
    camera.resume();
  };

  const switchMode = (value) => {
    if (value === mode) return;
    setScan(null);
    setMode(value);
  };

  return (
    <div className="scanner">
      <header className="scan-bar">
        <h1 className="brand">
          Odbavení <span>2026</span>
        </h1>
        <div className="scan-bar-actions">
          {mode === 'scan' && camera.torch.supported && (
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

      <label className="run-select">
        <span>Odbavuji termín</span>
        <select value={runId ?? ''} onChange={(e) => changeRun(Number(e.target.value))}>
          {runs.map((r) => (
            <option key={r.id} value={r.id}>
              {runLabel(r)}
            </option>
          ))}
        </select>
      </label>

      <div className="mode-switch" role="tablist">
        <button type="button" role="tab" aria-selected={mode === 'scan'} onClick={() => switchMode('scan')}>
          Vstupenky
        </button>
        <button type="button" role="tab" aria-selected={mode === 'vip'} onClick={() => switchMode('vip')}>
          VIP
        </button>
      </div>

      {mode === 'vip' ? (
        <VipView key={runId} runId={runId} onUnauthorized={onLogout} />
      ) : (
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
      )}

      {mode === 'scan' && scan && <ResultCard scan={scan} onNext={next} />}
    </div>
  );
}

export default function ScannerApp() {
  const [session, setSession] = useState('loading'); // loading | in | out | error
  const [runs, setRuns] = useState([]);
  const [error, setError] = useState(null);

  const loadSession = useCallback(() => {
    getSession()
      .then((s) => {
        setRuns(s.runs ?? []);
        setSession(s.loggedIn ? 'in' : 'out');
      })
      .catch((err) => {
        setError(err.message);
        setSession('error');
      });
  }, []);

  useEffect(loadSession, [loadSession]);

  const handleLogout = useCallback(() => {
    logout().catch(() => {});
    setSession('out');
  }, []);

  if (session === 'loading') return <div className="scan-center muted">Načítám…</div>;
  if (session === 'error') return <div className="scan-center form-error">{error}</div>;
  if (session === 'out') return <LoginScreen onLoggedIn={loadSession} />;
  if (!runs.length) return <div className="scan-center muted">Nejsou vypsané žádné termíny.</div>;
  return <Scanner runs={runs} onLogout={handleLogout} />;
}
