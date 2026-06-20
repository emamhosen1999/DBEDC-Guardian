<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class OvernightPunchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function nightShiftSettings(): void
    {
        // Default schedule resolver reads AttendanceSetting; 22:00->06:00 crosses midnight.
        AttendanceSetting::create([
            'office_start_time' => '22:00', 'office_end_time' => '06:00',
            'weekend_days' => ['friday', 'saturday'],
        ]);
    }

    public function test_post_midnight_out_closes_prior_day_open_row(): void
    {
        $this->nightShiftSettings();
        $user = User::factory()->create();

        // Punch IN at 23:00 on Wed Jun 17 (working day under the Fri/Sat weekend; row dated Jun 17, open).
        Carbon::setTestNow(Carbon::parse('2026-06-17 23:00:00'));
        $svc = app(AttendancePunchService::class);
        $svc->processPunch($user, new Request(['check_type' => 'in']));

        $open = Attendance::where('user_id', $user->id)->whereDate('date', '2026-06-17')->first();
        $this->assertNotNull($open);
        $this->assertNull($open->punchout);

        // Punch OUT at 06:00 on Thu Jun 18.
        Carbon::setTestNow(Carbon::parse('2026-06-18 06:00:00'));
        $res = $svc->processPunch($user, new Request(['check_type' => 'out']));

        $this->assertSame('success', $res['status']);
        $open->refresh();
        $this->assertNotNull($open->punchout);                       // prior-day row closed
        $this->assertSame('2026-06-18 06:00:00', Carbon::parse($open->punchout)->format('Y-m-d H:i:s'));
        // No stray Jun 18 row created.
        $this->assertSame(0, Attendance::whereDate('date', '2026-06-18')->count());
    }

    public function test_day_shift_out_does_not_match_prior_day(): void
    {
        // Default 09:00-17:00 day shift (no AttendanceSetting => not crossing midnight).
        $user = User::factory()->create();
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-19', 'punchin' => '2026-06-19 09:00:00', 'punchout' => null,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-06-20 06:00:00'));
        $res = app(AttendancePunchService::class)->processPunch($user, new Request(['check_type' => 'out']));
        // No crossesMidnight shift => prior-day row NOT matched => 422, prior row stays open.
        $this->assertSame('error', $res['status']);
        $this->assertNull(Attendance::whereDate('date', '2026-06-19')->first()->punchout);
    }
}
