<?php
// tests/Feature/Notifications/AttendanceNotificationChannelsTest.php
namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\Attendance\MissedPunchNotification;
use App\Notifications\Attendance\ShiftSwapDecidedNotification;
use App\Notifications\Attendance\TimeCorrectionRequestedNotification;
use App\Notifications\Channels\PushChannel;
use App\Services\Notification\Push\PushMessage;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AttendanceNotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_missed_punch_direction_maps_to_type_key(): void
    {
        $this->assertSame('attendance.missed_punch_in', (new MissedPunchNotification('in', '2026-07-01'))->typeKey());
        $this->assertSame('attendance.missed_punch_out', (new MissedPunchNotification('out', '2026-07-01'))->typeKey());
    }

    public function test_missed_punch_in_delivers_database_and_push(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        $user = User::factory()->create();

        $channels = (new MissedPunchNotification('in', now()->toDateString()))->via($user);

        $this->assertContains('database', $channels);
        $this->assertContains(PushChannel::class, $channels);
    }

    public function test_notify_records_database_row(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        Notification::fake();
        $user = User::factory()->create();

        $user->notify(new MissedPunchNotification('out', now()->toDateString()));

        Notification::assertSentTo($user, MissedPunchNotification::class);
    }

    public function test_decision_notifications_build_push_messages(): void
    {
        $swap = (new ShiftSwapDecidedNotification(7, 'approved'))->toPush(new User());
        $this->assertInstanceOf(PushMessage::class, $swap);
        $this->assertSame('attendance.shift_swap_decided', $swap->data['type_key']);

        $correction = (new TimeCorrectionRequestedNotification(9, 'Jane'))->toArray(new User());
        $this->assertSame('attendance.time_correction_requested', $correction['type_key']);
        $this->assertSame('/attendance.unified', $correction['url']);
    }
}
