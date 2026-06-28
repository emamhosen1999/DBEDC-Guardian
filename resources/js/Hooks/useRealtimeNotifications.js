import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { notificationKeys } from '@/api/queries/useNotificationsQuery';

/**
 * Subscribe to signals/notif/{userId}; on any change, refetch the in-app
 * notification queries. Degrades to a no-op if RTDB isn't available
 * (e.g. not provisioned yet) — the bell still refreshes on navigation/poll.
 */
export function useRealtimeNotifications(userId) {
  const qc = useQueryClient();
  useEffect(() => {
    if (!userId) return;
    let unsub = () => {};
    let cancelled = false;
    (async () => {
      try {
        const { getDatabase, ref, onValue } = await import('firebase/database');
        if (cancelled) return;
        const db = getDatabase();
        const r = ref(db, `signals/notif/${userId}`);
        unsub = onValue(r, () => {
          qc.invalidateQueries({ queryKey: notificationKeys.unread() });
          qc.invalidateQueries({ queryKey: notificationKeys.list() });
        });
      } catch {
        // RTDB not configured — bell still refreshes on navigation/poll.
      }
    })();
    return () => {
      cancelled = true;
      unsub();
    };
  }, [userId, qc]);
}

export default useRealtimeNotifications;
