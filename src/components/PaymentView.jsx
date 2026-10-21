import { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { SECTION_BY_ID, formatCzk, parseSeatId } from '../data/layout.js';
import { saveTicketNames, ticketsPdfUrl } from '../data/seatService.js';
import { seatsLabel } from '../plural.js';
import { KIND_LABELS } from '../pricing.js';
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

/** Refund summary after the organizers cancelled the reservation or some seats (customers can't cancel). */
function CancellationInfo({ reservation }) {
  const { status, refundAmount, refundedAmount, cancelledSeats } = reservation;
  const due = (refundAmount ?? 0) - (refundedAmount ?? 0);
  const whole = status === 'cancelled';
  if (status === 'expired' && refundAmount > 0) {
    return (
      <div className="cancel-info">
        <strong>Rezervace propadla, přijatá platba se vrací.</strong>
        {due > 0 ? (
          <span>Částku {formatCzk(due)} pošleme zpět na účet, ze kterého platba přišla.</span>
        ) : (
          <span>Vráceno {formatCzk(refundedAmount)}.</span>
        )}
      </div>
    );
  }
  if (!whole && !cancelledSeats.length) {
    // Paid reservation with a payment on top (overpayment or sent twice).
    if (status !== 'paid' || due <= 0) return null;
    return (
      <div className="cancel-info">
        <strong>Přišlo víc, než bylo potřeba.</strong>
        <span>Částku {formatCzk(due)} pošleme zpět na účet, ze kterého platba přišla.</span>
      </div>
    );
  }
  return (
    <div className="cancel-info">
      {whole ? (
        <strong>Rezervace byla zrušena.</strong>
      ) : (
        <strong>Zrušená místa: {cancelledSeats.map(seatLabel).join(', ')}</strong>
      )}
      {refundedAmount > 0 && <span>Vráceno {formatCzk(refundedAmount)}.</span>}
      {due > 0 && (
        <span>Částku {formatCzk(due)} pošleme zpět na účet, ze kterého platba přišla.</span>
      )}
    </div>
  );
}

/** QR code image (data URL) of a text, or null while it is being drawn. */
function useQrImage(text, width) {
  const [qr, setQr] = useState(null);
  useEffect(() => {
    if (!text) {
      setQr(null);
      return;
    }
    QRCode.toDataURL(text, { width, margin: 1, errorCorrectionLevel: 'M', color: { dark: '#2b2620', light: '#ffffff' } })
      .then(setQr)
      .catch(() => setQr(null));
  }, [text, width]);
  return qr;
}

function TicketCard({ ticket }) {
  const qr = useQrImage(ticket.code, 400);
  const { sectionId, row, seat } = parseSeatId(ticket.seat);
  return (
    <li className={`ticket-card ${ticket.checkedInAt ? 'is-used' : ''}`}>
      {qr ? <img src={qr} alt={`QR kód vstupenky ${ticket.seat}`} width="200" height="200" /> : <div className="qr-placeholder" />}
      <div>
        {ticket.holder && <span className="ticket-holder">{ticket.holder}</span>}
        <strong>{SECTION_BY_ID[sectionId]?.short ?? sectionId}</strong>
        <span>
          ř. {row} · m. {seat}
        </span>
        <span className="muted small">
          {KIND_LABELS[ticket.kind]}
          {ticket.checkedInAt && ' · odbaveno'}
        </span>
      </div>
    </li>
  );
}

/**
 * Seats of the reservation with kind and price; "Jména na vstupenkách" turns the same list into
 * name fields for the seat tickets (empty = the reservation name).
 */
function SeatList({ reservation, onChange }) {
  const [names, setNames] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [saved, setSaved] = useState(false);
  const defaultName = `${reservation.firstName} ${reservation.lastName}`;
  const editing = names !== null;

  const start = () => {
    setNames(Object.fromEntries(reservation.tickets.map((t) => [t.seat, t.holder ?? ''])));
    setError(null);
    setSaved(false);
  };

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      onChange(await saveTicketNames(reservation.token, names));
      setNames(null);
      setSaved(true);
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  };

  const list = (
    <ul className="pay-seats">
      {reservation.tickets.map((t) => (
        <li key={t.seat}>
          <div className="pay-seat-row">
            <span>
              {seatLabel(t.seat)}
              {!editing && t.holder && <small className="muted"> · {t.holder}</small>}
            </span>
            <span className="muted">
              {KIND_LABELS[t.kind]} · {t.free ? 'kód, zdarma' : formatCzk(t.price)}
            </span>
          </div>
          {editing && (
            <input
              className="seat-name"
              value={names[t.seat] ?? ''}
              onChange={(e) => setNames((prev) => ({ ...prev, [t.seat]: e.target.value }))}
              placeholder={defaultName}
              maxLength={100}
              disabled={busy}
              aria-label={`Jméno na vstupence ${seatLabel(t.seat)}`}
            />
          )}
        </li>
      ))}
    </ul>
  );

  if (!editing) {
    return (
      <>
        {list}
        {reservation.ticketNamesEditable && (
          <p className="names-link small">
            <button type="button" className="link" onClick={start}>
              Jména na vstupenkách
            </button>
            {saved && (
              <span className="muted">
                {' '}
                Uloženo.{reservation.status === 'paid' && ' QR kódy i PDF už jména obsahují.'}
              </span>
            )}
          </p>
        )}
      </>
    );
  }
  return (
    <form className="names-form" onSubmit={submit}>
      {list}
      {error && <p className="form-error">{error}</p>}
      <p className="names-link small">
        <button type="submit" className="link" disabled={busy}>
          {busy ? 'Ukládám…' : 'Uložit jména'}
        </button>
        <button type="button" className="link muted" onClick={() => setNames(null)} disabled={busy}>
          Zrušit
        </button>
      </p>
    </form>
  );
}

