// Invisible ALTCHA: solves the server's proof-of-work challenge in the background
// (PBKDF2 via WebCrypto). Keep in sync with api/lib/altcha.php.
import { solveChallenge } from 'altcha-lib';
import { deriveKey } from 'altcha-lib/algorithms/web/pbkdf2';

const API_URL = (import.meta.env.VITE_API_URL ?? 'api').replace(/\/$/, '');
const MAX_AGE_MS = 12 * 60 * 1000; // challenges expire after 15 min on the server

/** Fetches and solves a challenge; resolves to the base64 payload ('' when ALTCHA is disabled). */
async function solve() {
  const response = await fetch(`${API_URL}/altcha.php`, { headers: { Accept: 'application/json' } });
  const data = await response.json();
  if (!response.ok) throw new Error(data.error || 'Ověření se nepodařilo spustit.');
  if (!data.enabled) return '';
  const solution = await solveChallenge({ challenge: data.challenge, deriveKey });
  if (!solution) throw new Error('Ověření se nepodařilo dokončit.');
  return btoa(JSON.stringify({ challenge: data.challenge, solution }));
}

/**
 * Starts solving immediately; get() resolves to a fresh payload (re-solves when the
 * previous one is older than MAX_AGE_MS or after refresh()).
 */
export function createAltcha() {
  let started = 0;
  let pending = null;
  const start = () => {
    started = Date.now();
    pending = solve();
    pending.catch(() => {});
    return pending;
  };
  start();
  return {
    get: () => (Date.now() - started > MAX_AGE_MS ? start() : pending),
    refresh: start,
  };
}
