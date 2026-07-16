<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AutoPunchOutCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_closes_open_row_after_shift_end(): void
    {
        AttendanceSetting::create([
            'auto_punch_out' => true, 'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'weekend_days' => ['friday', 'saturday'],
        ]);
        $user = User::factory()->create();
        $open = Attendance::factory()->for($user)->create([
            'date' => '2026-06-17', 'punchin' => '2026-06-17 09:00:00', 'punchout' => null,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-17 20:00:00')); // past 17:00 end
        Artisan::call('attendance:auto-punch-out');

        $open->refresh();
        $this->assertNotNull($open->punchout);
        $this->assertSame('2026-06-17 17:00:00', Carbon::parse($open->punchout)->format('Y-m-d H:i:s'));
    }

    public function test_does_not_close_row_still_within_shift(): void
    {
        AttendanceSetting::create([
            'auto_punch_out' => true, 'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'weekend_days' => ['friday', 'saturday'],
        ]);
        $user = User::factory()->create();
        $open = Attendance::factory()->for($user)->create([
            'date' => '2026-06-17', 'punchin' => '2026-06-17 09:00:00', 'punchout' => null,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-06-17 14:00:00')); // before end
        Artisan::call('attendance:auto-punch-out');
        $this->assertNull($open->fresh()->punchout);
    }

    public function test_disabled_setting_closes_nothing(): void
    {
        AttendanceSetting::create(['auto_punch_out' => false, 'office_start_time' => '09:00', 'office_end_time' => '17:00']);
        $user = User::factory()->create();
        $open = Attendance::factory()->for($user)->create([
            'date' => '2026-06-17', 'punchin' => '2026-06-17 09:00:00', 'punchout' => null,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-06-17 20:00:00'));
        Artisan::call('attendance:auto-punch-out');
        $this->assertNull($open->fresh()->punchout);
    }

    /**
     * An off-day (non-working-day) open row must NOT be credited a near-24h
     * endOfDay() fallback — it should be capped at an 8-hour shift instead.
     * 2026-06-20 is a Saturday (weekend under the Fri/Sat setting below).
     */
    public function test_off_day_open_row_is_capped_at_eight_hours_not_end_of_day(): void
    {
        AttendanceSetting::create([
            'auto_punch_out' => true, 'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'weekend_days' => ['friday', 'saturday'],
        ]);
        $user = User::factory()->create();
        $open = Attendance::factory()->for($user)->create([
            'date' => '2026-06-20', 'punchin' => '2026-06-20 09:00:00', 'punchout' => null,
        ]);

        // Well past an 8h shift (09:00 + 8h = 17:00), still same day.
        Carbon::setTestNow(Carbon::parse('2026-06-20 20:00:00'));
        Artisan::call('attendance:auto-punch-out');

        $open->refresh();
        $this->assertNotNull($open->punchout);
        $this->assertSame('2026-06-20 17:00:00', Carbon::parse($open->punchout)->format('Y-m-d H:i:s'));
    }

    /**
     * When less than 8 hours have elapsed since punch-in on an off day, the
     * fallback must never stamp a punchout in the FUTURE relative to "now" —
     * it is capped at "now" instead of the full 8-hour credit.
     */
    public function test_off_day_open_row_punchout_is_capped_at_now_when_under_eight_hours(): void
    {
        AttendanceSetting::create([
            'auto_punch_out' => true, 'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'weekend_days' => ['friday', 'saturday'],
        ]);
        $user = User::factory()->create();
        $open = Attendance::factory()->for($user)->create([
            'date' => '2026-06-20', 'punchin' => '2026-06-20 09:00:00', 'punchout' => null,
        ]);

        // Only 3 hours after punch-in — well under the 8h fallback.
        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00'));
        Artisan::call('attendance:auto-punch-out');

        $open->refresh();
        $this->assertNotNull($open->punchout);
        $this->assertSame('2026-06-20 12:00:00', Carbon::parse($open->punchout)->format('Y-m-d H:i:s'));
    }
}
