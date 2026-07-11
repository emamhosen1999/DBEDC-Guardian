# Employee Directory — Phase A: Query Service + `/directory/search` Endpoint — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build one server-side employee query contract (`EmployeeDirectoryQuery`) with a permission `ScopeResolver`, expose it via a new lightweight `/directory/search` typeahead endpoint, and refactor the existing `paginateEmployees` to delegate to it with zero behavior change.

**Architecture:** A `ScopeResolver` turns the authenticated requester into an allowed-user-id constraint (mirroring `UserPolicy`). `EmployeeDirectoryQuery` applies that scope, then search/facet/sort filters, returning either full rows (list mode) or lightweight rows (typeahead mode). `paginateEmployees` delegates to it; a new `DirectoryController@search` calls the lightweight mode.

**Tech Stack:** Laravel 12, Spatie Permission, MySQL, PHPUnit.

## Global Constraints

- Package/app convention: services live in `app/Services/…`, controllers in `app/Http/Controllers/…`, Form Requests in `app/Http/Requests/…`. Follow existing DBEDC-Guardian structure.
- "Employee" is the `App\Models\User` model. Soft-deletes are in use (`deleted_at`).
- Scope rules mirror `App\Policies\UserPolicy` verbatim: `Super Administrator` / `Administrator` / `HR Manager` see all; `Department Manager` sees own `department_id`; everyone else sees only themselves.
- Permission-scoped: the resolved scope is a hard ceiling. A caller-supplied `scope` may only further narrow it, never widen.
- All writes in `DB::transaction()` (N/A this phase — read-only).
- Do NOT change the JSON shape returned by `employees.paginate` (existing frontend depends on it).

---

### Task 1: `ScopeResolver` — requester → allowed-user constraint

**Files:**
- Create: `app/Services/Directory/ScopeResolver.php`
- Test: `tests/Feature/Directory/ScopeResolverTest.php`

**Interfaces:**
- Consumes: `App\Models\User`, Spatie roles.
- Produces:
  - `ScopeResolver::applyBaseScope(\Illuminate\Database\Eloquent\Builder $query, User $requester): \Illuminate\Database\Eloquent\Builder` — narrows the query to the requester's allowed set.
  - `ScopeResolver::isGlobal(User $requester): bool` — true when the requester sees everyone.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Directory;