/** Paid: one ticket (QR code) per seat and the printable PDF. */
function Tickets({ reservation }) {
  const { tickets } = reservation;
  if (!tickets.length || !tickets[0].code) return null;
  return (
    <section className="tickets-view" aria-labelledby="tickets-title">
      <div className="tickets-head">
        <div>
          <h3 id="tickets-title">Vstupenky</h3>
          <p className="muted small">1 QR kód = 1 židle. Ukažte je u vchodu v mobilu, nebo vytiskněte.</p>
        </div>
        <a className="btn btn-primary" href={ticketsPdfUrl(reservation.token)} download>
          PDF k tisku
        </a>
      </div>
      <ul className="ticket-grid">
        {tickets.map((ticket) => (
          <TicketCard key={ticket.seat} ticket={ticket} />
        ))}
      </ul>
    </section>
  );
}

export default function PaymentView({ reservation, onChange, onBack }) {
  const { payment, status } = reservation;
  const isPending = status === 'pending';
  // Pending: bank payment QR. Paid: the tickets below, one QR per seat.
  const qr = useQrImage(isPending ? payment.spd : null, 520);

  return (
    <div className="detail">
      <div className="detail-head">
        <button type="button" className="btn btn-ghost btn-back" aria-label="Zpět na mapu" onClick={onBack}>
          ←<span className="wide-only"> Zpět na mapu</span>
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
        {isPending && (
          <div className="payment-qr">
            {qr ? <img src={qr} alt="QR kód pro platbu" width="260" height="260" /> : <div className="qr-placeholder" />}
            <span className="muted">QR Platba</span>
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
            <strong>{formatCzk(reservation.amount)}</strong>
          </div>

          {isPending && (
            <>
              <dl className="pay-list">
                {payment.account && <CopyValue label="Číslo účtu" value={payment.account} />}
                <CopyValue label="Variabilní symbol" value={payment.variableSymbol} />
                {payment.specificSymbol && <CopyValue label="Specifický symbol" value={payment.specificSymbol} />}
                <CopyValue label="Částka" value={String(payment.amount)} />
              </dl>
              {payment.received > 0 && (
                <p className="deadline">
                  Už jsme přijali <strong>{formatCzk(payment.received)}</strong> z {formatCzk(reservation.amount)}. Doplaťte
                  prosím zbývajících <strong>{formatCzk(payment.amount)}</strong> se stejným variabilním symbolem.
                </p>
              )}
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

          <SeatList reservation={reservation} onChange={onChange} />
          <p className="muted small">{reservation.email}</p>

          {status === 'paid' && <Tickets reservation={reservation} />}
          <CancellationInfo reservation={reservation} />
        </div>
      </div>
    </div>
  );
}
