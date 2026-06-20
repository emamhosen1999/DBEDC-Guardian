# Attendance Phase 3.0 — Policy Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the foundational, extensible attendance **policy engine** — effective-dated, scoped, versioned policies resolved per employee-day and applied at both capture (strictness: warn/flag/restrict) and compute (rounding + graduated grace tiers), with punch-exception approvals (reusing Phase 2), a read-only simulation preview, and a Policies admin UI.

**Architecture:** A `PolicyResolver` (mirrors the Phase 1 `ScheduleResolver`) resolves `(userId, date)` to an immutable `PolicyProfile` by scope precedence (user → designation → department → org → global default). A pure `RuleEngine` runs a registry of single-responsibility `RuleEvaluator`s (Rounding, GraceTiers) over a `DayContext`; the existing pure `AttendanceStatusService` gains an optional `PolicyProfile` arg (neutral default = byte-identical Phase 2 output). Capture stays permissive: a `PunchPolicyGuard` inside `AttendancePunchService` marks out-of-policy punches `provisional` (needs approval) but **never blocks**. Exceptions resolve through the Phase 2 `AttendanceApprovalService`. Full design: `docs/superpowers/specs/2026-06-20-attendance-phase-3-policy-engine-design.md`.

**Tech Stack:** PHP 8.3, Laravel 11, Inertia v2, React 18 + Radix Themes, MySQL (prod) / sqlite `:memory:` (tests), PHPUnit 11, Vite, dayjs.

## Global Constraints

- **Capture is NEVER blocked.** No code in this plan may deny/refuse a punch. "restrict" = record the punch as `provisional` (doesn't count until approved), never an HTTP error.
- **Back-compat is a hard test requirement.** A neutral/empty `PolicyProfile` MUST make `AttendanceStatusService` produce output identical to Phase 2. The existing `tests/Unit/Attendance/AttendanceStatusServiceTest.php` (and all `--filter=Attendance` tests) must stay green with zero changes to their assertions.
- **Tests are PHPUnit class-style** (NOT Pest), sqlite `:memory:` + `RefreshDatabase`. Run `php artisan test --filter=<Class>`. Backend tasks are TDD.
- **2 KNOWN pre-existing failures** unrelated to this work, which must remain the ONLY failures: `MobileSyncApiTest > sync push applies leave apply mutation`, `NavigationRoutesTest > any authenticated user can access organization directory`. No task may add a NEW failure.
- **Run `php artisan migrate` on the MySQL dev DB `dbedc_guardian`** after adding migrations (mysql bin `/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin`; root, no password). The sqlite suite will NOT catch a missing MySQL table.
- **Verify frontend by HTTP status**, not the rendered shell (empty-state UIs render identically on 200 and 500). Use Playwright `fetch` / browser console.
- **Inertia props that are Eloquent models** with appended accessors MUST be mapped to plain arrays before `Inertia::render` (`->get()->map(fn($m)=>[...])->values()`) — raw models 500 under preventLazyLoading.
- **Frontend client signature is `requestJson(method, url, { params | data })`** (`resources/js/api/client.js`). NEVER `(url,{method,body})`. Web endpoints return PLAIN json (no envelope).
- **Build frontend with `npx vite build`** — NEVER `npm run build` (its postbuild auto-commits/pushes). Dev server at `https://aero-enterprise-suite.test`. Frontend task commits are SOURCE-ONLY; ONE consolidated `public/build` rebuild + commit in the final sweep task.
- **Every multi-step write wraps in `DB::transaction`.** Policy management endpoints gated `permission:attendance.settings`; exception approval gated `permission:attendance.manage`.
- **Reuse, don't reinvent:** schedule via the bound `App\Services\Attendance\Contracts\ScheduleResolver`; approvals via `App\Services\Attendance\AttendanceApprovalService` (`pendingFor`/`approve`/`reject`); audit via `App\Services\Attendance\AttendanceAuditService::record(action, attendanceId, before, after, reason, request)`. The `ShiftSchedule` VO + `DayAttendance` DTO live in `app/Services/Attendance/DTO/`. Punch entry point is `App\Services\Attendance\AttendancePunchService::processPunch($user, $request)`.
- Match existing code style; Radix Themes only; no new dependencies; YAGNI.

---

## File Structure

**Backend — new**
- `database/migrations/2026_06_21_000001_create_attendance_policies_table.php`
- `database/migrations/2026_06_21_000002_add_policy_status_to_attendances_table.php` (adds `policy_status`, `needs_approval`, `policy_exception_reason`)
- `app/Models/HRM/AttendancePolicy.php` + `database/factories/HRM/AttendancePolicyFactory.php`
- `app/Services/Attendance/DTO/PolicyProfile.php` (immutable VO)
- `app/Services/Attendance/Contracts/PolicyResolver.php` (interface)
- `app/Services/Attendance/DbPolicyResolver.php` (bound impl) + binding in `AppServiceProvider`/the attendance service provider
- `app/Services/Attendance/DTO/DayContext.php` (engine accumulator)
- `app/Services/Attendance/Contracts/RuleEvaluator.php` (interface)
- `app/Services/Attendance/RuleEngine.php`
- `app/Services/Attendance/Rules/RoundingEvaluator.php`
- `app/Services/Attendance/Rules/GraceTiersEvaluator.php`
- `app/Services/Attendance/PunchPolicyGuard.php`
- `app/Services/Attendance/PunchExceptionService.php` (list/approve/reject provisional punches via AttendanceApprovalService-style chain)
- `app/Services/Attendance/PolicySimulationService.php`
- `app/Http/Controllers/HRM/PolicyController.php` + routes
- `database/seeders/AttendancePolicyDefaultSeeder.php` (global default)

**Backend — modify**
- `app/Services/Attendance/DTO/ShiftSchedule.php` — no change required (policy threaded separately); only touch if grace-tier plumbing needs the schedule window (it does — read-only).
- `app/Services/Attendance/AttendanceStatusService.php` — add optional `?PolicyProfile $policy = null` param; delegate late/early/rounding to the `RuleEngine` when a non-neutral policy is present; neutral default preserves current logic.
- `app/Services/Attendance/AttendancePunchService.php` — invoke `PunchPolicyGuard` after validation, persist `policy_status`.
- `routes/web.php` — policy + punch-exception routes.

**Frontend — new**
- `resources/js/Pages/Attendance/Components/PoliciesManager.jsx` (Settings tab content: list/CRUD/version timeline/simulate)
- `resources/js/Forms/PolicyForm.jsx` (Radix dialog: scope, strictness, grace tiers, rounding)
- `resources/js/Pages/Attendance/Components/PunchExceptions.jsx` (approvals-inbox section)

**Frontend — modify**
- `resources/js/Pages/Attendance/SettingsTab.jsx` — mount `<PoliciesManager/>` (new "Policies" sub-section), gated `attendance.settings`.
- `resources/js/Pages/Attendance/Components/ApprovalsInbox.jsx` — add the `<PunchExceptions/>` section.

---

### Task 1: `attendance_policies` + `attendances` policy flags — migrations, model, factory

**Files:**
- Create: `database/migrations/2026_06_21_000001_create_attendance_policies_table.php`, `database/migrations/2026_06_21_000002_add_policy_status_to_attendances_table.php`, `app/Models/HRM/AttendancePolicy.php`, `database/factories/HRM/AttendancePolicyFactory.php`
- Test: `tests/Feature/Attendance/AttendancePolicyModelTest.php`

**Interfaces:**
- Produces: table `attendance_policies(id, name, scope_type enum[org|department|designation|user], scope_id nullable, priority int default 0, effective_from date, effective_to date nullable, version_group_id unsignedBigInteger, version int default 1, status enum[draft|active|archived] default draft, punch_strictness enum[warn|flag|restrict] default warn, outside_window_minutes int default 120, grace_tiers json nullable, rounding json nullable, rule_overrides json nullable, created_by nullable, timestamps)`. Model `AttendancePolicy` casts `effective_from/to`→date, `grace_tiers/rounding/rule_overrides`→array, ints→integer; scopes `active()`, `forScope($type,$id)`. Adds to `attendances`: `policy_status` enum[accepted|provisional|rejected] default accepted, `needs_approval` bool default false, `policy_exception_reason` string nullable.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendancePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendancePolicyModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_and_casts(): void
    {
        $p = AttendancePolicy::create([
            'name' => 'Default', 'scope_type' => 'org', 'scope_id' => null,
            'priority' => 0, 'effective_from' => '2026-06-01', 'version_group_id' => 1, 'version' => 1,
            'status' => 'active', 'punch_strictness' => 'restrict', 'outside_window_minutes' => 90,
            'grace_tiers' => ['late' => [['upto_minutes' => 10, 'outcome' => 'present'], ['upto_minutes' => 30, 'outcome' => 'late']]],
            'rounding' => ['strategy' => 'quarter_hour', 'unit_minutes' => 15, 'direction' => 'nearest'],
        ]);
        $fresh = $p->fresh();
        $this->assertSame('restrict', $fresh->punch_strictness);
        $this->assertSame(10, $fresh->grace_tiers['late'][0]['upto_minutes']);
        $this->assertSame('quarter_hour', $fresh->rounding['strategy']);
        $this->assertSame('2026-06-01', $fresh->effective_from->toDateString());
        $this->assertCount(1, AttendancePolicy::active()->get());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AttendancePolicyModelTest`
Expected: FAIL — table/model missing.

- [ ] **Step 3: Migration `..._create_attendance_policies_table.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('scope_type', ['org', 'department', 'designation', 'user']);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->integer('priority')->default(0);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('version_group_id');
            $table->integer('version')->default(1);
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            $table->enum('punch_strictness', ['warn', 'flag', 'restrict'])->default('warn');
            $table->integer('outside_window_minutes')->default(120);
            $table->json('grace_tiers')->nullable();
            $table->json('rounding')->nullable();
            $table->json('rule_overrides')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['scope_type', 'scope_id', 'status']);
            $table->index(['version_group_id', 'version']);
            $table->index(['effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_policies');
    }
};
```

- [ ] **Step 4: Migration `..._add_policy_status_to_attendances_table.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->enum('policy_status', ['accepted', 'provisional', 'rejected'])->default('accepted')->after('symbol');
            $table->boolean('needs_approval')->default(false)->after('policy_status');
            $table->string('policy_exception_reason')->nullable()->after('needs_approval');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['policy_status', 'needs_approval', 'policy_exception_reason']);
        });
    }
};
```

- [ ] **Step 5: Model `app/Models/HRM/AttendancePolicy.php`**

```php
<?php

