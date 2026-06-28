<?php
// tests/Feature/Notifications/NotificationTokenModelTest.php
namespace Tests\Feature\Notifications;

use App\Models\NotificationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTokenModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_many_notification_tokens(): void
    {
        $user = User::factory()->create();
        NotificationToken::create(['user_id' => $user->id, 'provider' => 'fcm', 'token' => 'web-1', 'platform' => 'web']);
        NotificationToken::create(['user_id' => $user->id, 'provider' => 'expo', 'token' => 'ExponentPushToken[x]', 'platform' => 'android']);

        $this->assertCount(2, $user->fresh()->notificationTokens);
        $this->assertSame('expo', $user->notificationTokens()->where('platform', 'android')->first()->provider);
    }
}
