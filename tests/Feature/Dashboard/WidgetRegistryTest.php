<?php

namespace Tests\Feature\Dashboard;

use App\Models\DailyWork;
use App\Models\HRM\Attendance;
use App\Models\HRM\Department;
use App\Models\HRM\Leave;
use App\Models\User;
use App\Models\UserDepartmentScope;
use App\Services\Access\DepartmentScope;
use App\Services\Attendance\AttendanceDayPartitionService;
use App\Services\Dashboard\DashboardWidget;
use App\Services\Dashboard\TodaySummary;
use App\Services\Dashboard\WidgetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The widget registry behind both dashboards: placement, permission, scope, cache keys,
 * web/API parity and a query ceiling.
 */
class WidgetRegistryTest extends TestCase
{
    use RefreshDatabase;

    private Department $d1;

    private Department $d2;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Super Administrator' => 1, 'Administrator' => 10, 'HR Manager' => 20, 'Department Admin' => 25, 'Employee' => 60] as $name => $level) {
            Role::updateOrCreate(['name' => $name, 'guard_name' => 'web'], ['hierarchy_level' => $level]);
        }
        foreach (['department.admin', 'core.dashboard.view', 'attendance.own.view', 'leave.own.view', 'attendance.view', 'employees.view', 'daily-works.view', 'hr.offboarding.view', 'hr.onboarding.view', 'quality.ncr.view', 'om.dashboard.view', 'system.monitoring.view', 'leaves.view'] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        [$this->d1, $this->d2] = [Department::factory()->create(), Department::factory()->create()];
    }

    private function employee(Department $department, array $permissions = ['attendance.own.view', 'leave.own.view', 'daily-works.view'], string $role = 'Employee'): User
    {
        $user = User::factory()->create(['department_id' => $department->id, 'is_active' => true]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function registry(): WidgetRegistry
    {
        return app(WidgetRegistry::class);
    }

    /** @return array<int, string> */
    private function keys(array $payload): array
    {
        return array_column($payload['widgets'], 'key');
    }

    public function test_every_widget_lives_on_exactly_one_dashboard(): void
    {
        $widgets = $this->registry()->all();

        $this->assertSame($widgets->count(), $widgets->map->key()->unique()->count(), 'widget keys must be unique');
        $this->assertSame($widgets->count(), $widgets->unique(fn ($w) => $w::class)->count(), 'a widget class is registered once');

        foreach ($widgets as $widget) {
            $this->assertContains($widget->dashboard(), [DashboardWidget::DASHBOARD_EMPLOYEE, DashboardWidget::DASHBOARD_MAIN]);
            // Personal (SECTION_ME) widgets belong to the employee dashboard and only them.
            $this->assertSame($widget->section() === DashboardWidget::SECTION_ME, $widget->dashboard() === DashboardWidget::DASHBOARD_EMPLOYEE, $widget->key());
            $this->assertNotSame([], $widget->permissions(), $widget->key().' declares a permission');
        }

        // Even a viewer holding every permission gets disjoint sets on the two dashboards.
        $root = $this->employee($this->d1, Permission::pluck('name')->all(), 'Administrator');
        $employee = $this->keys($this->registry()->payloadFor($root, 'employee'));
        $main = $this->keys($this->registry()->payloadFor($root, 'main'));
        $this->assertSame([], array_intersect($employee, $main));
        $this->assertNotEmpty($employee);
        $this->assertNotEmpty($main);
    }

    public function test_widgets_come_back_in_priority_order_with_a_span_that_packs_into_twelve_columns(): void
    {
        $root = $this->employee($this->d1, Permission::pluck('name')->all(), 'Administrator');

        foreach (['employee', 'main'] as $dashboard) {
            $widgets = $this->registry()->payloadFor($root, $dashboard)['widgets'];
            $priorities = array_column($widgets, 'priority');
            $sorted = $priorities;
            sort($sorted);
            $this->assertSame($sorted, $priorities, $dashboard.' is ordered by priority');
            foreach ($widgets as $widget) {
                $this->assertContains($widget['span'], [4, 6, 8, 12], $widget['key']);
            }
        }

        $main = array_column($this->registry()->payloadFor($root, 'main')['widgets'], 'key');
        $order = ['team.approvals', 'team.today', 'ops.om', 'team.trend', 'team.department', 'project.ncr', 'main.activity', 'hr.snapshot', 'team.leave_month'];
        $this->assertSame($order, array_values(array_intersect($main, $order)), "the owner's main-dashboard order");
        $this->assertSame('admin.system_health', end($main), 'system health is last');
    }

    public function test_a_viewer_without_permissions_sees_no_widget(): void
    {
        $nobody = $this->employee($this->d1, []);

        $this->assertSame([], $this->keys($this->registry()->payloadFor($nobody, 'employee')));
        $this->assertSame([], $this->keys($this->registry()->payloadFor($nobody, 'main')));
    }

    public function test_employee_only_account_is_redirected_and_gets_employee_widgets_only(): void
    {
        $emp = $this->employee($this->d1, ['attendance.own.view', 'leave.own.view', 'daily-works.view', 'core.dashboard.view']);

        $this->actingAs($emp)->get('/dashboard')->assertRedirect(route('employee-dashboard'));

        $json = $this->actingAs($emp)->getJson('/dashboard/widgets?section=employee')->assertOk()->json();
        $this->assertNotEmpty($json['widgets']);
        $this->assertSame([DashboardWidget::DASHBOARD_EMPLOYEE], array_values(array_unique(array_column($json['widgets'], 'dashboard'))));

        $noMain = $this->employee($this->d1, ['attendance.own.view']);
        $this->actingAs($noMain)->getJson('/dashboard/widgets?section=main')->assertForbidden();
    }

    public function test_self_service_widgets_show_only_the_viewers_own_data(): void
    {
        $me = $this->employee($this->d1);
        $other = $this->employee($this->d1);
        Attendance::create(['user_id' => $other->employee_id, 'date' => now()->toDateString(), 'punchin' => now()]);

        $widget = collect($this->registry()->payloadFor($me, 'employee')['widgets'])->firstWhere('key', 'me.attendance_today');
        $this->assertSame('not_punched', $widget['data']['status']);
    }

    public function test_department_admin_is_department_scoped_and_global_admin_is_company_wide(): void
    {
        $head = $this->employee($this->d1, ['department.admin', 'core.dashboard.view', 'attendance.view', 'employees.view', 'daily-works.view'], 'Department Admin');
        $mate = $this->employee($this->d1);
        $this->employee($this->d2);
        $this->employee($this->d2);

        $stat = fn (User $viewer, string $widget, string $stat) => collect(collect($this->registry()->payloadFor($viewer, 'main')['widgets'])
            ->firstWhere('key', $widget)['data']['stats'])->firstWhere('key', $stat)['value'];

        $this->assertSame(2, $stat($head, 'team.department', 'headcount'), 'head + mate, nobody from the other department');

        $hr = $this->employee($this->d1, ['department.admin', 'core.dashboard.view', 'attendance.view', 'employees.view'], 'HR Manager');
        $this->assertSame(5, $stat($hr, 'team.department', 'headcount'), 'every active employee');
        $this->assertSame(0, $stat($hr, 'hr.snapshot', 'present'), 'nobody has punched in');
        $this->assertNotNull($mate);
    }

    public function test_department_head_of_other_department_never_leaks_across_scope(): void
    {
        $head = $this->employee($this->d1, ['department.admin', 'core.dashboard.view', 'employees.view'], 'Department Admin');
        $outsider = $this->employee($this->d2);
        Leave::query()->insert([
            'user_id' => $outsider->employee_id, 'leave_type' => 1, 'from_date' => now()->toDateString(), 'to_date' => now()->toDateString(),
            'no_of_days' => 1, 'reason' => 'test', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $widgets = collect($this->registry()->payloadFor($head, 'main')['widgets']);
        $onLeave = collect($widgets->firstWhere('key', 'team.department')['data']['stats'])->firstWhere('key', 'on_leave')['value'];
        $this->assertSame(0, $onLeave);
    }

    public function test_cache_keys_are_per_employee_scope_and_section(): void
    {
        $a = $this->employee($this->d1, ['department.admin', 'core.dashboard.view', 'attendance.view'], 'Department Admin');
        $b = $this->employee($this->d1, ['department.admin', 'core.dashboard.view', 'attendance.view'], 'Department Admin');
        $registry = $this->registry();
        $widget = $registry->all()->first(fn ($w) => $w->key() === 'team.department');

        $registry->payloadFor($a, 'main');
        $keyA = $registry->cacheKey($widget, $a);
        $this->assertTrue(Cache::store()->has($keyA));
        $this->assertFalse(Cache::store()->has($registry->cacheKey($widget, $b)), 'another employee has their own entry');
        $this->assertStringContainsString(':main:team.department:'.$a->employee_id.':', $keyA);

        // A new department grant changes the scope fingerprint, hence the key.
        UserDepartmentScope::create(['user_id' => $a->employee_id, 'department_id' => $this->d2->id, 'scope_type' => UserDepartmentScope::TYPE_ADMIN]);
        app(DepartmentScope::class)->forget();
        $this->assertNotSame($keyA, $registry->cacheKey($widget, $a));
    }

    public function test_cache_falls_back_to_file_store_when_default_is_null(): void
    {
        config(['cache.default' => 'null', 'cache.stores.null' => ['driver' => 'null']]);
        $user = $this->employee($this->d1);
        $widget = $this->registry()->all()->first(fn ($w) => $w->key() === 'me.attendance_today');
        $key = $this->registry()->cacheKey($widget, $user);
        Cache::store('file')->forget($key);

        $this->registry()->payloadFor($user, 'employee');

        $this->assertTrue(Cache::store('file')->has($key));
        Cache::store('file')->forget($key);
    }

    public function test_web_and_api_serve_the_same_payload(): void
    {
        $user = $this->employee($this->d1, ['department.admin', 'core.dashboard.view', 'attendance.own.view', 'leave.own.view', 'attendance.view', 'employees.view'], 'Department Admin');

        foreach (['employee', 'main'] as $section) {
            $web = $this->actingAs($user)->getJson("/dashboard/widgets?section={$section}")->assertOk()->json();
            Sanctum::actingAs($user);
            $api = $this->getJson("/api/v1/dashboard?section={$section}")->assertOk()->json('data');

            unset($web['generated_at'], $api['generated_at']);
            $this->assertSame($web, $api, "section {$section}");
        }
    }

    public function test_query_count_does_not_grow_with_headcount(): void
    {
        $hr = $this->employee($this->d1, ['department.admin', 'attendance.view', 'employees.view', 'hr.offboarding.view', 'hr.onboarding.view'], 'HR Manager');
        $count = function () use ($hr): int {
            Cache::flush();
            app(DepartmentScope::class)->forget();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $payload = $this->registry()->payloadFor($hr, 'main');
            $this->assertSame([], array_values(array_filter(array_map(fn ($w) => $w['error'] ? $w['key'] : null, $payload['widgets']))), 'no widget may fail');
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        foreach (range(1, 3) as $i) {
            $this->employee($this->d1);
        }
        $count(); // warm Spatie's per-request role/permission caches
        $small = $count();
        foreach (range(1, 30) as $i) {
            $u = $this->employee($i % 2 ? $this->d1 : $this->d2);
            Attendance::create(['user_id' => $u->employee_id, 'date' => now()->toDateString(), 'punchin' => now()]);
        }
        $large = $count();

        $this->assertSame($small, $large, 'queries must not scale with the number of employees');
        $this->assertLessThan(80, $large);
    }

    public function test_today_summary_matches_the_attendance_day_partition(): void
    {
        $people = collect(range(1, 4))->map(fn () => $this->employee($this->d1));
        Attendance::create(['user_id' => $people[0]->employee_id, 'date' => now()->toDateString(), 'punchin' => now()]);
        Leave::query()->insert([
            'user_id' => $people[1]->employee_id, 'leave_type' => 1, 'from_date' => now()->toDateString(), 'to_date' => now()->toDateString(),
            'no_of_days' => 1, 'reason' => 'test', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ids = $people->pluck('employee_id')->all();
        $partition = app(AttendanceDayPartitionService::class)->partition(now()->toDateString(), null, $ids)['counts'];
        $today = app(TodaySummary::class)->summarize(User::query()->whereIn('users.employee_id', $ids));

        $this->assertSame($partition['present'], $today['present']);
        $this->assertSame(1, $today['on_leave']);
        $this->assertSame($partition['total'], $today['total']);
    }

    public function test_each_domain_widget_is_gated_by_its_own_permission(): void
    {
        $expect = [
            'quality.ncr.view' => ['project.ncr', 'main.activity'],
            'om.dashboard.view' => ['ops.om'],
            'system.monitoring.view' => ['admin.system_health'],
            'employees.view' => ['hr.snapshot'],
            'department.admin' => ['team.department'],
            'attendance.view' => ['team.today', 'team.trend'],
        ];

        foreach ($expect as $permission => $widgetKeys) {
            $viewer = $this->employee($this->d1, [$permission]);
            $payload = $this->registry()->payloadFor($viewer, 'main');
            $this->assertSame($widgetKeys, $this->keys($payload), $permission.' (in priority order)');
            foreach ($payload['widgets'] as $widget) {
                $this->assertNull($widget['error'], $widget['key'].' computes on real tables');
                $this->assertNotNull($widget['as_of'], 'every widget states how fresh it is');
            }
        }
    }

    public function test_daily_works_widget_is_scoped_to_the_viewers_own_and_visible_work(): void
    {
        $me = $this->employee($this->d1);
        $other = $this->employee($this->d2);
        DailyWork::factory()->create(['incharge' => $me->employee_id, 'assigned' => $me->employee_id, 'status' => 'new', 'date' => now()->toDateString()]);
        DailyWork::factory()->count(2)->create(['incharge' => $other->employee_id, 'assigned' => $other->employee_id, 'status' => 'new', 'date' => now()->toDateString()]);

        $open = fn (User $viewer) => collect(collect($this->registry()->payloadFor($viewer, 'main')['widgets'])
            ->firstWhere('key', 'project.daily_works')['data']['stats'])->firstWhere('key', 'open')['value'];

        $this->assertSame(1, $open($me));

        $admin = $this->employee($this->d1, ['daily-works.view'], 'Administrator');
        $this->assertSame(3, $open($admin));
    }
}
