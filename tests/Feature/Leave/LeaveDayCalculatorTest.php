<?php

namespace Tests\Feature\Leave;

use App\Models\HRM\Holiday;
use App\Models\User;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Leave\LeaveDayCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveDayCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function bindResolver(array $offDates = []): void
    {
        // Working day unless the date is in $offDates (weekly-off).
        $this->app->bind(ScheduleResolver::class, fn () => new class($offDates) implements ScheduleResolver
        {
            public function __construct(private array $off) {}

            public function resolve(int $userId, \Carbon\CarbonInterface $date): ShiftSchedule
            {
                $isWorking = ! in_array($date->toDateString(), $this->off, true);

                return $isWorking
                    ? new ShiftSchedule(
                        start: $date->copy()->setTime(9, 0), end: $date->copy()->setTime(17, 0),
                        crossesMidnight: false, graceInMinutes: 0, graceOutMinutes: 0,
                        fullDayMinutes: 480, halfDayMinutes: 240, minPresentMinutes: 0,
                        breakMinutes: 0, isWorkingDay: true, isScheduled: true,
                    )
                    : ShiftSchedule::nonWorking($date);
            }
        });
    }

    /** @test */
    public function it_counts_only_working_days_excluding_weekly_off_and_holidays(): void
    {
        $user = User::factory()->create();
        // Fri 2026-02-13 is the employee's weekly off; 2026-02-12 is a holiday.
        $this->bindResolver(offDates: ['2026-02-13']);
        Holiday::create([
            'title' => 'Test Holiday', 'from_date' => '2026-02-12', 'to_date' => '2026-02-12',
            'is_active' => true, 'recurrence_pattern' => 'none',
        ]);

        $calc = app(LeaveDayCalculator::class);
        // Range Mon 2026-02-09 .. Sun 2026-02-15 (7 days). Exclude Fri-13 (off) + Thu-12 (holiday) = 5.
        $days = $calc->compute($user->id, Carbon::parse('2026-02-09'), Carbon::parse('2026-02-15'), false);

        $this->assertSame(5.0, $days);
    }

    /** @test */
    public function a_half_day_on_a_working_day_is_zero_point_five(): void
    {
        $user = User::factory()->create();
        $this->bindResolver();

        $calc = app(LeaveDayCalculator::class);
        $days = $calc->compute($user->id, Carbon::parse('2026-02-10'), Carbon::parse('2026-02-10'), true);

        $this->assertSame(0.5, $days);
    }

    /** @test */
    public function a_half_day_on_a_non_working_day_is_zero(): void
    {
        $user = User::factory()->create();
        $this->bindResolver(offDates: ['2026-02-10']);

        $calc = app(LeaveDayCalculator::class);
        $days = $calc->compute($user->id, Carbon::parse('2026-02-10'), Carbon::parse('2026-02-10'), true);

        $this->assertSame(0.0, $days);
    }
}
