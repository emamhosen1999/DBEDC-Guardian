import { useEffect } from 'react';
import { ref, onValue } from 'firebase/database';
import { getRealtimeDb } from '@/api/realtimeClient';
import { makeSignalHandler } from '@/api/realtimeSignalHandler';

// Must match the server's realtime.namespace (config/realtime.php → FIREBASE_PROJECT_ID).
const NS = import.meta.env.VITE_REALTIME_NAMESPACE || import.meta.env.VITE_FIREBASE_PROJECT_ID || 'app';

/**
 * Subscribe to a resource's RTDB change-marker and react when ANOTHER user changes it.
 * Degrades silently if the realtime client can't connect (no throw).
 */
export function useRealtimeSignals({ path, selfActorId, onSignal }) {
  useEffect(() => {
    if (!path) return undefined;
    let unsub = () => {};
    let cancelled = false;

    getRealtimeDb()
      .then((db) => {
        if (cancelled) return;
        const r = ref(db, `signals/${NS}/${path}`);
        unsub = onValue(r, makeSignalHandler({ selfActorId, onSignal }));
      })
      .catch(() => { /* realtime unavailable — degrade silently */ });

    return () => { cancelled = true; unsub(); };
  }, [path, selfActorId, onSignal]);
}
