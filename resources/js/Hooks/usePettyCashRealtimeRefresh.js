import { router, usePage } from '@inertiajs/react';
import { useRealtimeSignals } from '@/api/useRealtimeSignals';

export function usePettyCashRealtimeRefresh() {
    const actorId = usePage().props?.auth?.user?.id;

    useRealtimeSignals({
        path: 'pettycash/all',
        selfActorId: actorId,
        onSignal: () => router.reload({ preserveScroll: true, preserveState: true }),
    });
}
