import { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { SECTION_BY_ID, compareSeatIds, formatCzk, parseSeatId } from '../data/layout.js';
import { cancelReservation } from '../data/seatService.js';
import { seatsLabel } from '../plural.js';
import { stornoText } from '../storno.js';
import { runLabel } from '../runs.js';

const STATUS = {
  pending: { label: 'Čeká na platbu', cls: 'occ-medium' },
  paid: { label: 'Zaplaceno', cls: 'occ-low' },
  expired: { label: 'Propadlo – nezaplaceno včas', cls: 'occ-high' },
  cancelled: { label: 'Zrušeno', cls: 'occ-high' },
};

const formatDeadline = (iso) =>
  new Intl.DateTimeFormat('cs-CZ', {
    day: 'numeric',
    month: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Europe/Prague',
  }).format(new Date(iso));

function CopyValue({ label, value }) {
  const [copied, setCopied] = useState(false);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    } catch {
      /* clipboard unavailable */
    }
  };
  return (
    <div className="pay-row">
      <dt>{label}</dt>
      <dd>
        <span>{value}</span>
        <button type="button" className="copy-btn" onClick={copy} aria-label={`Kopírovat ${label}`}>
          {copied ? 'Zkopírováno' : 'Kopírovat'}
        </button>
      </dd>
    </div>
  );
}

const seatLabel = (id) => {
  const { sectionId, row, seat } = parseSeatId(id);
  return `${SECTION_BY_ID[sectionId]?.short ?? sectionId} ř. ${row} · ${seat}`;
};

/** Fee/refund summary after a cancellation (whole reservation or single seats). */
function CancellationInfo({ reservation }) {
  const { status, cancelFee, refundAmount, refundedAmount, cancelledBy, cancelledSeats } = reservation;
  const due = (refundAmount ?? 0) - (refundedAmount ?? 0);
  const whole = status === 'cancelled';
  if (!whole && !cancelledSeats.length) return null;
  return (
    <div className="cancel-info">
      {whole ? (
        <strong>{cancelledBy === 'customer' ? 'Rezervaci jste zrušili.' : 'Rezervace byla zrušena.'}</strong>
      ) : (
        <strong>Zrušená místa: {cancelledSeats.map(seatLabel).join(', ')}</strong>
      )}
      {cancelFee > 0 && <span>Storno poplatek {formatCzk(cancelFee)}.</span>}
      {refundedAmount > 0 && <span>Vráceno {formatCzk(refundedAmount)}.</span>}
      {due > 0 && (
        <span>Částku {formatCzk(due)} pošleme zpět na účet, ze kterého platba přišla.</span>
      )}
    </div>
  );
}

function CancelPanel({ reservation, onCancelled }) {
  const [open, setOpen] = useState(false);
  const [selected, setSelected] = useState(() => new Set(reservation.seats));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const { percent, seatPrice } = reservation.cancellation;
  const isPaid = reservation.status === 'paid';
  const seats = [...reservation.seats].sort(compareSeatIds);
  const count = selected.size;
  const whole = count === seats.length;
  // Same calculation as the server (cancellation_money); the server's result is authoritative.
  const value = isPaid ? seatPrice * count : 0;
  const fee = Math.round((value * percent) / 100);
  const refund = value - fee;

  const toggle = (id) =>
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });

  const submit = async (e) => {
    e.preventDefault();
    if (!count) return;
    setBusy(true);
    setError(null);
    try {
      const updated = await cancelReservation(reservation.token, whole ? null : [...selected]);
      onCancelled(updated);
      setOpen(false);
      setBusy(false);
      setSelected(new Set(updated.seats));
    } catch (err) {
      setError(err.message);
      setBusy(false);
    }
  };

  if (!open) {
    return (
      <button type="button" className="btn btn-danger-ghost" onClick={() => setOpen(true)}>
        {seats.length > 1 ? 'Zrušit rezervaci nebo jednotlivá místa' : 'Zrušit rezervaci'}
      </button>
    );
  }

  return (
    <form className="cancel-panel" onSubmit={submit}>
      <strong>{seats.length > 1 ? 'Která místa chcete zrušit?' : 'Opravdu zrušit rezervaci?'}</strong>
      {seats.length > 1 && (
        <div className="cancel-seats">
          {seats.map((id) => (
            <label key={id} className={`cancel-seat ${selected.has(id) ? 'is-on' : ''}`}>
              <input type="checkbox" checked={selected.has(id)} onChange={() => toggle(id)} disabled={busy} />
              {seatLabel(id)}
            </label>
          ))}
        </div>
      )}
      {count > 0 && (
        <p>
          {whole ? 'Zrušíte celou rezervaci, místa se uvolní.' : `Zrušíte ${seatsLabel(count)}, zbytek rezervace zůstane.`}{' '}
          {!isPaid && (whole ? 'Nic neplatíte.' : `Nová částka k úhradě: ${formatCzk(seatPrice * (seats.length - count))}.`)}
          {isPaid && percent === 0 && `Částku ${formatCzk(refund)} pošleme zpět na účet, ze kterého platba přišla.`}
          {isPaid &&
            percent > 0 &&
            percent < 100 &&
            `Storno poplatek ${percent} % (${formatCzk(fee)}). Zbylých ${formatCzk(refund)} pošleme zpět na účet, ze kterého platba přišla.`}
          {isPaid && percent >= 100 && 'Storno poplatek je 100 %, peníze se nevracejí.'}
          {isPaid && !whole && ' Na e-mail přijde nová vstupenka.'}
        </p>
      )}
      {error && <p className="form-error">{error}</p>}
      <div className="cancel-actions">
        <button type="button" className="btn btn-ghost" onClick={() => setOpen(false)} disabled={busy}>
          Ponechat
        </button>
        <button type="submit" className="btn btn-danger" disabled={busy || !count}>
          {busy ? 'Ruším…' : whole ? 'Zrušit rezervaci' : `Zrušit ${seatsLabel(count)}`}
        </button>
      </div>
    </form>
  );
}

