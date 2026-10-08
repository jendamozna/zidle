import { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { SECTION_BY_ID, compareSeatIds, formatCzk, parseSeatId } from '../data/layout.js';
import { seatsLabel } from '../plural.js';

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

export default function PaymentView({ reservation, onBack }) {
  const [qr, setQr] = useState(null);
  const { payment, status } = reservation;
  const isPending = status === 'pending';

  useEffect(() => {
    if (!isPending) return;
    QRCode.toDataURL(payment.spd, { width: 520, margin: 1, errorCorrectionLevel: 'M', color: { dark: '#2b2620', light: '#ffffff' } })
      .then(setQr)
      .catch(() => setQr(null));
  }, [payment.spd, isPending]);

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
        {isPending && (
          <div className="payment-qr">
            {qr ? <img src={qr} alt="QR kód pro platbu" width="260" height="260" /> : <div className="qr-placeholder" />}
            <span className="muted">QR Platba</span>
          </div>
        )}

        <div className="payment-info">
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
                <CopyValue label="Částka" value={String(payment.amount)} />
              </dl>
              <p className="deadline">
                Zaplaťte do <strong>{formatDeadline(reservation.expiresAt)}</strong>. Jinak bude rezervace zrušena
                a místa uvolněna.
              </p>
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
        </div>
      </div>
    </div>
  );
}
