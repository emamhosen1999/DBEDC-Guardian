<?php

namespace Tests\Feature\Access;

use App\Models\HRM\Department;
use App\Models\User;
use App\Models\UserDepartmentScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Web and mobile agree on department scope: a D1 department admin reaches D1 only,
 * a non-managing D2 employee reaches nobody but themselves (fail closed), global HR
 * reaches everyone — and only global HR who outranks the grantee manages grants.
 */
class DepartmentScopeEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Department $d1;

    private Department $d2;

    private Department $d3;

    /** @var array<string, User> */
    private array $u = [];

    private const PERMISSIONS = [
        'users.view', 'users.create', 'users.update', 'users.delete',
        'employees.view', 'employees.create', 'employees.update',
        'departments.update', 'department.admin', 'department.scopes.manage',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'Super Administrator' => 1,
            'Administrator' => 10,
            'HR Manager' => 20,
            'Department Admin' => 25,
            'Employee' => 60,
        ] as $name => $level) {
            Role::updateOrCreate(['name' => $name, 'guard_name' => 'web'], ['hierarchy_level' => $level]);
        }
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findByName('HR Manager')->givePermissionTo(self::PERMISSIONS);
        Role::findByName('Department Admin')->syncPermissions([
            'users.view', 'users.create', 'users.update',
            'employees.view', 'employees.create', 'employees.update',
            'departments.update', 'department.admin',
        ]);

        [$this->d1, $this->d2, $this->d3] = [Department::factory()->create(), Department::factory()->create(), Department::factory()->create()];

        $this->u['hr'] = $this->makeUser('HR Manager', $this->d3, 'Global Hr');

        // "Add an admin, assign him to a department": Department Admin + a D1 grant.
        $this->u['d1_admin'] = $this->makeUser('Department Admin', null, 'Dept Admin');
        UserDepartmentScope::create(['user_id' => $this->u['d1_admin']->employee_id, 'department_id' => $this->d1->id, 'scope_type' => 'admin']);

        // Holds the same permissions but manages nothing: must fail closed.
        $this->u['d2_employee'] = $this->makeUser('Employee', $this->d2, 'Plain Employee');
        $this->u['d2_employee']->givePermissionTo(['users.view', 'users.create', 'users.update', 'employees.view', 'departments.update']);

        $this->u['e1'] = $this->makeUser('Employee', $this->d1, 'Scopetarget One');
        $this->u['e2'] = $this->makeUser('Employee', $this->d2, 'Scopetarget Two');
    }

    private function makeUser(string $role, ?Department $department, string $name): User
    {
        $user = User::factory()->create(['department_id' => $department?->id, 'name' => $name]);
        $user->assignRole($role);

        return $user;
    }

    private function id(string $key): string
    {
        return (string) $this->u[$key]->employee_id;
    }

    /** @param  array<int, string>  $keys */
    private function idsOf(array $keys): array
    {
        return collect($keys)->map(fn (string $k) => $this->id($k))->sort()->values()->all();
    }

    private function sorted(iterable $ids): array
    {
        return collect($ids)->map(fn ($id) => (string) $id)->sort()->values()->all();
    }

    private function hit(string $actor, string $case): TestResponse
    {
        $user = $this->u[$actor];

        if ($case === 'team_members') {
            Sanctum::actingAs($user);

            return $this->getJson('/api/v1/manager/team-members');
        }

        $this->actingAs($user);

        return match ($case) {
            'show_e1' => $this->getJson(route('employees.show', $this->id('e1'))),
            'show_e2' => $this->getJson(route('employees.show', $this->id('e2'))),
            'update_e1' => $this->putJson(route('users.update', $this->id('e1')), ['name' => 'Renamed One']),
            'update_e2' => $this->putJson(route('users.update', $this->id('e2')), ['name' => 'Renamed Two']),
            'move_e1_to_d2' => $this->putJson(route('users.update-department', $this->id('e1')), ['department' => $this->d2->id]),
            'create_in_d1' => $this->postJson(route('users.store'), $this->newUserPayload($this->d1->id)),
            'create_in_d2' => $this->postJson(route('users.store'), $this->newUserPayload($this->d2->id)),
        };
    }

    private function newUserPayload(?int $departmentId): array
    {
        static $n = 0;
        $n++;

        return [
            'name' => "New Hire {$n}",
            'user_name' => "newhire{$n}",
            'email' => "new.hire{$n}@example.com",
            'employee_id' => "NEW-{$n}",
            'password' => 'Str0ng!Passw0rd#2026',
            'password_confirmation' => 'Str0ng!Passw0rd#2026',
            'department_id' => $departmentId,
        ];
    }

    public static function accessMatrix(): array
    {
        return [
            'd1 admin views own-dept employee' => ['d1_admin', 'show_e1', 200],
            'd1 admin cannot view other dept' => ['d1_admin', 'show_e2', 404],
            'd2 employee cannot view d1' => ['d2_employee', 'show_e1', 404],
            'hr views anyone' => ['hr', 'show_e2', 200],

            'd1 admin updates own-dept employee' => ['d1_admin', 'update_e1', 200],
            'd1 admin cannot update other dept' => ['d1_admin', 'update_e2', 403],
            'd2 employee cannot update d1' => ['d2_employee', 'update_e1', 403],
            'hr updates anyone' => ['hr', 'update_e2', 200],

            'd1 admin cannot move into unmanaged dept' => ['d1_admin', 'move_e1_to_d2', 403],
            'd2 employee cannot move anyone' => ['d2_employee', 'move_e1_to_d2', 403],
            'hr moves anyone' => ['hr', 'move_e1_to_d2', 200],

            'd1 admin creates into d1' => ['d1_admin', 'create_in_d1', 201],
            'd1 admin cannot create into d2' => ['d1_admin', 'create_in_d2', 422],
            'd2 employee manages no dept so cannot create' => ['d2_employee', 'create_in_d2', 403],
            'hr creates anywhere' => ['hr', 'create_in_d2', 201],

            'd1 admin has a mobile team' => ['d1_admin', 'team_members', 200],
            'd2 employee is not a mobile manager' => ['d2_employee', 'team_members', 403],
            'hr has a mobile team' => ['hr', 'team_members', 200],
        ];
    }

    #[DataProvider('accessMatrix')]
    public function test_access_matrix(string $actor, string $case, int $expected): void
    {
        $this->hit($actor, $case)->assertStatus($expected);
    }

    public static function visibilityMatrix(): array
    {
        return [
            'd1 admin' => ['d1_admin', ['d1_admin', 'e1']],
            'd2 employee (fail closed)' => ['d2_employee', ['d2_employee']],
            'global hr' => ['hr', ['hr', 'd1_admin', 'd2_employee', 'e1', 'e2']],
        ];
    }

    #[DataProvider('visibilityMatrix')]
    public function test_employee_list_is_scoped(string $actor, array $visible): void
    {
        $response = $this->actingAs($this->u[$actor])
            ->getJson(route('employees.paginate', ['perPage' => 50]))
            ->assertOk();

        $this->assertSame($this->idsOf($visible), $this->sorted($response->json('employees.data.*.employee_id')));
        $this->assertSame(count($visible), $response->json('stats.total'));
        $this->assertSame($this->sorted(array_map(fn ($k) => $this->id($k), $visible)), $this->sorted($response->json('allManagers.*.id')));
    }

    #[DataProvider('visibilityMatrix')]
    public function test_employee_stats_count_only_the_scoped_set(string $actor, array $visible): void
    {
        $this->actingAs($this->u[$actor])
            ->getJson(route('employees.stats'))
            ->assertOk()
            ->assertJsonPath('stats.overview.total_employees', count($visible));
    }

    #[DataProvider('visibilityMatrix')]
    public function test_global_search_is_scoped(string $actor, array $visible): void
    {
        $page = $this->actingAs($this->u[$actor])
            ->get(route('search', ['q' => 'Scopetarget']))
            ->assertOk()
            ->viewData('page');

        $found = collect($page['props']['groups'])->firstWhere('key', 'employees')['items'] ?? [];
        $expected = array_values(array_intersect($visible, ['e1', 'e2']));

        $this->assertSame($this->idsOf($expected), $this->sorted(array_column($found, 'id')));
    }

    #[DataProvider('visibilityMatrix')]
    public function test_managers_list_is_scoped(string $actor, array $visible): void
    {
        $ids = $this->actingAs($this->u[$actor])
            ->getJson(route('users.managers.list'))
            ->assertOk()
            ->json('*.id');

        // Only role-holding managers are listed; every one must be in scope.
        foreach ($ids as $id) {
            $this->assertContains((string) $id, $this->idsOf($visible));
        }
    }

    public function test_mobile_team_matches_web_scope(): void
    {
        Sanctum::actingAs($this->u['d1_admin']);
        $this->assertSame($this->idsOf(['e1']), $this->sorted($this->getJson('/api/v1/manager/team-members')->assertOk()->json('data.*.id')));

        Sanctum::actingAs($this->u['hr']);
        $this->assertSame(
            $this->idsOf(['d1_admin', 'd2_employee', 'e1', 'e2']),
            $this->sorted($this->getJson('/api/v1/manager/team-members')->assertOk()->json('data.*.id')),
        );
    }

    public function test_mobile_team_honours_acting_expiry_at_request_time(): void
    {
        UserDepartmentScope::create(['user_id' => $this->id('d2_employee'), 'department_id' => $this->d1->id, 'scope_type' => 'acting', 'expires_at' => now()->addHour()]);

        Sanctum::actingAs($this->u['d2_employee']);
        $this->assertSame($this->idsOf(['e1']), $this->sorted($this->getJson('/api/v1/manager/team-members')->assertOk()->json('data.*.id')));

        $this->travel(2)->hours();

        $this->getJson('/api/v1/manager/team-members')->assertForbidden();
    }

    public function test_create_defaults_to_the_single_managed_department(): void
    {
        $this->actingAs($this->u['d1_admin'])
            ->postJson(route('users.store'), $this->newUserPayload(null))
            ->assertCreated();

        $this->assertSame($this->d1->id, (int) User::where('email', 'like', 'new.hire%')->latest('created_at')->value('department_id'));
    }

    public function test_two_department_admin_can_move_between_managed_departments(): void
    {
        UserDepartmentScope::create(['user_id' => $this->id('d1_admin'), 'department_id' => $this->d2->id, 'scope_type' => 'acting', 'expires_at' => now()->addWeek()]);

        $this->actingAs($this->u['d1_admin'])
            ->putJson(route('users.update-department', $this->id('e1')), ['department' => $this->d2->id])
            ->assertOk();

        $this->assertSame($this->d2->id, (int) $this->u['e1']->fresh()->department_id);
    }

    public function test_hr_manager_grants_and_revokes_scope_and_access_follows(): void
    {
        $grantee = $this->u['d1_admin'];
        $epochBefore = (int) DB::table('users')->where('employee_id', $grantee->employee_id)->value('sync_epoch');

        $this->actingAs($grantee)->getJson(route('employees.show', $this->id('e2')))->assertNotFound();

        $scopeId = $this->actingAs($this->u['hr'])
            ->postJson(route('users.department-scopes.store', $grantee->employee_id), [
                'department_id' => $this->d2->id,
                'scope_type' => 'acting',
                'expires_at' => now()->addDays(10)->toDateTimeString(),
                'reason' => 'Head on annual leave',
            ])
            ->assertCreated()
            ->assertJsonPath('scope.status', 'active')
            ->json('scope.id');

        $this->assertGreaterThan($epochBefore, (int) DB::table('users')->where('employee_id', $grantee->employee_id)->value('sync_epoch'));

        $this->actingAs($this->u['hr'])
            ->getJson(route('users.department-scopes.index', $grantee->employee_id))
            ->assertOk()
            ->assertJsonCount(2, 'scopes');

        $this->actingAs($grantee)->getJson(route('employees.show', $this->id('e2')))->assertOk();

        $this->actingAs($this->u['hr'])
            ->deleteJson(route('users.department-scopes.destroy', ['id' => $grantee->employee_id, 'scopeId' => $scopeId]))
            ->assertOk();

        $this->assertDatabaseMissing('user_department_scopes', ['id' => $scopeId]);
        $this->actingAs($grantee)->getJson(route('employees.show', $this->id('e2')))->assertNotFound();
    }

    public function test_acting_grant_requires_an_end_date(): void
    {
        $this->actingAs($this->u['hr'])
            ->postJson(route('users.department-scopes.store', $this->id('d1_admin')), [
                'department_id' => $this->d2->id,
                'scope_type' => 'acting',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expires_at']);
    }

    public function test_department_admin_cannot_grant_scopes_even_with_the_permission(): void
    {
        $payload = ['department_id' => $this->d2->id, 'scope_type' => 'admin'];

        $this->actingAs($this->u['d1_admin'])
            ->postJson(route('users.department-scopes.store', $this->id('e1')), $payload)
            ->assertForbidden();

        $this->u['d1_admin']->givePermissionTo('department.scopes.manage');

        $this->actingAs($this->u['d1_admin'])
            ->postJson(route('users.department-scopes.store', $this->id('e1')), $payload)
            ->assertForbidden();
        $this->actingAs($this->u['d1_admin'])
            ->getJson(route('users.department-scopes.index', $this->id('d1_admin')))
            ->assertForbidden();

        $this->assertDatabaseMissing('user_department_scopes', ['user_id' => $this->id('e1')]);
    }

    public function test_cannot_grant_to_someone_who_outranks_you(): void
    {
        $administrator = $this->makeUser('Administrator', $this->d3, 'Company Admin');
        $peerHr = $this->makeUser('HR Manager', $this->d3, 'Peer Hr');

        foreach ([$administrator, $peerHr] as $target) {
            $this->actingAs($this->u['hr'])
                ->postJson(route('users.department-scopes.store', $target->employee_id), [
                    'department_id' => $this->d1->id,
                    'scope_type' => 'admin',
                ])
                ->assertForbidden();
        }
    }

    public function test_department_admin_cannot_edit_a_higher_ranked_user_inside_their_department(): void
    {
        $administratorInD1 = $this->makeUser('Administrator', $this->d1, 'Admin In D1');

        $this->actingAs($this->u['d1_admin'])
            ->putJson(route('users.update', $administratorInD1->employee_id), ['name' => 'Hijacked'])
            ->assertForbidden();
        $this->actingAs($this->u['d1_admin'])
            ->postJson(route('users.changePassword', $administratorInD1->employee_id), [
                'password' => 'An0ther!Passw0rd#1',
                'password_confirmation' => 'An0ther!Passw0rd#1',
            ])
            ->assertForbidden();
    }
}
