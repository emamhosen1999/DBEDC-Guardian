<?php
// tests/Feature/Notifications/FcmGracefulDegradationTest.php
namespace Tests\Feature\Notifications;

use App\Models\NotificationToken;
use App\Models\User;
use App\Notifications\Attendance\MissedPunchNotification;
use App\Services\Notification\FcmNotificationService;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Found by live verification: FcmNotificationService used to resolve
 * app('firebase.messaging') in its constructor, which throws when Firebase
 * credentials are absent. Because the service is constructed during push-channel
 * resolution, that throw aborted the ENTIRE notification — including the
 * always-on database channel. These tests guard the lazy/graceful behavior so a
 * push notification still records in-app even when Firebase is unconfigured.
 */
class FcmGracefulDegradationTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_constructs_without_firebase_credentials(): void
    {
        // Must not throw even when storage/app/firebase-credentials.json is absent.
        $this->assertInstanceOf(FcmNotificationService::class, new FcmNotificationService());
    }

    public function test_push_notification_still_records_in_app_when_firebase_unconfigured(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        $user = User::factory()->create();
        // A real FCM device token so the push path actually runs (and must degrade).
        NotificationToken::create([
            'user_id' => $user->id, 'provider' => 'fcm', 'token' => 'fcm-unconfigured-x', 'platform' => 'web',
        ]);

        // attendance.missed_punch_out = database + push. With no Firebase creds the
        // push channel must fail gracefully while the database channel still writes.
        Notification::sendNow($user, new MissedPunchNotification('out', now()->toDateString()));

        $this->assertSame(1, $user->fresh()->notifications()->count());
        $this->assertSame('attendance.missed_punch_out', $user->fresh()->notifications()->first()->data['type_key']);
    }
}
