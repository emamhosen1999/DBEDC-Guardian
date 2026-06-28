<?php
// app/Services/Notification/RealtimeNotificationSignal.php
namespace App\Services\Notification;

use Illuminate\Support\Facades\Log;

class RealtimeNotificationSignal
{
    public function ping(int|string $userId): void
    {
        try {
            app('firebase.database')
                ->getReference("signals/notif/{$userId}")
                ->set(['ts' => now()->timestamp]);
        } catch (\Throwable $e) {
            // RTDB not provisioned yet, or transient — degrade silently (bell still refreshes on nav/poll).
            Log::warning('Realtime notif signal skipped', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
