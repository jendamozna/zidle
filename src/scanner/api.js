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

export const getSession = () => call();
export const login = (password) => call({ action: 'login', password });
export const logout = () => call({ action: 'logout' });
export const verifyTicket = (code) => call({ action: 'verify', code });
export const vipList = () => call({ action: 'vip-list' });
export const vipCheckIn = (id) => call({ action: 'vip-checkin', id });
export const vipUndo = (id) => call({ action: 'vip-undo', id });
