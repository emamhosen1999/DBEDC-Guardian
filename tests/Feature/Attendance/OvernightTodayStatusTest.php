<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OvernightTodayStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_open_overnight_session_shows_as_checked_in_after_midnight(): void
    {
        AttendanceSetting::create([
            'office_start_time' => '22:00', 'office_end_time' => '06:00',
            'weekend_days' => ['friday', 'saturday'],
        ]);
        $user = User::factory()->create();
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-17', 'punchin' => '2026-06-17 23:00:00', 'punchout' => null,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-18 06:00:00'));
        $data = app(AttendanceQueryService::class)->getTodayAttendance($user->id);

        // The open overnight session is surfaced => UI shows "checked in" + Check Out.
        $this->assertNotEmpty($data['punches']);
        $open = collect($data['punches'])->firstWhere('punchout_time', null);
        $this->assertNotNull($open, 'open overnight session should be present');
        $this->assertSame('23:00:00', $open['punchin_time']);
    }

    public function test_day_shift_open_row_does_not_leak_into_next_day(): void
    {
        $user = User::factory()->create(); // no AttendanceSetting => default 09:00-17:00 (no crossesMidnight)
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-19', 'punchin' => '2026-06-19 09:00:00', 'punchout' => null,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-06-20 06:00:00'));
        $data = app(AttendanceQueryService::class)->getTodayAttendance($user->id);
        $this->assertEmpty($data['punches']); // prior-day day-shift row NOT surfaced
    }
}
