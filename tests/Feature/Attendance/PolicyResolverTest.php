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

    public function test_department_scope_resolves_for_matching_user(): void
    {
        $department = Department::factory()->create();
        $u = User::factory()->create(['department_id' => $department->id]);
        AttendancePolicy::factory()->create([
            'scope_type' => 'department', 'scope_id' => $department->id, 'version_group_id' => 5,
            'punch_strictness' => 'flag', 'effective_from' => '2026-01-01',
        ]);
        $p = app(PolicyResolver::class)->resolve($u->id, Carbon::parse('2026-06-20'));
        $this->assertSame('flag', $p->strictness());
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

    public function test_resolves_breaks_and_overtime_from_rule_overrides(): void
    {
        $u = User::factory()->create();
        AttendancePolicy::factory()->create([
            'scope_type' => 'org', 'scope_id' => null, 'version_group_id' => 50,
            'effective_from' => '2026-01-01',
            'rule_overrides' => [
                'breaks' => ['unpaid_meal_minutes' => 30, 'meal_threshold_minutes' => 360],
                'overtime' => ['daily_threshold_minutes' => 480, 'daily_multiplier' => 1.5],
            ],
        ]);
        $p = app(\App\Services\Attendance\Contracts\PolicyResolver::class)->resolve($u->id, \Carbon\Carbon::parse('2026-06-20'));
        $this->assertSame(30, $p->breaks()['unpaid_meal_minutes']);
        $this->assertSame(480, $p->overtime()['daily_threshold_minutes']);
        $this->assertFalse($p->isNeutral());
    }

    public function test_policy_with_only_rule_overrides_is_not_neutral_but_grace_rounding_absent(): void
    {
        $u = User::factory()->create();
        AttendancePolicy::factory()->create([
            'scope_type' => 'org', 'scope_id' => null, 'version_group_id' => 51,
            'effective_from' => '2026-01-01', 'punch_strictness' => 'warn',
            'rule_overrides' => ['overtime' => ['daily_threshold_minutes' => 480, 'daily_multiplier' => 1.5]],
        ]);
        $p = app(\App\Services\Attendance\Contracts\PolicyResolver::class)->resolve($u->id, \Carbon\Carbon::parse('2026-06-20'));
        $this->assertFalse($p->isNeutral()); // overtime config makes it non-neutral
        $this->assertNull($p->graceTiers());
    }
}
