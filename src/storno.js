const formatDate = (iso) =>
  new Intl.DateTimeFormat('cs-CZ', { day: 'numeric', month: 'numeric', year: 'numeric', timeZone: 'Europe/Prague' }).format(
    new Date(iso),
  );

/** "zdarma do 1. 12. 2026, od 1. 12. 2026 50 %, od 18. 12. 2026 100 % ceny" */
export function stornoText(rules) {
  if (!rules?.length) return null;
  const parts = [];
  if (rules[0].percent > 0) parts.push(`zdarma do ${formatDate(rules[0].from)}`);
  for (const rule of rules) parts.push(`od ${formatDate(rule.from)} ${rule.percent} %`);
  return `${parts.join(', ')} ceny`;
}