namespace App\Models\HRM;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendancePolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'scope_type', 'scope_id', 'priority', 'effective_from', 'effective_to',
        'version_group_id', 'version', 'status', 'punch_strictness', 'outside_window_minutes',
        'grace_tiers', 'rounding', 'rule_overrides', 'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'priority' => 'integer',
        'version_group_id' => 'integer',
        'version' => 'integer',
        'outside_window_minutes' => 'integer',
        'grace_tiers' => 'array',
        'rounding' => 'array',
        'rule_overrides' => 'array',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function scopeForScope(Builder $q, string $type, ?int $id): Builder
    {
        return $q->where('scope_type', $type)->where('scope_id', $id);
    }
}
```

- [ ] **Step 6: Factory `database/factories/HRM/AttendancePolicyFactory.php`**

```php
<?php

namespace Database\Factories\HRM;

use App\Models\HRM\AttendancePolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendancePolicyFactory extends Factory
{
    protected $model = AttendancePolicy::class;

    public function definition(): array
    {
        return [
            'name' => 'Policy', 'scope_type' => 'org', 'scope_id' => null, 'priority' => 0,
            'effective_from' => '2026-01-01', 'effective_to' => null,
            'version_group_id' => $this->faker->unique()->numberBetween(1, 100000), 'version' => 1,
            'status' => 'active', 'punch_strictness' => 'warn', 'outside_window_minutes' => 120,
            'grace_tiers' => null, 'rounding' => null, 'rule_overrides' => null,
        ];
    }
}
```

- [ ] **Step 7: Run test + migrate dev DB**

Run: `php artisan test --filter=AttendancePolicyModelTest` (PASS). Then `php artisan migrate` against `dbedc_guardian`; confirm `php artisan migrate:status | grep -E "attendance_policies|policy_status"` shows both Ran.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_06_21_000001_create_attendance_policies_table.php database/migrations/2026_06_21_000002_add_policy_status_to_attendances_table.php app/Models/HRM/AttendancePolicy.php database/factories/HRM/AttendancePolicyFactory.php tests/Feature/Attendance/AttendancePolicyModelTest.php
git commit -m "feat(attendance): attendance_policies table + policy flags on attendances"
```

---

### Task 2: `PolicyProfile` VO + `PolicyResolver` + `DbPolicyResolver` + global-default seeder

**Files:**
- Create: `app/Services/Attendance/DTO/PolicyProfile.php`, `app/Services/Attendance/Contracts/PolicyResolver.php`, `app/Services/Attendance/DbPolicyResolver.php`, `database/seeders/AttendancePolicyDefaultSeeder.php`
- Modify: the provider that binds `ScheduleResolver` (search `bind(ScheduleResolver::class` — bind `PolicyResolver::class` → `DbPolicyResolver::class` the same way)
- Test: `tests/Feature/Attendance/PolicyResolverTest.php`

**Interfaces:**
- Produces:
  - `PolicyProfile` (immutable) — constructor `(string $strictness='warn', int $outsideWindowMinutes=120, ?array $graceTiers=null, ?array $rounding=null)`; `static neutral(): self`; accessors `strictness()`, `outsideWindowMinutes()`, `graceTiers()`, `rounding()`, `isNeutral(): bool` (true when strictness=warn AND graceTiers/rounding null — i.e. reproduces Phase 2).
  - `Contracts\PolicyResolver::resolve(int $userId, CarbonInterface $date): PolicyProfile`.
  - `DbPolicyResolver` — picks the highest-precedence `active` policy whose effective range covers `$date`, precedence user → designation → department → org; falls back to `PolicyProfile::neutral()` if none. Reads the user's `department_id`/`designation_id`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendancePolicy;
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
        $u = User::factory()->create(['department_id' => 5]);
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PolicyResolverTest`
Expected: FAIL — `PolicyResolver` not bound / classes missing.

- [ ] **Step 3: `PolicyProfile` VO**

```php
<?php

