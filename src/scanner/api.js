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

/** { loggedIn, name, passwordLogin, runs: [... , scanFrom, scanTo] } */
export const getSession = () => call();
export const acceptInvite = (token) => call({ action: 'invite', token });
export const login = (password) => call({ action: 'login', password });
export const logout = () => call({ action: 'logout' });
export const verifyTicket = (code, runId, confirmOutside) => call({ action: 'verify', code, runId, confirmOutside });
export const vipList = (runId) => call({ action: 'vip-list', runId });
export const vipCheckIn = (id, runId, confirmOutside) => call({ action: 'vip-checkin', id, runId, confirmOutside });
export const vipUndo = (id, runId) => call({ action: 'vip-undo', id, runId });
/** Tickets and VIP guests of the run for checking offline. */
export const getSnapshot = (runId) => call({ action: 'snapshot', runId });
/** Sends check-ins made offline: {results: [{status, reason}], conflicts}. */
export const syncScans = (runId, events) => call({ action: 'sync', runId, events });
