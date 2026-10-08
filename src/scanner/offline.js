// Offline mode of the scanner. Kept in localStorage of the device (one key):
//   session    – last {name, runs} from the server, so the scanner opens without a connection
//   snapshots  – per run: the list of tickets and VIP guests (organizer.php "snapshot")
//   queue      – check-ins made without a connection, sent later (organizer.php "sync")
// Everything is removed on logout; snapshots also a day after their run.

const KEY = 'zidle-scanner-offline';
const KEEP_AFTER_RUN_MS = 24 * 60 * 60 * 1000;
export const STALE_MS = 30 * 60 * 1000;

const empty = () => ({ session: null, snapshots: {}, queue: [] });

function load() {
  try {
    const data = JSON.parse(localStorage.getItem(KEY) ?? 'null');
    return data && typeof data === 'object' ? { ...empty(), ...data } : empty();
  } catch {
    return empty();
  }
}

function save(data) {
  try {
    localStorage.setItem(KEY, JSON.stringify(data));
  } catch {
    /* storage full or unavailable – offline mode just has less to work with */
  }
}

function update(fn) {
  const data = load();
  fn(data);
  save(data);
  return data;
}

export const cachedSession = () => load().session;

/** Remembers the session and drops snapshots of runs that are gone or ended more than a day ago. */
export function saveSession(session) {
  update((data) => {
    data.session = { name: session.name, runs: session.runs };
    const keep = new Set(
      session.runs.filter((r) => new Date(r.scanTo).getTime() + KEEP_AFTER_RUN_MS > Date.now()).map((r) => String(r.id)),
    );
    for (const runId of Object.keys(data.snapshots)) {
      if (!keep.has(runId)) delete data.snapshots[runId];
    }
  });
}

export const getSnapshot = (runId) => load().snapshots[runId] ?? null;

export function saveSnapshot(snapshot) {
  update((data) => {
    data.snapshots[snapshot.runId] = snapshot;
  });
}

/** Records an online check-in in the snapshot, so a later offline scan of the same ticket says "used". */
export function markTicketCheckedIn(runId, id, checkedInAt) {
  update((data) => {
    const ticket = data.snapshots[runId]?.tickets.find((t) => t.id === id);
    if (ticket && !ticket.checkedInAt) ticket.checkedInAt = checkedInAt;
  });
}

export const getQueue = () => load().queue;

export function enqueue(event) {
  const full = { uid: `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`, at: new Date().toISOString(), ...event };
  update((data) => {
    data.queue.push(full);
  });
  return full;
}

export function removeFromQueue(uids) {
  const drop = new Set(uids);
  update((data) => {
    data.queue = data.queue.filter((e) => !drop.has(e.uid));
  });
}

export function clearOffline() {
  try {
    localStorage.removeItem(KEY);
  } catch {
    /* storage unavailable */
  }
}

/**
 * Checks a decoded ticket against the run's snapshot (and the check-ins queued on
 * this device). Returns {result, ticket, changed, checkedInAt}; result is one of
 * valid / used / unpaid / cancelled / unknown (not in this run's list).
 */
export function verifyOffline(decoded, snapshot, queue) {
  const found = snapshot.tickets.find((t) =>
    decoded.id !== null ? t.id === decoded.id : t.variableSymbol === decoded.variableSymbol,
  );
  if (!found || found.variableSymbol !== decoded.variableSymbol) {
    return { result: 'unknown', ticket: decoded };
  }
  const ticket = {
    id: found.id,
    variableSymbol: found.variableSymbol,
    count: found.seats.length,
    name: found.name || decoded.name,
    seats: found.seats,
  };
  const changed = found.seats.join(',') !== decoded.seats.join(',');
  if (found.status === 'pending') return { result: 'unpaid', ticket, changed };
  if (found.status !== 'paid') return { result: 'cancelled', ticket, changed };
  const queued = queue.find((e) => e.runId === snapshot.runId && e.type === 'ticket' && e.id === found.id);
  if (found.checkedInAt || queued) {
    return { result: 'used', ticket, changed, checkedInAt: found.checkedInAt ?? queued.at };
  }
  return { result: 'valid', ticket, changed };
}

/** VIP list with this device's not yet sent arrivals/undos applied. */
export function applyVipQueue(vips, runId, queue) {
  const byId = new Map(vips.map((v) => [v.id, { ...v }]));
  for (const e of queue) {
    if (e.runId !== runId) continue;
    const vip = byId.get(e.id);
    if (!vip) continue;
    if (e.type === 'vip-checkin' && !vip.checkedInAt) vip.checkedInAt = e.at;
    if (e.type === 'vip-undo') vip.checkedInAt = null;
  }
  return [...byId.values()];
}
