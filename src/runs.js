// Formatting of event runs (dates), shared by the app and the scanner.

const WEEKDAYS = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];
const zoned = (iso, options) =>
  new Intl.DateTimeFormat('cs-CZ', { timeZone: 'Europe/Prague', ...options }).format(new Date(iso));

const weekdayIndex = (iso) => {
  const name = zoned(iso, { weekday: 'long' });
  return Math.max(0, WEEKDAYS.indexOf(name));
};

/** { weekday: "sobota", date: "18. 10. 2026", time: "18:00" } */
export function runParts(run) {
  return {
    weekday: WEEKDAYS[weekdayIndex(run.startsAt)],
    date: zoned(run.startsAt, { day: 'numeric', month: 'numeric', year: 'numeric' }),
    time: zoned(run.startsAt, { hour: '2-digit', minute: '2-digit' }),
  };
}

/** "so 18. 10. 2026 18:00 · Premiéra" */
export function runLabel(run) {
  if (!run) return '';
  const { weekday, date, time } = runParts(run);
  const text = `${weekday.slice(0, 2)} ${date} ${time}`;
  return run.label ? `${text} · ${run.label}` : text;
}
