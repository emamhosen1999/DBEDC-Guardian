<?php

namespace Tests\Feature\Leave;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeaveFractionSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function bindAllWorkingDays(): void
    {
        $this->app->bind(ScheduleResolver::class, fn () => new class implements ScheduleResolver
        {
            public function resolve(int $userId, \Carbon\CarbonInterface $date): ShiftSchedule
            {
                return new ShiftSchedule(
                    start: $date->copy()->setTime(9, 0), end: $date->copy()->setTime(17, 0),
                    crossesMidnight: false, graceInMinutes: 0, graceOutMinutes: 0,
                    fullDayMinutes: 480, halfDayMinutes: 240, minPresentMinutes: 0,
                    breakMinutes: 0, isWorkingDay: true, isScheduled: true,
                );
            }
        });
    }

    /** @test */
    public function per_employee_summary_counts_a_half_day_leave_as_half_and_splits_paid_lwp(): void
    {
        $this->bindAllWorkingDays();
        Role::findOrCreate('Employee');

        $user = User::factory()->create(['date_of_joining' => '2020-01-01']);
        $user->assignRole('Employee');

        $type = LeaveSetting::create(['type' => 'Casual', 'symbol' => 'C', 'days' => 100, 'is_paid' => true]);

        Leave::create([
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-03-02', 'to_date' => '2026-03-02',
            'no_of_days' => 0.5, 'is_half_day' => true, 'half_day_session' => 'first_half',
            'reason' => 'x', 'status' => 'approved',
        ]);

        $summary = app(AttendanceReportService::class)->getPerEmployeeMonthlySummary(2026, 3);
        $row = collect($summary['rows'])->firstWhere('employee_id', $user->employee_id);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(0.5, $row['leave'], 0.001);
        $this->assertEqualsWithDelta(0.5, $row['paid_leave'], 0.001);
        $this->assertEqualsWithDelta(0.0, $row['lwp'], 0.001);
    }
}