namespace App\Services\Attendance\DTO;

final class PolicyProfile
{
    public function __construct(
        private readonly string $strictness = 'warn',
        private readonly int $outsideWindowMinutes = 120,
        private readonly ?array $graceTiers = null,
        private readonly ?array $rounding = null,
    ) {}

    public static function neutral(): self
    {
        return new self();
    }

    public function strictness(): string { return $this->strictness; }
    public function outsideWindowMinutes(): int { return $this->outsideWindowMinutes; }
    public function graceTiers(): ?array { return $this->graceTiers; }
    public function rounding(): ?array { return $this->rounding; }

    public function isNeutral(): bool
    {
        return $this->strictness === 'warn' && $this->graceTiers === null && $this->rounding === null;
    }
}
```

- [ ] **Step 4: `Contracts\PolicyResolver`**

```php
<?php

namespace App\Services\Attendance\Contracts;

use App\Services\Attendance\DTO\PolicyProfile;
use Carbon\CarbonInterface;

interface PolicyResolver
{
    public function resolve(int $userId, CarbonInterface $date): PolicyProfile;
}
```

- [ ] **Step 5: `DbPolicyResolver`**

```php
<?php

namespace App\Services\Attendance;

use App\Models\HRM\AttendancePolicy;
use App\Models\User;
use App\Services\Attendance\Contracts\PolicyResolver;
use App\Services\Attendance\DTO\PolicyProfile;
use Carbon\CarbonInterface;

class DbPolicyResolver implements PolicyResolver
{
    public function resolve(int $userId, CarbonInterface $date): PolicyProfile
    {
        $user = User::find($userId);
        $d = $date->toDateString();

        $candidates = AttendancePolicy::active()
            ->whereDate('effective_from', '<=', $d)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $d))
            ->get();

        // precedence: user > designation > department > org
        $order = ['user' => 4, 'designation' => 3, 'department' => 2, 'org' => 1];
        $match = $candidates
            ->filter(fn ($p) => match ($p->scope_type) {
                'user' => $p->scope_id === $userId,
                'designation' => $user && $p->scope_id === $user->designation_id,
                'department' => $user && $p->scope_id === $user->department_id,
                'org' => true,
                default => false,
            })
            ->sortByDesc(fn ($p) => [$order[$p->scope_type] ?? 0, $p->priority])
            ->first();

        if (! $match) {
            return PolicyProfile::neutral();
        }

        return new PolicyProfile(
            strictness: $match->punch_strictness,
            outsideWindowMinutes: $match->outside_window_minutes,
            graceTiers: $match->grace_tiers,
            rounding: $match->rounding,
        );
    }
}
```

- [ ] **Step 6: Bind it** — in the provider that binds `ScheduleResolver` (grep `App\Services\Attendance\Contracts\ScheduleResolver`), add next to it:

```php
$this->app->bind(\App\Services\Attendance\Contracts\PolicyResolver::class, \App\Services\Attendance\DbPolicyResolver::class);
```

- [ ] **Step 7: Default seeder `database/seeders/AttendancePolicyDefaultSeeder.php`**

```php
<?php

namespace Database\Seeders;

use App\Models\HRM\AttendancePolicy;
use Illuminate\Database\Seeder;

