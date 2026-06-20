<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendancePolicy;
use App\Models\User;
use App\Services\Attendance\PolicySimulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicySimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulation_reports_a_diff_without_writing(): void
    {
        $u = User::factory()->create();
        Attendance::factory()->for($u)->create([
            'date' => '2026-06-19', 'punchin' => '2026-06-19 09:20:00', 'punchout' => '2026-06-19 17:00:00',
        ]);
        $draft = AttendancePolicy::factory()->make([
            'status' => 'draft', 'punch_strictness' => 'warn',
            'grace_tiers' => ['late' => [['upto_minutes' => 5, 'outcome' => 'present'], ['upto_minutes' => 9999, 'outcome' => 'late']]],
        ]);

        $before = Attendance::count();
        $res = app(PolicySimulationService::class)->simulate($draft, [$u->id], '2026-06-19', '2026-06-19');
        $this->assertSame($before, Attendance::count()); // no writes

        // Default shift (no AttendanceSetting row) starts at 09:00 with a 15-minute grace.
        // A 09:20 punch is already LATE under the neutral profile (20min > 15min grace).
        // The draft's grace tiers classify 20min under the "upto 9999 => late" band, which
        // also resolves to LATE — so this fixture is a same-status case: one day evaluated,
        // zero status changes, no samples recorded.
        $this->assertSame(1, $res['days']);
        $this->assertSame(0, $res['changed']);
        $this->assertCount(0, $res['samples']);
    }

    public function test_simulation_reports_a_real_change_when_draft_grace_tier_flips_status(): void
    {
        $u = User::factory()->create();
        Attendance::factory()->for($u)->create([
            'date' => '2026-06-19', 'punchin' => '2026-06-19 09:20:00', 'punchout' => '2026-06-19 17:00:00',
        ]);
        // A "half_day" tier outcome (up to 30min) reclassifies the same 09:20 punch
        // from LATE (neutral: 20min > 15min default grace) to HALF_DAY under the draft —
        // the grace-tier flag (tier_half_day) takes precedence over the plain late-minutes status.
        $draft = AttendancePolicy::factory()->make([
            'status' => 'draft', 'punch_strictness' => 'warn',
            'grace_tiers' => ['late' => [['upto_minutes' => 30, 'outcome' => 'half_day'], ['upto_minutes' => 9999, 'outcome' => 'late']]],
        ]);

        $res = app(PolicySimulationService::class)->simulate($draft, [$u->id], '2026-06-19', '2026-06-19');

        $this->assertSame(1, $res['days']);
        $this->assertSame(1, $res['changed']);
        $this->assertCount(1, $res['samples']);
        $this->assertSame('late', $res['samples'][0]['before_status']);
        $this->assertSame('half_day', $res['samples'][0]['after_status']);
    }
}
