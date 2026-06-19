<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendanceSetting;
use App\Services\Attendance\Contracts\ScheduleResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultScheduleResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_schedule_from_settings(): void
    {
        AttendanceSetting::create([
            'office_start_time' => '09:00',
            'office_end_time' => '17:00',
            'break_time_duration' => 60,
            'late_mark_after' => 20,
            'early_leave_before' => 10,
            'overtime_after' => 30,
            'weekend_days' => ['friday', 'saturday'],
            'auto_punch_out' => false,
        ]);

        $resolver = app(ScheduleResolver::class);

        $weekday = $resolver->resolve(1, Carbon::parse('2026-06-18')); // Thursday
        $this->assertTrue($weekday->isWorkingDay);
        $this->assertSame('09:00', $weekday->start->format('H:i'));
        $this->assertSame(20, $weekday->graceInMinutes);

        $friday = $resolver->resolve(1, Carbon::parse('2026-06-19')); // Friday = weekend
        $this->assertFalse($friday->isWorkingDay);
    }
}
