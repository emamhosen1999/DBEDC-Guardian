import { useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { ref, onValue } from 'firebase/database';
import { getRealtimeDb } from '@/api/realtimeClient';
import { makeSignalHandler } from '@/api/realtimeSignalHandler';

/**
 * Subscribe to a resource's RTDB change-marker and react when ANOTHER user changes it.
 *
 * The namespace is read from the server-shared Inertia prop `realtime.namespace`
 * (HandleInertiaRequests) — the SAME value the server publishes under — so the client
 * can never drift to a different namespace than the server. Falls back to 'app' only
 * if the prop is missing.
 *
 * `onSignal` may be inline — it's held in a ref so the subscription isn't torn down and
 * recreated every render (only path/selfActorId/namespace changes re-subscribe). The
 * initial onValue snapshot is skipped (the query already loads fresh data on mount);
 * we react only to subsequent changes. Degrades silently if realtime can't connect.
 */
export function useRealtimeSignals({ path, selfActorId, onSignal }) {
  const ns = usePage().props?.realtime?.namespace || 'app';
  const onSignalRef = useRef(onSignal);
  onSignalRef.current = onSignal;

  useEffect(() => {
    if (!path) return undefined;
    let unsub = () => {};
    let cancelled = false;

    getRealtimeDb()
      .then((db) => {
        if (cancelled) return;
        const r = ref(db, `signals/${ns}/${path}`);
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
  }, [path, selfActorId, ns]);
}
