import { useEffect, useRef } from 'react';
import { ref, onValue } from 'firebase/database';
import { getRealtimeDb } from '@/api/realtimeClient';
import { makeSignalHandler } from '@/api/realtimeSignalHandler';

// Must match the server's realtime.namespace (config/realtime.php → FIREBASE_PROJECT_ID).
const NS = import.meta.env.VITE_REALTIME_NAMESPACE || import.meta.env.VITE_FIREBASE_PROJECT_ID || 'app';

/**
 * Subscribe to a resource's RTDB change-marker and react when ANOTHER user changes it.
 * `onSignal` may be an inline function — it's held in a ref so the subscription is not
 * torn down and recreated on every render (only path/selfActorId changes re-subscribe).
 * Degrades silently if the realtime client can't connect (no throw).
 */
export function useRealtimeSignals({ path, selfActorId, onSignal }) {
  const onSignalRef = useRef(onSignal);
  onSignalRef.current = onSignal;

  useEffect(() => {
    if (!path) return undefined;
    let unsub = () => {};
    let cancelled = false;

    getRealtimeDb()
      .then((db) => {
        if (cancelled) return;
        const r = ref(db, `signals/${NS}/${path}`);
        // onValue fires immediately with the current value on subscribe; skip that
        // initial snapshot (the query already loads fresh data on mount) and react
        // only to subsequent changes — genuine cross-user updates.
        let isFirst = true;
        const handle = makeSignalHandler({
          selfActorId,
          onSignal: (marker) => onSignalRef.current?.(marker),
        });
        unsub = onValue(r, (snapshot) => {
          if (isFirst) { isFirst = false; return; }
          handle(snapshot);
        });
      })
      .catch(() => { /* realtime unavailable — degrade silently */ });

    return () => { cancelled = true; unsub(); };
  }, [path, selfActorId]);
}
