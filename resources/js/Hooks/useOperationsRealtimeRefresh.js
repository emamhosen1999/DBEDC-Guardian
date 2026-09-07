import { router, usePage } from '@inertiajs/react';
import { useRealtimeSignals } from '@/api/useRealtimeSignals';

/**
 * Keep any O&M Inertia page fresh after another operator commits a change.
 * Local writes already update through Inertia, so the signal handler ignores
 * the current actor and only reloads page props for remote/system changes.
 */
export function useOperationsRealtimeRefresh() {
    const actorId = usePage().props?.auth?.user?.id;

    useRealtimeSignals({
        path: 'operations/all',
        selfActorId: actorId,
        onSignal: () => router.reload({ preserveScroll: true, preserveState: true }),
    });
}
