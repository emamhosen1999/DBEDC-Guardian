<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendancePolicy;
use App\Models\HRM\Department;
use App\Models\User;
use App\Services\Attendance\Contracts\PolicyResolver;
use App\Services\Attendance\DTO\PolicyProfile;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_falls_back_to_neutral_when_no_policy(): void
    {
        $u = User::factory()->create();
        $p = app(PolicyResolver::class)->resolve($u->id, Carbon::parse('2026-06-20'));
        $this->assertTrue($p->isNeutral());
        $this->assertSame('warn', $p->strictness());
    }

    public function test_user_scope_beats_org_scope(): void
    {
        $department = Department::factory()->create();
        $u = User::factory()->create(['department_id' => $department->id]);
        AttendancePolicy::factory()->create([
            'scope_type' => 'org', 'scope_id' => null, 'version_group_id' => 1,
            'punch_strictness' => 'warn', 'effective_from' => '2026-01-01',
        ]);
        AttendancePolicy::factory()->create([
            'scope_type' => 'user', 'scope_id' => $u->id, 'version_group_id' => 2,
            'punch_strictness' => 'restrict', 'outside_window_minutes' => 60,
            'effective_from' => '2026-01-01',
        ]);
        $p = app(PolicyResolver::class)->resolve($u->id, Carbon::parse('2026-06-20'));
        $this->assertSame('restrict', $p->strictness());
        $this->assertSame(60, $p->outsideWindowMinutes());
        $this->assertFalse($p->isNeutral());
    }

    public function test_only_active_in_effective_range_resolves(): void
    {
        $u = User::factory()->create();
        AttendancePolicy::factory()->create([
            'scope_type' => 'org', 'scope_id' => null, 'version_group_id' => 3,
            'status' => 'draft', 'punch_strictness' => 'restrict', 'effective_from' => '2026-01-01',
        ]);
        $p = app(PolicyResolver::class)->resolve($u->id, Carbon::parse('2026-06-20'));
        $this->assertTrue($p->isNeutral()); // draft does not resolve
    }
}
