<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendancePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendancePolicyModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_and_casts(): void
    {
        $p = AttendancePolicy::create([
            'name' => 'Default', 'scope_type' => 'org', 'scope_id' => null,
            'priority' => 0, 'effective_from' => '2026-06-01', 'version_group_id' => 1, 'version' => 1,
            'status' => 'active', 'punch_strictness' => 'restrict', 'outside_window_minutes' => 90,
            'grace_tiers' => ['late' => [['upto_minutes' => 10, 'outcome' => 'present'], ['upto_minutes' => 30, 'outcome' => 'late']]],
            'rounding' => ['strategy' => 'quarter_hour', 'unit_minutes' => 15, 'direction' => 'nearest'],
        ]);
        $fresh = $p->fresh();
        $this->assertSame('restrict', $fresh->punch_strictness);
        $this->assertSame(10, $fresh->grace_tiers['late'][0]['upto_minutes']);
        $this->assertSame('quarter_hour', $fresh->rounding['strategy']);
        $this->assertSame('2026-06-01', $fresh->effective_from->toDateString());
        $this->assertCount(1, AttendancePolicy::active()->get());
    }
}
