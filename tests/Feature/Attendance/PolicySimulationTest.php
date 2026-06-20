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
        $this->assertArrayHasKey('changed', $res);
        $this->assertGreaterThanOrEqual(0, $res['changed']);
    }
}
