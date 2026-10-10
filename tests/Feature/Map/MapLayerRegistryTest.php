<?php

namespace Tests\Feature\Map;

use App\Models\DailyWork;
use App\Models\HRM\Attendance;
use App\Models\HRM\Department;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Dashboard\WidgetRegistry;
use App\Services\Map\MapFilter;
use App\Services\Map\MapLayer;
use App\Services\Map\MapLayerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** The map layer registry: permission gates, scope, empty layers hidden, payload contract, web/API parity. */
class MapLayerRegistryTest extends TestCase
{
    use RefreshDatabase;

    private Department $d1;

    private Department $d2;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Cache::flush();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['Super Administrator' => 1, 'Administrator' => 10, 'HR Manager' => 20, 'Department Admin' => 25, 'Employee' => 60] as $name => $level) {
            Role::updateOrCreate(['name' => $name, 'guard_name' => 'web'], ['hierarchy_level' => $level]);
        }
        foreach (['core.dashboard.view', 'attendance.view', 'daily-works.view', 'om.maintenance.view', 'om.incidents.view', 'department.admin', 'employees.view', 'jurisdiction.view'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        [$this->d1, $this->d2] = [Department::factory()->create(), Department::factory()->create()];
    }

    private function person(Department $department, array $permissions = [], string $role = 'Employee'): User
    {
        $user = User::factory()->create(['department_id' => $department->id, 'is_active' => true]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function registry(): MapLayerRegistry
    {
        return app(MapLayerRegistry::class);
    }

    /** @return array<int, string> */
    private function keys(User $viewer, ?MapFilter $filter = null): array
    {
        return array_column($this->registry()->build($viewer, $filter ?? MapFilter::today())['layers'], 'key');
    }

    private function defect(array $overrides = []): void
    {
        DB::table('om_defects')->insert($overrides + [
            'defect_number' => 'DEF-T-'.uniqid(), 'title' => 'Crack at K4+100', 'distress_type' => 'pothole', 'chainage' => 'K4+100', 'direction' => 'northbound',
            'severity' => 'high', 'status' => 'reported', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_every_layer_declares_its_contract(): void
    {
        $valid = [MapLayer::POINT, MapLayer::CHAINAGE_POINT, MapLayer::CHAINAGE_BAND, MapLayer::GEOFENCE_CIRCLE, MapLayer::POLYGON, MapLayer::ROUTE];
        $keys = [];
        foreach ($this->registry()->all() as $layer) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $layer->key());
            $this->assertNotContains($layer->key(), $keys, 'layer keys are unique');
            $keys[] = $layer->key();
            $this->assertArrayHasKey($layer->group(), MapLayerRegistry::GROUPS);
            $this->assertNotEmpty($layer->label());
            $this->assertNotEmpty($layer->permissions(), $layer->key().' needs a permission gate');
            $this->assertNotEmpty($layer->tables(), $layer->key().' declares its source tables');
            $this->assertNotEmpty($layer->fields());
            $this->assertNotEmpty(array_intersect($layer->geometry(), $valid));
            $this->assertContains($layer->scope(), [MapLayer::SCOPE_PERMISSION, MapLayer::SCOPE_DEPARTMENT]);
            $this->assertContains($layer->timeMode(), [MapLayer::TIME_NONE, MapLayer::TIME_PERIOD, MapLayer::TIME_OPEN]);
        }
    }

    public function test_a_layer_needs_its_own_permission(): void
    {
        $this->defect();
        $without = $this->person($this->d1, ['core.dashboard.view']);
        $with = $this->person($this->d1, ['core.dashboard.view', 'om.maintenance.view']);

        $this->assertNotContains('om.defects', $this->keys($without));
        $this->assertContains('om.defects', $this->keys($with));
        $this->assertNotContains('om.incidents', $this->keys($with));
    }

    public function test_a_revoked_permission_drops_the_layer_at_once(): void
    {
        $this->defect();
        $user = $this->person($this->d1, ['core.dashboard.view', 'om.maintenance.view']);
        $this->assertContains('om.defects', $this->keys($user));

        $user->revokePermissionTo('om.maintenance.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertNotContains('om.defects', $this->keys($user->fresh()));
    }

    public function test_empty_layers_are_hidden_and_counted(): void
    {
        $viewer = $this->person($this->d1, ['core.dashboard.view', 'om.maintenance.view', 'om.incidents.view']);
        $payload = $this->registry()->build($viewer, MapFilter::today());
        $this->assertNotContains('om.defects', array_column($payload['layers'], 'key'));
        $this->assertNotContains('om.incidents', array_column($payload['layers'], 'key'));
        $this->assertGreaterThan(0, $payload['summary']['empty']);

        $this->defect();
        $this->assertContains('om.defects', $this->keys($viewer));
    }

    public function test_chainages_are_placed_on_the_alignment_and_unreadable_ones_are_counted_not_guessed(): void
    {
        $this->defect(['chainage' => 'K4+100 to 4+320']);
        $this->defect(['chainage' => 'near the culvert']);
        $viewer = $this->person($this->d1, ['core.dashboard.view', 'om.maintenance.view']);

        $layer = collect($this->registry()->build($viewer, MapFilter::today())['layers'])->firstWhere('key', 'om.defects');
        $this->assertSame(1, $layer['count']);
        $this->assertSame(1, $layer['unplaced']);
        $band = $layer['features'][0];
        $this->assertSame('band', $band['shape']);
        $this->assertSame([4100, 4320], [$band['from_m'], $band['to_m']]);
        $this->assertGreaterThanOrEqual(2, count($band['path']));
        $this->assertEqualsWithDelta(23.97, $band['lat'], 0.05);
    }

    public function test_daily_works_follow_department_scope(): void
    {
        $mine = $this->person($this->d1, ['core.dashboard.view', 'daily-works.view'], 'Department Admin');
        $mine->givePermissionTo('department.admin');
        $theirs = $this->person($this->d2, ['daily-works.view']);
        $day = now()->toDateString();
        DailyWork::factory()->create(['incharge' => $mine->employee_id, 'assigned' => $mine->employee_id, 'date' => $day, 'location' => 'K10+000-K10+100']);
        DailyWork::factory()->create(['incharge' => $theirs->employee_id, 'assigned' => $theirs->employee_id, 'date' => $day, 'location' => 'K20+000-K20+100']);

        $layer = collect($this->registry()->build($mine, MapFilter::today())['layers'])->firstWhere('key', 'works.daily_works');
        $this->assertSame(1, $layer['count']);

        $admin = $this->person($this->d1, ['core.dashboard.view', 'daily-works.view'], 'Administrator');
        $this->assertSame(2, collect($this->registry()->build($admin, MapFilter::today())['layers'])->firstWhere('key', 'works.daily_works')['count']);
    }

    public function test_attendance_locations_are_scoped_and_an_employee_never_sees_others(): void
    {
        $day = now()->toDateString();
        $location = json_encode(['lat' => 23.93, 'lng' => 90.45, 'address' => 'Mirer Bazar']);
        $viewer = $this->person($this->d1, ['core.dashboard.view', 'attendance.view']);
        $colleague = $this->person($this->d1, []);
        $stranger = $this->person($this->d2, []);
        foreach ([$viewer, $colleague, $stranger] as $u) {
            Attendance::create(['user_id' => $u->employee_id, 'date' => $day, 'punchin' => now(), 'punchin_location' => $location]);
        }

        $ids = fn (User $v) => collect(collect($this->registry()->build($v, MapFilter::today())['layers'])->firstWhere('key', 'workforce.punches')['features'] ?? [])
            ->pluck('person.employee_id')->all();

        $this->assertSame([(string) $viewer->employee_id], $ids($viewer), 'a plain employee sees only themselves, never a colleague or another department');

        $admin = $this->person($this->d1, ['core.dashboard.view', 'attendance.view', 'department.admin'], 'Department Admin');
        Attendance::create(['user_id' => $admin->employee_id, 'date' => $day, 'punchin' => now(), 'punchin_location' => $location]);
        $this->assertEqualsCanonicalizing([(string) $viewer->employee_id, (string) $colleague->employee_id, (string) $admin->employee_id], $ids($admin));
        $this->assertNotContains((string) $stranger->employee_id, $ids($admin));

        $global = $this->person($this->d1, ['core.dashboard.view', 'attendance.view'], 'Administrator');
        $this->assertContains((string) $stranger->employee_id, $ids($global));
    }

    public function test_attendance_popup_content_matches_the_timesheet_map(): void
    {
        $viewer = $this->person($this->d1, ['core.dashboard.view', 'attendance.view'], 'Administrator');
        Attendance::create(['user_id' => $viewer->employee_id, 'date' => now()->toDateString(), 'punchin' => now()->setTime(7, 23), 'punchin_location' => json_encode(['lat' => 23.93, 'lng' => 90.45, 'address' => 'Mirer Bazar'])]);

        $feature = collect($this->registry()->build($viewer, MapFilter::today())['layers'])->firstWhere('key', 'workforce.punches')['features'][0];
        $person = $feature['person'];
        foreach (['employee_id', 'name', 'designation', 'department', 'photo', 'status', 'punch_in', 'punch_out', 'timesheet', 'attendance_type', 'date', 'cycles'] as $key) {
            $this->assertArrayHasKey($key, $person);
        }
        $this->assertSame('active', $person['status']);
        $this->assertSame('Mirer Bazar', $person['punch_in']['address']);
        $this->assertSame('07:23:00', $person['punch_in']['time']);
    }

    public function test_payload_contract(): void
    {
        $this->defect();
        $viewer = $this->person($this->d1, ['core.dashboard.view', 'om.maintenance.view', 'jurisdiction.view']);
        $payload = $this->registry()->build($viewer, MapFilter::today());

        foreach (['generated_at', 'filter', 'alignment', 'groups', 'layers', 'summary'] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }
        $this->assertGreaterThan(1, count($payload['alignment']['points']));
        $this->assertCount(3, $payload['alignment']['points'][0]);
        $this->assertSame(47611, $payload['alignment']['length_m']);
        foreach (['key', 'label', 'captured_on', 'attribution', 'chainage_basis'] as $key) {
            $this->assertArrayHasKey($key, $payload['alignment']['source']);
        }
        foreach ($payload['layers'] as $layer) {
            foreach (['key', 'label', 'group', 'geometry', 'tone', 'time_mode', 'scope', 'fields', 'source', 'count', 'unplaced', 'truncated', 'href', 'features'] as $key) {
                $this->assertArrayHasKey($key, $layer);
            }
            $this->assertNotEmpty($layer['features'], 'empty layers are never sent');
            foreach ($layer['features'] as $f) {
                $this->assertArrayHasKey('id', $f);
                $this->assertArrayHasKey('shape', $f);
                $this->assertContains($f['shape'], ['point', 'band', 'circle', 'polygon', 'route']);
                $this->assertArrayHasKey('fields', $f);
            }
        }
        foreach (['registered', 'permitted', 'with_data', 'empty', 'excluded'] as $key) {
            $this->assertArrayHasKey($key, $payload['summary']);
        }
    }

    public function test_the_widget_is_registered_on_the_main_dashboard_and_the_api_serves_it(): void
    {
        $viewer = $this->person($this->d1, ['core.dashboard.view', 'jurisdiction.view']);
        $payload = app(WidgetRegistry::class)->payloadFor($viewer, 'main');
        $widget = collect($payload['widgets'])->firstWhere('key', 'ops.corridor_map');
        $this->assertNotNull($widget);
        $this->assertSame('main', $widget['dashboard']);
        $this->assertSame('corridor_map', $widget['type']);
        $this->assertSame(12, $widget['span']);
        $this->assertSame(0, $widget['priority']);
        $this->assertSame('corridor.structures', $widget['data']['layers'][0]['key']);

        Sanctum::actingAs($viewer);
        $api = collect($this->getJson('/api/v1/dashboard?section=main')->assertOk()->json('data.widgets'))->firstWhere('key', 'ops.corridor_map');
        $this->assertNotNull($api);

        $employeeOnly = $this->person($this->d1, []);
        $this->assertNull(collect(app(WidgetRegistry::class)->payloadFor($employeeOnly, 'main')['widgets'])->firstWhere('key', 'ops.corridor_map'));
    }

    public function test_the_map_endpoint_filters_by_date_and_needs_the_dashboard_permission(): void
    {
        $viewer = $this->person($this->d1, ['core.dashboard.view', 'daily-works.view'], 'Administrator');
        DailyWork::factory()->create(['incharge' => $viewer->employee_id, 'date' => '2026-06-05', 'location' => 'K10+000-K10+100']);

        $this->actingAs($viewer)->getJson(route('dashboard.map'))->assertOk()
            ->assertJsonMissing(['key' => 'works.daily_works']);
        $response = $this->actingAs($viewer)->getJson(route('dashboard.map', ['from' => '2026-06-01', 'to' => '2026-06-30']))->assertOk();
        $this->assertContains('works.daily_works', array_column($response->json('layers'), 'key'));
        $this->assertSame('2026-06-01', $response->json('filter.from'));

        $this->actingAs($viewer)->getJson(route('dashboard.map', ['from' => 'not-a-date']))->assertStatus(422);
        $this->actingAs($this->person($this->d1, []))->getJson(route('dashboard.map'))->assertForbidden();
    }

    public function test_every_table_with_a_position_is_a_layer_or_excluded_with_a_reason(): void
    {
        $covered = $this->registry()->coveredTables();
        $excluded = MapLayerRegistry::EXCLUDED;
        $uncovered = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            $columns = Schema::getColumnListing($name);
            $positional = array_filter($columns, fn (string $c): bool => preg_match('/(^|_)(lat|lng|latitude|longitude)$|chainage|geofence|(^|_)locations?(_|$)|_location$/', $c) === 1);
            if ($positional !== [] && ! in_array($name, $covered, true) && ! array_key_exists($name, $excluded)) {
                $uncovered[$name] = array_values($positional);
            }
        }
        $this->assertSame([], $uncovered, 'These tables have a coordinate, chainage, geofence or location column but are neither a map layer nor excluded with a reason in MapLayerRegistry::EXCLUDED');

        foreach ($excluded as $table => $reason) {
            $this->assertGreaterThan(20, strlen($reason), "{$table} needs a written reason");
        }
    }

    public function test_layers_have_scope_strategy_for_employee_owned_data(): void
    {
        foreach (['workforce.punches', 'workforce.roster', 'workforce.assigned', 'works.daily_works'] as $key) {
            $layer = $this->registry()->all()->first(fn (MapLayer $l) => $l->key() === $key);
            $this->assertSame(MapLayer::SCOPE_DEPARTMENT, $layer->scope(), "{$key} holds employee-owned rows");
        }
        $this->assertInstanceOf(DepartmentScope::class, app(DepartmentScope::class));
    }
}