use App\Models\User;
use App\Services\Directory\ScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScopeResolverTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    public function test_hr_manager_sees_everyone(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole($this->role('HR Manager'));
        User::factory()->count(3)->create();

        $resolver = new ScopeResolver();
        $count = $resolver->applyBaseScope(User::query(), $hr)->count();

        $this->assertTrue($resolver->isGlobal($hr));
        $this->assertSame(User::count(), $count);
    }

    public function test_department_manager_sees_only_own_department(): void
    {
        $mgr = User::factory()->create(['department_id' => 10]);
        $mgr->assignRole($this->role('Department Manager'));
        User::factory()->count(2)->create(['department_id' => 10]);
        User::factory()->count(4)->create(['department_id' => 20]);

        $resolver = new ScopeResolver();
        $ids = $resolver->applyBaseScope(User::query(), $mgr)->pluck('id');

        $this->assertFalse($resolver->isGlobal($mgr));
        $this->assertTrue($ids->contains($mgr->id));
        $this->assertCount(3, $ids); // mgr + 2 same-dept
    }

    public function test_plain_employee_sees_only_self(): void
    {
        $emp = User::factory()->create();
        $emp->assignRole($this->role('Employee'));
        User::factory()->count(5)->create();

        $resolver = new ScopeResolver();
        $ids = $resolver->applyBaseScope(User::query(), $emp)->pluck('id');

        $this->assertSame([$emp->id], $ids->all());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ScopeResolverTest`
Expected: FAIL — class `App\Services\Directory\ScopeResolver` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Services\Directory;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ScopeResolver
{
    /** Roles that may see the entire directory. */
    private const GLOBAL_ROLES = ['Super Administrator', 'Administrator', 'HR Manager'];

    public function isGlobal(User $requester): bool
    {
        return $requester->hasRole(self::GLOBAL_ROLES);
    }

    /**
     * Narrow the query to the requester's allowed set of users.
     * Mirrors App\Policies\UserPolicy scope rules.
     */
    public function applyBaseScope(Builder $query, User $requester): Builder
    {
        if ($this->isGlobal($requester)) {
            return $query;
        }

        if ($requester->hasRole('Department Manager') && $requester->department_id !== null) {
            return $query->where('department_id', $requester->department_id);
        }

        // Default: only self.
        return $query->where('id', $requester->id);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ScopeResolverTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Directory/ScopeResolver.php tests/Feature/Directory/ScopeResolverTest.php
git commit -m "feat: ScopeResolver for permission-scoped employee directory"
```

---

### Task 2: `EmployeeDirectoryQuery` — search / facets / sort, two output modes

**Files:**
- Create: `app/Services/Directory/EmployeeDirectoryQuery.php`
- Test: `tests/Feature/Directory/EmployeeDirectoryQueryTest.php`

**Interfaces:**
- Consumes: `ScopeResolver::applyBaseScope()`, `App\Models\User` (+ relations `department`, `designation`, `attendanceType`).
- Produces:
  - `EmployeeDirectoryQuery::search(User $requester, array $params): \Illuminate\Support\Collection` — lightweight rows `{ id, name, employee_id, avatar_url, department_name, designation_name }`. Params: `q` (string), `scope` (`all|department:{id}|manager:{id}|myteam`), `limit` (int, default 20), `excludeIds` (int[]).
  - `EmployeeDirectoryQuery::baseQuery(User $requester, array $filters): \Illuminate\Database\Eloquent\Builder` — scoped+filtered+sorted builder for full-list mode. Filters: `search, department, designation, attendanceType, role, status, showDeleted, sort, direction`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Directory;

use App\Models\User;
use App\Services\Directory\EmployeeDirectoryQuery;
use App\Services\Directory\ScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeDirectoryQueryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::firstOrCreate(['name' => 'Administrator', 'guard_name' => 'web']));
        return $u;
    }

    private function svc(): EmployeeDirectoryQuery
    {
        return new EmployeeDirectoryQuery(new ScopeResolver());
    }

    public function test_search_matches_name_and_employee_id_and_ranks_exact_id_first(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Rahim Uddin', 'employee_id' => 'E-100']);
        $exact = User::factory()->create(['name' => 'Karim', 'employee_id' => 'RAHIM']);

        $rows = $this->svc()->search($admin, ['q' => 'RAHIM', 'limit' => 10]);

        $this->assertGreaterThanOrEqual(2, $rows->count());
        $this->assertSame($exact->id, $rows->first()['id']); // exact employee_id ranks first
        $this->assertArrayHasKey('department_name', $rows->first());
    }

    public function test_search_honors_exclude_ids(): void
    {
        $admin = $this->admin();
        $drop = User::factory()->create(['name' => 'Zed One']);
        User::factory()->create(['name' => 'Zed Two']);

        $rows = $this->svc()->search($admin, ['q' => 'Zed', 'excludeIds' => [$drop->id]]);

        $this->assertFalse($rows->pluck('id')->contains($drop->id));
    }

    public function test_search_respects_permission_scope_ceiling(): void
    {
        $mgr = User::factory()->create(['department_id' => 5]);
        $mgr->assignRole(Role::firstOrCreate(['name' => 'Department Manager', 'guard_name' => 'web']));
        User::factory()->create(['name' => 'InDept', 'department_id' => 5]);
        User::factory()->create(['name' => 'OutDept', 'department_id' => 9]);

        // Manager passes scope=all but may not widen beyond own department.
        $rows = $this->svc()->search($mgr, ['q' => 'Dept', 'scope' => 'all']);

        $this->assertTrue($rows->pluck('name')->contains('InDept'));
        $this->assertFalse($rows->pluck('name')->contains('OutDept'));
    }

    public function test_base_query_sorts_by_requested_column(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Bravo']);
        User::factory()->create(['name' => 'Alpha']);

        $names = $this->svc()
            ->baseQuery($admin, ['sort' => 'name', 'direction' => 'asc'])
            ->pluck('name');

        $this->assertSame('Alpha', $names->first());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=EmployeeDirectoryQueryTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Services\Directory;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EmployeeDirectoryQuery
{
    /** Columns permitted for sorting (allow-list guards against injection). */
    private const SORTABLE = [
        'name', 'employee_id', 'email', 'created_at',
        'department_id', 'designation_id', 'attendance_type_id',
    ];

    public function __construct(private ScopeResolver $scope) {}

    /**
     * Lightweight typeahead search. Returns minimal disambiguation rows.
     */
    public function search(User $requester, array $params): Collection
    {
        $q = trim((string) ($params['q'] ?? ''));
        $limit = (int) ($params['limit'] ?? 20);
        $excludeIds = array_filter(array_map('intval', (array) ($params['excludeIds'] ?? [])));

        $query = $this->scope->applyBaseScope(User::query(), $requester)
            ->whereNull('deleted_at')
            ->with(['department:id,name', 'designation:id,title']);

        $this->applyCallerScope($query, $requester, (string) ($params['scope'] ?? 'all'));

        if ($excludeIds) {
            $query->whereNotIn('id', $excludeIds);
        }

        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function (Builder $w) use ($like) {
                $w->where('name', 'like', $like)
                    ->orWhere('employee_id', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });
            // Ranking: exact employee_id, then name-prefix, then the rest.
            $query->orderByRaw(
                'CASE
                    WHEN employee_id = ? THEN 0
                    WHEN name LIKE ? THEN 1
                    ELSE 2 END',
                [$q, $q.'%']
            );
        }

        return $query->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'employee_id' => $u->employee_id,
                'avatar_url' => $u->profile_image_url,
                'department_name' => $u->department?->name,
                'designation_name' => $u->designation?->title,
            ]);
    }

    /**
     * Scoped + filtered + sorted builder for the full list page (list mode).
     */
    public function baseQuery(User $requester, array $filters): Builder
    {
        $query = $this->scope->applyBaseScope(User::withTrashed(), $requester);

        $status = $filters['status'] ?? null;
        $showDeleted = filter_var($filters['showDeleted'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($status && $status !== 'all') {
            $status === 'active'
                ? $query->whereNull('deleted_at')
                : $query->whereNotNull('deleted_at');
        } elseif (! $showDeleted) {
            $query->whereNull('deleted_at');
        }

        if (! empty($filters['role']) && $filters['role'] !== 'all') {
            $query->whereHas('roles', fn ($r) => $r->where('name', $filters['role']));
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $w) use ($like) {
                $w->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('employee_id', 'like', $like);
            });
        }

        foreach (['department' => 'department_id', 'designation' => 'designation_id', 'attendanceType' => 'attendance_type_id'] as $key => $col) {
            if (! empty($filters[$key]) && $filters[$key] !== 'all') {
                $query->where($col, $filters[$key]);
            }
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true) ? $filters['sort'] : 'created_at';
        $direction = strtolower($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction);
        if ($sort !== 'name') {
            $query->orderBy('name'); // stable secondary sort
        }

        return $query;
    }

    /** Caller-supplied scope may only narrow within the resolved ceiling. */
    private function applyCallerScope(Builder $query, User $requester, string $scope): void
    {
        if ($scope === '' || $scope === 'all') {
            return;
        }
        if ($scope === 'myteam') {
            $query->where('reports_to', $requester->id);
            return;
        }
        [$type, $id] = array_pad(explode(':', $scope, 2), 2, null);
        if ($type === 'department' && $id !== null) {
            $query->where('department_id', (int) $id);
        } elseif ($type === 'manager' && $id !== null) {
            $query->where('reports_to', (int) $id);
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=EmployeeDirectoryQueryTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Directory/EmployeeDirectoryQuery.php tests/Feature/Directory/EmployeeDirectoryQueryTest.php
git commit -m "feat: EmployeeDirectoryQuery search + scoped list builder"
```

---

### Task 3: Refactor `paginateEmployees` to delegate to the builder (no behavior change)

**Files:**
- Modify: `app/Services/Admin/UserManagementService.php:450-...` (`paginateEmployees`)
- Test: `tests/Feature/Directory/PaginateEmployeesParityTest.php`

**Interfaces:**
- Consumes: `EmployeeDirectoryQuery::baseQuery()`.
- Produces: unchanged public method `paginateEmployees(array $filters): array` returning the same `['employees' => LengthAwarePaginator, 'stats' => [...], 'allManagers' => [...]]` shape.

- [ ] **Step 1: Write the failing (characterization) test**

```php
<?php

namespace Tests\Feature\Directory;

use App\Models\User;
use App\Services\Admin\UserManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaginateEmployeesParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_and_new_sort_param_work(): void
    {
        User::factory()->create(['name' => 'Alpha', 'employee_id' => 'A-1']);
        User::factory()->create(['name' => 'Bravo', 'employee_id' => 'B-1']);

        $svc = app(UserManagementService::class);

        // Existing behavior: search still filters.
        $result = $svc->paginateEmployees(['search' => 'Alpha']);
        $this->assertSame(1, $result['employees']->total());

        // New behavior: sort param is honored (was hard-coded created_at desc).
        $sorted = $svc->paginateEmployees(['sort' => 'name', 'direction' => 'asc', 'perPage' => 50]);
        $names = collect($sorted['employees']->items())->pluck('name');
        $this->assertSame('Alpha', $names->first());

        // Stats + allManagers keys still present.
        $this->assertArrayHasKey('stats', $result);
        $this->assertArrayHasKey('allManagers', $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PaginateEmployeesParityTest`
Expected: FAIL — the `sort=name asc` assertion fails because sort is currently hard-coded `created_at desc`.

- [ ] **Step 3: Refactor `paginateEmployees` to use the builder**

Replace the query-construction block (from `$query = User::withTrashed()...` through `$query->orderBy('created_at', 'desc');` and the `$employees = $query->...->paginate(...)` line) with delegation to `EmployeeDirectoryQuery::baseQuery()`. Inject the dependency and preserve the eager-load list, the transform closure, `$stats`, and `$allManagers` exactly as they are today.

```php
// at top of class, add constructor dependency (merge with any existing constructor):
public function __construct(
    private \App\Services\Directory\EmployeeDirectoryQuery $directory,
) {}

// inside paginateEmployees(), replace the manual query building with:
$perPage = $filters['perPage'] ?? 20;
$page = $filters['page'] ?? 1;

$query = $this->directory->baseQuery(request()->user() ?? \App\Models\User::query()->firstOrFail(), $filters)
    ->with([
        'department', 'designation', 'attendanceType', 'media', 'roles',
        'workLocation.attendanceType', 'employeeAttendanceType',
        'attendanceTypes:id,name,slug', 'biometricDevices:id,name,serial_number',
        'reportsTo.designation',
    ]);

$employees = $query->paginate($perPage, ['*'], 'page', $page);
// ...leave the existing $transformedEmployees closure, setCollection, $stats, $allManagers untouched...
```

> Note: if `UserManagementService` already has a constructor, merge the `EmployeeDirectoryQuery` parameter into it rather than adding a second constructor. Remove the now-dead manual filter/orderBy block that the builder replaces.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=PaginateEmployeesParityTest`
Expected: PASS.

- [ ] **Step 5: Run the broader user/employee suite for regressions**

Run: `php artisan test --filter=User`
Expected: PASS (no regressions in existing user tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Admin/UserManagementService.php tests/Feature/Directory/PaginateEmployeesParityTest.php
git commit -m "refactor: paginateEmployees delegates to EmployeeDirectoryQuery (adds column sort)"
```

---

### Task 4: `/directory/search` endpoint (request validation + controller + route)

**Files:**
- Create: `app/Http/Requests/Directory/DirectorySearchRequest.php`
- Create: `app/Http/Controllers/DirectoryController.php`
- Modify: `routes/web.php` (add route inside the authenticated group)
- Test: `tests/Feature/Directory/DirectorySearchEndpointTest.php`

**Interfaces:**
- Consumes: `EmployeeDirectoryQuery::search()`.
- Produces: `GET /directory/search` named `directory.search` → JSON `{ "results": [ { id, name, employee_id, avatar_url, department_name, designation_name } ] }`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Directory;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DirectorySearchEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->getJson('/directory/search?q=a')->assertUnauthorized();
    }

    public function test_admin_can_search_everyone(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::firstOrCreate(['name' => 'Administrator', 'guard_name' => 'web']));
        User::factory()->create(['name' => 'Findable Person']);

        $this->actingAs($admin)
            ->getJson('/directory/search?q=Findable')
            ->assertOk()
            ->assertJsonStructure(['results' => [['id', 'name', 'employee_id', 'department_name']]])
            ->assertJsonFragment(['name' => 'Findable Person']);
    }

    public function test_manager_scope_ceiling_enforced_over_query_param(): void
    {
        $mgr = User::factory()->create(['department_id' => 7]);
        $mgr->assignRole(Role::firstOrCreate(['name' => 'Department Manager', 'guard_name' => 'web']));
        User::factory()->create(['name' => 'Same Dept', 'department_id' => 7]);
        User::factory()->create(['name' => 'Other Dept', 'department_id' => 8]);

        $res = $this->actingAs($mgr)->getJson('/directory/search?q=Dept&scope=all')->assertOk();

        $names = collect($res->json('results'))->pluck('name');
        $this->assertTrue($names->contains('Same Dept'));
        $this->assertFalse($names->contains('Other Dept'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DirectorySearchEndpointTest`
Expected: FAIL — route `/directory/search` not defined (404).

- [ ] **Step 3: Create the Form Request**

```php
<?php

namespace App\Http\Requests\Directory;

use Illuminate\Foundation\Http\FormRequest;

class DirectorySearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', 'string', 'max:40'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'excludeIds' => ['nullable', 'array'],
            'excludeIds.*' => ['integer'],
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Directory\DirectorySearchRequest;
use App\Services\Directory\EmployeeDirectoryQuery;
use Illuminate\Http\JsonResponse;

class DirectoryController extends Controller
{
    public function __construct(private EmployeeDirectoryQuery $directory) {}

    public function search(DirectorySearchRequest $request): JsonResponse
    {
        $results = $this->directory->search($request->user(), $request->validated());

        return response()->json(['results' => $results]);
    }
}
```

- [ ] **Step 5: Register the route**

Add inside the existing authenticated middleware group in `routes/web.php` (the group that already contains the `/employees` routes, so `auth` applies). Do NOT gate it behind `permission:employees.view` — the scope resolver already enforces visibility, and self-service forms must be usable by ordinary employees:

```php
Route::get('/directory/search', [\App\Http\Controllers\DirectoryController::class, 'search'])
    ->name('directory.search');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=DirectorySearchEndpointTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Directory/DirectorySearchRequest.php app/Http/Controllers/DirectoryController.php routes/web.php tests/Feature/Directory/DirectorySearchEndpointTest.php
git commit -m "feat: permission-scoped /directory/search typeahead endpoint"
```

---

## Self-Review

- **Spec coverage (Phase A slice):** `EmployeeDirectoryQuery` service ✓ (Task 2), `ScopeResolver` ✓ (Task 1), `/directory/search` lightweight endpoint + row shape ✓ (Task 4), scope-narrowing-never-widening ✓ (Tasks 2 & 4 tests), `paginateEmployees` delegates with column sort added ✓ (Task 3). Phases B (`<EmployeePicker>`), C (migrate 21 forms), and D (list UI upgrade) are separate plans and intentionally out of this plan's scope.
- **Placeholder scan:** none — every code step contains complete code.
- **Type consistency:** `applyBaseScope`, `isGlobal`, `search`, `baseQuery` names are used identically across tasks; lightweight row keys (`id, name, employee_id, avatar_url, department_name, designation_name`) match between Task 2, Task 4 test, and the endpoint JSON.

## Follow-on plans (not in this file)

- **Phase B:** `<EmployeePicker>` on react-aria ComboBox, consuming `directory.search`.
- **Phase C:** migrate the 21 client-side `allUsers`/`allManagers` usages onto `<EmployeePicker>`; retire react-select + primereact for people-selection.
- **Phase D:** `/employees` list upgrade — column sort UI, preset segment chips, active-filter chips, URL persistence, column visibility/density/export.
