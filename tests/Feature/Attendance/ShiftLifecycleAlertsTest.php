<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Notifications\Attendance\MissingPunchInNotification;
use App\Notifications\Attendance\ShiftAbsenceNotification;
use App\Notifications\Attendance\ShiftStartReminderNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ShiftLifecycleAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-07-20';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rosterEmployeeOnMorningShift(): array
    {
        $employee = User::factory()->create();
        // MRN: 07:00 → 15:00, same-day.
        $shift = Shift::factory()->create([
            'code' => 'MRN',
            'start_time' => '07:00',
            'end_time' => '15:00',
            'crosses_midnight' => false,
        ]);

        RosterDay::create([
            'user_id' => $employee->id,
            'date' => self::DATE,
            'shift_id' => $shift->id,
            'source' => 'manual',
            'locked' => true,
        ]);

        return [$employee, $shift];
    }

    public function test_overdue_punch_in_notifies_the_employee(): void
    {
        Notification::fake();
        [$employee] = $this->rosterEmployeeOnMorningShift();

        // 07:20 — 20 min after a 07:00 start, inside the overdue window.
        Carbon::setTestNow(self::DATE.' 07:20:00');

        $this->artisan('attendance:shift-alerts')->assertExitCode(0);

        Notification::assertSentTo($employee, MissingPunchInNotification::class);
        Notification::assertNothingSentTo(User::factory()->create()); // no stray sends
    }

    public function test_punched_in_employee_is_not_alerted(): void
    {
        Notification::fake();
        [$employee] = $this->rosterEmployeeOnMorningShift();

        Attendance::create([
            'user_id' => $employee->id,
            'date' => self::DATE,
            'punchin' => self::DATE.' 06:58:00',
            'policy_status' => 'accepted',
        ]);

        Carbon::setTestNow(self::DATE.' 07:20:00');

        $this->artisan('attendance:shift-alerts')->assertExitCode(0);

        Notification::assertNotSentTo($employee, MissingPunchInNotification::class);
        Notification::assertNotSentTo($employee, ShiftAbsenceNotification::class);
    }

    public function test_absence_notifies_the_manager_not_the_employee(): void
    {
        Notification::fake();
        $manager = User::factory()->create();
        [$employee] = $this->rosterEmployeeOnMorningShift();
        $employee->forceFill(['report_to' => $manager->id])->save();

        // 08:30 — 90 min after start, past the absence threshold (60 min).
        Carbon::setTestNow(self::DATE.' 08:30:00');

        $this->artisan('attendance:shift-alerts')->assertExitCode(0);

        Notification::assertSentTo($manager, ShiftAbsenceNotification::class);
        Notification::assertNotSentTo($employee, ShiftAbsenceNotification::class);
        // Escalated straight to absence — employee is not also pinged as overdue.
        Notification::assertNotSentTo($employee, MissingPunchInNotification::class);
    }

    public function test_reminder_fires_before_the_shift_starts(): void
    {
        Notification::fake();
        [$employee] = $this->rosterEmployeeOnMorningShift();

        // 06:40 — 20 min before a 07:00 start, inside the 30-min lead window.
        Carbon::setTestNow(self::DATE.' 06:40:00');

        $this->artisan('attendance:shift-alerts')->assertExitCode(0);

        Notification::assertSentTo($employee, ShiftStartReminderNotification::class);
        Notification::assertNotSentTo($employee, MissingPunchInNotification::class);
    }

    public function test_dedupe_does_not_double_send_on_a_second_run(): void
    {
        Notification::fake();
        [$employee] = $this->rosterEmployeeOnMorningShift();

        Carbon::setTestNow(self::DATE.' 07:20:00');

        // Two ticks 5 minutes apart, still inside the overdue window.
        $this->artisan('attendance:shift-alerts')->assertExitCode(0);
        Carbon::setTestNow(self::DATE.' 07:25:00');
        $this->artisan('attendance:shift-alerts')->assertExitCode(0);

        Notification::assertSentToTimes($employee, MissingPunchInNotification::class, 1);
    }

    public function test_off_day_roster_row_produces_no_alert(): void
    {
        Notification::fake();
        $employee = User::factory()->create();

        // A materialized OFF row (no shift) must never trigger an alert.
        RosterDay::create([
            'user_id' => $employee->id,
            'date' => self::DATE,
            'shift_id' => null,
            'source' => 'manual',
            'locked' => true,
        ]);

        Carbon::setTestNow(self::DATE.' 07:20:00');

        $this->artisan('attendance:shift-alerts')->assertExitCode(0);

        Notification::assertNothingSentTo($employee);
    }

    public function test_to_push_carries_deep_link_url(): void
    {
        $employeePush = (new MissingPunchInNotification('MRN', '07:00', self::DATE))->toPush(new User);
        $this->assertSame('/mobile/punch', $employeePush->data['url']);

        $managerPush = (new ShiftAbsenceNotification('Jane Doe', 'MRN', '07:00', self::DATE))->toPush(new User);
        $this->assertSame('/mobile/team-attendance', $managerPush->data['url']);
        $this->assertStringContainsString('Jane Doe', $managerPush->body);
    }
}
