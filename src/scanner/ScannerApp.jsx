import { useCallback, useEffect, useState } from 'react';
import { SECTION_BY_ID, parseSeatId, setLayout } from '../data/layout.js';
import { seatsLabel } from '../plural.js';
import { KIND_LABELS } from '../pricing.js';
import { decodeTicket } from './ticket.js';
import { adminUrl, getSession, login, logout, verifyTicket } from './api.js';
import {
  STALE_MS,
  cachedSession,
  clearOffline,
  getQueue,
  markTicketCheckedIn,
  saveSession,
  verifyOffline,
} from './offline.js';
import { useOffline } from './useOffline.js';
import { useQrCamera } from './useQrCamera.js';
import SaleView from './SaleView.jsx';
import { runLabel } from '../runs.js';

const RUN_KEY = 'zidle-scanner-run';

const inWindow = (run, now) => now >= new Date(run.scanFrom).getTime() && now <= new Date(run.scanTo).getTime();
const clock = (iso) =>
  new Intl.DateTimeFormat('cs-CZ', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Prague' }).format(
    new Date(iso),
  );

/**
 * Run to check in by default: the run whose check-in window is open now;
 * otherwise the remembered choice; otherwise the next upcoming run; else the last.
 */
function defaultRunId(runs) {
  const now = Date.now();
  const open = runs.find((r) => inWindow(r, now));
  if (open) return open.id;
  try {
    const saved = Number(localStorage.getItem(RUN_KEY));
    if (runs.some((r) => r.id === saved)) return saved;
  } catch {
    /* storage unavailable */
  }
  return (runs.find((r) => new Date(r.scanTo).getTime() >= now) ?? runs[runs.length - 1])?.id ?? null;
}

/** Shown instead of the scanner while the chosen run is outside its check-in window. */
function WindowWarning({ run, openRun, onSwitch, onConfirm }) {
  const before = Date.now() < new Date(run.scanFrom).getTime();
  return (
    <section className="window-warning" role="alert">
      <span className="scan-result-icon" aria-hidden="true">
        !
      </span>
      <h2>Tento termín právě neprobíhá</h2>
      <p>
        Odbavení termínu <strong>{runLabel(run)}</strong> je určeno na {clock(run.scanFrom)}–{clock(run.scanTo)}
        {before ? ', ještě nezačalo.' : ', už skončilo.'}
      </p>
      {openRun && (
        <button type="button" className="btn btn-primary btn-block btn-large" onClick={() => onSwitch(openRun.id)}>
          Přepnout na probíhající {runLabel(openRun)}
        </button>
      )}
      <button
        type="button"
        className={`btn btn-block ${openRun ? 'btn-ghost' : 'btn-primary btn-large'}`}
        onClick={onConfirm}
      >
        Přesto odbavovat tento termín
      </button>
    </section>
  );
}

const RESULT = {
  valid: { tone: 'ok', title: 'Platná vstupenka' },
  used: { tone: 'warn', title: 'Už odbaveno' },
  unpaid: { tone: 'bad', title: 'Nezaplaceno' },
  cancelled: { tone: 'bad', title: 'Rezervace zrušena' },
  seat_cancelled: { tone: 'bad', title: 'Místo zrušeno' },
  invalid: { tone: 'bad', title: 'Neplatný kód' },
  payment: { tone: 'bad', title: 'To je platební QR kód' },
  wrong_run: { tone: 'bad', title: 'Jiný termín' },
  outside_window: { tone: 'warn', title: 'Mimo čas odbavení – neodbaveno' },
  unknown: { tone: 'bad', title: 'Není v seznamu termínu' },
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

/** Sign-in with the account (role Odbavovatel or Správce) the organizer was invited to. */
function LoginScreen({ onLoggedIn }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await login(email, password);
      onLoggedIn();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <form className="scan-login" onSubmit={submit}>
      <h1 className="brand">Těšín zpívá gospel</h1>
      <p className="muted">Odbavení vstupenek</p>
      <label className="field">
        <span>E-mail</span>
        <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="username" required />
      </label>
      <label className="field">
        <span>Heslo</span>
        <input
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          autoComplete="current-password"
          required
        />
      </label>
      {error && <p className="form-error">{error}</p>}
      <button type="submit" className="btn btn-primary btn-block" disabled={busy || !email || !password}>
        {busy ? 'Přihlašuji…' : 'Přihlásit'}
      </button>
      <p className="scan-login-help">
        <a href={adminUrl('?zapomenute')}>Zapomněl jsem heslo</a>
      </p>
    </form>
  );
}

