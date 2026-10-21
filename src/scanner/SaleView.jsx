import { useCallback, useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { SECTIONS, compareSeatIds, formatCzk, parseSeatId } from '../data/layout.js';
import { seatsLabel } from '../plural.js';
import { KIND_LABELS, priceSeats } from '../pricing.js';
import Stepper from '../components/Stepper.jsx';
import { saleCancel, saleCreate, salePaid, saleSeats } from './api.js';

const shortSeat = (id) => {
  const { sectionId, row, seat } = parseSeatId(id);
  return `${SECTIONS.find((s) => s.id === sectionId)?.short ?? sectionId} ř. ${row} m. ${seat}`;
};

/** Grid of one section: free seats can be picked, held ones are disabled. */
function SeatGrid({ section, taken, selected, onToggle }) {
  return (
    <div className="sale-grid" role="group" aria-label={section.name}>
      {Array.from({ length: section.rows }, (_, r) => (
        <div key={r} className="sale-row">
          <span className="muted">{r + 1}</span>
          {Array.from({ length: section.seatsPerRow }, (_, n) => {
            const id = `${section.id}-${r + 1}-${n + 1}`;
            const isTaken = taken.has(id);
            return (
              <button
                key={id}
                type="button"
                className={`sale-seat ${selected.has(id) ? 'is-on' : ''}`}
                disabled={isTaken}
                aria-pressed={selected.has(id)}
                aria-label={`Řada ${r + 1}, místo ${n + 1}`}
                onClick={() => onToggle(id)}
              >
                {n + 1}
              </button>
            );
          })}
        </div>
      ))}
    </div>
  );
}

/** QR payment shown to the customer; the organizer confirms when the customer shows the payment sent. */
function SalePayment({ sale, busy, onPaid, onCancel }) {
  const [qr, setQr] = useState(null);
  useEffect(() => {
    QRCode.toDataURL(sale.spd, { width: 560, margin: 1, errorCorrectionLevel: 'M' }).then(setQr).catch(() => setQr(null));
  }, [sale.spd]);
  return (
    <section className="sale-pay">
      {qr ? <img src={qr} alt="QR kód pro platbu" width="280" height="280" /> : <div className="qr-placeholder" />}
      <p className="sale-amount">{formatCzk(sale.amount)}</p>
      <p className="small">
        VS <strong>{sale.variableSymbol}</strong>
        {sale.account && ` · účet ${sale.account}`}
      </p>
      <ul className="sale-tickets small">
        {sale.tickets.map((t) => (
          <li key={t.seat}>
            {shortSeat(t.seat)} – {KIND_LABELS[t.kind]} {formatCzk(t.price)}
          </li>
        ))}
      </ul>
      <button type="button" className="btn btn-primary btn-block btn-large" disabled={busy} onClick={onPaid}>
        Zaplaceno – vpustit
      </button>
      <button type="button" className="btn btn-ghost btn-block" disabled={busy} onClick={onCancel}>
        Zrušit prodej
      </button>
    </section>
  );
}

/**
 * Selling tickets at the entrance while seats are free (organizer.php sale-*):
 * pick seats and ticket kinds → QR payment → "Zaplaceno – vpustit" checks the seats in.
 * Needs a connection; the accountant verifies the money later in admin.
 */
export default function SaleView({ runId, onUnauthorized }) {
  const [info, setInfo] = useState(null);
  const [error, setError] = useState(null);
  const [sectionId, setSectionId] = useState(null);
  const [selected, setSelected] = useState(() => new Set());
  const [children, setChildren] = useState(0);
  const [sale, setSale] = useState(null);
  const [done, setDone] = useState(null);
  const [busy, setBusy] = useState(false);

  const fail = useCallback(
    (err) => {
      if (err.status === 401) onUnauthorized();
      else setError(err.offline ? 'Prodej na místě potřebuje internet. Zkuste to znovu, až bude spojení.' : err.message);
    },
    [onUnauthorized],
  );

  const load = useCallback(() => {
    saleSeats(runId)
      .then((data) => {
        setInfo({ ...data, taken: new Set(data.taken) });
        setError(null);
      })
      .catch(fail);
  }, [runId, fail]);

  useEffect(() => {
    load();
  }, [load]);

  const reset = () => {
    setSelected(new Set());
    setChildren(0);
    setSale(null);
    load();
  };

  const toggle = (id) => {
    const next = new Set(selected);
    if (next.has(id)) next.delete(id);
    else if (next.size < (info?.maxSeats ?? 20)) next.add(id);
    setSelected(next);
    setChildren((c) => Math.min(c, next.size));
  };

  const run = async (request) => {
    setBusy(true);
    setError(null);
    try {
      return await request();
    } catch (err) {
      fail(err);
      if (err.status === 409) load();
      return null;
    } finally {
      setBusy(false);
    }
  };

  const create = async () => {
    const res = await run(() => saleCreate(runId, [...selected].sort(compareSeatIds), children));
    if (res) {
      setDone(null);
      setSale(res.sale);
    }
  };

  const paid = async () => {
    if (!window.confirm(`Zákazník ukázal odeslanou platbu ${formatCzk(sale.amount)}?`)) return;
    const res = await run(() => salePaid(runId, sale.id));
    if (res) {
      setDone(res.message);
      reset();
    }
  };

  const cancel = async () => {
    const res = await run(() => saleCancel(runId, sale.id));
    if (res) {
      setDone(res.message);
      reset();
    }
  };

  if (sale) {
    return (
      <div className="sale">
        {error && <p className="form-error">{error}</p>}
        <SalePayment sale={sale} busy={busy} onPaid={paid} onCancel={cancel} />
      </div>
    );
  }
  if (!info) return <div className="sale">{error ? <p className="form-error">{error}</p> : <p className="muted">Načítám…</p>}</div>;

  const section = SECTIONS.find((s) => s.id === sectionId) ?? null;
  const free = (s) => s.rows * s.seatsPerRow - [...info.taken].filter((id) => id.startsWith(`${s.id}-`)).length;
  const prices = priceSeats([...selected], children, 0, info.price, info.childPrice);

  return (
    <div className="sale">
      {done && (
        <p className="sale-done" role="status">
          {done}
        </p>
      )}
      {error && <p className="form-error">{error}</p>}
      <div className="sale-sections">
        {SECTIONS.map((s) => (
          <button
            key={s.id}
            type="button"
            className={s.id === sectionId ? 'is-active' : ''}
            disabled={free(s) === 0}
            onClick={() => setSectionId(s.id === sectionId ? null : s.id)}
          >
            {s.short} <small>{free(s)}</small>
          </button>
        ))}
      </div>
      {section && <SeatGrid section={section} taken={info.taken} selected={selected} onToggle={toggle} />}

      {selected.size > 0 && (
        <div className="sale-summary">
          <div className="ticket-row">
            <span>
              Dospělí <span className="muted">· {formatCzk(info.price)}</span>
            </span>
            <output className="stepper-fixed">{selected.size - children}</output>
          </div>
          <Stepper label="Děti 4–17 let" hint={formatCzk(info.childPrice)} value={children} max={selected.size} onChange={setChildren} />
          <button type="button" className="btn btn-primary btn-block btn-large" disabled={busy} onClick={create}>
            QR platba {formatCzk(prices.total)} · {seatsLabel(selected.size)}
          </button>
        </div>
      )}
    </div>
  );
}
