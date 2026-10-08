const API_URL = (import.meta.env.VITE_API_URL ?? 'api').replace(/\/$/, '');

async function call(body) {
  const response = await fetch(`${API_URL}/organizer.php`, {
    method: body ? 'POST' : 'GET',
    credentials: 'same-origin',
    headers: body ? { 'Content-Type': 'application/json' } : {},
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await response.json().catch(() => ({}));
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
