const API_URL = (import.meta.env.VITE_API_URL ?? 'api').replace(/\/$/, '');

/** Error without an answer from the server (no connection, server down): error.offline = true. */
function offlineError() {
  const error = new Error('Bez spojení se serverem.');
  error.offline = true;
  return error;
}

async function call(body) {
  let response;
  try {
    response = await fetch(`${API_URL}/organizer.php`, {
      method: body ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers: body ? { 'Content-Type': 'application/json' } : {},
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw offlineError();
  }
  if (response.status >= 500) throw offlineError();
  const data = await response.json().catch(() => null);
  if (data === null) throw offlineError(); // e.g. a captive portal page instead of the API
  if (!response.ok) {
    const error = new Error(data.error || 'Chyba serveru.');
    error.status = response.status;
    throw error;
  }
  return data;
}

/** { loggedIn, name, admin, runs: [... , scanFrom, scanTo], layout } */
export const getSession = () => call();
export const login = (email, password) => call({ action: 'login', email, password });
/** Admin pages (forgotten password, Správa) next to the API. */
export const adminUrl = (query = '') => `${API_URL}/admin.php${query}`;
export const logout = () => call({ action: 'logout' });
export const verifyTicket = (code, runId, confirmOutside) => call({ action: 'verify', code, runId, confirmOutside });
/** Tickets of the run for checking offline. */
export const getSnapshot = (runId) => call({ action: 'snapshot', runId });
/** Sends check-ins made offline: {results: [{status, reason}], conflicts}. */
export const syncScans = (runId, events) => call({ action: 'sync', runId, events });
/** Selling on site: {taken, price, childPrice, maxSeats} of the run. */
export const saleSeats = (runId) => call({ action: 'sale-seats', runId });
/** {sale: {id, variableSymbol, specificSymbol, amount, seats, tickets, spd, account}} */
export const saleCreate = (runId, seats, children) => call({ action: 'sale-create', runId, seats, children });
export const salePaid = (runId, id) => call({ action: 'sale-paid', runId, id });
export const saleCancel = (runId, id) => call({ action: 'sale-cancel', runId, id });
