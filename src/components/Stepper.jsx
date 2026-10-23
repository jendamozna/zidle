/** − n + control for a count 0…max. */
export default function Stepper({ label, hint, value, max, onChange, disabled }) {
  return (
    <div className="stepper">
      <span className="stepper-label">
        {label}
        {hint && <span className="muted"> · {hint}</span>}
      </span>
      <span className="stepper-controls">
        <button
          type="button"
          onClick={() => onChange(value - 1)}
          disabled={disabled || value <= 0}
          aria-label={`${label}: méně`}
        >
          −
        </button>
        <output aria-live="polite">{value}</output>
        <button
          type="button"
          onClick={() => onChange(value + 1)}
          disabled={disabled || value >= max}
          aria-label={`${label}: více`}
        >
          +
        </button>
      </span>
    </div>
  );
}
