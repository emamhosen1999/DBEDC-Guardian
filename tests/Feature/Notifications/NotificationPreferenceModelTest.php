<?php
// tests/Feature/Notifications/NotificationPreferenceModelTest.php
namespace Tests\Feature\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPreferenceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_disable_a_category_channel(): void
    {
        $user = User::factory()->create();
        NotificationPreference::create(['user_id' => $user->id, 'category' => 'attendance', 'channel' => 'push', 'enabled' => false]);

        $pref = $user->fresh()->notificationPreferences->first();
        $this->assertSame('attendance', $pref->category);
        $this->assertFalse($pref->enabled);
    }
}
