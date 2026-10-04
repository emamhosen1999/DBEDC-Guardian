<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Notifications\Attendance\MissedPunchNotification;
use App\Notifications\Attendance\MissingPunchInNotification;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** F-7: attendance:reminders is one code path (shift-alerts): rostered and unpunched only, no 22:17 blast. */
class AttendanceRemindersSinglePathTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-07-20';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_rostered_unpunched_employees_are_reminded_through_the_notification(): void
    {
        Notification::fake();
        $shift = Shift::factory()->create(['code' => 'MRN', 'start_time' => '07:00', 'end_time' => '15:00', 'crosses_midnight' => false]);
        $late = User::factory()->create();
        $present = User::factory()->create();
        $offDuty = User::factory()->create();
        foreach ([$late, $present] as $user) {
            RosterDay::create(['user_id' => $user->id, 'date' => self::DATE, 'shift_id' => $shift->id, 'source' => 'manual', 'locked' => true]);
        }
        Attendance::create(['user_id' => $present->id, 'date' => self::DATE, 'punchin' => self::DATE.' 06:58:00', 'policy_status' => 'accepted']);

        Carbon::setTestNow(self::DATE.' 07:20:00');
        $this->artisan('attendance:reminders')->assertExitCode(0);

        Notification::assertSentTo($late, MissingPunchInNotification::class);
        Notification::assertNotSentTo($present, MissingPunchInNotification::class);
        Notification::assertNotSentTo($offDuty, MissingPunchInNotification::class);
        Notification::assertNotSentTo($offDuty, MissedPunchNotification::class);
        Notification::assertNotSentTo($late, MissedPunchNotification::class); // the old blanket "missed punch-in" is gone
    }

    public function test_the_duplicate_job_and_the_2217_schedule_are_gone(): void
    {
        $this->assertFileDoesNotExist(app_path('Jobs/SendAttendanceReminder.php'));

        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command);
        $this->assertTrue($events->contains(fn ($c) => str_contains($c, 'attendance:shift-alerts')));
        $this->assertFalse($events->contains(fn ($c) => str_contains($c, 'attendance:reminders')));
    }
}
