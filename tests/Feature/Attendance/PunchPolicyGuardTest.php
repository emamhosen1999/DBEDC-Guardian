<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendancePolicy;
use App\Models\User;
use App\Services\Attendance\PunchPolicyGuard;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PunchPolicyGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_restrict_marks_provisional_when_out_of_window(): void
    {
        $u = User::factory()->create();
        AttendancePolicy::factory()->create([
            'scope_type' => 'user', 'scope_id' => $u->id, 'version_group_id' => 1,
            'punch_strictness' => 'restrict', 'outside_window_minutes' => 60, 'effective_from' => '2026-01-01',
        ]);
        // default office window is 09:00-17:00; punch at 06:00 is >60min before start.
        $res = app(PunchPolicyGuard::class)->assess($u->id, Carbon::parse('2026-06-19 06:00'));
        $this->assertSame('provisional', $res['policy_status']);
        $this->assertTrue($res['needs_approval']);
    }

    public function test_warn_never_provisional(): void
    {
        $u = User::factory()->create();
        AttendancePolicy::factory()->create([
            'scope_type' => 'user', 'scope_id' => $u->id, 'version_group_id' => 2,
            'punch_strictness' => 'warn', 'outside_window_minutes' => 60, 'effective_from' => '2026-01-01',
        ]);
        $res = app(PunchPolicyGuard::class)->assess($u->id, Carbon::parse('2026-06-19 06:00'));
        $this->assertSame('accepted', $res['policy_status']);
        $this->assertFalse($res['needs_approval']);
    }
}
