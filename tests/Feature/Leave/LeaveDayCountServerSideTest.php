<?php

namespace Tests\Feature\Leave;

use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Leave\LeaveCrudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveDayCountServerSideTest extends TestCase
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
    public function it_ignores_a_lying_client_days_count_and_computes_server_side(): void
    {
        $this->bindAllWorkingDays();
        $user = User::factory()->create();
        LeaveSetting::create(['type' => 'Casual', 'days' => 100, 'requires_approval' => true]);

        $leave = app(LeaveCrudService::class)->createLeave([
            'user_id' => $user->id,
            'leaveType' => 'Casual',
            'fromDate' => '2026-03-02', // Mon
            'toDate' => '2026-03-04',   // Wed -> 3 working days
            'daysCount' => 999,         // client lie
            'leaveReason' => 'server count wins',
        ]);

        $this->assertSame(3.0, (float) $leave->no_of_days);
    }

    /** @test */
    public function a_half_day_leave_records_zero_point_five(): void
    {
        $this->bindAllWorkingDays();
        $user = User::factory()->create();
        LeaveSetting::create(['type' => 'Casual', 'days' => 100, 'requires_approval' => true]);

        $leave = app(LeaveCrudService::class)->createLeave([
            'user_id' => $user->id,
            'leaveType' => 'Casual',
            'fromDate' => '2026-03-02',
            'toDate' => '2026-03-02',
            'daysCount' => 1,
            'leaveReason' => 'half day am',
            'isHalfDay' => true,
            'halfDaySession' => 'first_half',
        ]);

        $this->assertSame(0.5, (float) $leave->no_of_days);
        $this->assertTrue($leave->is_half_day);
        $this->assertSame('first_half', $leave->half_day_session);
    }
}