export default function PaymentView({ reservation, onChange, onBack }) {
  const [qr, setQr] = useState(null);
  const { payment, status } = reservation;
  const isPending = status === 'pending';
  // Pending: bank payment QR. Paid: ticket QR scanned at the entrance.
  const qrText = isPending ? payment.spd : reservation.ticket;

  useEffect(() => {
    if (!qrText) {
      setQr(null);
      return;
    }
    QRCode.toDataURL(qrText, { width: 520, margin: 1, errorCorrectionLevel: 'M', color: { dark: '#2b2620', light: '#ffffff' } })
      .then(setQr)
      .catch(() => setQr(null));
  }, [qrText]);

  const groups = {};
  for (const id of [...reservation.seats].sort(compareSeatIds)) {
    const { sectionId, row, seat } = parseSeatId(id);
    (groups[sectionId] ??= []).push(`ř. ${row} · ${seat}`);
  }

  return (
    <div className="detail">
      <div className="detail-head">
        <button type="button" className="btn btn-ghost" onClick={onBack}>
          ← Zpět na mapu
        </button>
        <div className="detail-title">
          <span className="detail-level">Rezervace {payment.variableSymbol}</span>
          <h2>
            {reservation.firstName} {reservation.lastName}
          </h2>
        </div>
        <div className={`chip chip-occ ${STATUS[status].cls}`}>
          <strong>{STATUS[status].label}</strong>
        </div>
      </div>

      <div className="payment">
        {qrText && (
          <div className="payment-qr">
            {qr ? (
              <img src={qr} alt={isPending ? 'QR kód pro platbu' : 'QR kód vstupenky'} width="260" height="260" />
            ) : (
              <div className="qr-placeholder" />
            )}
            <span className="muted">{isPending ? 'QR Platba' : 'Vstupenka'}</span>
          </div>
        )}

        <div className="payment-info">
          {reservation.run && (
            <p className="payment-run">
              <span className="muted">Termín</span> <strong>{runLabel(reservation.run)}</strong>
            </p>
          )}
          <div className="payment-amount">
            <span className="muted">{seatsLabel(reservation.seats.length)}</span>
            <strong>{formatCzk(payment.amount)}</strong>
          </div>

          {isPending && (
            <>
              <dl className="pay-list">
                {payment.account && <CopyValue label="Číslo účtu" value={payment.account} />}
                <CopyValue label="IBAN" value={payment.iban} />
                <CopyValue label="Variabilní symbol" value={payment.variableSymbol} />
                {payment.specificSymbol && <CopyValue label="Specifický symbol" value={payment.specificSymbol} />}
                <CopyValue label="Částka" value={String(payment.amount)} />
              </dl>
              {new Date(reservation.expiresAt) < new Date() ? (
                <p className="deadline is-overdue">
                  Splatnost <strong>{formatDeadline(reservation.expiresAt)}</strong> uplynula. Zaplaťte prosím
                  co nejdříve, jinak bude rezervace brzy zrušena.
                </p>
              ) : (
                <p className="deadline">
                  Zaplaťte do <strong>{formatDeadline(reservation.expiresAt)}</strong>. Jinak bude rezervace zrušena
                  a místa uvolněna.
                </p>
              )}
            </>
          )}

          <ul className="pay-seats">
            {Object.entries(groups).map(([sectionId, seats]) => (
              <li key={sectionId}>
                <span>{SECTION_BY_ID[sectionId]?.name ?? sectionId}</span>
                <span className="muted">{seats.join(', ')}</span>
              </li>
            ))}
          </ul>
          <p className="muted small">{reservation.email}</p>

          <CancellationInfo reservation={reservation} />
          {reservation.cancellation?.allowed && (
            <div className="cancel-box">
              {status === 'paid' && stornoText(reservation.run?.stornoRules) && (
                <p className="muted small">Storno: {stornoText(reservation.run.stornoRules)}.</p>
              )}
              <CancelPanel reservation={reservation} onCancelled={onChange} />
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
