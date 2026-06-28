<?php
// tests/Feature/Notifications/NotificationPreferenceApiTest.php
namespace Tests\Feature\Notifications;

use App\Models\User;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPreferenceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_disable_a_category_channel_but_not_a_locked_one(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/settings/notifications', [
            'preferences' => [
                ['category' => 'leave', 'channel' => 'mail', 'enabled' => false],
                ['category' => 'leave', 'channel' => 'database', 'enabled' => false], // locked → must be ignored
            ],
        ])->assertOk();

        $this->assertDatabaseHas('notification_preferences', ['user_id' => $user->id, 'category' => 'leave', 'channel' => 'mail', 'enabled' => false]);
        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $user->id, 'category' => 'leave', 'channel' => 'database']);
    }
}
