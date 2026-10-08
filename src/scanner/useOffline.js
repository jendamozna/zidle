import { useCallback, useEffect, useRef, useState } from 'react';
import { getSnapshot as fetchSnapshot, syncScans } from './api.js';
import {
  enqueue as storeEnqueue,
  getQueue,
  getSnapshot,
  removeFromQueue,
  saveSnapshot,
} from './offline.js';

const REFRESH_MS = 60_000;

/**
 * Offline support of the scanner for the selected run: keeps the run's snapshot
 * fresh while online, knows whether the server is reachable and sends queued
 * offline check-ins as soon as it is. onUnauthorized: the device was signed out.
 */
export function useOffline(runId, onUnauthorized) {
  const [snapshot, setSnapshot] = useState(() => (runId ? getSnapshot(runId) : null));
  const [online, setOnline] = useState(true);
  const [queue, setQueue] = useState(getQueue);
  const [note, setNote] = useState(null);
  const syncing = useRef(false);

  const fail = useCallback(
    (err) => {
      if (err.offline) setOnline(false);
      else if (err.status === 401) onUnauthorized();
    },
    [onUnauthorized],
  );

  /** Sends queued check-ins run by run; keeps them when the server is not reachable. */
  const flush = useCallback(async () => {
    if (syncing.current) return;
    const pending = getQueue();
    if (!pending.length) return;
    syncing.current = true;
    let conflicts = 0;
    let sent = 0;
    try {
      for (const id of [...new Set(pending.map((e) => e.runId))]) {
        const events = pending.filter((e) => e.runId === id);
        try {
          const res = await syncScans(id, events);
          conflicts += res.conflicts;
          sent += events.length;
          removeFromQueue(events.map((e) => e.uid));
          setOnline(true);
        } catch (err) {
          // 403: the run is no longer allowed for this device – nothing can be sent for it.
          if (err.status === 403) removeFromQueue(events.map((e) => e.uid));
          else {
            fail(err);
            break;
          }
        }
      }
    } finally {
      syncing.current = false;
      setQueue(getQueue());
    }
    if (sent) {
      setNote(
        conflicts
          ? `Odesláno ${sent} odbavení bez spojení. Konflikty: ${conflicts} – uvidí je správce.`
          : `Odesláno ${sent} odbavení bez spojení.`,
      );
    }
  }, [fail]);

  const refresh = useCallback(async () => {
    if (!runId) return;
    try {
      const fresh = await fetchSnapshot(runId);
      saveSnapshot(fresh);
      setSnapshot(fresh);
      setOnline(true);
      await flush();
    } catch (err) {
      fail(err);
    }
  }, [runId, flush, fail]);

  useEffect(() => {
    setSnapshot(runId ? getSnapshot(runId) : null);
    refresh();
    const timer = setInterval(refresh, REFRESH_MS);
    const back = () => refresh();
    const lost = () => setOnline(false);
    window.addEventListener('online', back);
    window.addEventListener('offline', lost);
    return () => {
      clearInterval(timer);
      window.removeEventListener('online', back);
      window.removeEventListener('offline', lost);
    };
  }, [runId, refresh]);

  useEffect(() => {
    if (!note) return undefined;
    const timer = setTimeout(() => setNote(null), 8000);
    return () => clearTimeout(timer);
  }, [note]);

  const enqueue = useCallback((event) => {
    storeEnqueue({ runId, ...event });
    setQueue(getQueue());
  }, [runId]);

  const dequeue = useCallback((uids) => {
    removeFromQueue(uids);
    setQueue(getQueue());
  }, []);

  /** Called after any successful request: the server is reachable again. */
  const reachable = useCallback(() => {
    setOnline(true);
    if (getQueue().length) flush();
  }, [flush]);

  return { snapshot, online, queue, note, enqueue, dequeue, fail, reachable };
}