class AttendancePolicyDefaultSeeder extends Seeder
{
    public function run(): void
    {
        if (AttendancePolicy::forScope('org', null)->exists()) {
            return;
        }
        AttendancePolicy::create([
            'name' => 'Global Default', 'scope_type' => 'org', 'scope_id' => null, 'priority' => 0,
            'effective_from' => now()->startOfYear()->toDateString(), 'version_group_id' => 1, 'version' => 1,
            'status' => 'active', 'punch_strictness' => 'warn', 'outside_window_minutes' => 120,
        ]);
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --filter=PolicyResolverTest`
Expected: PASS (3 tests).

- [ ] **Step 9: Commit**

```bash
git add app/Services/Attendance/DTO/PolicyProfile.php app/Services/Attendance/Contracts/PolicyResolver.php app/Services/Attendance/DbPolicyResolver.php database/seeders/AttendancePolicyDefaultSeeder.php tests/Feature/Attendance/PolicyResolverTest.php
git add -p   # stage the provider binding edit
git commit -m "feat(attendance): policy resolver with scope precedence + neutral fallback"
```

---

### Task 3: `RuleEngine` + `RuleEvaluator` + `DayContext` + `RoundingEvaluator`

**Files:**
- Create: `app/Services/Attendance/DTO/DayContext.php`, `app/Services/Attendance/Contracts/RuleEvaluator.php`, `app/Services/Attendance/RuleEngine.php`, `app/Services/Attendance/Rules/RoundingEvaluator.php`
- Test: `tests/Unit/Attendance/RoundingEvaluatorTest.php`

**Interfaces:**
- Produces:
  - `DayContext` (mutable accumulator) — public props `Carbon $firstIn`, `?Carbon $lastOut`, `int $workedMinutes`, `array $flags`, plus `ShiftSchedule $shift`, `PolicyProfile $policy`. Status/late/early are computed by the status service AFTER rounding; the engine in 3.0 only adjusts `firstIn`/`lastOut` (rounding) and classifies grace (Task 4 reads tiers). Construct from the status service.
  - `Contracts\RuleEvaluator::supports(PolicyProfile $p): bool`, `evaluate(DayContext $ctx): void`.
  - `RuleEngine` — `__construct(iterable $evaluators)`; `apply(DayContext $ctx): void` runs each supporting evaluator in registration order.
  - `RoundingEvaluator` — `supports` when `$p->rounding()` set; rounds `firstIn`/`lastOut` to the configured unit (`strategy` nearest|quarter_hour|seven_minute; `unit_minutes`; `direction` nearest|up|down) before minute math.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\DTO\DayContext;
use App\Services\Attendance\DTO\PolicyProfile;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Attendance\Rules\RoundingEvaluator;
use Carbon\Carbon;
use Tests\TestCase;

class RoundingEvaluatorTest extends TestCase
{
    private function shift(): ShiftSchedule
    {
        return new ShiftSchedule(
            start: Carbon::parse('2026-06-19 09:00'), end: Carbon::parse('2026-06-19 17:00'),
            crossesMidnight: false, graceInMinutes: 0, graceOutMinutes: 0, fullDayMinutes: 0,
            halfDayMinutes: 0, minPresentMinutes: 0, breakMinutes: 0, isWorkingDay: true,
        );
    }

    public function test_quarter_hour_nearest_rounds_first_in_and_last_out(): void
    {
        $policy = new PolicyProfile(rounding: ['strategy' => 'quarter_hour', 'unit_minutes' => 15, 'direction' => 'nearest']);
        $ctx = new DayContext(
            firstIn: Carbon::parse('2026-06-19 09:07'), lastOut: Carbon::parse('2026-06-19 17:08'),
            workedMinutes: 0, flags: [], shift: $this->shift(), policy: $policy,
        );
        (new RoundingEvaluator)->evaluate($ctx);
        $this->assertSame('09:00', $ctx->firstIn->format('H:i')); // 09:07 → nearest 15 = 09:00
        $this->assertSame('17:15', $ctx->lastOut->format('H:i')); // 17:08 → nearest 15 = 17:15
    }

    public function test_does_not_support_neutral_policy(): void
    {
        $this->assertFalse((new RoundingEvaluator)->supports(PolicyProfile::neutral()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RoundingEvaluatorTest`
Expected: FAIL — classes missing.

- [ ] **Step 3: `DayContext`**

```php
<?php

namespace App\Services\Attendance\DTO;

use Carbon\Carbon;

final class DayContext
{
    public function __construct(
        public ?Carbon $firstIn,
        public ?Carbon $lastOut,
        public int $workedMinutes,
        public array $flags,
        public ShiftSchedule $shift,
        public PolicyProfile $policy,
    ) {}
}
```

- [ ] **Step 4: `Contracts\RuleEvaluator`**

```php
<?php

namespace App\Services\Attendance\Contracts;

use App\Services\Attendance\DTO\DayContext;
use App\Services\Attendance\DTO\PolicyProfile;

interface RuleEvaluator
{
    public function supports(PolicyProfile $policy): bool;

    public function evaluate(DayContext $ctx): void;
}
```

- [ ] **Step 5: `RuleEngine`**

```php
<?php

namespace App\Services\Attendance;

use App\Services\Attendance\Contracts\RuleEvaluator;
use App\Services\Attendance\DTO\DayContext;

class RuleEngine
{
    /** @var RuleEvaluator[] */
    private array $evaluators;

    public function __construct(RuleEvaluator ...$evaluators)
    {
        $this->evaluators = $evaluators;
    }

    public function apply(DayContext $ctx): void
    {
        foreach ($this->evaluators as $evaluator) {
            if ($evaluator->supports($ctx->policy)) {
                $evaluator->evaluate($ctx);
            }
        }
    }
}
```

- [ ] **Step 6: `RoundingEvaluator`**

```php
<?php

namespace App\Services\Attendance\Rules;

use App\Services\Attendance\Contracts\RuleEvaluator;
use App\Services\Attendance\DTO\DayContext;
use App\Services\Attendance\DTO\PolicyProfile;
use Carbon\Carbon;

class RoundingEvaluator implements RuleEvaluator
{
    public function supports(PolicyProfile $policy): bool
    {
        $r = $policy->rounding();

        return is_array($r) && ($r['strategy'] ?? 'none') !== 'none';
    }

    public function evaluate(DayContext $ctx): void
    {
        $r = $ctx->policy->rounding();
        $unit = max(1, (int) ($r['unit_minutes'] ?? ($r['strategy'] === 'seven_minute' ? 15 : 15)));
        $dir = $r['direction'] ?? 'nearest';

        if ($ctx->firstIn) {
            $ctx->firstIn = $this->round($ctx->firstIn, $unit, $dir);
        }
        if ($ctx->lastOut) {
            $ctx->lastOut = $this->round($ctx->lastOut, $unit, $dir);
        }
    }

    private function round(Carbon $t, int $unit, string $dir): Carbon
    {
        $minutes = $t->hour * 60 + $t->minute;
        $rounded = match ($dir) {
            'up' => (int) (ceil($minutes / $unit) * $unit),
            'down' => (int) (floor($minutes / $unit) * $unit),
            default => (int) (round($minutes / $unit) * $unit),
        };

        return $t->copy()->startOfDay()->addMinutes($rounded);
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=RoundingEvaluatorTest`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Services/Attendance/DTO/DayContext.php app/Services/Attendance/Contracts/RuleEvaluator.php app/Services/Attendance/RuleEngine.php app/Services/Attendance/Rules/RoundingEvaluator.php tests/Unit/Attendance/RoundingEvaluatorTest.php
git commit -m "feat(attendance): rule engine + rounding evaluator"
```

---

### Task 4: `GraceTiersEvaluator` + thread `PolicyProfile` through `AttendanceStatusService` (back-compat)

**Files:**
- Create: `app/Services/Attendance/Rules/GraceTiersEvaluator.php`, `tests/Unit/Attendance/GraceTiersEvaluatorTest.php`
- Modify: `app/Services/Attendance/AttendanceStatusService.php` (add `?PolicyProfile $policy = null`; when non-neutral, run rounding via the engine BEFORE minute math and apply grace tiers for late/half-day classification; neutral default = unchanged behavior)
- Test: extend `tests/Unit/Attendance/AttendanceStatusServiceTest.php` with a tiered case + a neutral back-compat assertion

**Interfaces:**
- Consumes: `DayContext`, `PolicyProfile`, `RuleEngine`, `RoundingEvaluator`.
- Produces: `GraceTiersEvaluator` — `supports` when `graceTiers()['late']` set; reads `firstIn` vs `shift->start`, walks the ordered `late` bands, and on a band whose `outcome` is `half_day` adds flag `tier_half_day`; on `late` adds flag `tier_late`; on `present` no flag. (The status service reads these flags to pick the status, keeping the evaluator pure and the status precedence centralized.) `AttendanceStatusService::resolve(...)` gains a trailing `?PolicyProfile $policy = null`; when `$policy` is null or `->isNeutral()`, behavior is byte-identical to today.

- [ ] **Step 1: Write the failing tests** (new file + the back-compat assertion)

```php
<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\AttendanceStatusService;
use App\Services\Attendance\DTO\DayAttendance;
use App\Services\Attendance\DTO\PolicyProfile;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Tests\TestCase;

class GraceTiersEvaluatorTest extends TestCase
{
    private function shift(string $start = '09:00'): ShiftSchedule
    {
        return new ShiftSchedule(
            start: Carbon::parse("2026-06-19 $start"), end: Carbon::parse('2026-06-19 17:00'),
            crossesMidnight: false, graceInMinutes: 15, graceOutMinutes: 0, fullDayMinutes: 0,
            halfDayMinutes: 0, minPresentMinutes: 0, breakMinutes: 0, isWorkingDay: true,
        );
    }

    private function punch(string $in, string $out): object
    {
        return (object) ['punchin' => Carbon::parse($in), 'punchout' => Carbon::parse($out)];
    }

    public function test_tier_classifies_late_when_in_late_band(): void
    {
        // tiers: 0-10 present, 10-30 late, 30+ half_day. In at 09:20 → 20 min late → 'late'.
        $policy = new PolicyProfile(graceTiers: ['late' => [
            ['upto_minutes' => 10, 'outcome' => 'present'],
            ['upto_minutes' => 30, 'outcome' => 'late'],
            ['upto_minutes' => 9999, 'outcome' => 'half_day'],
        ]]);
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 09:20', '2026-06-19 17:00')]), $this->shift(), policy: $policy,
        );
        $this->assertSame(DayAttendance::LATE, $r->status);
    }

    public function test_tier_half_day_when_beyond_late_band(): void
    {
        $policy = new PolicyProfile(graceTiers: ['late' => [
            ['upto_minutes' => 10, 'outcome' => 'present'],
            ['upto_minutes' => 30, 'outcome' => 'late'],
            ['upto_minutes' => 9999, 'outcome' => 'half_day'],
        ]]);
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 10:00', '2026-06-19 17:00')]), $this->shift(), policy: $policy,
        );
        $this->assertSame(DayAttendance::HALF_DAY, $r->status);
    }

