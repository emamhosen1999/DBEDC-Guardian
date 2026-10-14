<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceRegularization;
use App\Models\HRM\BiometricAttLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rejected terminal scans become pending regularization requests for the employee's approver,
 * never attendance directly; repeat runs and already-settled days create nothing.
 */
class RaiseCaptureExceptionRegularizationsTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->employee = User::factory()->create(['employee_id' => '9120', 'name' => 'Field Engineer']);
    }

    private function scan(string $at, string $reason = 'Attendance type is not biometric: route_waypoint', string $status = 'failed'): void
    {
        BiometricAttLog::create([
            'serial_number' => 'AF6P-TEST', 'user_pin' => '9120', 'user_id' => '9120', 'punch_time' => $at, 'occurred_at' => $at,
            'check_type' => 'in', 'punch_status' => $status, 'punch_status_reason' => $reason,
        ]);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $this->scan('2026-09-21 11:32:00');

        $this->artisan('attendance:raise-capture-exceptions', ['employee' => '9120', '--from' => '2026-09-01', '--to' => '2026-09-30'])
            ->expectsOutputToContain('would be raised')
            ->assertExitCode(0);

        $this->assertSame(0, AttendanceRegularization::count());
    }

    public function test_apply_raises_one_pending_request_per_day_with_first_and_last_scan(): void
    {
        $this->scan('2026-09-21 11:32:00');
        $this->scan('2026-09-21 18:52:00');
        $this->scan('2026-09-22 12:35:00');

        $this->artisan('attendance:raise-capture-exceptions', ['employee' => '9120', '--from' => '2026-09-01', '--to' => '2026-09-30', '--apply' => true])->assertExitCode(0);

        $requests = AttendanceRegularization::orderBy('date')->get();
        $this->assertCount(2, $requests);
        $this->assertSame(['pending', 'pending'], $requests->pluck('status')->all());
        $this->assertSame('2026-09-21 11:32:00', $requests[0]->requested_punchin->toDateTimeString());
        $this->assertSame('2026-09-21 18:52:00', $requests[0]->requested_punchout->toDateTimeString());
        $this->assertNull($requests[1]->requested_punchout);
        $this->assertStringContainsString('AF6P-TEST', $requests[0]->reason);
        $this->assertSame(0, Attendance::count(), 'nothing reaches attendance before an approver decides');
    }

    public function test_settled_days_other_reasons_and_repeat_runs_raise_nothing(): void
    {
        $this->scan('2026-09-21 11:32:00');
        $this->scan('2026-09-23 11:00:00');
        $this->scan('2026-09-24 11:00:00', 'Already punched in for this period.');
        $this->scan('2026-09-25 11:00:00', 'Attendance type is not biometric: route_waypoint', 'processed');
        Attendance::create(['user_id' => '9120', 'date' => '2026-09-23', 'punchin' => '2026-09-23 09:00:00']);
        $args = ['employee' => '9120', '--from' => '2026-09-01', '--to' => '2026-09-30', '--apply' => true];

        $this->artisan('attendance:raise-capture-exceptions', $args)->assertExitCode(0);
        $this->artisan('attendance:raise-capture-exceptions', $args)->expectsOutputToContain('skipped: request exists')->assertExitCode(0);

        $this->assertSame(['2026-09-21'], AttendanceRegularization::pluck('date')->map->toDateString()->all());
    }
}
