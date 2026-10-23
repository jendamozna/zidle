// API client for the PHP backend (api/*.php). The base URL can be changed with
// VITE_API_URL; by default the API is expected next to the built app.

const API_URL = (import.meta.env.VITE_API_URL ?? 'api').replace(/\/$/, '');

export class ApiError extends Error {
  constructor(message, status, data) {
    super(message);
    this.status = status;
    this.data = data;
  }
}

async function request(path, options = {}) {
  let response;
  try {
    response = await fetch(`${API_URL}/${path}`, {
      headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}) },
      ...options,
    });
  } catch {
    throw new ApiError('Nepodařilo se spojit se serverem.', 0, {});
  }
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new ApiError(data.error || 'Chyba serveru.', response.status, data);
  return data;
}

/**
 * { runs: [{id, label, startsAt, bookingOpen, free}], runId, taken: string[] (of runId),
 *   price (adult seat), childPrice, deadlineHours, maxSeats, bookingOpen (of runId), formToken, contact {email, phone}, dataRetentionDays }
 */
export const fetchSeats = (runId) => request(runId ? `seats.php?run=${encodeURIComponent(runId)}` : 'seats.php');

/** Creates a pending reservation; returns it with payment details. */
// formToken comes from fetchSeats; hp is the honeypot field (empty for humans).
// altcha is the invisible ALTCHA payload (src/altcha.js). children = number of child seats (4–17 years),
// infants = children up to 3 years on a lap, codes = discount codes.
export const createReservation = ({
  runId,
  firstName,
  lastName,
  email,
  seats,
  children = 0,
  infants = 0,
  codes = [],
  formToken,
  hp = '',
  altcha = '',
}) =>
  request('reservations.php', {
    method: 'POST',
    body: JSON.stringify({ runId, firstName, lastName, email, seats, children, infants, codes, formToken, hp, altcha }),
  });

/** Printable tickets of a paid reservation (PDF, one ticket per seat). */
export const ticketsPdfUrl = (token) => `${API_URL}/tickets.php?token=${encodeURIComponent(token)}`;

/** Resolves when the discount code can be used; ApiError with the reason otherwise. */
export const checkDiscountCode = (code) => request(`reservations.php?code=${encodeURIComponent(code)}`);

export const fetchReservation = (token) => request(`reservations.php?token=${encodeURIComponent(token)}`);
