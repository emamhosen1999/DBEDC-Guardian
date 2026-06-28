<?php
// tests/Feature/Notifications/LeaveNotificationChannelsTest.php
namespace Tests\Feature\Notifications;

use App\Models\HRM\Leave;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use App\Notifications\LeaveApprovalNotification;
use App\Notifications\LeaveApprovedNotification;
use App\Notifications\LeaveRejectedNotification;
use App\Services\Notification\Push\PushMessage;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveNotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_leave_notifications_declare_registry_type_keys(): void
    {
        // No Leave factory exists; via()/typeKey() do not read leave fields,
        // so an unsaved Leave instance is sufficient for channel resolution.
        $leave = new Leave(['user_id' => 1]);

        $this->assertSame('leave.requested', (new LeaveApprovalNotification($leave))->typeKey());
        $this->assertSame('leave.approved', (new LeaveApprovedNotification($leave))->typeKey());
        $this->assertSame('leave.rejected', (new LeaveRejectedNotification($leave, 'no'))->typeKey());
    }

    public function test_leave_approved_uses_registry_channels(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        $user = User::factory()->create();
        $leave = new Leave(['user_id' => $user->id]);

        $channels = (new LeaveApprovedNotification($leave))->via($user);

        $this->assertContains('database', $channels);
        $this->assertContains(PushChannel::class, $channels);
    }

    public function test_leave_rejected_builds_push_message(): void
    {
        $user = User::factory()->create();
        $leave = new Leave(['user_id' => $user->id]);
        $leave->from_date = \Illuminate\Support\Carbon::parse('2026-07-01');
        $leave->to_date = \Illuminate\Support\Carbon::parse('2026-07-02');
        $leave->no_of_days = 2;

        $push = (new LeaveRejectedNotification($leave, 'Insufficient balance'))->toPush($user);

        $this->assertInstanceOf(PushMessage::class, $push);
        $this->assertSame('Leave rejected', $push->title);
        $this->assertSame('leave.rejected', $push->data['type_key']);
    }
}
