import { useEffect, useRef, useState } from 'react';
import { formatCzk } from '../data/layout.js';
import { seatsLabel } from '../plural.js';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function validate(values) {
  const errors = {};
  if (!values.firstName.trim()) errors.firstName = 'Vyplňte jméno.';
  if (!values.lastName.trim()) errors.lastName = 'Vyplňte příjmení.';
  if (!EMAIL_RE.test(values.email.trim())) errors.email = 'Zadejte platný e-mail.';
  return errors;
}

export default function ReservationForm({ stats, deadlineHours, submitting, onSubmit, onClose }) {
  const [values, setValues] = useState({ firstName: '', lastName: '', email: '', hp: '' });
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const firstInput = useRef(null);

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
    };
    try {
      await onSubmit(customer);
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

        <div className="modal-summary">
          <span>{seatsLabel(stats.selectedCount)} × {formatCzk(stats.price)}</span>
          <strong>{formatCzk(stats.total)}</strong>
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

        {deadlineHours ? <p className="modal-note">Splatnost {deadlineHours} hodin, poté se místa uvolní.</p> : null}
        {formError && <p className="form-error" role="alert">{formError}</p>}

        <button type="submit" className="btn btn-primary btn-block" disabled={submitting}>
          {submitting ? 'Rezervuji…' : 'Rezervovat a zaplatit'}
        </button>
      </form>
    </div>
  );
}
