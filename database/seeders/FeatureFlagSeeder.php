<?php

namespace Database\Seeders;

use App\Models\FeatureFlag;
use Illuminate\Database\Seeder;

/**
 * The starting flag set. Idempotent — safe to re-run on production.
 *
 * Both rows are real: the first is enforced server-side by
 * SyncController::push, the second is consumed by the mobile app's foreground
 * poll. Neither is decorative config.
 */
class FeatureFlagSeeder extends Seeder
{
    public function run(): void
    {
        $flags = [
            [
                'key' => 'mobile.offline_sync_push_enabled',
                'value' => null,
                'is_enabled' => true,
                'role' => null,
                'description' => 'Master kill switch for the offline outbox flush (POST /api/v1/sync/push). Turning this OFF makes the server reject pushes with a transient 503; queued punches stay pending on the device and drain when it is turned back on. Enforced server-side, not just advertised.',
            ],
            [
                'key' => 'mobile.sync_poll_interval_seconds',
                'value' => 300,
                'is_enabled' => true,
                'role' => null,
                'description' => 'How often the app re-syncs in the background, in seconds. Raise it to relieve the server or to save battery/data on rural connections; lower it when fresher data matters.',
            ],
            [
                // Scope demo AND genuinely useful: supervisors approving work in
                // the field need fresher data than the general workforce.
                'key' => 'mobile.sync_poll_interval_seconds',
                'value' => 120,
                'is_enabled' => true,
                'role' => 'Project Manager',
                'description' => 'Project Managers poll more often: approval queues must not be stale in the field.',
            ],
        ];

        foreach ($flags as $flag) {
            FeatureFlag::query()->updateOrCreate(
                ['key' => $flag['key'], 'role' => $flag['role']],
                $flag,
            );
        }
    }
}
