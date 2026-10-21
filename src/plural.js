export function seatsLabel(n) {
  if (n === 1) return '1 místo';
  if (n >= 2 && n <= 4) return `${n} místa`;
  return `${n} míst`;
}

export function freeLabel(n) {
  if (n === 1) return '1 volné';
  if (n >= 2 && n <= 4) return `${n} volná`;
  return `${n} volných`;
}
