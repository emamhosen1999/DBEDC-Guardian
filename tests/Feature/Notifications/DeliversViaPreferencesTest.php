<?php

// tests/Feature/Notifications/DeliversViaPreferencesTest.php

namespace Tests\Feature\Notifications;

use App\Models\NotificationPreference;
use App\Models\NotificationType;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Concerns\DeliversViaPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Tests\TestCase;

class DeliversViaPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_via_reflects_admin_type_and_user_prefs(): void
    {
        // The registry is seeded by migration, so the row may already exist.
        NotificationType::updateOrCreate(['key' => 'leave.approved'], [
            'category' => 'leave', 'label' => 'x',
            'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'is_active' => true,
        ]);
        $user = User::factory()->create();
        NotificationPreference::create(['user_id' => $user->id, 'category' => 'leave', 'channel' => 'mail', 'enabled' => false]);

        $notification = new class extends Notification
        {
            use DeliversViaPreferences;

            public function typeKey(): string
            {
                return 'leave.approved';
            }
        };

        $channels = $notification->via($user);
        $this->assertContains('database', $channels);
        $this->assertContains(PushChannel::class, $channels);
        $this->assertNotContains('mail', $channels); // user opted out, not locked
    }
}
