<?php

namespace Tests\Feature\Access;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\HRM\OvertimeRequest;
use App\Models\User;
use App\Models\UserDepartmentScope;
use App\Services\Access\DepartmentScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\Feature\Access\Concerns\TogglesSelfAdministration;
use Tests\TestCase;

/**
 * ROUTE AUTHORIZATION MATRIX (deny by default, OWASP A01). EVERY registered route x HTTP method is driven as
 * a Department Admin of D1 — with D2 ids for parameterised routes and a mass-assignment payload full of
 * escalation fields — and classified:
 *
 *   PUBLIC     no auth middleware (login, password reset, device protocols...)
 *   FORBIDDEN  a permission / role / scope.global middleware he does not satisfy  -> never 2xx
 *   PERMITTED  a permission middleware he satisfies (his exact 52)               -> in scope only
 *   GUARDED    authenticated only: must be listed in GUARDED below (self-service or policy/scope inside
 *              the controller) — anything unclassified FAILS the test
 *
 * Every request runs inside a transaction: the protected tables (roles, permissions and their pivots,
 * department scopes, feature flags, every pre-existing user's privileged columns) and every D2-owned row
 * are hashed before and after, and must not change for a forbidden or out-of-scope call. Nothing may answer
 * 5xx. The full matrix is written to storage/logs/department-admin-route-matrix.csv.
 */
class DepartmentAdminRouteMatrixTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;
    use TogglesSelfAdministration;

    /** Routes never driven: device protocols, websocket auth, framework/health, session mutators. */
    private const SKIP = ['#^iclock/#', '#^broadcasting/#', '#^sanctum/#', '#^_ignition#', '#^up$#', '#^horizon#', '#^telescope#', '#^pulse#', '#^logout$#'];

    /**
     * 5xx that exist for EVERY actor because the sandbox lacks the integration (Stripe key, tenancy tables,
     * Fortify views) — not an authorization matter.
     */
    private const ENVIRONMENT_5XX = [
        '#^stripe/#', '#^tenancy/#', '#^verify-email#', '#^user/confirm-password#', '#^user/confirmed-password-status#',
        // telemetry sinks / MySQL-only analytics / a PDF view that needs a day with data: they answer 500 to EVERY actor
        // in this sqlite sandbox and expose nothing
        '#^api/log-error$#', '#^api/log-performance$#', '#^leaves/analytics$#', '#^attendance/export/pdf$#',
    ];

    /**
     * Authenticated-only routes (no permission middleware) and WHY each is safe: self-service (acts on the
     * caller's own data) or guarded inside the controller by policy / DepartmentScope. Regex => reason.
     * Anything not matched here and without a permission middleware fails the deny-by-default check.
     */
    private const GUARDED = [
        '#^user/#' => 'self-service: Fortify account security of the caller (own profile, password, 2FA)',
        '#^api/(user|notifications|notification-token|log-error|log-performance)#' => 'self-service: the caller\'s own notifications, token and telemetry',
        '#^(notifications|settings/notifications)#' => 'self-service: the caller\'s own notifications and preferences',
        '#^(verify-email|email/verification-notification)#' => 'self-service: the caller\'s own e-mail verification',
        '#^(firebase/token|employee-dashboard|account/password|my-devices|security/dashboard)#' => 'self-service: the caller\'s own dashboard, password, devices',
        '#^aeon#' => 'self-service: the caller\'s own AI conversations (owner-scoped)',
        '#^petty-cash#' => 'the caller\'s own petty-cash fund; the admin views sit behind petty-cash.* permissions',
        '#^search$#' => 'global search: every section re-checks its permission and DepartmentScope',
        '#^api/(designations|departments)/list$#' => 'picker lists, scoped through DepartmentScope',
        '#^api/users/managers/list$#' => 'picker list, scoped through DepartmentScope',
        '#^api/v1/#' => 'mobile API: own data; team data via ResolvesTeamMembers/DepartmentScope; approvals via policies',
    ];

    /** Parameters that are dates / unguessable file names, not record ids: a D2 id means nothing to them. */
    private const NON_ID_PARAMETERS = ['#check-user-locations-updates/#', '#check-timesheet-updates/#', '#export/status/#'];

    private array $existingUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Hundreds of requests from one user would otherwise answer 429 and mask what the routes do.
        $this->withoutMiddleware([ThrottleRequests::class]);
        $this->buildWorld();
        $this->existingUserIds = User::withTrashed()->pluck('employee_id')->map(fn ($id) => (string) $id)->all();
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

    /** @return array{0: string, 1: string} [class, reason] */
    private function classify(RoutingRoute $route): array
    {
        $middleware = $this->middleware($route);
        if ($this->isPublic($middleware)) {
            return ['PUBLIC', 'no auth middleware'];
        }

        $verdicts = [];
        foreach ($middleware as $m) {
            if (preg_match('/^(permission|role_or_permission|custom_permission):(.+)$/', $m, $match)) {
                $alternatives = preg_split('/[|,]/', $match[2]);
                $held = collect($alternatives)->contains(fn ($p) => $this->admin->checkPermissionTo(trim($p)));
                $verdicts[] = $held ? 'PERMITTED' : 'FORBIDDEN';
            } elseif (preg_match('/^role:(.+)$/', $m, $match)) {
                $verdicts[] = $this->admin->hasAnyRole(preg_split('/[|,]/', $match[1])) ? 'PERMITTED' : 'FORBIDDEN';
            } elseif ($m === 'scope.global') {
                $verdicts[] = 'FORBIDDEN';
            }
        }

        if (in_array('FORBIDDEN', $verdicts, true)) {
            return ['FORBIDDEN', 'permission/role/scope middleware he does not satisfy'];
        }
        if ($verdicts !== []) {
            return ['PERMITTED', 'permission middleware he holds'];
        }

        foreach (self::GUARDED as $pattern => $reason) {
            if (preg_match($pattern, $route->uri())) {
                return ['GUARDED', $reason];
            }
        }

        return ['UNCLASSIFIED', 'authenticated only: neither a permission middleware nor listed in GUARDED'];
    }

    private function url(RoutingRoute $route, string $value): string
    {
        $uri = preg_replace('/\{[^}]+\?\}/', '', $route->uri());

        return '/'.trim(preg_replace('/\{[^}]+\}/', $value, $uri), '/');
    }

    /** The payload a hostile admin would try: every escalation and mass-assignment field at once. */
    private function hostilePayload(): array
    {
        return [
            'name' => 'Hostile', 'roles' => ['Super Administrator'], 'role' => 'Super Administrator', 'permissions' => ['roles.update', 'users.view'],
            'permission' => 'roles.update', 'salary_amount' => 1, 'department_id' => 90001, 'department' => 90001, 'employee_id' => '90001',
            'user_id' => '90001', 'employee' => '90001', 'report_to' => '90001', 'is_admin' => true, 'hierarchy_level' => 1, 'scope_type' => 'admin',
            'password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026', 'status' => 'Approved', 'approved' => true,
            'user_ids' => ['90001'], 'ids' => [90001],
        ];
    }

    /** Hash of everything that must not change: protected tables, pre-existing users' privileged columns, D2 rows. */
    private function snapshot(): array
    {
        $hash = fn ($rows) => md5(json_encode($rows));
        $table = fn (string $name, callable $q) => Schema::hasTable($name) ? $hash($q(DB::table($name))->get()) : 'n/a';
        $ids = $this->existingUserIds;
        $d2Users = ['90001', '90002', '90003'];

        return [
            'roles' => $table('roles', fn ($q) => $q->orderBy('id')),
            'permissions' => $table('permissions', fn ($q) => $q->orderBy('id')),
            'role_has_permissions' => $table('role_has_permissions', fn ($q) => $q->orderBy('role_id')->orderBy('permission_id')),
            'model_has_roles' => $table('model_has_roles', fn ($q) => $q->whereIn('model_id', $ids)->orderBy('role_id')->orderBy('model_id')),
            'model_has_permissions' => $table('model_has_permissions', fn ($q) => $q->whereIn('model_id', $ids)->orderBy('permission_id')->orderBy('model_id')),
            'user_department_scopes' => $table('user_department_scopes', fn ($q) => $q->orderBy('id')),
            'feature_flags' => $table('feature_flags', fn ($q) => $q->orderBy('id')),
            'users_privileged' => $table('users', fn ($q) => $q->whereIn('employee_id', $ids)->orderBy('employee_id')
                ->select('employee_id', 'email', 'password', 'department_id', 'designation_id', 'report_to', 'salary_amount', 'deleted_at', 'work_location_id', 'attendance_type_id')),
            'd2_users' => $table('users', fn ($q) => $q->whereIn('employee_id', $d2Users)->orderBy('employee_id')),
            'd2_department' => $table('departments', fn ($q) => $q->where('id', 90001)),
            'd2_designations' => $table('designations', fn ($q) => $q->where('department_id', 90001)->orderBy('id')),
            'd2_shifts' => $table('shifts', fn ($q) => $q->where('department_id', 90001)->orderBy('id')),
            'd2_patterns' => $table('shift_rotation_patterns', fn ($q) => $q->where('department_id', 90001)->orderBy('id')),
            'd2_roster' => $table('roster_days', fn ($q) => $q->whereIn('user_id', $d2Users)->orderBy('id')),
            'd2_leaves' => $table('leaves', fn ($q) => $q->whereIn('user_id', $d2Users)->orderBy('id')),
            'd2_attendances' => $table('attendances', fn ($q) => $q->whereIn('user_id', $d2Users)->orderBy('id')),
            'd2_overtime' => $table('overtime_requests', fn ($q) => $q->whereIn('user_id', $d2Users)->orderBy('id')),
            'd2_assets' => $table('assets', fn ($q) => $q->whereIn('assignee_id', $d2Users)->orderBy('id')),
            'd2_onboardings' => $table('onboardings', fn ($q) => $q->whereIn('employee_id', $d2Users)->orderBy('id')),
            'd2_offboardings' => $table('offboardings', fn ($q) => $q->whereIn('employee_id', $d2Users)->orderBy('id')),
            'd2_petty' => $table('petty_cash_loans', fn ($q) => $q->whereIn('user_id', $d2Users)->orderBy('id')),
            'd2_petty_tx' => $table('petty_cash_transactions', fn ($q) => $q->where('petty_cash_loan_id', 90001)->orderBy('id')),
        ];
    }

    /** @return array{status: int, body: string, changed: array<int, string>} */
    private function drive(RoutingRoute $route, string $method, string $url, bool $withPayload): array
    {
        $middleware = $this->middleware($route);
        $sanctum = collect($middleware)->contains(fn ($m) => str_starts_with($m, 'auth:sanctum'));
        $sanctum ? Sanctum::actingAs($this->admin, ['*']) : $this->actingAs($this->admin);

        DB::beginTransaction();
        $before = $this->snapshot();
        try {
            $response = $this->json($method, $url, $withPayload && $method !== 'GET' ? $this->hostilePayload() : ($method === 'GET' ? ['department_id' => 90001, 'user_id' => '90001', 'employee_id' => '90001'] : []));
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

    public function test_the_full_route_matrix_holds(): void
    {
        $rows = [];
        $problems = [];

        foreach ($this->entries() as ['route' => $route, 'method' => $method]) {
            [$class, $reason] = $this->classify($route);
            $uri = $route->uri();
            $hasParams = str_contains($uri, '{');
            $idParams = $hasParams && ! collect(self::NON_ID_PARAMETERS)->contains(fn ($p) => preg_match($p, $uri));

            if ($class === 'PUBLIC') {
                $rows[] = [$method, $uri, $class, 'n/a', '', '', $reason];

                continue;
            }
            if ($class === 'UNCLASSIFIED') {
                $problems[] = "UNCLASSIFIED {$method} {$uri}";
            }

            // forbidden / out-of-scope: with D2 ids where the route has parameters
            $url = $hasParams ? $this->url($route, $this->d2Ids['user']) : '/'.ltrim($uri, '/');
            $result = $this->drive($route, $method, $url, true);
            $status = $result['status'];
            $env = collect(self::ENVIRONMENT_5XX)->contains(fn ($p) => preg_match($p, $uri));

            $violations = [];
            if ($status >= 500 && ! $env) {
                $violations[] = "5xx ({$status}) ".substr(preg_replace('/\s+/', ' ', strip_tags($result['body'])), 0, 140);
            }
            if (($class === 'FORBIDDEN' || $class === 'UNCLASSIFIED' || $idParams) && $status >= 200 && $status < 300) {
                $violations[] = "2xx ({$status}) for a ".($class === 'FORBIDDEN' ? 'forbidden' : 'D2-id').' call';
            }
            if ($result['changed'] !== [] && ($class === 'FORBIDDEN' || $idParams || array_intersect($result['changed'], ['roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'user_department_scopes', 'feature_flags']))) {
                $violations[] = 'CHANGED '.implode(',', $result['changed']);
            }
            if (stripos($result['body'], self::MARKER) !== false) {
                $violations[] = 'LEAK';
            }

            $expected = $class === 'FORBIDDEN' ? '4xx' : ($hasParams ? '4xx (D2 id)' : 'in scope');
            $rows[] = [$method, $uri, $class, $expected, $status, implode(',', $result['changed']), $violations === [] ? 'ok' : implode('; ', $violations)];
            foreach ($violations as $violation) {
                $problems[] = "{$violation}: {$method} {$uri} [{$class}]";
            }
        }

        $handle = fopen(base_path('storage/logs/department-admin-route-matrix.csv'), 'w');
        fputcsv($handle, ['method', 'uri', 'class', 'expected', 'actual_status', 'tables_changed', 'result']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        $this->assertGreaterThan(300, count($rows), 'the matrix covers the whole route table');
        $this->assertSame([], array_slice($problems, 0, 80), count($problems).' matrix violation(s)');
    }

    // ── explicit escalation and abuse cases ────────────────────────────────────────────────────────────

    private function as(User $user): self
    {
        $this->actingAs($user);

        return $this;
    }

    private function mutable(User $user): array
    {
        $user = $user->fresh();

        return [$user->roles->pluck('name')->sort()->values()->all(), $user->getDirectPermissions()->pluck('name')->all(), $user->salary_amount, $user->department_id, $user->employee_id, $user->password, $user->deleted_at, $user->report_to];
    }

    public function test_he_cannot_grant_himself_or_anyone_a_role_permission_or_department_scope(): void
    {
        $before = $this->mutable($this->admin);
        $this->as($this->admin)->postJson(route('users.updateRole', $this->admin->employee_id), ['roles' => ['Super Administrator']])->assertForbidden();
        $this->as($this->admin)->postJson(route('users.updateRole', $this->e1->employee_id), ['roles' => ['HR Manager']])->assertForbidden();
        $this->as($this->admin)->postJson(route('users.bulk.role'), ['user_ids' => [(string) $this->admin->employee_id], 'role' => 'Administrator'])->assertForbidden();
        $this->as($this->admin)->postJson('/api/users/'.$this->admin->employee_id.'/permissions/give', ['permission' => 'roles.update'])->assertForbidden();
        $this->as($this->admin)->postJson('/api/users/'.$this->e1->employee_id.'/permissions', ['permissions' => ['users.view']])->assertForbidden();
        $this->as($this->admin)->postJson(route('users.department-scopes.store', $this->admin->employee_id), ['department_id' => $this->d2->id, 'scope_type' => 'admin'])->assertForbidden();
        $this->as($this->admin)->postJson(route('users.department-scopes.store', $this->e1->employee_id), ['department_id' => $this->d2->id, 'scope_type' => 'admin'])->assertForbidden();
        $this->as($this->admin)->postJson('/api/roles/1/permissions/sync', ['permissions' => ['roles.update']])->assertForbidden();

        $this->assertSame($before, $this->mutable($this->admin));
        $this->assertSame(0, UserDepartmentScope::count());
    }

    public function test_he_cannot_change_his_own_role_salary_department_or_employee_id(): void
    {
        $this->withoutSelfAdministration();
        $this->admin->forceFill(['salary_amount' => 5000])->save();
        $before = $this->mutable($this->admin);

        $this->as($this->admin)->putJson(route('users.update', $this->admin->employee_id), [
            'name' => 'Still Me', 'roles' => ['Super Administrator'], 'salary_amount' => 999999, 'department_id' => $this->d2->id,
            'employee_id' => 'HIJACK-1', 'report_to' => '90001', 'designation_id' => 90001,
        ])->assertOk();

        $this->assertSame($before, $this->mutable($this->admin), 'only harmless fields moved');
        $this->assertSame('Still Me', $this->admin->fresh()->name);
        $this->as($this->admin)->postJson(route('profile.update'), ['id' => $this->admin->employee_id, 'ruleSet' => 'salary', 'salary_basis' => 'monthly', 'salary_amount' => 999999, 'payment_type' => 'Cash'])->assertForbidden();
        $this->as($this->admin)->putJson(route('users.update-department', $this->admin->employee_id), ['department' => $this->d2->id])->assertForbidden();
        $this->assertSame($before[2], $this->mutable($this->admin)[2]);
    }

    public function test_he_cannot_touch_people_who_outrank_him_or_sit_outside_his_department(): void
    {
        // The equal-rank peer holds something administrative he lacks, so the delegation subset rule protects it.
        $this->peer->givePermissionTo(Permission::findOrCreate('hr.payroll.view', 'web'));
        app(DepartmentScope::class)->forget();
        $password = ['password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026'];
        foreach ([$this->hrInD1, $this->peer, $this->hr, $this->c1, $this->d2Admin] as $target) {
            $before = $this->mutable($target);
            $id = (string) $target->employee_id;
            $statuses = [
                $this->as($this->admin)->putJson(route('users.update', $id), ['name' => 'Hijacked', 'salary_amount' => 1])->getStatusCode(),
                $this->as($this->admin)->postJson(route('users.changePassword', $id), $password)->getStatusCode(),
                $this->as($this->admin)->deleteJson(route('users.destroy', $id))->getStatusCode(),
                $this->as($this->admin)->postJson(route('users.restore', $id))->getStatusCode(),
                $this->as($this->admin)->postJson(route('admin.users.devices.reset', $id))->getStatusCode(),
                $this->as($this->admin)->postJson(route('users.updateReportTo', $id), ['report_to' => (string) $this->admin->employee_id])->getStatusCode(),
                $this->as($this->admin)->postJson(route('profile.update'), ['id' => $id, 'ruleSet' => 'emergency', 'emergency_contact_primary_name' => 'X', 'emergency_contact_primary_relationship' => 'Y', 'emergency_contact_primary_phone' => '1'])->getStatusCode(),
            ];
            foreach ($statuses as $status) {
                $this->assertContains($status, [403, 404], "{$target->name}: every write is refused");
            }
            $this->assertSame($before, $this->mutable($target), "{$target->name} is untouched");
        }
    }

    public function test_he_cannot_move_people_into_or_out_of_unmanaged_departments(): void
    {
        $this->as($this->admin)->putJson(route('users.update-department', $this->c1->employee_id), ['department' => $this->d1->id])->assertForbidden();   // pull a D2 person into D1
        $this->as($this->admin)->putJson(route('users.update-department', $this->e1->employee_id), ['department' => $this->d2->id])->assertForbidden();   // push a D1 person out
        $this->as($this->admin)->putJson(route('users.update', $this->e1->employee_id), ['department_id' => $this->d2->id])->assertForbidden();
        $this->as($this->admin)->putJson(route('users.update', $this->c1->employee_id), ['department_id' => $this->d1->id])->assertForbidden();
        $this->assertSame($this->d1->id, (int) $this->e1->fresh()->department_id);
        $this->assertSame($this->d2->id, (int) $this->c1->fresh()->department_id);
    }

    public function test_he_cannot_approve_his_own_leave_overtime_or_regularization(): void
    {
        $this->withoutSelfAdministration();
        $type = LeaveSetting::first();
        $own = Leave::create(['user_id' => $this->admin->employee_id, 'leave_type' => $type->id, 'from_date' => '2026-06-20', 'to_date' => '2026-06-20', 'no_of_days' => 1, 'status' => 'Pending', 'reason' => 'mine']);
        $this->as($this->admin)->postJson(route('leaves.approve', $own->id))->assertForbidden();
        $this->as($this->admin)->postJson(route('leaves.bulk-approve'), ['leave_ids' => [$own->id, 90001]])->assertOk()->assertJsonPath('updated_count', 0);
        $this->as($this->admin)->postJson(route('leaves.bulk-reject'), ['leave_ids' => [90001]])->assertStatus(200);
        $this->assertSame('Pending', $own->fresh()->status);
        $this->assertSame('Pending', Leave::find(90001)->status, 'another department\'s leave is never decided');

        $ot = OvertimeRequest::create(['user_id' => $this->admin->employee_id, 'date' => self::DAY, 'requested_minutes' => 60, 'reason' => 'mine', 'status' => 'pending']);
        $this->assertContains($this->as($this->admin)->postJson(route('attendance.overtime.approve', $ot->id))->getStatusCode(), [403, 422]);
        $this->assertSame('pending', $ot->fresh()->status);

        // ...and another department's pending items are out of reach (D2 ids, from the canary world)
        $this->assertContains($this->as($this->admin)->postJson(route('leaves.approve', 90001))->getStatusCode(), [403, 404]);
        $this->assertContains($this->as($this->admin)->postJson(route('attendance.overtime.approve', 90001))->getStatusCode(), [403, 404, 422]);
        $this->assertSame('Pending', Leave::find(90001)->status);
    }

    public function test_fleet_wide_and_company_wide_administration_is_closed(): void
    {
        $closed = [
            ['post', route('admin.feature-flags.store'), ['key' => 'x', 'is_enabled' => true]],
            ['post', route('admin.client-errors.resolve', 1), []],
            ['post', route('admin.device-sessions.revoke', 90001), []],
            ['get', route('roles-settings'), []],
            ['post', route('admin.roles.store'), ['name' => 'Evil']],
            ['put', route('update-company-settings'), ['companyName' => 'Evil']],
            ['post', route('attendance-settings.update'), []],
            ['get', route('biometric-devices.index'), []],
            ['post', route('holiday-add'), ['title' => 'x']],
            ['get', route('attendance.policies.index'), []],
            ['get', route('leave-settings'), []],
            ['post', route('add-leave-type'), []],
            ['post', route('hr.payroll.generate'), []],
            ['post', route('hr.settlement.approve', 1), []],
            ['get', route('departments'), []],
            ['post', route('departments.store'), ['name' => 'Evil']],
        ];
        foreach ($closed as [$method, $uri, $payload]) {
            $this->assertContains($this->as($this->admin)->json($method, $uri, $payload)->getStatusCode(), [403, 404], "{$method} {$uri} must be closed");
        }
    }

    public function test_mass_assignment_fields_in_create_and_update_payloads_are_ignored_or_rejected(): void
    {
        $created = $this->as($this->admin)->postJson(route('users.store'), [
            'name' => 'Mass Assigned', 'user_name' => 'massassigned', 'email' => 'mass@example.com', 'employee_id' => 'MASS-1',
            'password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026',
            'department_id' => $this->d1->id, 'roles' => ['Super Administrator', 'Administrator'], 'permissions' => ['roles.update'],
            'is_admin' => true, 'hierarchy_level' => 1, 'salary_amount' => 123456, 'report_to' => '90001', 'sync_epoch' => 99, 'must_change_password' => false,
        ]);
        // the manager outside his scope is refused outright; retry without it to inspect what was stored
        $created->assertStatus(422);
        $created = $this->as($this->admin)->postJson(route('users.store'), [
            'name' => 'Mass Assigned', 'user_name' => 'massassigned', 'email' => 'mass@example.com', 'employee_id' => 'MASS-1',
            'password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026',
            'department_id' => $this->d1->id, 'attendance_type_ids' => [$this->attendanceMethodId()], 'roles' => ['Super Administrator', 'Administrator'], 'permissions' => ['roles.update'],
            'is_admin' => true, 'hierarchy_level' => 1, 'salary_amount' => 123456, 'sync_epoch' => 99, 'must_change_password' => false,
        ])->assertCreated();
        $new = User::find('MASS-1');
        $this->assertSame(['Employee'], $new->roles->pluck('name')->all());
        $this->assertSame([], $new->getDirectPermissions()->pluck('name')->all());
        $this->assertSame($this->d1->id, (int) $new->department_id);
        $this->assertNotSame(99, (int) $new->sync_epoch, 'internal counters are not mass-assignable');
        $this->assertTrue((bool) $new->must_change_password, 'an admin-set password always forces a change');
    }

    public function test_exports_and_listings_ignore_filters_for_another_department(): void
    {
        foreach (['/attendance/export/excel', '/attendance-log/export', '/leave-summary/export/excel', '/employees/paginate', '/admin/daily-timesheet', '/leaves-paginate', '/users/paginate'] as $uri) {
            $response = $this->as($this->admin)->getJson($uri.'?department_id=90001&department=90001&user_id=90001&employee_id=90001&search=Zed&date='.self::DAY);
            $body = $response->baseResponse instanceof BinaryFileResponse ? (string) file_get_contents($response->baseResponse->getFile()->getPathname()) : (string) $response->getContent();
            $this->assertStringNotContainsString(self::MARKER, $body, "{$uri} must not widen to D2");
            $this->assertLessThan(500, $response->getStatusCode(), $uri);
        }
    }
}