    public function test_neutral_policy_is_back_compat(): void
    {
        // On-time 09:05 with neutral policy → PRESENT, identical to no-policy.
        $svc = new AttendanceStatusService;
        $punches = collect([$this->punch('2026-06-19 09:05', '2026-06-19 17:30')]);
        $a = $svc->resolve($punches, $this->shift());
        $b = $svc->resolve($punches, $this->shift(), policy: PolicyProfile::neutral());
        $this->assertSame($a->status, $b->status);
        $this->assertSame(DayAttendance::PRESENT, $b->status);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter=GraceTiersEvaluatorTest`
Expected: FAIL — `resolve` has no `policy` param / tiers unhandled.

- [ ] **Step 3: `GraceTiersEvaluator`**

```php
<?php

namespace App\Services\Attendance\Rules;

use App\Services\Attendance\Contracts\RuleEvaluator;
use App\Services\Attendance\DTO\DayContext;
use App\Services\Attendance\DTO\PolicyProfile;

class GraceTiersEvaluator implements RuleEvaluator
{
    public function supports(PolicyProfile $policy): bool
    {
        $t = $policy->graceTiers();

        return is_array($t) && ! empty($t['late']);
    }

    public function evaluate(DayContext $ctx): void
    {
        if (! $ctx->firstIn || ! $ctx->shift->isWorkingDay) {
            return;
        }
        $lateMin = max(0, (int) round($ctx->shift->start->diffInMinutes($ctx->firstIn, false)));
        if ($ctx->firstIn->lessThanOrEqualTo($ctx->shift->start)) {
            $lateMin = 0;
        }
        foreach ($ctx->policy->graceTiers()['late'] as $band) {
            if ($lateMin <= (int) $band['upto_minutes']) {
                if ($band['outcome'] === 'late') {
                    $ctx->flags[] = 'tier_late';
                } elseif ($band['outcome'] === 'half_day') {
                    $ctx->flags[] = 'tier_half_day';
                }

                return;
            }
        }
    }
}
```

- [ ] **Step 4: Thread the policy through `AttendanceStatusService`**

Add `use App\Services\Attendance\DTO\PolicyProfile;`, `use App\Services\Attendance\DTO\DayContext;`, `use App\Services\Attendance\Rules\RoundingEvaluator;`, `use App\Services\Attendance\Rules\GraceTiersEvaluator;`. Change the signature to:

```php
    public function resolve(
        Collection $punches,
        ShiftSchedule $shift,
        bool $isHoliday = false,
        bool $isOnLeave = false,
        ?CarbonInterface $now = null,
        ?PolicyProfile $policy = null,
    ): DayAttendance {
```

In the has-punches branch, AFTER computing `$firstIn`/`$lastOut`/`$workedMinutes` and BEFORE the existing late/early/OT block, insert:

```php
        $policy ??= PolicyProfile::neutral();
        if (! $policy->isNeutral()) {
            $ctx = new DayContext($firstIn, $lastOut, $workedMinutes, $flags, $shift, $policy);
            (new RuleEngine(new RoundingEvaluator, new GraceTiersEvaluator))->apply($ctx);
            $firstIn = $ctx->firstIn;
            $lastOut = $ctx->lastOut;
            $flags = $ctx->flags;
            // Recompute worked minutes if rounding changed the boundaries.
            if ($policy->rounding()) {
                $workedMinutes = $firstIn && $lastOut ? max(0, (int) round($firstIn->diffInMinutes($lastOut))) : $workedMinutes;
            }
        }
```

Then, where status precedence is decided, honor tier flags (tiers take precedence over the single-grace `late`):

```php
        if (in_array('tier_half_day', $flags, true)) {
            $status = DayAttendance::HALF_DAY;
        } elseif (in_array('tier_late', $flags, true)) {
            $status = DayAttendance::LATE;
        } elseif ($shift->minPresentMinutes > 0 && $workedMinutes < $shift->minPresentMinutes) {
            $status = DayAttendance::SHORT;
        } elseif ($shift->halfDayMinutes > 0 && $workedMinutes < $shift->halfDayMinutes) {
            $status = DayAttendance::HALF_DAY;
        } elseif ($lateMinutes > 0) {
            $status = DayAttendance::LATE;
        }
```

(Keep `array_values(array_unique($flags))` at the end. When tiers are present, you may keep `$lateMinutes` computed as-is for reporting; the tier flags govern the *status*.)

- [ ] **Step 5: Run the new tests + the full back-compat sweep**

Run: `php artisan test --filter=GraceTiersEvaluatorTest` (PASS). Then `php artisan test --filter=Attendance` — ALL existing tests still green (neutral default guarantees no regression). If any pre-existing assertion changed, you broke back-compat — revert the status-precedence edit and gate tier logic strictly behind `! $policy->isNeutral()`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/Rules/GraceTiersEvaluator.php app/Services/Attendance/AttendanceStatusService.php tests/Unit/Attendance/GraceTiersEvaluatorTest.php
git commit -m "feat(attendance): graduated grace tiers in the status engine (neutral = back-compat)"
```

---

### Task 5: `PunchPolicyGuard` + capture enforcement (records always; marks provisional; never blocks)

**Files:**
- Create: `app/Services/Attendance/PunchPolicyGuard.php`, `tests/Feature/Attendance/PunchPolicyGuardTest.php`
- Modify: `app/Services/Attendance/AttendancePunchService.php` (after validation, before/after persisting, set `policy_status`/`needs_approval`/`policy_exception_reason`)

**Interfaces:**
- Consumes: `PolicyResolver`, `ScheduleResolver`, `PolicyProfile`, `ShiftSchedule`.
- Produces: `PunchPolicyGuard::assess(int $userId, CarbonInterface $punchMoment): array` → `['policy_status' => 'accepted'|'provisional', 'needs_approval' => bool, 'reason' => ?string, 'warning' => ?string]`. Logic: resolve policy + schedule for the day; if `strictness === 'warn'` → always accepted (warning string if out-of-window); `flag` → provisional+needs_approval; `restrict` → provisional+needs_approval ONLY when out-of-window (`punchMoment` before `start − outsideWindow` or after `end + outsideWindow`); otherwise accepted. NEVER throws / NEVER denies.

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=PunchPolicyGuardTest`
Expected: FAIL — guard missing.

- [ ] **Step 3: `PunchPolicyGuard`**

```php
<?php

namespace App\Services\Attendance;

use App\Services\Attendance\Contracts\PolicyResolver;
use App\Services\Attendance\Contracts\ScheduleResolver;
use Carbon\CarbonInterface;

class PunchPolicyGuard
{
    public function __construct(
        private readonly PolicyResolver $policies,
        private readonly ScheduleResolver $schedules,
    ) {}

    public function assess(int $userId, CarbonInterface $punchMoment): array
    {
        $policy = $this->policies->resolve($userId, $punchMoment);
        $accepted = ['policy_status' => 'accepted', 'needs_approval' => false, 'reason' => null, 'warning' => null];

        if ($policy->strictness() === 'warn' && $policy->isNeutral()) {
            return $accepted;
        }

        $shift = $this->schedules->resolve($userId, $punchMoment);
        $outOfWindow = false;
        if ($shift->isWorkingDay) {
            $earliest = $shift->start->copy()->subMinutes($policy->outsideWindowMinutes());
            $latest = $shift->end->copy()->addMinutes($policy->outsideWindowMinutes());
            $outOfWindow = $punchMoment->lessThan($earliest) || $punchMoment->greaterThan($latest);
        }

        return match ($policy->strictness()) {
            'flag' => ['policy_status' => 'provisional', 'needs_approval' => true, 'reason' => 'flagged by policy', 'warning' => null],
            'restrict' => $outOfWindow
                ? ['policy_status' => 'provisional', 'needs_approval' => true, 'reason' => 'outside permitted window', 'warning' => null]
                : $accepted,
            default => $outOfWindow
                ? $accepted + ['warning' => 'Punch is outside your shift window.']
                : $accepted,
        };
    }
}
```

- [ ] **Step 4: Wire into `AttendancePunchService::processPunch`** — after the existing validation succeeds and you have the `$user` + the punch `Carbon` moment (search for where the `Attendance` row is created/updated), call the guard and persist the result onto the saved row:

```php
        $assessment = app(\App\Services\Attendance\PunchPolicyGuard::class)->assess($user->id, $punchMoment);
        $attendance->forceFill([
            'policy_status' => $assessment['policy_status'],
            'needs_approval' => $assessment['needs_approval'],
            'policy_exception_reason' => $assessment['reason'],
        ])->save();
```

Include `$assessment['warning']` in the JSON response payload when non-null (non-blocking). DO NOT add any branch that returns an error / prevents the save.

- [ ] **Step 5: Run the guard test + the punch tests**

Run: `php artisan test --filter=PunchPolicyGuardTest` (PASS). Then `php artisan test --filter="Punch|Attendance"` — existing punch tests stay green (default neutral policy ⇒ accepted).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/PunchPolicyGuard.php app/Services/Attendance/AttendancePunchService.php tests/Feature/Attendance/PunchPolicyGuardTest.php
git commit -m "feat(attendance): punch policy guard marks provisional punches (never blocks capture)"
```

---

### Task 6: Punch-exception approvals (list / approve / reject) — reuse the approval inbox

**Files:**
- Create: `app/Services/Attendance/PunchExceptionService.php`, `app/Http/Controllers/HRM/PunchExceptionController.php`, `tests/Feature/Attendance/PunchExceptionApiTest.php`
- Modify: `routes/web.php`

**Interfaces:**
- Consumes: `Attendance` (with `policy_status`/`needs_approval`), `AttendanceAuditService`.
- Produces:
  - `PunchExceptionService::pending(): Collection` — `Attendance::where('needs_approval', true)->where('policy_status','provisional')` with `user:id,name`.
  - `PunchExceptionService::approve(int $attendanceId, User $approver): array` — set `policy_status='accepted'`, `needs_approval=false`; audit `policy.exception.approve`; return `{success,status}`.
  - `PunchExceptionService::reject(int $attendanceId, User $approver, string $reason): array` — set `policy_status='rejected'`, `needs_approval=false`, reason; audit `policy.exception.reject`. (Rejected rows are excluded from worked-minutes by the status service — add: when building punches for the engine, skip `policy_status==='rejected'` rows. Make that filter change in the query layer that feeds the engine, mirroring how punches are currently loaded.)
  - Routes (gated `permission:attendance.manage`): `GET /attendance/punch-exceptions/pending` (`attendance.punch-exceptions.pending`), `POST /attendance/punch-exceptions/{id}/approve`, `POST /attendance/punch-exceptions/{id}/reject`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PunchExceptionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Manager']);
        Permission::firstOrCreate(['name' => 'attendance.manage']);
    }

    public function test_manager_approves_a_provisional_punch(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('attendance.manage');
        $emp = User::factory()->create();
        $att = Attendance::factory()->for($emp)->create([
            'date' => '2026-06-19', 'punchin' => '2026-06-19 06:00:00', 'punchout' => '2026-06-19 14:00:00',
            'policy_status' => 'provisional', 'needs_approval' => true, 'policy_exception_reason' => 'outside permitted window',
        ]);

        $this->actingAs($manager)->getJson(route('attendance.punch-exceptions.pending'))
            ->assertOk()->assertJsonFragment(['id' => $att->id]);

        $this->actingAs($manager)->postJson(route('attendance.punch-exceptions.approve', $att->id))->assertOk();
        $this->assertSame('accepted', $att->fresh()->policy_status);
        $this->assertFalse((bool) $att->fresh()->needs_approval);
    }

    public function test_employee_cannot_access_pending(): void
    {
        $emp = User::factory()->create();
        $this->actingAs($emp)->getJson(route('attendance.punch-exceptions.pending'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=PunchExceptionApiTest`
Expected: FAIL — routes/service missing.

- [ ] **Step 3: `PunchExceptionService`** (full impl)

```php
<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PunchExceptionService
{
    public function __construct(private readonly AttendanceAuditService $audit) {}

    public function pending(): Collection
    {
        return Attendance::where('needs_approval', true)->where('policy_status', 'provisional')
            ->with('user:id,name')->orderByDesc('date')->get();
    }

    public function approve(int $attendanceId, User $approver): array
    {
        return DB::transaction(function () use ($attendanceId, $approver) {
            $att = Attendance::findOrFail($attendanceId);
            $before = $att->only(['policy_status', 'needs_approval']);
            $att->update(['policy_status' => 'accepted', 'needs_approval' => false]);
            $this->audit->record('policy.exception.approve', $att->id, $before, $att->only(['policy_status', 'needs_approval']), 'Punch exception approved by '.$approver->id, null);

            return ['success' => true, 'status' => 'accepted'];
        });
    }

    public function reject(int $attendanceId, User $approver, string $reason): array
    {
        return DB::transaction(function () use ($attendanceId, $approver, $reason) {
            $att = Attendance::findOrFail($attendanceId);
            $before = $att->only(['policy_status', 'needs_approval']);
            $att->update(['policy_status' => 'rejected', 'needs_approval' => false, 'policy_exception_reason' => $reason]);
            $this->audit->record('policy.exception.reject', $att->id, $before, $att->only(['policy_status', 'needs_approval']), $reason, null);

            return ['success' => true, 'status' => 'rejected'];
        });
    }
}
```

- [ ] **Step 4: Controller + routes** — `PunchExceptionController` mirrors `RegularizationController`'s thin shape (`pending`/`approve`/`reject` delegating to `PunchExceptionService`; `approve` returns `response()->json($res)`; `reject` validates `reason required|string|max:500`). Routes go INSIDE the `Route::middleware(['permission:attendance.manage'])->group(...)` block in routes/web.php (the one with `attendance.regularizations.pending`):

```php
        Route::get('/attendance/punch-exceptions/pending', [\App\Http\Controllers\HRM\PunchExceptionController::class, 'pending'])->name('attendance.punch-exceptions.pending');
        Route::post('/attendance/punch-exceptions/{id}/approve', [\App\Http\Controllers\HRM\PunchExceptionController::class, 'approve'])->name('attendance.punch-exceptions.approve');
        Route::post('/attendance/punch-exceptions/{id}/reject', [\App\Http\Controllers\HRM\PunchExceptionController::class, 'reject'])->name('attendance.punch-exceptions.reject');
```

- [ ] **Step 5: Exclude rejected punches from the engine** — find where punches are loaded for `AttendanceStatusService` (grep `AttendanceStatusService` usages / the query service that fetches a day's `Attendance` rows) and add `->where('policy_status', '!=', 'rejected')` (or filter the collection) so rejected punches don't count toward worked-minutes/status. Add a focused assertion to `PunchExceptionApiTest` if a natural seam exists; otherwise note it for the sweep.

- [ ] **Step 6: Run + commit**

Run: `php artisan test --filter=PunchExceptionApiTest` (PASS); `php artisan test --filter=NavigationRoutesTest` (route registration healthy; only the known org-directory failure).
```bash
git add app/Services/Attendance/PunchExceptionService.php app/Http/Controllers/HRM/PunchExceptionController.php routes/web.php tests/Feature/Attendance/PunchExceptionApiTest.php
git commit -m "feat(attendance): punch-exception approval API (provisional → accepted/rejected)"
```

---

### Task 7: `PolicySimulationService` + simulate endpoint (read-only impact preview)

**Files:**
- Create: `app/Services/Attendance/PolicySimulationService.php`, `tests/Feature/Attendance/PolicySimulationTest.php`
- Modify: `app/Http/Controllers/HRM/PolicyController.php` (added in Task 8 — if executing strictly in order, add the `simulate` method there; the service + test can land here)

**Interfaces:**
- Produces: `PolicySimulationService::simulate(AttendancePolicy $draft, array $userIds, string $from, string $to): array` — for each user-day in range, resolve the CURRENT profile vs. a profile built from `$draft`, run `AttendanceStatusService` both ways (read-only, no writes), and return a diff summary `['days' => int, 'changed' => int, 'samples' => [['user_id','date','before_status','after_status'], ...up to 50]]`. Writes NOTHING.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendancePolicy;
use App\Models\User;
use App\Services\Attendance\PolicySimulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicySimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulation_reports_a_diff_without_writing(): void
    {
        $u = User::factory()->create();
        Attendance::factory()->for($u)->create([
            'date' => '2026-06-19', 'punchin' => '2026-06-19 09:20:00', 'punchout' => '2026-06-19 17:00:00',
        ]);
        $draft = AttendancePolicy::factory()->make([
            'status' => 'draft', 'punch_strictness' => 'warn',
            'grace_tiers' => ['late' => [['upto_minutes' => 5, 'outcome' => 'present'], ['upto_minutes' => 9999, 'outcome' => 'late']]],
        ]);

        $before = Attendance::count();
        $res = app(PolicySimulationService::class)->simulate($draft, [$u->id], '2026-06-19', '2026-06-19');
        $this->assertSame($before, Attendance::count()); // no writes
        $this->assertArrayHasKey('changed', $res);
        $this->assertGreaterThanOrEqual(0, $res['changed']);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `php artisan test --filter=PolicySimulationTest` → FAIL (service missing).

- [ ] **Step 3: Implement `PolicySimulationService`** — resolve each user-day's punches + `ShiftSchedule` (via the bound `ScheduleResolver`), build a `PolicyProfile` from the `$draft` (`new PolicyProfile($draft->punch_strictness, $draft->outside_window_minutes, $draft->grace_tiers, $draft->rounding)`), call `AttendanceStatusService::resolve($punches, $shift)` (current, neutral) and again with `policy: $draftProfile`, compare `->status`, accumulate counts + up to 50 samples. No DB writes.

- [ ] **Step 4: Run to verify it passes** — `php artisan test --filter=PolicySimulationTest` → PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/PolicySimulationService.php tests/Feature/Attendance/PolicySimulationTest.php
git commit -m "feat(attendance): read-only policy simulation (impact preview)"
```

---

### Task 8: `PolicyController` CRUD + activate + simulate + routes

**Files:**
- Create: `app/Http/Controllers/HRM/PolicyController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Attendance/PolicyApiTest.php`

**Interfaces:**
- Produces (all gated `permission:attendance.settings`):
  - `GET /attendance/policies` (`attendance.policies.index`) → list policies mapped to PLAIN arrays (`->get()->map(fn($p)=>[...])->values()`).
  - `POST /attendance/policies` (`attendance.policies.store`) → validate + create as `draft`; 201.
  - `PUT /attendance/policies/{id}` (`attendance.policies.update`) → update a draft.
  - `POST /attendance/policies/{id}/activate` (`attendance.policies.activate`) → set `status='active'`, supersede the prior active version in the same `version_group_id` (set its `effective_to` and `status='archived'`), in a `DB::transaction`; audit `policy.activate`.
  - `POST /attendance/policies/simulate` (`attendance.policies.simulate`) → build a draft `AttendancePolicy` from the request (not persisted) + `PolicySimulationService::simulate`.
  - Validation: `name required|string|max:120`; `scope_type required|in:org,department,designation,user`; `scope_id nullable|integer`; `effective_from required|date`; `effective_to nullable|date|after_or_equal:effective_from`; `punch_strictness required|in:warn,flag,restrict`; `outside_window_minutes integer|min:0|max:1440`; `grace_tiers nullable|array`; `rounding nullable|array`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendancePolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PolicyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    public function test_admin_creates_and_activates_a_policy(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('attendance.settings');

        $this->actingAs($admin)->postJson(route('attendance.policies.store'), [
            'name' => 'Night strict', 'scope_type' => 'org', 'effective_from' => '2026-06-01',
            'punch_strictness' => 'restrict', 'outside_window_minutes' => 60,
        ])->assertCreated();

        $p = AttendancePolicy::first();
        $this->assertSame('draft', $p->status);

        $this->actingAs($admin)->postJson(route('attendance.policies.activate', $p->id))->assertOk();
        $this->assertSame('active', $p->fresh()->status);
    }

    public function test_employee_cannot_manage_policies(): void
    {
        $emp = User::factory()->create();
        $this->actingAs($emp)->getJson(route('attendance.policies.index'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `php artisan test --filter=PolicyApiTest` → FAIL.

- [ ] **Step 3: Controller + routes** — implement `PolicyController` (thin; `version_group_id` defaults to `max(version_group_id)+1` on create; `version=1`). Add routes INSIDE the `Route::middleware(['permission:attendance.settings'])->group(...)` block in routes/web.php. Map models to plain arrays in `index`.

- [ ] **Step 4: Run to verify it passes** — `php artisan test --filter=PolicyApiTest` → PASS; then `php artisan test --filter=NavigationRoutesTest` (only the known org-directory failure).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/HRM/PolicyController.php routes/web.php tests/Feature/Attendance/PolicyApiTest.php
git commit -m "feat(attendance): policy CRUD + activation + simulate API"
```

---

### Task 9: Frontend — Policies admin UI + Punch Exceptions inbox section

**Files:**
- Create: `resources/js/Forms/PolicyForm.jsx`, `resources/js/Pages/Attendance/Components/PoliciesManager.jsx`, `resources/js/Pages/Attendance/Components/PunchExceptions.jsx`
- Modify: `resources/js/Pages/Attendance/SettingsTab.jsx` (mount `<PoliciesManager/>` as a new "Policies" section, gated `attendance.settings`), `resources/js/Pages/Attendance/Components/ApprovalsInbox.jsx` (add a `<PunchExceptions/>` section)
- Verify: `npx vite build` + Playwright HTTP-status.

**Interfaces:**
- Consumes (web, PLAIN json; `requestJson(method,url,{params|data})`):
  - `GET /attendance/policies` → `{ policies:[...] }`; `POST /attendance/policies`; `PUT /attendance/policies/{id}`; `POST /attendance/policies/{id}/activate`; `POST /attendance/policies/simulate` → `{ days, changed, samples }`.
  - `GET /attendance/punch-exceptions/pending` → `{ exceptions:[...] }` (or the raw collection — match the controller); `POST .../{id}/approve|reject`.
- Produces: `PolicyForm` (Radix Dialog: scope_type Select + scope_id, strictness Select warn/flag/restrict, a grace-tier rows editor [upto_minutes + outcome Select present/late/half_day], a rounding Select [none/nearest/quarter_hour/seven_minute] + unit, effective_from/to). `PoliciesManager` (Radix Table list + "New policy" + per-row Activate + a "Preview impact" button calling `/simulate` and showing `{changed}/{days}` + samples). `PunchExceptions` (Radix Table of provisional punches with Approve/Reject, mirroring `SwapApprovals.jsx`).

- [ ] **Step 1: Read the sibling patterns** — `resources/js/Forms/ShiftAssignmentForm.jsx` (scope select + effective dates), `resources/js/Pages/Attendance/Components/AssignmentManager.jsx` (list + CRUD), `resources/js/Pages/Attendance/Components/SwapApprovals.jsx` (approve/reject table), `resources/js/Pages/Attendance/SettingsTab.jsx` (how sub-sections mount). Match them.

- [ ] **Step 2: Build `PolicyForm.jsx`** — Radix Dialog mirroring `ShiftAssignmentForm.jsx`; `requestJson('post', '/attendance/policies', { data })` / `requestJson('put', ...)`. Grace tiers + rounding as small sub-editors. Surface 422 via `showToast.error(err?.message)`.

- [ ] **Step 3: Build `PoliciesManager.jsx`** — `useQuery(['policies'], () => requestJson('get','/attendance/policies'))`; Radix Table; New/Edit open `PolicyForm`; Activate via `requestJson('post', \`/attendance/policies/${id}/activate\`)`; "Preview impact" calls `requestJson('post','/attendance/policies/simulate',{data})` and renders `changed/days` + a few samples. Invalidate `['policies']` on success.

- [ ] **Step 4: Build `PunchExceptions.jsx`** — mirror `SwapApprovals.jsx`: `useQuery(['punch-exceptions'])`, approve/reject `useMutation`, reject reason via `window.prompt` (v1), invalidate on success. Status `Badge`s.

- [ ] **Step 5: Mount** — add a "Policies" section to `SettingsTab.jsx` (gated `auth.permissions?.includes('attendance.settings')`) hosting `<PoliciesManager/>`; add `<PunchExceptions/>` into `ApprovalsInbox.jsx` as a third section.

- [ ] **Step 6: Build + verify (SOURCE-ONLY commit)** — `npx vite build` (compiles clean; NEVER `npm run build`). On the dev server (`https://aero-enterprise-suite.test`), confirm by HTTP status: `GET /attendance/policies` → 200 for an `attendance.settings` user; create+activate a policy → 200/201; `GET /attendance/punch-exceptions/pending` → 200. Do NOT stage `public/build`.

- [ ] **Step 7: Commit** (source only)

```bash
git add resources/js/Forms/PolicyForm.jsx resources/js/Pages/Attendance/Components/PoliciesManager.jsx resources/js/Pages/Attendance/Components/PunchExceptions.jsx resources/js/Pages/Attendance/SettingsTab.jsx resources/js/Pages/Attendance/Components/ApprovalsInbox.jsx
git commit -m "feat(attendance): policies admin UI + punch-exceptions inbox section"
```

---

### Task 10: Phase 3.0 acceptance sweep

**Files:** Verify only + one consolidated `public/build` rebuild.

- [ ] **Step 1: Full suite** — `php artisan test`; only the 2 known pre-existing failures. Pay special attention: the entire `--filter=Attendance` engine/daily/monthly set is still green (back-compat).
- [ ] **Step 2: Migrate dev DB** — `php artisan migrate:status` shows `attendance_policies` + the `attendances` policy-flags migration Ran on `dbedc_guardian`. Run the default seeder: `php artisan db:seed --class=AttendancePolicyDefaultSeeder`.
- [ ] **Step 3: Build + commit assets** — `npx vite build`; `git add public/build && git commit -m "build(attendance): rebuild assets for Phase 3.0 policy core"`.
- [ ] **Step 4: End-to-end browser pass (HTTP status, not shells)** — as an `attendance.settings` admin: create a `restrict` org policy (out-of-window 60), activate it, run "Preview impact" (2xx + a diff). As an employee under it, punch out-of-window → punch still succeeds (2xx) and the row is `provisional`. As an `attendance.manage` manager: the punch appears in Punch Exceptions → approve → row `accepted`. Confirm each endpoint 2xx and `/attendance` + `/attendance-employee` render 200.
- [ ] **Step 5: Closeout** — `git commit --allow-empty -m "test(attendance): Phase 3.0 policy core acceptance sweep"`.

---

## Self-Review

**Spec coverage (Phase 3.0 scope §10):** policies table/model → Task 1; resolver + neutral fallback + seeder → Task 2; rule engine + rounding → Task 3; grace tiers + status threading + back-compat → Task 4; capture guard (never blocks) → Task 5; exception approvals (reuse Phase 2 audit) → Task 6; simulation → Task 7; policy CRUD/activate/simulate API → Task 8; admin UI + inbox section → Task 9; sweep → Task 10. ✅

**Placeholder scan:** Backend tasks (1–8) carry full code + tests. Frontend Task 9 gives exact endpoints + component contracts and requires reading the named sibling components (`ShiftAssignmentForm.jsx`, `AssignmentManager.jsx`, `SwapApprovals.jsx`, `SettingsTab.jsx`) for the established patterns — same approach as the Phase 2 plan's frontend tasks. ✅

**Type consistency:** `PolicyProfile` accessors (`strictness/outsideWindowMinutes/graceTiers/rounding/isNeutral`) are used identically across `DbPolicyResolver`, `RuleEngine` evaluators, `PunchPolicyGuard`, `AttendanceStatusService`, and `PolicySimulationService`. `policy_status` values `accepted|provisional|rejected` and route names (`attendance.policies.*`, `attendance.punch-exceptions.*`) match between backend and frontend. The status engine's neutral-default contract is asserted in Task 4 and re-verified in Task 10. ✅

**Back-compat guard:** every status-engine change is gated behind `! $policy->isNeutral()`; the existing `AttendanceStatusServiceTest` and the full `--filter=Attendance` set must stay green unchanged (Tasks 4, 5, 10). ✅
