<?php
// tests/Feature/Notifications/PushDispatcherTest.php
namespace Tests\Feature\Notifications;

use App\Models\NotificationToken;
use App\Models\User;
use App\Services\Notification\FcmNotificationService;
use App\Services\Notification\Push\PushDispatcher;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // FcmNotificationService needs Firebase credentials; mock it so tests run without live Firebase.
        $this->mock(FcmNotificationService::class);
    }

    public function test_prunes_expo_token_reported_device_not_registered(): void
    {
        Http::fake([
            'exp.host/*' => Http::response(['data' => [
                ['status' => 'error', 'message' => 'x', 'details' => ['error' => 'DeviceNotRegistered']],
            ]]),
        ]);

        $user = User::factory()->create();
        NotificationToken::create(['user_id' => $user->id, 'provider' => 'expo', 'token' => 'ExponentPushToken[dead]', 'platform' => 'android']);

        app(PushDispatcher::class)->send(
            $user->notificationTokens()->get(),
            new PushMessage('Hi', 'Body', ['type_key' => 'leave.approved']),
        );

        $this->assertDatabaseMissing('notification_tokens', ['token' => 'ExponentPushToken[dead]']);
    }
}
