<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationToken;
use App\Models\User;
use App\Services\Notification\FcmNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyTokenCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // FcmNotificationService's constructor resolves 'firebase.messaging', which
        // requires real Firebase credentials. handleInvalidToken() never touches
        // $this->messaging, so a stub binding is sufficient to construct the
        // real service and exercise its pruning behavior via reflection.
        $this->app->bind('firebase.messaging', fn () => new \stdClass);
    }

    public function test_handle_invalid_token_prunes_notification_tokens(): void
    {
        $user = User::factory()->create();
        NotificationToken::create([
            'user_id' => $user->id,
            'provider' => 'fcm',
            'token' => 'dead-web',
            'platform' => 'web',
        ]);

        // handleInvalidToken is protected; call via reflection to assert pruning behavior.
        $svc = app(FcmNotificationService::class);
        $m = new \ReflectionMethod($svc, 'handleInvalidToken');
        $m->setAccessible(true);
        $m->invoke($svc, 'dead-web');

        $this->assertDatabaseMissing('notification_tokens', ['token' => 'dead-web']);
    }

    public function test_users_table_no_longer_has_fcm_token_column(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'fcm_token'));
    }
}
