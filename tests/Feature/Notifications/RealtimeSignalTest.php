<?php
// tests/Feature/Notifications/RealtimeSignalTest.php
namespace Tests\Feature\Notifications;

use App\Listeners\WriteRealtimeNotificationSignal;
use App\Services\Notification\RealtimeNotificationSignal;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RealtimeSignalTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_channel_pings_realtime_signal_once(): void
    {
        $user = User::factory()->create();
        $spy = Mockery::mock(RealtimeNotificationSignal::class);
        $spy->shouldReceive('ping')->once()->with($user->id);

        $listener = new WriteRealtimeNotificationSignal($spy);
        $listener->handle(new NotificationSent($user, new class extends Notification {}, 'database'));
        $listener->handle(new NotificationSent($user, new class extends Notification {}, 'mail')); // ignored
    }
}