/** The seat of a scanned ticket in large type, with its kind (adult / child). */
function TicketSeat({ ticket }) {
  const { sectionId, row, seat } = parseSeatId(ticket.seat);
  return (
    <div className="scan-seat-big">
      <span className="scan-section">{SECTION_BY_ID[sectionId]?.name ?? sectionId}</span>
      <span className="scan-place">
        řada <strong>{row}</strong> · místo <strong>{seat}</strong>
      </span>
      <span className={`scan-kind kind-${ticket.kind}`}>{KIND_LABELS[ticket.kind]}</span>
    </div>
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
        </div>
      </div>

      {ticket ? (
        <div className="scan-ticket">
          <TicketSeat ticket={ticket} />
          <div className="scan-ticket-name">
            <strong>{ticket.name}</strong>
            {ticket.count !== undefined && (
              <span className="scan-count">
                rezervace {seatsLabel(ticket.count)} · odbaveno {ticket.checkedIn} z {ticket.count}
              </span>
            )}
          </div>
          {ticket.reservedBy && ticket.reservedBy !== ticket.name && (
            <p className="muted small">Rezervace: {ticket.reservedBy}</p>
          )}
          {result === 'seat_cancelled' && (
            <p className="small">Toto místo bylo z rezervace zrušeno, ostatní místa platí.</p>
          )}
          {result === 'unknown' && (
            <p className="small">Vstupenka není v seznamu tohoto termínu – může být na jiný termín, nebo neplatná.</p>
          )}
          <p className="muted small">
            Rezervace č. {ticket.id ?? '–'} · SS {ticket.specificSymbol}
            {result === 'offline'
              ? ' · údaje z QR kódu, neověřeno'
              : scan.offlineAt
                ? ` · bez spojení, podle seznamu z ${clock(scan.offlineAt)}`
                : ' · aktuální stav ze systému'}
          </p>
          {scan.offlineAt && result === 'valid' && (
            <p className="muted small">Odbavení se odešle, jakmile bude spojení.</p>
          )}
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

      <button
        type="button"
        className="btn btn-primary btn-block btn-large"
        onClick={onNext}
        disabled={result === 'checking'}
      >
        Skenovat další
      </button>
    </section>
  );
}

/** Connection state of the scanner; hidden while online with nothing to send. */
function OfflineStrip({ offline }) {
  const { online, snapshot, queue, note } = offline;
  const pending = queue.length;
  if (online && !pending && !note) return null;
  const stale = snapshot && Date.now() - new Date(snapshot.at).getTime() > STALE_MS;
  let text;
  if (online) {
    text = pending ? `Odesílám odbavení bez spojení: ${pending}…` : note;
  } else if (!snapshot) {
    text = 'Bez spojení a bez staženého seznamu – vstupenky nelze ověřit.';
  } else {
    text = `Bez spojení – ověřuji podle seznamu z ${clock(snapshot.at)}${stale ? ' (starší než 30 min)' : ''}`;
    if (pending) text += ` · k odeslání: ${pending}`;
  }
  return (
    <p
      className={`offline-strip ${online ? 'is-online' : ''} ${!online && (!snapshot || stale) ? 'is-bad' : ''}`}
      role="status"
    >
      {text}
    </p>
  );
}

function Scanner({ runs, name, admin, onLogout, onUnauthorized }) {
  const [mode, setMode] = useState('scan'); // scan | sale
  const [scan, setScan] = useState(null);
  const [runId, setRunId] = useState(() => defaultRunId(runs));
  const [confirmed, setConfirmed] = useState(() => new Set()); // runs confirmed for check-in outside their window
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 30_000);
    return () => clearInterval(timer);
  }, []);

  const offline = useOffline(runId, onUnauthorized);
  const { snapshot, enqueue, reachable, fail } = offline;

  const run = runs.find((r) => r.id === runId) ?? null;
  const outside = run !== null && !inWindow(run, now);
  const confirmOutside = outside && confirmed.has(runId);
  const blocked = outside && !confirmOutside;
  const openRun = runs.find((r) => r.id !== runId && inWindow(r, now)) ?? null;

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
        const res = await verifyTicket(raw, runId, confirmOutside);
        reachable();
        if (res.result === 'valid') markTicketCheckedIn(runId, res.ticket.id, res.ticket.seat, res.checkedInAt);
        setScan({
          raw,
          ticket: res.ticket ?? decoded,
          result: res.result,
          checkedInAt: res.checkedInAt,
          run: res.run,
        });
      } catch (err) {
        if (err.status === 401) {
          onUnauthorized();
          return;
        }
        if (!err.offline || !snapshot) {
          if (err.offline) fail(err);
          setScan({ raw, ticket: decoded, result: 'offline' });
          return;
        }
        // No connection: check against the downloaded list, queue the check-in.
        fail(err);
        const res = verifyOffline(decoded, snapshot, getQueue());
        if (res.result === 'valid') {
          enqueue({
            type: 'ticket',
            id: res.ticket.id,
            specificSymbol: res.ticket.specificSymbol,
            seat: res.ticket.seat,
          });
        }
        setScan({ raw, ...res, offlineAt: snapshot.at });
      }
    },
    [onUnauthorized, runId, confirmOutside, snapshot, enqueue, reachable, fail],
  );

  const camera = useQrCamera(handleCode, mode === 'scan' && runId !== null && !blocked);

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
        <div>
          <h1 className="brand">
            Odbavení <span>2026</span>
          </h1>
          {name && <p className="scan-who">{name}</p>}
        </div>
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
          {admin && (
            <a className="btn btn-ghost btn-small" href={adminUrl()}>
              Správa
            </a>
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
        <button type="button" role="tab" aria-selected={mode === 'sale'} onClick={() => switchMode('sale')}>
          Prodej
        </button>
      </div>

      <OfflineStrip offline={offline} />

      {confirmOutside && (
        <p className="window-strip" role="status">
          Mimo čas odbavení ({clock(run.scanFrom)}–{clock(run.scanTo)}) – odbavujete na vlastní potvrzení.
        </p>
      )}

      {blocked ? (
        <WindowWarning
          run={run}
          openRun={openRun}
          onSwitch={changeRun}
          onConfirm={() => setConfirmed((prev) => new Set(prev).add(runId))}
        />
      ) : mode === 'sale' ? (
        <SaleView key={runId} runId={runId} onUnauthorized={onUnauthorized} />
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

      {mode === 'scan' && !blocked && scan && <ResultCard scan={scan} onNext={next} />}
    </div>
  );
}

export default function ScannerApp() {
  const [session, setSession] = useState('loading'); // loading | in | out | error
  const [info, setInfo] = useState({ runs: [], name: null, admin: false });
  const [error, setError] = useState(null);

  const loadSession = useCallback(() => {
    getSession()
      .then((s) => {
        setLayout(s.layout);
        setInfo({ runs: s.runs ?? [], name: s.name, admin: !!s.admin });
        if (s.loggedIn) saveSession({ name: s.name, admin: !!s.admin, runs: s.runs ?? [], layout: s.layout });
        else clearOffline();
        setSession(s.loggedIn ? 'in' : 'out');
      })
      .catch((err) => {
        // Without a connection the scanner opens with the last known session.
        const cached = err.offline ? cachedSession() : null;
        if (cached) {
          setLayout(cached.layout);
          setInfo({ runs: cached.runs, name: cached.name, admin: !!cached.admin });
          setSession('in');
          return;
        }
        setError(err.offline ? 'Bez spojení. Poprvé se scanner musí přihlásit s internetem.' : err.message);
        setSession('error');
      });
  }, []);

  useEffect(() => {
    loadSession();
  }, [loadSession]);

  const handleLogout = useCallback(() => {
    const pending = getQueue().length;
    if (
      pending &&
      !window.confirm(`${pending} odbavení bez spojení ještě nebylo odesláno. Odhlášením se ztratí. Přesto odhlásit?`)
    ) {
      return;
    }
    clearOffline();
    logout().catch(() => {});
    setSession('out');
  }, []);

  // Account disabled, password changed or role removed: nothing stored can be used or sent any more.
  const handleUnauthorized = useCallback(() => {
    clearOffline();
    setSession('out');
  }, []);

  if (session === 'loading') return <div className="scan-center muted">Načítám…</div>;
  if (session === 'error') return <div className="scan-center form-error">{error}</div>;
  if (session === 'out') return <LoginScreen onLoggedIn={loadSession} />;
  if (!info.runs.length) {
    // An Odbavovatel without assigned runs (or no runs at all yet).
    return (
      <div className="scan-center scan-no-runs">
        <p className="muted">
          Nemáte přiřazený žádný termín k odbavení. Požádejte správce, aby Vám termín přiřadil, a pak stránku obnovte.
        </p>
        {info.name && <p className="small muted">{info.name}</p>}
        <button type="button" className="btn btn-ghost btn-small" onClick={handleLogout}>
          Odhlásit
        </button>
      </div>
    );
  }
  return (
    <Scanner
      runs={info.runs}
      name={info.name}
      admin={info.admin}
      onLogout={handleLogout}
      onUnauthorized={handleUnauthorized}
    />
  );
}
