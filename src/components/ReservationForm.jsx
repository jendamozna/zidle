import { useEffect, useRef, useState } from 'react';
import { formatCzk } from '../data/layout.js';
import { seatsLabel } from '../plural.js';
import { runLabel } from '../runs.js';
import { createAltcha } from '../altcha.js';
import { formatCode, isCodeFormat, normalizeCode, priceSeats } from '../pricing.js';
import Stepper from './Stepper.jsx';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function validate(values) {
  const errors = {};
  if (!values.firstName.trim()) errors.firstName = 'Vyplňte jméno.';
  if (!values.lastName.trim()) errors.lastName = 'Vyplňte příjmení.';
  if (!EMAIL_RE.test(values.email.trim())) errors.email = 'Zadejte platný e-mail.';
  return errors;
}

export default function ReservationForm({ stats, deadlineHours, run, submitting, onSubmit, onClose }) {
  const [values, setValues] = useState({ firstName: '', lastName: '', email: '', hp: '' });
  const [children, setChildren] = useState(0);
  const [codes, setCodes] = useState([]);
  const [codeInput, setCodeInput] = useState('');
  const seatCount = stats.selectedCount;
  const prices = priceSeats(stats.selected, children, codes.length, stats.price, stats.childPrice);
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const firstInput = useRef(null);
  const [verifying, setVerifying] = useState(false);
  // Invisible ALTCHA: starts solving as soon as the form opens.
  const altcha = useRef(null);
  if (altcha.current === null) altcha.current = createAltcha();

  useEffect(() => {
    firstInput.current?.focus();
    const onKey = (e) => e.key === 'Escape' && !submitting && onClose();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose, submitting]);

  const set = (field) => (e) => {
    setValues((v) => ({ ...v, [field]: e.target.value }));
    setErrors((errs) => ({ ...errs, [field]: undefined }));
  };

  const addCode = () => {
    const code = normalizeCode(codeInput);
    if (!code) return;
    let error;
    if (!isCodeFormat(code)) error = 'Kód má 8 znaků, např. ABCD-EFGH.';
    else if (codes.includes(code)) error = 'Tento kód už je zadaný.';
    else if (codes.length >= seatCount) error = 'Víc kódů než míst nelze použít.';
    setErrors((errs) => ({ ...errs, codes: error }));
    if (error) return;
    setCodes((prev) => [...prev, code]);
    setCodeInput('');
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    const found = validate(values);
    setErrors(found);
    if (Object.keys(found).length) return;
    setFormError(null);
    const customer = {
      firstName: values.firstName.trim(),
      lastName: values.lastName.trim(),
      email: values.email.trim(),
      hp: values.hp,
      children,
      codes,
    };
    const send = async (payload) => onSubmit({ ...customer, altcha: payload });
    try {
      setVerifying(true);
      let payload;
      try {
        payload = await altcha.current.get();
      } finally {
        setVerifying(false);
      }
      try {
        await send(payload);
      } catch (err) {
        if (err.data?.code !== 'altcha') throw err;
        // Expired or already used challenge: solve a new one and try once more.
        setVerifying(true);
        try {
          payload = await altcha.current.refresh();
        } finally {
          setVerifying(false);
        }
        await send(payload);
      }
    } catch (err) {
      setErrors(err.data?.fields ?? {});
      setFormError(err.message);
    }
  };

  const field = (name, label, props = {}) => (
    <label className={`field ${errors[name] ? 'has-error' : ''}`}>
      <span>{label}</span>
      <input
        name={name}
        value={values[name]}
        onChange={set(name)}
        aria-invalid={Boolean(errors[name])}
        disabled={submitting}
        {...props}
      />
      {errors[name] && <small>{errors[name]}</small>}
    </label>
  );

  return (
    <div className="modal-backdrop" onMouseDown={(e) => e.target === e.currentTarget && !submitting && onClose()}>
      <form className="modal" onSubmit={handleSubmit} noValidate aria-labelledby="reserve-title">
        <div className="modal-head">
          <h2 id="reserve-title">Rezervace</h2>
          <button type="button" className="icon-btn" onClick={onClose} disabled={submitting} aria-label="Zavřít">
            ×
          </button>
        </div>

        <p className="modal-run">
          <strong>{runLabel(run)}</strong> · {seatsLabel(seatCount)}
        </p>

        <div className="tickets">
          <div className="ticket-row">
            <span>
              Dospělí <span className="muted">· {formatCzk(stats.price)}</span>
            </span>
            <output className="stepper-fixed">{seatCount - children}</output>
          </div>
          <Stepper
            label="Děti 4–17 let"
            hint={formatCzk(stats.childPrice)}
            value={children}
            max={seatCount}
            onChange={setChildren}
            disabled={submitting}
          />
          <p className="muted small">Děti do 3 let mohou sedět zdarma na klíně dospělého.</p>
        </div>

        {run?.test ? null : (
          <div className={`field codes ${errors.codes ? 'has-error' : ''}`}>
            <label htmlFor="discount-code">Slevový kód</label>
            <div className="code-entry">
              <input
                id="discount-code"
                value={codeInput}
                onChange={(e) => {
                  setCodeInput(e.target.value);
                  setErrors((errs) => ({ ...errs, codes: undefined }));
                }}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    addCode();
                  }
                }}
                placeholder="ABCD-EFGH"
                autoComplete="off"
                autoCapitalize="characters"
                spellCheck={false}
                disabled={submitting}
              />
              <button
                type="button"
                className="btn btn-ghost"
                onClick={addCode}
                disabled={submitting || !codeInput.trim()}
              >
                Použít
              </button>
            </div>
            {codes.length > 0 && (
              <ul className="code-list">
                {codes.map((code) => (
                  <li key={code}>
                    <span>
                      <strong>{formatCode(code)}</strong> · místo zdarma
                    </span>
                    <button
                      type="button"
                      className="icon-btn"
                      onClick={() => setCodes((prev) => prev.filter((c) => c !== code))}
                      aria-label={`Odebrat kód ${formatCode(code)}`}
                      disabled={submitting}
                    >
                      ×
                    </button>
                  </li>
                ))}
              </ul>
            )}
            {errors.codes && <small>{errors.codes}</small>}
          </div>
        )}

        <div className="modal-summary">
          <span>Celkem</span>
          <strong>{formatCzk(prices.total)}</strong>
        </div>

        <div className="field-row">
          {field('firstName', 'Jméno', { autoComplete: 'given-name', ref: firstInput })}
          {field('lastName', 'Příjmení', { autoComplete: 'family-name' })}
        </div>
        {field('email', 'E-mail', { type: 'email', autoComplete: 'email', inputMode: 'email' })}

        {/* Honeypot for spam bots – hidden from people and assistive technology. */}
        <div className="hp-field" aria-hidden="true">
          <label>
            Nevyplňujte
            <input name="hp" value={values.hp} onChange={set('hp')} tabIndex={-1} autoComplete="off" />
          </label>
        </div>

        <div className="modal-notes">
          {run?.test ? (
            <p>Testovací termín – rezervace bude rovnou zaplacená.</p>
          ) : deadlineHours && prices.total > 0 ? (
            <p>Zaplaťte do {deadlineHours} hodin, jinak se místa uvolní.</p>
          ) : null}
          <p>Vstupenky přijdou e-mailem.</p>
        </div>
        {formError && (
          <p className="form-error" role="alert">
            {formError}
          </p>
        )}

        <button type="submit" className="btn btn-primary btn-block" disabled={submitting || verifying}>
          {verifying
            ? 'Ověřuji…'
            : submitting
              ? 'Rezervuji…'
              : run?.test
                ? 'Rezervovat (test)'
                : prices.total > 0
                  ? 'Rezervovat a zaplatit'
                  : 'Rezervovat zdarma'}
        </button>
      </form>
    </div>
  );
}
