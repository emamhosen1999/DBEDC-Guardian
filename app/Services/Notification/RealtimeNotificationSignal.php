<?php
// app/Services/Notification/RealtimeNotificationSignal.php
namespace App\Services\Notification;

use Illuminate\Support\Facades\Log;

/**
 * Per-user notification ping — the second half of the canonical realtime scheme
 * documented on App\Services\Realtime\RealtimeSignal.
 *
 * Path:    signals/notif/{userId}     (per-recipient; the bucket IS the user id)
 * Payload: { ts, actor_id, action }   — the SAME ID-only marker contract as the
 *          domain signals, so a rebuilt mobile/web listener parses one shape.
 *
 * The path is deliberately NOT namespaced under {ns}: a live web reader
 * (resources/js/Hooks/useRealtimeNotifications.js) hardcodes `signals/notif/{id}`.
 * Relocating it to signals/{ns}/notification/{id} must be done together with that
 * reader — out of scope for a writer-only change. Read access is locked to
 * auth.uid === {userId} in database.rules.json; clients can never write.
 */
class RealtimeNotificationSignal
{
    public function ping(int|string $userId): void
    {
        if (! config('realtime.enabled')) {
            return; // honour the same global kill-switch as RealtimeSignal
        }

        try {
            app('firebase.database')
                ->getReference("signals/notif/{$userId}")
                ->set([
                    'ts' => now()->toIso8601String(),
                    'actor_id' => null, // ping is addressed by path; no actor is passed in
                    'action' => 'notify',
                ]);
        } catch (\Throwable $e) {
            // RTDB not provisioned yet, or transient — degrade silently (bell still refreshes on nav/poll).
            Log::warning('Realtime notif signal skipped', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
