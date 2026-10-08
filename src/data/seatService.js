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
 * { runs: [{id, label, startsAt, bookingOpen, free, stornoRules}], runId, taken: string[] (of runId),
 *   price, deadlineHours, maxSeats, bookingOpen (of runId), formToken, dataRetentionDays }
 */
export const fetchSeats = (runId) => request(runId ? `seats.php?run=${encodeURIComponent(runId)}` : 'seats.php');

/** Creates a pending reservation; returns it with payment details. */
// formToken comes from fetchSeats; hp is the honeypot field (empty for humans).
export const createReservation = ({ runId, firstName, lastName, email, seats, formToken, hp = '' }) =>
  request('reservations.php', {
    method: 'POST',
    body: JSON.stringify({ runId, firstName, lastName, email, seats, formToken, hp }),
  });

export const fetchReservation = (token) => request(`reservations.php?token=${encodeURIComponent(token)}`);

/** Customer cancellation of the given seats (null = whole reservation). */
export const cancelReservation = (token, seats = null) =>
  request('cancel.php', { method: 'POST', body: JSON.stringify({ token, seats }) });
