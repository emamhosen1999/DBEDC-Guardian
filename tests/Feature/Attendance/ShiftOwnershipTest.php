<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Department;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\HRM\ShiftRotationPattern;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Shift definitions and rotation patterns have an OWNER: NULL = company-wide (attendance.settings), or a
 * department. A department admin creates / edits / deletes the templates of the departments he
 * administers (delegated) and assigns only those plus the company-wide ones; creator names never reach
 * a non-global viewer.
 */
class ShiftOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-06-03';

    private Department $d1;

    private Department $d2;

    private User $admin1;   // Department Admin of D1

    private User $admin2;   // Department Admin of D2

    private User $hr;       // company-wide

    private User $officer;  // attendance.settings, not company-wide

    private User $e1;       // D1 employee

    private User $e2;       // D2 employee

    private Shift $global;  // company-wide, authored by HR

    private Shift $s1;      // owned by D1

    private Shift $s2;      // owned by D2

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Super Administrator' => 1, 'HR Manager' => 20, 'Department Admin' => 25, 'Attendance Officer' => 40, 'Employee' => 60] as $name => $level) {
            Role::updateOrCreate(['name' => $name, 'guard_name' => 'web'], ['hierarchy_level' => $level]);
        }
        foreach (['attendance.view', 'attendance.settings', 'attendance.roster.manage', 'department.admin'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findByName('HR Manager')->syncPermissions(Permission::all());
        Role::findByName('Department Admin')->syncPermissions(['department.admin', 'attendance.view', 'attendance.roster.manage']);
        Role::findByName('Attendance Officer')->syncPermissions(['attendance.view', 'attendance.settings', 'attendance.roster.manage']);

        [$this->d1, $this->d2] = [Department::factory()->create(), Department::factory()->create()];
        $this->admin1 = $this->makeUser('Department Admin', $this->d1);
        $this->admin2 = $this->makeUser('Department Admin', $this->d2);
        $this->hr = $this->makeUser('HR Manager', null);
        $this->officer = $this->makeUser('Attendance Officer', null);
        $this->e1 = $this->makeUser('Employee', $this->d1);
        $this->e2 = $this->makeUser('Employee', $this->d2);

        $this->global = Shift::factory()->create(['code' => 'GEN', 'name' => 'General', 'created_by' => $this->hr->employee_id, 'department_id' => null]);
        $this->s1 = Shift::factory()->create(['code' => 'D1-A', 'name' => 'Inspection Day', 'created_by' => $this->admin1->employee_id, 'department_id' => $this->d1->id]);
        $this->s2 = Shift::factory()->create(['code' => 'D2-A', 'name' => 'Operations Night', 'created_by' => $this->admin2->employee_id, 'department_id' => $this->d2->id]);
    }

    private function makeUser(string $role, ?Department $department): User
    {
        $user = User::factory()->create(['department_id' => $department?->id]);
        $user->assignRole($role);

        return $user;
    }

    private function as(User $user): self
    {
        return $this->actingAs($user);
    }

    private function shiftPayload(array $extra = []): array
    {
        static $n = 0;
        $n++;

        return array_merge(['name' => "Shift {$n}", 'code' => "SH{$n}", 'type' => 'fixed', 'start_time' => '08:00', 'end_time' => '16:00'], $extra);
    }

    private function patternPayload(array $extra = []): array
    {
        static $n = 0;
        $n++;

        return array_merge(['name' => "Pattern {$n}", 'code' => "PT{$n}", 'cycle_length_days' => 2, 'definition' => [$this->global->id, 'off']], $extra);
    }

    // ── schema ─────────────────────────────────────────────────────────────────

    public function test_both_tables_carry_a_nullable_department_owner(): void
    {
        foreach (['shifts', 'shift_rotation_patterns'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'department_id'), "{$table}.department_id exists");
        }
        $this->assertNull($this->global->fresh()->department_id, 'every pre-existing template stays company-wide');
        $this->assertSame($this->d1->id, $this->s1->fresh()->department_id);
    }

    // ── what he sees ───────────────────────────────────────────────────────────

    public function test_a_scheduler_sees_company_wide_templates_and_those_of_his_departments_only(): void
    {
        $pg = ShiftRotationPattern::factory()->create(['code' => 'PG', 'definition' => [$this->global->id, 'off'], 'department_id' => null]);
        $p1 = ShiftRotationPattern::factory()->create(['code' => 'P1', 'definition' => [$this->s1->id, 'off'], 'department_id' => $this->d1->id]);
        $p2 = ShiftRotationPattern::factory()->create(['code' => 'P2', 'definition' => [$this->s2->id, 'off'], 'department_id' => $this->d2->id]);

        $shifts = collect($this->as($this->admin1)->getJson(route('attendance.shifts.index'))->assertOk()->json('shifts'))->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['GEN', 'D1-A'], $shifts);

        $patterns = collect($this->as($this->admin1)->getJson(route('attendance.patterns.index'))->assertOk()->json('patterns'))->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['PG', 'P1'], $patterns);

        // attendance administrators and global HR see everything
        foreach ([$this->hr, $this->officer] as $actor) {
            $this->assertCount(3, $this->as($actor)->getJson(route('attendance.shifts.index'))->json('shifts'));
            $this->assertCount(3, $this->as($actor)->getJson(route('attendance.patterns.index'))->json('patterns'));
        }
    }

    public function test_the_catalogue_says_who_owns_what_and_which_rows_he_may_edit(): void
    {
        $rows = collect($this->as($this->admin1)->getJson(route('attendance.shifts.index'))->json('shifts'))->keyBy('code');

        $this->assertSame($this->d1->id, $rows['D1-A']['department']['id']);
        $this->assertNull($rows['GEN']['department'], 'company-wide');
        $this->assertTrue($rows['D1-A']['can_manage']);
        $this->assertFalse($rows['GEN']['can_manage'], 'a company-wide template is attendance.settings territory');
    }

    public function test_creator_names_never_reach_a_non_global_viewer(): void
    {
        $asAdmin = $this->as($this->admin1)->getJson(route('attendance.shifts.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString($this->hr->name, $asAdmin);
        $this->assertStringNotContainsString($this->admin1->name, $asAdmin);
        $this->assertStringNotContainsString('"creator"', $asAdmin);
        $this->assertStringNotContainsString('"created_by"', $asAdmin);

        $created = $this->as($this->admin1)->postJson(route('attendance.shifts.store'), $this->shiftPayload(['department_id' => $this->d1->id]))->assertCreated();
        $this->assertArrayNotHasKey('creator', $created->json('shift'));
        $this->assertArrayNotHasKey('created_by', $created->json('shift'));

        // company-wide actors still see who authored what
        $asHr = collect($this->as($this->hr)->getJson(route('attendance.shifts.index'))->json('shifts'))->keyBy('code');
        $this->assertSame($this->hr->name, $asHr['GEN']['creator']['name']);
    }

    // ── what he may create / edit / delete ─────────────────────────────────────

    public function test_a_department_admin_creates_templates_for_his_own_department_only(): void
    {
        $created = $this->as($this->admin1)->postJson(route('attendance.shifts.store'), $this->shiftPayload(['department_id' => $this->d1->id]))->assertCreated();
        $this->assertSame($this->d1->id, $created->json('shift.department_id'));
        $this->assertSame($this->admin1->employee_id, (string) Shift::find($created->json('shift.id'))->created_by);

        // company-wide stays attendance.settings; another department's is not his
        $this->as($this->admin1)->postJson(route('attendance.shifts.store'), $this->shiftPayload())->assertForbidden();
        $this->as($this->admin1)->postJson(route('attendance.shifts.store'), $this->shiftPayload(['department_id' => $this->d2->id]))->assertForbidden();
        $this->as($this->admin1)->postJson(route('attendance.shifts.store'), [])->assertForbidden();

        $this->as($this->admin1)->postJson(route('attendance.patterns.store'), $this->patternPayload(['department_id' => $this->d1->id, 'definition' => [$this->s1->id, $this->global->id]]))->assertCreated()
            ->assertJsonPath('pattern.department_id', $this->d1->id);
        $this->as($this->admin1)->postJson(route('attendance.patterns.store'), $this->patternPayload())->assertForbidden();
        $this->as($this->admin1)->postJson(route('attendance.patterns.store'), $this->patternPayload(['department_id' => $this->d2->id]))->assertForbidden();
    }

    public function test_settings_holders_and_global_actors_still_create_company_wide_templates(): void
    {
        $this->as($this->officer)->postJson(route('attendance.shifts.store'), $this->shiftPayload())->assertCreated()->assertJsonPath('shift.department_id', null);
        $this->as($this->hr)->postJson(route('attendance.shifts.store'), $this->shiftPayload(['department_id' => $this->d2->id]))->assertCreated()->assertJsonPath('shift.department_id', $this->d2->id);
        $this->as($this->hr)->postJson(route('attendance.patterns.store'), $this->patternPayload())->assertCreated();
    }

    public function test_he_edits_and_deletes_only_templates_his_departments_own(): void
    {
        $this->as($this->admin1)->putJson(route('attendance.shifts.update', $this->s1->id), ['name' => 'Inspection Early', 'department_id' => $this->d1->id])->assertOk()->assertJsonPath('shift.name', 'Inspection Early');

        $this->as($this->admin1)->putJson(route('attendance.shifts.update', $this->global->id), ['name' => 'Hijack'])->assertForbidden();
        $this->as($this->admin1)->putJson(route('attendance.shifts.update', $this->s2->id), ['name' => 'Hijack'])->assertForbidden();
        // he can neither move his template out nor make it company-wide
        $this->as($this->admin1)->putJson(route('attendance.shifts.update', $this->s1->id), ['department_id' => $this->d2->id])->assertForbidden();
        $this->as($this->admin1)->putJson(route('attendance.shifts.update', $this->s1->id), ['department_id' => null])->assertForbidden();
        $this->assertSame($this->d1->id, $this->s1->fresh()->department_id);

        $this->as($this->admin1)->deleteJson(route('attendance.shifts.destroy', $this->global->id))->assertForbidden();
        $this->as($this->admin1)->deleteJson(route('attendance.shifts.destroy', $this->s2->id))->assertForbidden();
        $this->as($this->admin1)->deleteJson(route('attendance.shifts.destroy', $this->s1->id))->assertOk();
        $this->assertNull(Shift::find($this->s1->id));
    }

    public function test_a_template_in_use_is_deactivated_not_deleted_by_a_delegated_admin(): void
    {
        RosterDay::create(['user_id' => $this->e1->employee_id, 'date' => self::DAY, 'shift_id' => $this->s1->id, 'source' => 'manual']);
        $this->as($this->admin1)->deleteJson(route('attendance.shifts.destroy', $this->s1->id))->assertStatus(422);
        $this->assertNotNull(Shift::find($this->s1->id));
        $this->as($this->admin1)->putJson(route('attendance.shifts.update', $this->s1->id), ['is_active' => false])->assertOk();
        $this->assertFalse((bool) $this->s1->fresh()->is_active);

        $pattern = ShiftRotationPattern::factory()->create(['definition' => [$this->s1->id, 'off'], 'department_id' => $this->d1->id]);
        ShiftAssignment::create(['scope_type' => 'user', 'scope_id' => $this->e1->employee_id, 'rotation_pattern_id' => $pattern->id, 'anchor_date' => self::DAY, 'effective_from' => self::DAY, 'priority' => 0, 'assigned_by' => $this->admin1->employee_id]);
        $this->as($this->admin1)->deleteJson(route('attendance.patterns.destroy', $pattern->id))->assertStatus(422);
    }

    public function test_a_departments_pattern_only_uses_company_wide_shifts_or_its_own(): void
    {
        $this->as($this->admin1)->postJson(route('attendance.patterns.store'), $this->patternPayload(['department_id' => $this->d1->id, 'definition' => [$this->s2->id, 'off']]))
            ->assertStatus(422)->assertJsonValidationErrors('definition');

        // a company-wide pattern may not embed a department's shift
        $this->as($this->hr)->postJson(route('attendance.patterns.store'), $this->patternPayload(['definition' => [$this->s1->id, 'off']]))
            ->assertStatus(422)->assertJsonValidationErrors('definition');
        $this->as($this->hr)->postJson(route('attendance.patterns.store'), $this->patternPayload(['definition' => [$this->global->id, 'off']]))->assertCreated();
    }

    // ── what he may assign ─────────────────────────────────────────────────────

    public function test_he_assigns_only_templates_he_can_see(): void
    {
        $assign = fn (Shift $shift, string $from) => $this->as($this->admin1)->postJson(route('attendance.assignments.store'), [
            'scope_type' => 'user', 'scope_id' => $this->e1->employee_id, 'shift_id' => $shift->id,
            'anchor_date' => $from, 'effective_from' => $from, 'effective_to' => $from,
        ]);

        $assign($this->s1, '2026-06-04')->assertSuccessful();      // his department's
        $assign($this->global, '2026-06-05')->assertSuccessful();  // company-wide
        $assign($this->s2, '2026-06-06')->assertForbidden();       // another department's

        $this->as($this->admin1)->postJson(route('attendance.assignments.storeBulk'), [
            'scope_type' => 'user', 'scope_ids' => [$this->e1->employee_id], 'shift_id' => $this->s2->id, 'anchor_date' => self::DAY, 'effective_from' => self::DAY,
        ])->assertForbidden();

        $p2 = ShiftRotationPattern::factory()->create(['definition' => [$this->s2->id, 'off'], 'department_id' => $this->d2->id]);
        $this->as($this->admin1)->postJson(route('attendance.assignments.store'), [
            'scope_type' => 'user', 'scope_id' => $this->e1->employee_id, 'rotation_pattern_id' => $p2->id, 'anchor_date' => self::DAY, 'effective_from' => self::DAY,
        ])->assertForbidden();

        // an attendance administrator is not bound by departments
        $this->as($this->officer)->postJson(route('attendance.assignments.store'), [
            'scope_type' => 'user', 'scope_id' => $this->e2->employee_id, 'shift_id' => $this->s2->id, 'anchor_date' => self::DAY, 'effective_from' => self::DAY,
        ])->assertSuccessful();
    }

    public function test_roster_cells_accept_only_templates_he_can_see(): void
    {
        // (a manually edited roster day is locked, so each call targets its own date)
        $cell = fn (Shift $shift, string $date) => $this->as($this->admin1)->putJson(route('attendance.roster.cell'), ['user_id' => $this->e1->employee_id, 'date' => $date, 'shift_id' => $shift->id]);

        $cell($this->s1, '2026-06-04')->assertOk();
        $cell($this->global, '2026-06-05')->assertOk();
        $cell($this->s2, '2026-06-06')->assertForbidden();
        $this->assertSame(0, RosterDay::where('shift_id', $this->s2->id)->count());
    }
}
