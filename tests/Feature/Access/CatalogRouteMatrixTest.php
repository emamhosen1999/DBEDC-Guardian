<?php

namespace Tests\Feature\Access;

use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Access\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\TestCase;

/**
 * ROUTE AUTHORIZATION MATRIX for EVERY catalog role (deny by default, OWASP A01), the sibling of
 * DepartmentAdminRouteMatrixTest. Each role's holder (Employee + the role, in department D1) drives every registered
 * route x method with D2 ids and a mass-assignment payload full of escalation fields. A route whose permission or role
 * middleware he does not satisfy is FORBIDDEN and must never answer 2xx; and, for every route:
 *
 *   - nothing answers 5xx (a few sandbox-only exceptions are listed below);
 *   - the access tables (roles, permissions, their pivots, department scopes, feature flags) never change: since
 *     owner decision O-15 no role but the Super Administrator can alter them, Administrator and HR Manager included;
 *   - a non-global role never changes or reads another department's canary records.
 *
 * Super Administrator (Gate::before opens everything) and Department Admin (its own matrix) are not driven here.
 */
class CatalogRouteMatrixTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;

    /** Routes never driven: device protocols, websocket auth, framework/health, session mutators. */
    private const SKIP = ['#^iclock/#', '#^broadcasting/#', '#^sanctum/#', '#^_ignition#', '#^up$#', '#^horizon#', '#^telescope#', '#^pulse#', '#^logout$#'];

    /** 5xx that exist for EVERY actor because the sandbox lacks the integration (Stripe key, tenancy tables, MySQL-only SQL). */
    private const ENVIRONMENT_5XX = [
        '#^stripe/#', '#^tenancy/#', '#^verify-email#', '#^user/confirm-password#', '#^user/confirmed-password-status#',
        '#^api/log-error$#', '#^api/log-performance$#', '#^leaves/analytics$#', '#^attendance/export/pdf$#',
    ];

    /** Authenticated-only routes (no permission middleware): self-service or guarded in the controller (same list as the Department Admin matrix). */
    private const GUARDED = [
        '#^user/#', '#^api/(user|notifications|notification-token|log-error|log-performance)#', '#^(notifications|settings/notifications)#',
        '#^(verify-email|email/verification-notification)#', '#^(firebase/token|employee-dashboard|account/password|my-devices|security/dashboard)#',
        '#^aeon#', '#^petty-cash#', '#^search$#', '#^api/(designations|departments)/list$#', '#^api/users/managers/list$#', '#^api/v1/#',
    ];

    /**
     * Routes a role IS allowed to call that answer 5xx to the hostile call (unknown or foreign id, payload without its
     * required fields, a table the sandbox lacks). None is an authorization decision: the role is permitted, the
     * controller then fails with 500 where it should answer 404/422. Found by this matrix, listed so the matrix stays a
     * regression guard; each is a defect to fix, and none may appear on a call the role is FORBIDDEN to make.
     * "METHOD uri" => why.
     *
     * @var array<string, string>
     */
    private const KNOWN_PERMITTED_5XX = [
        'DELETE delete-daily-work' => 'unknown id: ModelNotFound surfaces as 500',
        'POST update-daily-work' => 'unknown id: ModelNotFound surfaces as 500',
        'POST daily-works/assign' => 'validation failure surfaces as 500',
        'POST daily-works/assigned' => 'validation failure surfaces as 500',
        'POST daily-works/incharge' => 'validation failure surfaces as 500',
        'POST daily-works/completion-time' => 'validation failure surfaces as 500',
        'POST daily-works/submission-time' => 'validation failure surfaces as 500',
        'GET daily-works/export-objected-rfis' => 'MySQL-only HAVING clause (sqlite sandbox)',
        'GET daily-works-unified' => 'sqlite sandbox (MySQL-only SQL)',
        'POST daily-works-summary/analytics' => 'sqlite sandbox (MySQL-only SQL)',
        'POST daily-works-summary/export-excel' => 'sqlite sandbox (MySQL-only SQL)',
        'POST daily-works-summary/export-pdf' => 'sqlite sandbox (MySQL-only SQL)',
        'GET dashboard/command' => 'sqlite sandbox (MySQL-only SQL)',
        'GET quality/ncr' => 'sqlite sandbox',
        'GET om/analytics' => 'sqlite sandbox (MySQL-only SQL)',
        'GET api/v1/om/analytics' => 'sqlite sandbox (MySQL-only SQL)',
        'POST om/inspections' => 'validation failure surfaces as 500',
        'POST api/v1/om/field/inspections' => 'validation failure surfaces as 500',
        'POST api/v1/om/field/incidents/create' => 'validation failure surfaces as 500',
        'GET letters-paginate' => 'the letters table does not exist in the sandbox',
        'PUT letters-update' => 'the letters table does not exist in the sandbox',
        'GET profile/{user}/stats' => 'sandbox table missing',
        'DELETE delete-leave-type/{id}' => 'unknown id answers 500',
        'PATCH attendance/{id}/status' => 'unknown id answers 500',
        'POST attendance/overtime/{id}/approve' => 'unknown id answers 500',
        'POST api/v1/attendance/overtime/{id}/approve' => 'unknown id answers 500',
        'GET settings/biometric-devices/download-sessions/{id}/logs' => 'unknown id answers 500',
        'GET api/roles/{id}' => 'Role::findById() called on a query builder (defect in RoleController::show)',
        'GET tasks-all' => 'User::role(\'Supervision Engineer\') throws RoleDoesNotExist (docs section 2.4)',
    ];

    /**
     * Routes a non-global role may call that DO show another department's canary data today. Listed, not hidden: each is a
     * finding for Phase B, and a new entry here needs the same scrutiny as a new permission.
     *
     * @var array<string, string>
     */
    private const KNOWN_LEAKS = [
        'GET departments' => 'a department is a company directory entry (its name), readable by whoever holds departments.view; its people and records are what is protected',
        'GET api/departments' => 'same: the department directory',
        'GET departments/{id}' => 'same: the department directory',
        'GET daily-works-unified' => 'FINDING (not in the catalog document): the Daily Works page props carry EVERY employee (name, department, designation) for the incharge / assigned pickers, to any daily-works.view holder; Phase B scopes it with DepartmentScope',
    ];

    private const ACCESS_TABLES = ['roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'user_department_scopes', 'feature_flags'];

    private array $existingUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class]);
        // The matrix judges authorization, never the network: outbound calls (camera WHEP/HLS through the
        // Cloudflare tunnel) are faked, and the ONVIF snapshot (raw cURL) is served from its micro-cache.
        Http::preventStrayRequests();
        Http::fake();
        Cache::put('cctv_frame_live_main', 'jpeg', 60);
        Cache::put('cctv_frame_live_sub', 'jpeg', 60);
        $this->buildWorld();
        $this->existingUserIds = User::withTrashed()->pluck('employee_id')->map(fn ($id) => (string) $id)->all();
    }

    /** @return array<string, array{0: string}> */
    public static function catalogRoles(): array
    {
        $roles = [];
        foreach (array_keys(RoleCatalog::definitions()) as $role) {
            if (! in_array($role, ['Super Administrator', 'Department Admin'], true)) {
                $roles[$role] = [$role];
            }
        }

        return $roles;
    }

    /** @return array<int, array{route: RoutingRoute, method: string}> */
    private function entries(): array
    {
        $entries = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (collect(self::SKIP)->contains(fn ($pattern) => preg_match($pattern, $route->uri()))) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if (! in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    $entries[] = ['route' => $route, 'method' => $method];
                }
            }
        }

        return $entries;
    }

    /** @return array<int, string> */
    private function middleware(RoutingRoute $route): array
    {
        return collect($route->gatherMiddleware())->map(fn ($m) => is_string($m) ? $m : 'closure')->values()->all();
    }

    private function isPublic(array $middleware): bool
    {
        return ! collect($middleware)->contains(fn ($m) => $m === 'auth' || str_starts_with($m, 'auth:') || $m === 'verified');
    }

    /** @return string PUBLIC | FORBIDDEN | PERMITTED | GUARDED | UNCLASSIFIED */
    private function classify(RoutingRoute $route, User $actor): string
    {
        $middleware = $this->middleware($route);
        if ($this->isPublic($middleware)) {
            return 'PUBLIC';
        }

        $verdicts = [];
        foreach ($middleware as $m) {
            if (preg_match('/^(permission|role_or_permission|custom_permission):(.+)$/', $m, $match)) {
                $held = collect(preg_split('/[|,]/', $match[2]))->contains(fn ($p) => $actor->checkPermissionTo(trim($p)));
                $verdicts[] = $held ? 'PERMITTED' : 'FORBIDDEN';
            } elseif (preg_match('/^role:(.+)$/', $m, $match)) {
                $verdicts[] = $actor->hasAnyRole(preg_split('/[|,]/', $match[1])) ? 'PERMITTED' : 'FORBIDDEN';
            } elseif ($m === 'scope.global') {
                $verdicts[] = $actor->hasRole(DepartmentScope::GLOBAL_ROLES) ? 'PERMITTED' : 'FORBIDDEN';
            }
        }
        if (in_array('FORBIDDEN', $verdicts, true)) {
            return 'FORBIDDEN';
        }
        if ($verdicts !== []) {
            return 'PERMITTED';
        }

        return collect(self::GUARDED)->contains(fn ($pattern) => preg_match($pattern, $route->uri())) ? 'GUARDED' : 'UNCLASSIFIED';
    }

    private function url(RoutingRoute $route, string $value): string
    {
        $uri = preg_replace('/\{[^}]+\?\}/', '', $route->uri());

        return '/'.trim(preg_replace('/\{[^}]+\}/', $value, $uri), '/');
    }

    private function hostilePayload(): array
    {
        return [
            'name' => 'Hostile', 'roles' => ['Super Administrator'], 'role' => 'Super Administrator', 'permissions' => ['roles.update', 'users.view'],
            'permission' => 'roles.update', 'salary_amount' => 1, 'department_id' => 90001, 'department' => 90001, 'employee_id' => '90001',
            'user_id' => '90001', 'employee' => '90001', 'report_to' => '90001', 'is_admin' => true, 'hierarchy_level' => 1, 'scope_type' => 'admin',
            'default_roles' => ['Quality Manager'], 'password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026',
            'status' => 'Approved', 'approved' => true, 'user_ids' => ['90001'], 'ids' => [90001],
        ];
    }

    /** @return array<string, string> hash per protected table, plus everything D2 owns */
    private function snapshot(): array
    {
        $hash = fn ($rows) => md5(json_encode($rows));
        $table = fn (string $name, callable $q) => Schema::hasTable($name) ? $hash($q(DB::table($name))->get()) : 'n/a';
        $d2 = ['90001', '90002', '90003'];
        $snapshot = [];
        foreach (self::ACCESS_TABLES as $name) {
            $snapshot[$name] = $table($name, fn ($q) => $q->orderBy(DB::table($name)->getGrammar() ? (Schema::hasColumn($name, 'id') ? 'id' : Schema::getColumnListing($name)[0]) : 'id'));
        }

        return $snapshot + [
            'd2_users' => $table('users', fn ($q) => $q->whereIn('employee_id', $d2)->orderBy('employee_id')),
            'd2_department' => $table('departments', fn ($q) => $q->where('id', 90001)),
            'd2_designations' => $table('designations', fn ($q) => $q->where('department_id', 90001)->orderBy('id')),
            'd2_shifts' => $table('shifts', fn ($q) => $q->where('department_id', 90001)->orderBy('id')),
            'd2_roster' => $table('roster_days', fn ($q) => $q->whereIn('user_id', $d2)->orderBy('id')),
            'd2_leaves' => $table('leaves', fn ($q) => $q->whereIn('user_id', $d2)->orderBy('id')),
            'd2_attendances' => $table('attendances', fn ($q) => $q->whereIn('user_id', $d2)->orderBy('id')),
            'd2_overtime' => $table('overtime_requests', fn ($q) => $q->whereIn('user_id', $d2)->orderBy('id')),
            'd2_assets' => $table('assets', fn ($q) => $q->whereIn('assignee_id', $d2)->orderBy('id')),
            'd2_onboardings' => $table('onboardings', fn ($q) => $q->whereIn('employee_id', $d2)->orderBy('id')),
            'd2_offboardings' => $table('offboardings', fn ($q) => $q->whereIn('employee_id', $d2)->orderBy('id')),
            'd2_petty' => $table('petty_cash_loans', fn ($q) => $q->whereIn('user_id', $d2)->orderBy('id')),
        ];
    }

    /** @return array{status: int, body: string, changed: array<int, string>} */
    private function drive(User $actor, RoutingRoute $route, string $method, string $url): array
    {
        $sanctum = collect($this->middleware($route))->contains(fn ($m) => str_starts_with($m, 'auth:sanctum'));
        $sanctum ? Sanctum::actingAs($actor, ['*']) : $this->actingAs($actor);

        DB::beginTransaction();
        $before = $this->snapshot();
        try {
            $response = $this->json($method, $url, $method !== 'GET' ? $this->hostilePayload() : ['department_id' => 90001, 'user_id' => '90001', 'employee_id' => '90001']);
            $status = $response->getStatusCode();
            $body = (string) ($response->baseResponse instanceof BinaryFileResponse ? '' : $response->getContent());
        } catch (\Throwable $e) {
            $status = 599;
            $body = 'EXCEPTION '.get_class($e).': '.$e->getMessage();
        }
        $after = $this->snapshot();
        DB::rollBack();

        return ['status' => $status, 'body' => $body, 'changed' => array_keys(array_filter($before, fn ($hash, $key) => $hash !== $after[$key], ARRAY_FILTER_USE_BOTH))];
    }

    #[DataProvider('catalogRoles')]
    public function test_the_role_matrix_holds(string $role): void
    {
        $actor = $this->person('Catalog '.$role, $this->d1, array_values(array_unique(['Employee', $role])), '9'.str_pad((string) abs(crc32($role) % 1000), 3, '0', STR_PAD_LEFT));
        $isGlobal = $actor->hasRole(DepartmentScope::GLOBAL_ROLES);
        $rows = 0;
        $problems = [];
        $reached = ['FORBIDDEN' => 0, 'PERMITTED' => 0, 'GUARDED' => 0];

        foreach ($this->entries() as ['route' => $route, 'method' => $method]) {
            $class = $this->classify($route, $actor);
            if ($class === 'PUBLIC') {
                continue;
            }
            $uri = $route->uri();
            $url = str_contains($uri, '{') ? $this->url($route, $this->d2Ids['user']) : '/'.ltrim($uri, '/');
            $result = $this->drive($actor, $route, $method, $url);
            $rows++;
            $reached[$class] = ($reached[$class] ?? 0) + 1;

            $violations = [];
            $key = "{$method} {$uri}";
            $knownWhenPermitted = in_array($class, ['PERMITTED', 'GUARDED'], true) && isset(self::KNOWN_PERMITTED_5XX[$key]);
            if ($result['status'] >= 500 && ! $knownWhenPermitted && ! collect(self::ENVIRONMENT_5XX)->contains(fn ($p) => preg_match($p, $uri))) {
                $violations[] = "5xx ({$result['status']}) ".substr(preg_replace('/\s+/', ' ', strip_tags($result['body'])), 0, 120);
            }
            if (in_array($class, ['FORBIDDEN', 'UNCLASSIFIED'], true) && $result['status'] >= 200 && $result['status'] < 300) {
                $violations[] = "2xx ({$result['status']}) for a ".strtolower($class).' call';
            }
            $accessChanged = array_intersect($result['changed'], self::ACCESS_TABLES);
            if ($accessChanged !== []) {
                $violations[] = 'ACCESS TABLES CHANGED '.implode(',', $accessChanged);
            }
            if ($class === 'FORBIDDEN' && $result['changed'] !== []) {
                $violations[] = 'CHANGED '.implode(',', $result['changed']);
            }
            if (! $isGlobal && array_filter($result['changed'], fn ($t) => str_starts_with($t, 'd2_'))) {
                $violations[] = 'D2 CHANGED '.implode(',', $result['changed']);
            }
            if (! $isGlobal && ! isset(self::KNOWN_LEAKS[$key]) && stripos($result['body'], self::MARKER) !== false) {
                $violations[] = 'LEAK';
            }

            foreach ($violations as $violation) {
                $problems[] = "{$violation}: {$method} {$uri} [{$class}]";
            }
        }

        $this->assertGreaterThan(300, $rows, 'the matrix covers the whole route table');
        $this->assertGreaterThan(0, $reached['FORBIDDEN'], "{$role} is denied something");
        $this->assertSame([], $problems, "{$role}: ".count($problems)." matrix violation(s)\n".implode("\n", array_slice($problems, 0, 40)));
    }

    // ── access administration is the Super Administrator's alone (O-15) ────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function nonSuperRoles(): array
    {
        return ['Administrator' => ['Administrator'], 'HR Manager' => ['HR Manager'], 'Department Manager' => ['Department Manager']];
    }

    #[DataProvider('nonSuperRoles')]
    public function test_access_administration_is_closed_to_every_role_but_the_super_administrator(string $role): void
    {
        $actor = $this->person('Admin '.$role, null, ['Employee', $role]);
        $target = $this->e1;
        $before = $target->fresh()->roles->pluck('name')->sort()->values()->all();
        $payloads = [
            ['postJson', route('users.updateRole', $target->employee_id), ['roles' => ['HR Manager']]],
            ['postJson', route('users.bulk.role'), ['user_ids' => [(string) $target->employee_id], 'role' => 'Administrator']],
            ['postJson', '/api/users/'.$target->employee_id.'/permissions/give', ['permission' => 'roles.update']],
            ['postJson', '/api/users/'.$target->employee_id.'/permissions', ['permissions' => ['users.view']]],
            ['postJson', route('users.department-scopes.store', $target->employee_id), ['department_id' => $this->d2->id, 'scope_type' => 'admin']],
            ['postJson', route('admin.roles.store'), ['name' => 'Evil']],
            ['postJson', '/api/roles/1/permissions/sync', ['permissions' => ['roles.update']]],
            ['putJson', route('departments.update', $this->d1->id), ['name' => $this->d1->name, 'default_roles' => ['Quality Manager']]],
        ];

        foreach ($payloads as [$verb, $url, $payload]) {
            $this->actingAs($actor);
            $status = $this->{$verb}($url, $payload)->getStatusCode();
            $this->assertContains($status, [403, 404], "{$role} must not reach {$url} (got {$status})");
        }

        $this->assertSame($before, $target->fresh()->roles->pluck('name')->sort()->values()->all());
        $this->assertSame([], $this->d1->fresh()->default_roles ?? [], 'default_roles untouched');
    }

    public function test_only_the_super_administrator_can_change_a_role_assignment(): void
    {
        $superAdmin = $this->person('The Boss', null, ['Employee', 'Super Administrator']);
        $this->actingAs($superAdmin)->postJson(route('users.updateRole', $this->e1->employee_id), ['roles' => ['Employee', 'Quality Contributor']])->assertOk();

        $this->assertTrue($this->e1->fresh()->hasRole('Quality Contributor'));
    }
}
