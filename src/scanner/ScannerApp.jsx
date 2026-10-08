import { useCallback, useEffect, useState } from 'react';
import { SECTIONS, compareSeatIds, parseSeatId } from '../data/layout.js';
import { seatsLabel } from '../plural.js';
import { decodeTicket } from './ticket.js';
import { acceptInvite, getSession, login, logout, verifyTicket } from './api.js';
import { useQrCamera } from './useQrCamera.js';
import VipView from './VipView.jsx';
import { runLabel } from '../runs.js';

const RUN_KEY = 'zidle-scanner-run';

const inWindow = (run, now) => now >= new Date(run.scanFrom).getTime() && now <= new Date(run.scanTo).getTime();
const clock = (iso) =>
  new Intl.DateTimeFormat('cs-CZ', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Prague' }).format(new Date(iso));

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
      <button type="button" className={`btn btn-block ${openRun ? 'btn-ghost' : 'btn-primary btn-large'}`} onClick={onConfirm}>
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
  invalid: { tone: 'bad', title: 'Neplatný kód' },
  payment: { tone: 'bad', title: 'To je platební QR kód' },
  wrong_run: { tone: 'bad', title: 'Jiný termín' },
  outside_window: { tone: 'warn', title: 'Mimo čas odbavení – neodbaveno' },
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

function LoginScreen({ passwordLogin, inviteError, onLoggedIn }) {
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
      {inviteError && <p className="form-error">{inviteError}</p>}
      <p className="scan-login-help">
        Otevřete na tomto mobilu <strong>odkaz nebo QR kód z pozvánky</strong>, kterou Vám poslal správce. Pozvánka
        povolí odbavení Vašich termínů.
      </p>
      {passwordLogin && (
        <>
          <label className="field">
            <span>Nebo hlavní heslo pořadatele</span>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoComplete="current-password"
              required
            />
          </label>
          {error && <p className="form-error">{error}</p>}
          <button type="submit" className="btn btn-primary btn-block" disabled={busy || !password}>
            {busy ? 'Přihlašuji…' : 'Přihlásit'}
          </button>
        </>
      )}
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

function Scanner({ runs, name, onLogout }) {
  const [mode, setMode] = useState('scan'); // scan | vip
  const [scan, setScan] = useState(null);
  const [runId, setRunId] = useState(() => defaultRunId(runs));
  const [confirmed, setConfirmed] = useState(() => new Set()); // runs confirmed for check-in outside their window
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 30_000);
    return () => clearInterval(timer);
  }, []);

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
    [onLogout, runId, confirmOutside],
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
      ) : mode === 'vip' ? (
        <VipView key={runId} runId={runId} confirmOutside={confirmOutside} onUnauthorized={onLogout} />
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
  const [info, setInfo] = useState({ runs: [], name: null, passwordLogin: false });
  const [error, setError] = useState(null);
  const [inviteError, setInviteError] = useState(null);

  const loadSession = useCallback(() => {
    getSession()
      .then((s) => {
        setInfo({ runs: s.runs ?? [], name: s.name, passwordLogin: s.passwordLogin });
        setSession(s.loggedIn ? 'in' : 'out');
      })
      .catch((err) => {
        setError(err.message);
        setSession('error');
      });
  }, []);

  // Invite link: scanner.html?pozvanka=<token> signs this device in, then the token leaves the URL.
  useEffect(() => {
    const url = new URL(window.location.href);
    const token = url.searchParams.get('pozvanka');
    if (!token) {
      loadSession();
      return;
    }
    url.searchParams.delete('pozvanka');
    window.history.replaceState(null, '', url);
    acceptInvite(token)
      .catch((err) => setInviteError(err.message))
      .finally(loadSession);
  }, [loadSession]);

  const handleLogout = useCallback(() => {
    logout().catch(() => {});
    setSession('out');
  }, []);

  if (session === 'loading') return <div className="scan-center muted">Načítám…</div>;
  if (session === 'error') return <div className="scan-center form-error">{error}</div>;
  if (session === 'out') {
    return <LoginScreen passwordLogin={info.passwordLogin} inviteError={inviteError} onLoggedIn={loadSession} />;
  }
  if (!info.runs.length) {
    return <div className="scan-center muted">Nemáte přiřazený žádný termín. Požádejte správce o pozvánku.</div>;
  }
  return <Scanner runs={info.runs} name={info.name} onLogout={handleLogout} />;
}
