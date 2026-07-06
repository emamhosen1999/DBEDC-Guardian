# Roster Phase 2 — Post Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the roster into a demand-driven, coverage-aware tool: define required headcount per WorkLocation × Shift (optional role split, recurring rules + per-date overrides), assign a per-day post to each roster cell, and show under/over-staffing computed from actual assignments minus approved leave.

**Architecture:** Two new tables (`coverage_requirements`, plus a `work_location_id` column on `roster_days`) and a read-side `CoverageService` that resolves the effective requirement per (location, shift, role, date) and counts effective assignments minus approved leave (reusing Phase 1's `RosterOverlayService`). Read endpoints feed a coverage panel + requirements-admin dialog inside `RosterTab`; the cell popover gains a post picker. The attendance engine is untouched.

**Tech Stack:** Laravel 11 (PHP 8.2), Eloquent, PHPUnit (sqlite `:memory:` + `RefreshDatabase`); React 18 + Radix Themes + `@tanstack/react-query` v5 + `requestJson`; Vitest (pure-function tests, no `@testing-library/react`).

## Global Constraints

- A "post" = an existing `WorkLocation`. No new location model; no dependency on the (unbuilt) Monitoring domain.
- `coverage_requirements`: `designation_id = null` ⇒ post/shift TOTAL headcount; set ⇒ an independent role requirement. Total and role rows are evaluated independently (role rows need NOT sum to total).
- Requirement resolution precedence per (location, shift, role, date D): `date == D` > matching `weekday`(0=Sun..6=Sat) with `date IS NULL` > all-days (`weekday IS NULL AND date IS NULL`). No match ⇒ untracked (`required=null`), NOT a violation.
- Effective post of a roster day = `roster_days.work_location_id ?? users.work_location_id`.
- Assigned count reduces by APPROVED leave only (full-day −1.0, half-day −0.5) via `RosterOverlayService`; PENDING leave does not reduce; HOLIDAYS do NOT reduce (posts staffed through holidays).
- Status: `understaffed` (assigned<required), `met` (==), `overstaffed` (>). Compare numerically (assigned may be fractional).
- Do NOT modify `AttendanceStatusService`, `RosterScheduleResolver`, `RosterService::resolveShift`, `LeaveDayCalculator`.
- Access control: coverage read + requirements CRUD + roster cell updates live in the existing `permission:attendance.settings` route group (consistent with Phase 1 roster routes); tests grant that permission.
- New migrations MUST be run on dev MySQL `dbedc_guardian` (`php artisan migrate`) or live pages 500 — noted in the final task.
- Frontend: verify with Vitest + `npm run dev`; NEVER `npm run build` (auto-commits/pushes on this machine).
- `weekday` uses Carbon `->dayOfWeek` (0=Sunday..6=Saturday), identical to PHP `date('w')`.

---

### Task 1: Schema + models

**Files:**
- Create: `database/migrations/2026_07_01_000001_create_coverage_requirements_table.php`
- Create: `database/migrations/2026_07_01_000002_add_work_location_id_to_roster_days.php`
- Create: `app/Models/HRM/CoverageRequirement.php`
- Modify: `app/Models/HRM/RosterDay.php`
- Test: `tests/Feature/Attendance/Coverage/CoverageRequirementModelTest.php`

**Interfaces:**
- Produces: table `coverage_requirements(id, work_location_id, shift_id, designation_id nullable, required_headcount, weekday nullable, date nullable, is_active, timestamps)`; `roster_days.work_location_id` nullable FK; `App\Models\HRM\CoverageRequirement` with fillable `['work_location_id','shift_id','designation_id','required_headcount','weekday','date','is_active']`, casts `['required_headcount'=>'integer','weekday'=>'integer','date'=>'date:Y-m-d','is_active'=>'boolean']`, relations `workLocation()`, `shift()`, `designation()`; `RosterDay` gains `work_location_id` in fillable + `workLocation()` relation.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance\Coverage;

use App\Models\HRM\CoverageRequirement;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoverageRequirementModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_coverage_requirement_persists_with_relations(): void
    {
        $loc = WorkLocation::create(['name' => 'Control Room']);
        $shift = Shift::factory()->create(['code' => 'N']);

        $req = CoverageRequirement::create([
            'work_location_id' => $loc->id,
            'shift_id' => $shift->id,
            'designation_id' => null,
            'required_headcount' => 3,
            'weekday' => null,
            'date' => null,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('coverage_requirements', [
            'id' => $req->id, 'work_location_id' => $loc->id, 'required_headcount' => 3,
        ]);
        $this->assertTrue($req->is_active);
        $this->assertSame($loc->id, $req->workLocation->id);
        $this->assertSame($shift->id, $req->shift->id);
    }

    public function test_roster_day_stores_work_location(): void
    {
        $loc = WorkLocation::create(['name' => 'Toll Plaza 2']);
        $user = User::factory()->create();
        $shift = Shift::factory()->create(['code' => 'D']);

        $day = RosterDay::create([
            'user_id' => $user->id, 'date' => '2026-07-06',
            'shift_id' => $shift->id, 'source' => 'manual', 'work_location_id' => $loc->id,
        ]);

        $this->assertSame($loc->id, $day->fresh()->work_location_id);
        $this->assertSame($loc->id, $day->workLocation->id);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=CoverageRequirementModelTest`
Expected: FAIL — table/column/model missing.

- [ ] **Step 3: Create the migrations**

`database/migrations/2026_07_01_000001_create_coverage_requirements_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coverage_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_location_id')->constrained('work_locations')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->unsignedInteger('required_headcount');
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->date('date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['work_location_id', 'shift_id', 'date']);
            $table->index(['work_location_id', 'shift_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_requirements');
    }
};
```

`database/migrations/2026_07_01_000002_add_work_location_id_to_roster_days.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roster_days', function (Blueprint $table) {
            $table->foreignId('work_location_id')->nullable()->after('shift_id')
                ->constrained('work_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('roster_days', function (Blueprint $table) {
            if (Schema::hasColumn('roster_days', 'work_location_id')) {
                $table->dropForeign(['work_location_id']);
                $table->dropColumn('work_location_id');
            }
        });
    }
};
```

- [ ] **Step 4: Create the model + extend RosterDay**

`app/Models/HRM/CoverageRequirement.php`:

```php
<?php

namespace App\Models\HRM;

use App\Models\WorkLocation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoverageRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'work_location_id', 'shift_id', 'designation_id',
        'required_headcount', 'weekday', 'date', 'is_active',
    ];

    protected $casts = [
        'required_headcount' => 'integer',
        'weekday' => 'integer',
        'date' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }
}
```

In `app/Models/HRM/RosterDay.php`, add `work_location_id` to `$fillable` (it currently is `['user_id', 'date', 'shift_id', 'source', 'assignment_id', 'note', 'locked']`) and add the relation:

```php
    protected $fillable = ['user_id', 'date', 'shift_id', 'work_location_id', 'source', 'assignment_id', 'note', 'locked'];
```

```php
    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(\App\Models\WorkLocation::class);
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=CoverageRequirementModelTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_01_000001_create_coverage_requirements_table.php database/migrations/2026_07_01_000002_add_work_location_id_to_roster_days.php app/Models/HRM/CoverageRequirement.php app/Models/HRM/RosterDay.php tests/Feature/Attendance/Coverage/CoverageRequirementModelTest.php
git commit -m "feat(coverage): coverage_requirements table + roster_days.work_location_id"
```

---

### Task 2: `CoverageService` — resolution + range aggregation

**Files:**
- Create: `app/Services/Attendance/CoverageService.php`
- Test: `tests/Feature/Attendance/Coverage/CoverageServiceTest.php`

**Interfaces:**
- Consumes: `CoverageRequirement` (Task 1); `App\Services\Attendance\RosterOverlayService::forRange(array $userIds, string $from, string $to): array` (Phase 1, returns `['leave'=>[uid=>['Y-m-d'=>['type','fraction','session','status']]], 'holidays'=>[...]]`).
- Produces:
  ```
  forRange(string $from, string $to, ?array $locationIds = null): array
  // [ '<Y-m-d>' => [ '<work_location_id>' => [ '<shift_id>' => [
  //     'total' => ['required'=>int|null,'assigned'=>float,'status'=>string|null],
  //     'roles' => [ '<designation_id>' => ['required'=>int,'assigned'=>float,'status'=>string] ],
  // ] ] ] ]
  ```
  Only (location, shift) pairs that have at least one active requirement appear. `required=null`/`status=null` when no requirement resolves for that date.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance\Coverage;

use App\Models\HRM\CoverageRequirement;
use App\Models\HRM\Designation;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use App\Services\Attendance\CoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoverageServiceTest extends TestCase
{
    use RefreshDatabase;

    private function leaveType(): LeaveSetting
    {
        return LeaveSetting::create(['type' => 'Annual', 'symbol' => 'AL', 'days' => 20]);
    }

    public function test_counts_assigned_against_required_with_status(): void
    {
        $loc = WorkLocation::create(['name' => 'Control Room']);
        $shift = Shift::factory()->create(['code' => 'N']);
        $svc = app(CoverageService::class);

        // Require 2 total on all days.
        CoverageRequirement::create([
            'work_location_id' => $loc->id, 'shift_id' => $shift->id,
            'required_headcount' => 2, 'is_active' => true,
        ]);

        // One user assigned at the location (home location fallback), one via per-day override.
        $u1 = User::factory()->create(['work_location_id' => $loc->id]);
        $u2 = User::factory()->create(); // home elsewhere; deployed via roster_day override
        RosterDay::create(['user_id' => $u1->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern']);
        RosterDay::create(['user_id' => $u2->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'manual', 'work_location_id' => $loc->id]);

        $out = $svc->forRange('2026-07-06', '2026-07-06');
        $cell = $out['2026-07-06'][$loc->id][$shift->id]['total'];

        $this->assertSame(2, $cell['required']);
        $this->assertSame(2.0, $cell['assigned']);
        $this->assertSame('met', $cell['status']);
    }

    public function test_approved_full_and_half_leave_reduce_assigned_pending_does_not(): void
    {
        $loc = WorkLocation::create(['name' => 'Toll Plaza 1']);
        $shift = Shift::factory()->create(['code' => 'D']);
        $type = $this->leaveType();
        $svc = app(CoverageService::class);

        CoverageRequirement::create([
            'work_location_id' => $loc->id, 'shift_id' => $shift->id,
            'required_headcount' => 3, 'is_active' => true,
        ]);

        $full = User::factory()->create(['work_location_id' => $loc->id]);
        $half = User::factory()->create(['work_location_id' => $loc->id]);
        $pending = User::factory()->create(['work_location_id' => $loc->id]);
        foreach ([$full, $half, $pending] as $u) {
            RosterDay::create(['user_id' => $u->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern']);
        }
        Leave::create(['user_id' => $full->id, 'leave_type' => $type->id, 'from_date' => '2026-07-06', 'to_date' => '2026-07-06', 'status' => 'approved', 'is_half_day' => false, 'no_of_days' => 1, 'reason' => 't']);
        Leave::create(['user_id' => $half->id, 'leave_type' => $type->id, 'from_date' => '2026-07-06', 'to_date' => '2026-07-06', 'status' => 'approved', 'is_half_day' => true, 'half_day_session' => 'first_half', 'no_of_days' => 1, 'reason' => 't']);
        Leave::create(['user_id' => $pending->id, 'leave_type' => $type->id, 'from_date' => '2026-07-06', 'to_date' => '2026-07-06', 'status' => 'pending', 'is_half_day' => false, 'no_of_days' => 1, 'reason' => 't']);

        $cell = $svc->forRange('2026-07-06', '2026-07-06')['2026-07-06'][$loc->id][$shift->id]['total'];

        // full: 0, half: 0.5, pending: 1.0  => assigned 1.5 of 3 required
        $this->assertSame(1.5, $cell['assigned']);
        $this->assertSame('understaffed', $cell['status']);
    }

    public function test_date_override_beats_weekday_beats_all_days(): void
    {
        $loc = WorkLocation::create(['name' => 'Patrol Base']);
        $shift = Shift::factory()->create(['code' => 'D']);
        $svc = app(CoverageService::class);

        // 2026-07-06 is a Monday (dayOfWeek 1).
        CoverageRequirement::create(['work_location_id' => $loc->id, 'shift_id' => $shift->id, 'required_headcount' => 1, 'is_active' => true]); // all-days
        CoverageRequirement::create(['work_location_id' => $loc->id, 'shift_id' => $shift->id, 'required_headcount' => 2, 'weekday' => 1, 'is_active' => true]); // Monday
        CoverageRequirement::create(['work_location_id' => $loc->id, 'shift_id' => $shift->id, 'required_headcount' => 5, 'date' => '2026-07-06', 'is_active' => true]); // override

        $mon = $svc->forRange('2026-07-06', '2026-07-06')['2026-07-06'][$loc->id][$shift->id]['total'];
        $this->assertSame(5, $mon['required']); // date override wins

        $tue = $svc->forRange('2026-07-07', '2026-07-07')['2026-07-07'][$loc->id][$shift->id]['total'];
        $this->assertSame(1, $tue['required']); // Tuesday → all-days
    }

    public function test_role_requirement_counts_only_matching_designation(): void
    {
        $loc = WorkLocation::create(['name' => 'Toll Plaza 3']);
        $shift = Shift::factory()->create(['code' => 'D']);
        $supervisor = Designation::factory()->create();
        $svc = app(CoverageService::class);

        CoverageRequirement::create(['work_location_id' => $loc->id, 'shift_id' => $shift->id, 'designation_id' => $supervisor->id, 'required_headcount' => 1, 'is_active' => true]);

        $sup = User::factory()->create(['work_location_id' => $loc->id, 'designation_id' => $supervisor->id]);
        $other = User::factory()->create(['work_location_id' => $loc->id]);
        RosterDay::create(['user_id' => $sup->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern']);
        RosterDay::create(['user_id' => $other->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern']);

        $roles = $svc->forRange('2026-07-06', '2026-07-06')['2026-07-06'][$loc->id][$shift->id]['roles'];
        $this->assertSame(1, $roles[$supervisor->id]['required']);
        $this->assertSame(1.0, $roles[$supervisor->id]['assigned']); // only the supervisor counts
        $this->assertSame('met', $roles[$supervisor->id]['status']);
    }

    public function test_query_count_bounded(): void
    {
        $loc = WorkLocation::create(['name' => 'Control Room']);
        $shift = Shift::factory()->create(['code' => 'N']);
        CoverageRequirement::create(['work_location_id' => $loc->id, 'shift_id' => $shift->id, 'required_headcount' => 1, 'is_active' => true]);
        User::factory()->count(4)->create(['work_location_id' => $loc->id])->each(
            fn ($u) => RosterDay::create(['user_id' => $u->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern'])
        );

        DB::enableQueryLog();
        app(CoverageService::class)->forRange('2026-07-01', '2026-07-31');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(5, $count); // requirements + roster/users + leave overlay's 2 = constant
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=CoverageServiceTest`
Expected: FAIL — `CoverageService` not found.

- [ ] **Step 3: Write the implementation**

`app/Services/Attendance/CoverageService.php`:

```php
<?php

namespace App\Services\Attendance;

use App\Models\HRM\CoverageRequirement;
use App\Models\HRM\RosterDay;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Read-side coverage aggregation: for each (location, shift, date) that has an
 * active requirement, resolve the effective required headcount and count the
 * effective assignments minus APPROVED leave. Attendance engine untouched.
 */
class CoverageService
{
    public function __construct(private readonly RosterOverlayService $overlay) {}

    public function forRange(string $from, string $to, ?array $locationIds = null): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        $requirements = CoverageRequirement::query()
            ->where('is_active', true)
            ->when($locationIds, fn ($q) => $q->whereIn('work_location_id', $locationIds))
            ->get();

        if ($requirements->isEmpty()) {
            return [];
        }

        // Distinct (location, shift) pairs that have any requirement.
        $pairs = $requirements->map(fn ($r) => ['loc' => $r->work_location_id, 'shift' => $r->shift_id])
            ->unique(fn ($p) => $p['loc'].'-'.$p['shift'])->values();

        $assigned = $this->assignedWeights($start, $end, $locationIds); // [date][loc][shift]['total'|desigId] => float

        $out = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $dateStr = $d->toDateString();
            $weekday = $d->dayOfWeek; // 0=Sun..6=Sat

            foreach ($pairs as $pair) {
                $loc = $pair['loc'];
                $shift = $pair['shift'];

                // TOTAL (designation_id null)
                $totalReq = $this->resolve($requirements, $loc, $shift, null, $dateStr, $weekday);
                $totalAssigned = (float) ($assigned[$dateStr][$loc][$shift]['total'] ?? 0.0);

                $roles = [];
                foreach ($requirements->where('work_location_id', $loc)->where('shift_id', $shift)->whereNotNull('designation_id')->pluck('designation_id')->unique() as $desigId) {
                    $roleReq = $this->resolve($requirements, $loc, $shift, $desigId, $dateStr, $weekday);
                    if ($roleReq === null) {
                        continue;
                    }
                    $roleAssigned = (float) ($assigned[$dateStr][$loc][$shift][$desigId] ?? 0.0);
                    $roles[$desigId] = [
                        'required' => $roleReq,
                        'assigned' => $roleAssigned,
                        'status' => $this->status($roleAssigned, $roleReq),
                    ];
                }

                // Skip cells with neither a total requirement nor any role requirement.
                if ($totalReq === null && empty($roles)) {
                    continue;
                }

                $out[$dateStr][$loc][$shift] = [
                    'total' => [
                        'required' => $totalReq,
                        'assigned' => $totalAssigned,
                        'status' => $totalReq === null ? null : $this->status($totalAssigned, $totalReq),
                    ],
                    'roles' => $roles,
                ];
            }
        }

        return $out;
    }

    /**
     * Effective required headcount for (loc, shift, role, date) by precedence:
     * exact date > matching weekday (date null) > all-days (weekday null & date null).
     */
    private function resolve($requirements, int $loc, int $shift, ?int $desigId, string $dateStr, int $weekday): ?int
    {
        $scoped = $requirements->filter(fn ($r) => $r->work_location_id === $loc
            && $r->shift_id === $shift
            && $r->designation_id === $desigId);

        $exact = $scoped->firstWhere(fn ($r) => $r->date?->toDateString() === $dateStr);
        if ($exact) {
            return $exact->required_headcount;
        }

        $byWeekday = $scoped->first(fn ($r) => $r->date === null && $r->weekday === $weekday);
        if ($byWeekday) {
            return $byWeekday->required_headcount;
        }

        $allDays = $scoped->first(fn ($r) => $r->date === null && $r->weekday === null);

        return $allDays?->required_headcount;
    }

    /**
     * Effective assigned weight per date/loc/shift, keyed 'total' and by designation_id,
     * reduced by approved leave (full -1.0, half -0.5). Pending/holiday do not reduce.
     */
    private function assignedWeights(CarbonInterface $start, CarbonInterface $end, ?array $locationIds): array
    {
        $rows = RosterDay::query()
            ->join('users', 'users.id', '=', 'roster_days.user_id')
            ->whereBetween('roster_days.date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('roster_days.shift_id')
            ->select([
                'roster_days.date',
                'roster_days.shift_id',
                'roster_days.user_id',
                DB::raw('COALESCE(roster_days.work_location_id, users.work_location_id) as loc_id'),
                'users.designation_id',
            ])
            ->get();

        $rows = $rows->filter(fn ($r) => $r->loc_id !== null
            && ($locationIds === null || in_array((int) $r->loc_id, $locationIds, true)));

        $userIds = $rows->pluck('user_id')->unique()->values()->all();
        $leave = empty($userIds)
            ? []
            : ($this->overlay->forRange($userIds, $start->toDateString(), $end->toDateString())['leave'] ?? []);

        $weights = [];
        foreach ($rows as $r) {
            $date = Carbon::parse($r->date)->toDateString();
            $lv = $leave[$r->user_id][$date] ?? null;
            $weight = 1.0;
            if ($lv && ($lv['status'] ?? null) === 'approved') {
                $weight = 1.0 - (float) $lv['fraction']; // full -> 0, half -> 0.5
            }
            if ($weight <= 0) {
                continue;
            }

            $loc = (int) $r->loc_id;
            $shift = (int) $r->shift_id;
            $weights[$date][$loc][$shift]['total'] = ($weights[$date][$loc][$shift]['total'] ?? 0.0) + $weight;
            if ($r->designation_id !== null) {
                $d = (int) $r->designation_id;
                $weights[$date][$loc][$shift][$d] = ($weights[$date][$loc][$shift][$d] ?? 0.0) + $weight;
            }
        }

        return $weights;
    }

    private function status(float $assigned, int $required): string
    {
        return match (true) {
            $assigned < $required => 'understaffed',
            $assigned > $required => 'overstaffed',
            default => 'met',
        };
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=CoverageServiceTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/CoverageService.php tests/Feature/Attendance/Coverage/CoverageServiceTest.php
git commit -m "feat(coverage): CoverageService resolution + leave-aware assignment counting"
```

---

### Task 3: Coverage + work-locations read endpoints

**Files:**
- Create: `app/Http/Controllers/HRM/CoverageController.php`
- Modify: `routes/web.php` (add routes in the `permission:attendance.settings` group, right after the roster routes near line 595)
- Test: `tests/Feature/Attendance/Coverage/CoverageEndpointTest.php`

**Interfaces:**
- Consumes: `CoverageService::forRange` (Task 2); `WorkLocation`.
- Produces:
  - `GET attendance.coverage.index` (`/attendance/coverage?from&to[&location_id]`) → `{ coverage: <CoverageService payload> }`.
  - `GET attendance.workLocations.index` (`/attendance/work-locations`) → `{ work_locations: [{id,name,code}] }` (active only).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance\Coverage;

use App\Models\HRM\CoverageRequirement;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CoverageEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    private function admin(): User
    {
        $a = User::factory()->create();
        $a->assignRole('Admin');
        $a->givePermissionTo('attendance.settings');

        return $a;
    }

    public function test_coverage_endpoint_returns_payload(): void
    {
        $loc = WorkLocation::create(['name' => 'Control Room']);
        $shift = Shift::factory()->create(['code' => 'N']);
        CoverageRequirement::create(['work_location_id' => $loc->id, 'shift_id' => $shift->id, 'required_headcount' => 2, 'is_active' => true]);
        $u = User::factory()->create(['work_location_id' => $loc->id]);
        RosterDay::create(['user_id' => $u->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern']);

        $res = $this->actingAs($this->admin())->getJson(route('attendance.coverage.index', [
            'from' => '2026-07-06', 'to' => '2026-07-06',
        ]))->assertOk();

        $this->assertSame(2, $res->json("coverage.2026-07-06.{$loc->id}.{$shift->id}.total.required"));
        $this->assertSame(1, $res->json("coverage.2026-07-06.{$loc->id}.{$shift->id}.total.assigned"));
        $this->assertSame('understaffed', $res->json("coverage.2026-07-06.{$loc->id}.{$shift->id}.total.status"));
    }

    public function test_work_locations_endpoint_lists_active(): void
    {
        WorkLocation::create(['name' => 'Active Site', 'code' => 'AS', 'is_active' => true]);
        WorkLocation::create(['name' => 'Dead Site', 'code' => 'DS', 'is_active' => false]);

        $res = $this->actingAs($this->admin())->getJson(route('attendance.workLocations.index'))->assertOk();
        $names = collect($res->json('work_locations'))->pluck('name');

        $this->assertTrue($names->contains('Active Site'));
        $this->assertFalse($names->contains('Dead Site'));
    }

    public function test_coverage_requires_settings_permission(): void
    {
        $emp = User::factory()->create();
        $this->actingAs($emp)->getJson(route('attendance.coverage.index', [
            'from' => '2026-07-06', 'to' => '2026-07-06',
        ]))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=CoverageEndpointTest`
Expected: FAIL — routes/controller missing.

- [ ] **Step 3: Create the controller**

`app/Http/Controllers/HRM/CoverageController.php`:

```php
<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\WorkLocation;
use App\Services\Attendance\CoverageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoverageController extends Controller
{
    public function __construct(private readonly CoverageService $coverage) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'location_id' => 'nullable|integer',
        ]);

        $locationIds = isset($data['location_id']) ? [(int) $data['location_id']] : null;

        return response()->json([
            'coverage' => $this->coverage->forRange($data['from'], $data['to'], $locationIds),
        ]);
    }

    public function workLocations(): JsonResponse
    {
        return response()->json([
            'work_locations' => WorkLocation::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
        ]);
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/web.php`, inside the `Route::middleware(['permission:attendance.settings'])->group(...)` block, immediately after the roster routes (the `attendance.roster.cell` line ~595), add:

```php
        // Coverage (Phase 2)
        Route::get('/attendance/coverage', [\App\Http\Controllers\HRM\CoverageController::class, 'index'])->name('attendance.coverage.index');
        Route::get('/attendance/work-locations', [\App\Http\Controllers\HRM\CoverageController::class, 'workLocations'])->name('attendance.workLocations.index');
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=CoverageEndpointTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/HRM/CoverageController.php routes/web.php tests/Feature/Attendance/Coverage/CoverageEndpointTest.php
git commit -m "feat(coverage): coverage + work-locations read endpoints"
```

---

### Task 4: Coverage requirements CRUD

**Files:**
- Create: `app/Http/Controllers/HRM/CoverageRequirementController.php`
- Modify: `routes/web.php` (same `permission:attendance.settings` group, after the coverage routes)
- Test: `tests/Feature/Attendance/Coverage/CoverageRequirementCrudTest.php`

**Interfaces:**
- Consumes: `CoverageRequirement` (Task 1).
- Produces REST endpoints:
  - `GET attendance.coverageRequirements.index` → `{ requirements: [ ...with workLocation/shift/designation names ] }`
  - `POST attendance.coverageRequirements.store` → creates, returns `{ requirement }`
  - `PUT attendance.coverageRequirements.update` (`/attendance/coverage-requirements/{id}`) → updates
  - `DELETE attendance.coverageRequirements.destroy` (`/attendance/coverage-requirements/{id}`)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance\Coverage;

use App\Models\HRM\CoverageRequirement;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CoverageRequirementCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    private function admin(): User
    {
        $a = User::factory()->create();
        $a->assignRole('Admin');
        $a->givePermissionTo('attendance.settings');

        return $a;
    }

    public function test_store_creates_requirement(): void
    {
        $loc = WorkLocation::create(['name' => 'Control Room']);
        $shift = Shift::factory()->create(['code' => 'N']);

        $this->actingAs($this->admin())->postJson(route('attendance.coverageRequirements.store'), [
            'work_location_id' => $loc->id, 'shift_id' => $shift->id,
            'required_headcount' => 3, 'weekday' => null, 'date' => null,
        ])->assertOk();

        $this->assertDatabaseHas('coverage_requirements', [
            'work_location_id' => $loc->id, 'shift_id' => $shift->id, 'required_headcount' => 3,
        ]);
    }

    public function test_store_rejects_bad_headcount_and_weekday(): void
    {
        $loc = WorkLocation::create(['name' => 'X']);
        $shift = Shift::factory()->create(['code' => 'D']);

        $this->actingAs($this->admin())->postJson(route('attendance.coverageRequirements.store'), [
            'work_location_id' => $loc->id, 'shift_id' => $shift->id,
            'required_headcount' => -1, 'weekday' => 9,
        ])->assertStatus(422);
    }

    public function test_update_and_delete(): void
    {
        $loc = WorkLocation::create(['name' => 'X']);
        $shift = Shift::factory()->create(['code' => 'D']);
        $req = CoverageRequirement::create(['work_location_id' => $loc->id, 'shift_id' => $shift->id, 'required_headcount' => 1, 'is_active' => true]);

        $this->actingAs($this->admin())->putJson(route('attendance.coverageRequirements.update', $req->id), [
            'work_location_id' => $loc->id, 'shift_id' => $shift->id, 'required_headcount' => 4,
        ])->assertOk();
        $this->assertDatabaseHas('coverage_requirements', ['id' => $req->id, 'required_headcount' => 4]);

        $this->actingAs($this->admin())->deleteJson(route('attendance.coverageRequirements.destroy', $req->id))->assertOk();
        $this->assertDatabaseMissing('coverage_requirements', ['id' => $req->id]);
    }

    public function test_requires_settings_permission(): void
    {
        $emp = User::factory()->create();
        $this->actingAs($emp)->getJson(route('attendance.coverageRequirements.index'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=CoverageRequirementCrudTest`
Expected: FAIL — routes/controller missing.

- [ ] **Step 3: Create the controller**

`app/Http/Controllers/HRM/CoverageRequirementController.php`:

```php
<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\CoverageRequirement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoverageRequirementController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'requirements' => CoverageRequirement::with([
                'workLocation:id,name',
                'shift:id,code,name',
                'designation:id,title',
            ])->orderBy('work_location_id')->orderBy('shift_id')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $requirement = CoverageRequirement::create($data + ['is_active' => true]);

        return response()->json(['requirement' => $requirement]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $requirement = CoverageRequirement::findOrFail($id);
        $requirement->update($this->validated($request));

        return response()->json(['requirement' => $requirement]);
    }

    public function destroy(int $id): JsonResponse
    {
        CoverageRequirement::findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'work_location_id' => 'required|integer|exists:work_locations,id',
            'shift_id' => 'required|integer|exists:shifts,id',
            'designation_id' => 'nullable|integer|exists:designations,id',
            'required_headcount' => 'required|integer|min:0',
            'weekday' => 'nullable|integer|between:0,6',
            'date' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ]);
    }
}
```

Note: `designations.title` is the label column (verified — `Designation` fillable includes `title`), so `designation:id,title` and the frontend `d.title` are correct.

- [ ] **Step 4: Register routes**

In `routes/web.php`, in the same `permission:attendance.settings` group after the coverage routes:

```php
        Route::get('/attendance/coverage-requirements', [\App\Http\Controllers\HRM\CoverageRequirementController::class, 'index'])->name('attendance.coverageRequirements.index');
        Route::post('/attendance/coverage-requirements', [\App\Http\Controllers\HRM\CoverageRequirementController::class, 'store'])->name('attendance.coverageRequirements.store');
        Route::put('/attendance/coverage-requirements/{id}', [\App\Http\Controllers\HRM\CoverageRequirementController::class, 'update'])->name('attendance.coverageRequirements.update');
        Route::delete('/attendance/coverage-requirements/{id}', [\App\Http\Controllers\HRM\CoverageRequirementController::class, 'destroy'])->name('attendance.coverageRequirements.destroy');
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=CoverageRequirementCrudTest`
Expected: PASS (4 tests). If the designation label column differs, the test still passes (it does not assert designation title); fix the select if a 500 occurs.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/HRM/CoverageRequirementController.php routes/web.php tests/Feature/Attendance/Coverage/CoverageRequirementCrudTest.php
git commit -m "feat(coverage): coverage requirements CRUD endpoints"
```

---

### Task 5: `updateCell` accepts per-day post (`work_location_id`)

**Files:**
- Modify: `app/Http/Controllers/HRM/RosterController.php` (the `updateCell` method)
- Test: `tests/Feature/Attendance/Coverage/RosterCellWorkLocationTest.php`

**Interfaces:**
- Consumes: existing `updateCell` validation + `RosterDay::updateOrCreate`.
- Produces: `PUT attendance.roster.cell` now accepts optional `work_location_id` (nullable int, exists) and persists it on the `roster_days` row.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance\Coverage;

use App\Models\HRM\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RosterCellWorkLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    public function test_update_cell_persists_work_location(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.settings');
        $emp = User::factory()->create();
        $shift = Shift::factory()->create(['code' => 'D']);
        $loc = WorkLocation::create(['name' => 'Toll Plaza 9']);

        $this->actingAs($admin)->putJson(route('attendance.roster.cell'), [
            'user_id' => $emp->id, 'date' => '2026-07-06', 'shift_id' => $shift->id,
            'work_location_id' => $loc->id,
        ])->assertOk();

        $this->assertDatabaseHas('roster_days', [
            'user_id' => $emp->id, 'date' => '2026-07-06', 'work_location_id' => $loc->id,
        ]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=RosterCellWorkLocationTest`
Expected: FAIL — `work_location_id` not persisted (validation drops it / not written).

- [ ] **Step 3: Implement**

In `app/Http/Controllers/HRM/RosterController.php` `updateCell`, add `work_location_id` to the validation array:

```php
            'work_location_id' => 'nullable|integer|exists:work_locations,id',
```

and include it in the `RosterDay::updateOrCreate` values array (currently `['shift_id' => ..., 'source' => 'manual', 'locked' => true, 'note' => ...]`):

```php
        $cell = RosterDay::updateOrCreate(
            ['user_id' => $data['user_id'], 'date' => $data['date']],
            [
                'shift_id' => $data['shift_id'] ?? null,
                'work_location_id' => $data['work_location_id'] ?? null,
                'source' => 'manual',
                'locked' => true,
                'note' => $data['note'] ?? null,
            ],
        );
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=RosterCellWorkLocationTest`
Expected: PASS. Also run the Phase 1 endpoint test for no regression: `php artisan test --filter=RosterOverlayEndpointTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/HRM/RosterController.php tests/Feature/Attendance/Coverage/RosterCellWorkLocationTest.php
git commit -m "feat(coverage): roster cell accepts per-day work location (post)"
```

---

### Task 6: Pure coverage-cell display resolver

**Files:**
- Create: `resources/js/Pages/Attendance/coverageCellDisplay.js`
- Test: `resources/js/Pages/Attendance/__tests__/coverageCellDisplay.test.js`

**Interfaces:**
- Produces:
  ```js
  // resolveCoverageCellDisplay(cell) -> { label: string, color: string, status: string }
  // cell: { required: number|null, assigned: number, status: 'understaffed'|'met'|'overstaffed'|null }
  // color tokens: understaffed -> 'var(--red-9)', met -> 'var(--green-9)',
  //               overstaffed -> 'var(--amber-9)', untracked(null) -> 'var(--gray-6)'
  // label: '<assigned>/<required>' (required null -> '<assigned>/–')
  ```

- [ ] **Step 1: Write the failing test**

```js
import { describe, it, expect } from 'vitest';
import { resolveCoverageCellDisplay } from '../coverageCellDisplay';

describe('resolveCoverageCellDisplay', () => {
  it('understaffed is red', () => {
    const out = resolveCoverageCellDisplay({ required: 3, assigned: 1, status: 'understaffed' });
    expect(out.color).toBe('var(--red-9)');
    expect(out.label).toBe('1/3');
  });
  it('met is green', () => {
    expect(resolveCoverageCellDisplay({ required: 2, assigned: 2, status: 'met' }).color).toBe('var(--green-9)');
  });
  it('overstaffed is amber', () => {
    expect(resolveCoverageCellDisplay({ required: 1, assigned: 3, status: 'overstaffed' }).color).toBe('var(--amber-9)');
  });
  it('untracked (null required) is gray with dash label', () => {
    const out = resolveCoverageCellDisplay({ required: null, assigned: 2, status: null });
    expect(out.color).toBe('var(--gray-6)');
    expect(out.label).toBe('2/–');
  });
  it('formats fractional assigned', () => {
    expect(resolveCoverageCellDisplay({ required: 3, assigned: 1.5, status: 'understaffed' }).label).toBe('1.5/3');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run resources/js/Pages/Attendance/__tests__/coverageCellDisplay.test.js`
Expected: FAIL — module missing.

- [ ] **Step 3: Write the implementation**

```js
/**
 * Pure display decision for a coverage cell (assigned vs required).
 * Presentational only — no React, no side effects.
 * cell: { required: number|null, assigned: number, status: string|null }
 */
export function resolveCoverageCellDisplay(cell) {
  const assigned = Number(cell?.assigned ?? 0);
  const assignedLabel = Number.isInteger(assigned) ? String(assigned) : String(assigned);
  const required = cell?.required ?? null;

  const color =
    cell?.status === 'understaffed' ? 'var(--red-9)'
    : cell?.status === 'met' ? 'var(--green-9)'
    : cell?.status === 'overstaffed' ? 'var(--amber-9)'
    : 'var(--gray-6)';

  return {
    label: `${assignedLabel}/${required ?? '–'}`,
    color,
    status: cell?.status ?? 'untracked',
  };
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx vitest run resources/js/Pages/Attendance/__tests__/coverageCellDisplay.test.js`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Attendance/coverageCellDisplay.js resources/js/Pages/Attendance/__tests__/coverageCellDisplay.test.js
git commit -m "feat(coverage): pure coverage-cell display resolver"
```

---

### Task 7: Coverage panel in RosterTab

**Files:**
- Create: `resources/js/Pages/Attendance/Components/CoveragePanel.jsx`
- Modify: `resources/js/Pages/Attendance/RosterTab.jsx` (render the panel; provide a selected date)

**Interfaces:**
- Consumes: `GET /attendance/coverage` (Task 3); `GET /attendance/work-locations` (Task 3); `GET /attendance/shifts` (already used in RosterTab, returns `{shifts:[{id,code,...}]}`); `resolveCoverageCellDisplay` (Task 6).
- Produces: a `<CoveragePanel from={from} to={to} isActive={boolean} />` component showing a Location × Shift matrix for the range with color-coded `assigned/required` cells and an understaffed-posts list.

- [ ] **Step 1: Create the component**

`resources/js/Pages/Attendance/Components/CoveragePanel.jsx`:

```jsx
import React, { useMemo } from 'react';
import { Box, Flex, Text, Card, Tooltip, Badge } from '@radix-ui/themes';
import { useQuery } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { resolveCoverageCellDisplay } from '../coverageCellDisplay';

/**
 * Location × Shift coverage matrix for [from,to], aggregated across the range
 * by worst-status per cell, plus an understaffed-posts list.
 */
export default function CoveragePanel({ from, to, isActive = true }) {
    const { data: covData } = useQuery({
        queryKey: ['coverage', from, to],
        queryFn: () => requestJson('get', '/attendance/coverage', { params: { from, to } }),
        enabled: isActive,
    });
    const { data: locData } = useQuery({
        queryKey: ['work-locations'],
        queryFn: () => requestJson('get', '/attendance/work-locations'),
        enabled: isActive,
    });
    const { data: shiftData } = useQuery({
        queryKey: ['shifts'],
        queryFn: () => requestJson('get', '/attendance/shifts'),
        enabled: isActive,
    });

    const coverage = covData?.coverage || {};
    const locations = locData?.work_locations || [];
    const shifts = shiftData?.shifts || [];

    // Reduce the date-keyed payload to a worst-status-per (location,shift) summary + gaps list.
    const { matrix, gaps } = useMemo(() => {
        const rank = { understaffed: 3, overstaffed: 2, met: 1, untracked: 0, null: 0 };
        const m = {}; // [loc][shift] = {required, assigned, status}
        const g = []; // understaffed rows
        Object.entries(coverage).forEach(([date, locs]) => {
            Object.entries(locs).forEach(([locId, shiftsObj]) => {
                Object.entries(shiftsObj).forEach(([shiftId, cell]) => {
                    const t = cell.total;
                    const key = `${locId}:${shiftId}`;
                    const prev = m[key];
                    const status = t.status || 'untracked';
                    if (!prev || (rank[status] ?? 0) > (rank[prev.status] ?? 0)) {
                        m[key] = { required: t.required, assigned: t.assigned, status };
                    }
                    if (status === 'understaffed') {
                        g.push({ date, locId, shiftId, required: t.required, assigned: t.assigned });
                    }
                });
            });
        });
        return { matrix: m, gaps: g };
    }, [coverage]);

    const locName = (id) => locations.find(l => String(l.id) === String(id))?.name || `#${id}`;
    const shiftCode = (id) => shifts.find(s => String(s.id) === String(id))?.code || `#${id}`;

    if (locations.length === 0 || shifts.length === 0) {
        return null;
    }

    return (
        <Card mb="4">
            <Flex justify="between" align="center" mb="3">
                <Text size="2" weight="bold">Coverage — {from} → {to}</Text>
                {gaps.length > 0 && <Badge color="red">{gaps.length} understaffed</Badge>}
            </Flex>

            <Box style={{ overflowX: 'auto' }}>
                <Flex>
                    <Box style={{ minWidth: 160, width: 160 }} />
                    {shifts.map(s => (
                        <Box key={s.id} style={{ width: 72, textAlign: 'center' }}>
                            <Text size="1" weight="medium">{s.code}</Text>
                        </Box>
                    ))}
                </Flex>
                {locations.map(loc => (
                    <Flex key={loc.id} align="center" style={{ borderTop: '1px solid var(--gray-a4)' }}>
                        <Box style={{ minWidth: 160, width: 160, padding: '6px 4px' }}>
                            <Text size="1">{loc.name}</Text>
                        </Box>
                        {shifts.map(s => {
                            const cell = matrix[`${loc.id}:${s.id}`];
                            if (!cell) {
                                return <Box key={s.id} style={{ width: 72, textAlign: 'center', color: 'var(--gray-6)' }}><Text size="1">–</Text></Box>;
                            }
                            const disp = resolveCoverageCellDisplay(cell);
                            return (
                                <Box key={s.id} style={{ width: 72, padding: 4 }}>
                                    <Tooltip content={`${loc.name} · ${s.code}: ${disp.status}`}>
                                        <Box style={{
                                            borderRadius: 4, textAlign: 'center', padding: '2px 0',
                                            background: disp.color, color: '#fff', fontSize: 11, fontWeight: 700,
                                        }}>
                                            {disp.label}
                                        </Box>
                                    </Tooltip>
                                </Box>
                            );
                        })}
                    </Flex>
                ))}
            </Box>

            {gaps.length > 0 && (
                <Box mt="3">
                    <Text size="1" color="gray">Understaffed: {gaps.slice(0, 12).map(g => `${g.date} ${locName(g.locId)}/${shiftCode(g.shiftId)} (${g.assigned}/${g.required})`).join(' · ')}{gaps.length > 12 ? ' …' : ''}</Text>
                </Box>
            )}
        </Card>
    );
}
```

- [ ] **Step 2: Render it in RosterTab**

In `resources/js/Pages/Attendance/RosterTab.jsx`, import the panel near the other component imports:

```jsx
import CoveragePanel from './Components/CoveragePanel';
```

Render it above the `<RosterCalendar ...>` (inside the loaded branch, before the calendar):

```jsx
                        <CoveragePanel from={from} to={to} isActive={isActive} />
```

- [ ] **Step 3: Run the frontend suite (no regression)**

Run: `npx vitest run resources/js/Pages/Attendance`
Expected: PASS (existing + new coverage resolver test).

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Attendance/Components/CoveragePanel.jsx resources/js/Pages/Attendance/RosterTab.jsx
git commit -m "feat(coverage): coverage panel (location x shift) in roster tab"
```

---

### Task 8: Coverage requirements admin dialog

**Files:**
- Create: `resources/js/Pages/Attendance/Components/CoverageRequirementsDialog.jsx`
- Modify: `resources/js/Pages/Attendance/RosterTab.jsx` (toolbar button that opens the dialog)

**Interfaces:**
- Consumes: `GET/POST/PUT/DELETE /attendance/coverage-requirements` (Task 4); `/attendance/work-locations` (Task 3); `/attendance/shifts`; `designations` from `usePage().props` (already provided to the attendance page).
- Produces: a `<CoverageRequirementsDialog open onOpenChange />` with a list of requirements and an add/delete form.

- [ ] **Step 1: Create the dialog**

`resources/js/Pages/Attendance/Components/CoverageRequirementsDialog.jsx`:

```jsx
import React, { useState } from 'react';
import { Dialog, Flex, Box, Text, Button, TextField, Select, Table, IconButton } from '@radix-ui/themes';
import { TrashIcon } from '@radix-ui/react-icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { usePage } from '@inertiajs/react';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export default function CoverageRequirementsDialog({ open, onOpenChange }) {
    const qc = useQueryClient();
    const { designations = [] } = usePage().props;

    const { data: reqData } = useQuery({ queryKey: ['coverage-requirements'], queryFn: () => requestJson('get', '/attendance/coverage-requirements'), enabled: open });
    const { data: locData } = useQuery({ queryKey: ['work-locations'], queryFn: () => requestJson('get', '/attendance/work-locations'), enabled: open });
    const { data: shiftData } = useQuery({ queryKey: ['shifts'], queryFn: () => requestJson('get', '/attendance/shifts'), enabled: open });

    const requirements = reqData?.requirements || [];
    const locations = locData?.work_locations || [];
    const shifts = shiftData?.shifts || [];

    const [form, setForm] = useState({ work_location_id: '', shift_id: '', designation_id: '', required_headcount: '1', weekday: '', date: '' });
    const set = (k, v) => setForm(f => ({ ...f, [k]: v }));

    const invalidate = () => { qc.invalidateQueries({ queryKey: ['coverage-requirements'] }); qc.invalidateQueries({ queryKey: ['coverage'] }); };

    const create = useMutation({
        mutationFn: () => requestJson('post', '/attendance/coverage-requirements', { data: {
            work_location_id: Number(form.work_location_id),
            shift_id: Number(form.shift_id),
            designation_id: form.designation_id ? Number(form.designation_id) : null,
            required_headcount: Number(form.required_headcount),
            weekday: form.weekday === '' ? null : Number(form.weekday),
            date: form.date || null,
        } }),
        onSuccess: () => { showToast.success('Requirement added.'); invalidate(); },
        onError: (e) => showToast.error(e?.message || 'Failed to add requirement.'),
    });

    const remove = useMutation({
        mutationFn: (id) => requestJson('delete', `/attendance/coverage-requirements/${id}`),
        onSuccess: () => { showToast.success('Removed.'); invalidate(); },
        onError: (e) => showToast.error(e?.message || 'Failed to remove.'),
    });

    const canAdd = form.work_location_id && form.shift_id && form.required_headcount !== '';

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Content maxWidth="720px">
                <Dialog.Title>Coverage requirements</Dialog.Title>
                <Dialog.Description size="2" color="gray" mb="3">
                    Required headcount per post &amp; shift. Leave weekday/date empty for an all-days rule; set weekday for a recurring day, or date for a one-off override. Role optional.
                </Dialog.Description>

                <Flex gap="2" wrap="wrap" align="end" mb="3">
                    <Box><Text size="1" color="gray">Location</Text>
                        <Select.Root value={form.work_location_id} onValueChange={v => set('work_location_id', v)}>
                            <Select.Trigger placeholder="Location" />
                            <Select.Content>{locations.map(l => <Select.Item key={l.id} value={String(l.id)}>{l.name}</Select.Item>)}</Select.Content>
                        </Select.Root>
                    </Box>
                    <Box><Text size="1" color="gray">Shift</Text>
                        <Select.Root value={form.shift_id} onValueChange={v => set('shift_id', v)}>
                            <Select.Trigger placeholder="Shift" />
                            <Select.Content>{shifts.map(s => <Select.Item key={s.id} value={String(s.id)}>{s.code}</Select.Item>)}</Select.Content>
                        </Select.Root>
                    </Box>
                    <Box><Text size="1" color="gray">Role (opt)</Text>
                        <Select.Root value={form.designation_id || 'any'} onValueChange={v => set('designation_id', v === 'any' ? '' : v)}>
                            <Select.Trigger placeholder="Any" />
                            <Select.Content><Select.Item value="any">Any (total)</Select.Item>{designations.map(d => <Select.Item key={d.id} value={String(d.id)}>{d.title}</Select.Item>)}</Select.Content>
                        </Select.Root>
                    </Box>
                    <Box style={{ width: 70 }}><Text size="1" color="gray">Count</Text>
                        <TextField.Root type="number" min="0" value={form.required_headcount} onChange={e => set('required_headcount', e.target.value)} />
                    </Box>
                    <Box><Text size="1" color="gray">Weekday (opt)</Text>
                        <Select.Root value={form.weekday === '' ? 'all' : form.weekday} onValueChange={v => set('weekday', v === 'all' ? '' : v)}>
                            <Select.Trigger placeholder="All" />
                            <Select.Content><Select.Item value="all">All days</Select.Item>{WEEKDAYS.map((w, i) => <Select.Item key={i} value={String(i)}>{w}</Select.Item>)}</Select.Content>
                        </Select.Root>
                    </Box>
                    <Box><Text size="1" color="gray">Date (opt)</Text>
                        <TextField.Root type="date" value={form.date} onChange={e => set('date', e.target.value)} />
                    </Box>
                    <Button disabled={!canAdd || create.isPending} onClick={() => create.mutate()}>Add</Button>
                </Flex>

                <Table.Root size="1" variant="surface">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell>Location</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Shift</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Role</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Count</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>When</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell></Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {requirements.map(r => (
                            <Table.Row key={r.id}>
                                <Table.Cell>{r.work_location?.name || `#${r.work_location_id}`}</Table.Cell>
                                <Table.Cell>{r.shift?.code || `#${r.shift_id}`}</Table.Cell>
                                <Table.Cell>{r.designation?.title || 'Any'}</Table.Cell>
                                <Table.Cell>{r.required_headcount}</Table.Cell>
                                <Table.Cell>{r.date ? r.date : (r.weekday === null || r.weekday === undefined ? 'All days' : WEEKDAYS[r.weekday])}</Table.Cell>
                                <Table.Cell><IconButton size="1" color="red" variant="ghost" onClick={() => remove.mutate(r.id)}><TrashIcon /></IconButton></Table.Cell>
                            </Table.Row>
                        ))}
                    </Table.Body>
                </Table.Root>

                <Flex justify="end" mt="3"><Dialog.Close><Button variant="soft" color="gray">Close</Button></Dialog.Close></Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}
```

- [ ] **Step 2: Wire a toolbar button in RosterTab**

In `resources/js/Pages/Attendance/RosterTab.jsx`, import the dialog and add state:

```jsx
import CoverageRequirementsDialog from './Components/CoverageRequirementsDialog';
```

Add near the other `useState` hooks:

```jsx
    const [coverageDialogOpen, setCoverageDialogOpen] = useState(false);
```

Add a button in the right-hand toolbar `Flex` (next to Refresh/Generate):

```jsx
                    <Button variant="soft" color="gray" size="2" onClick={() => setCoverageDialogOpen(true)}>
                        Coverage rules
                    </Button>
```

Render the dialog (near the popover render):

```jsx
                        <CoverageRequirementsDialog open={coverageDialogOpen} onOpenChange={setCoverageDialogOpen} />
```

- [ ] **Step 3: Run the frontend suite (no regression)**

Run: `npx vitest run resources/js/Pages/Attendance`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Attendance/Components/CoverageRequirementsDialog.jsx resources/js/Pages/Attendance/RosterTab.jsx
git commit -m "feat(coverage): coverage requirements admin dialog"
```

---

### Task 9: Post picker in the cell popover

**Files:**
- Modify: `resources/js/Pages/Attendance/Components/RosterCellPopover.jsx`
- Modify: `resources/js/Pages/Attendance/RosterTab.jsx`

**Interfaces:**
- Consumes: `/attendance/work-locations` (Task 3); the existing `updateCell` mutation (extended in Task 5 to accept `work_location_id`).
- Produces: the popover shows a work-location select; `onPick(shiftId, workLocationId)` passes the chosen post, threaded into the `updateCell` mutation payload.

- [ ] **Step 1: Add a location select to the popover**

In `resources/js/Pages/Attendance/Components/RosterCellPopover.jsx`, extend the signature and imports (add `Select` to the `@radix-ui/themes` import), accept `workLocations = []`, `selectedLocationId = null`, and change `onPick` to pass a location. Add local state for the chosen location and render a select above the shift buttons:

```jsx
import React, { useState, useEffect } from 'react';
```

```jsx
export default function RosterCellPopover({ open, onOpenChange, anchor, shifts = [], notice = null, workLocations = [], selectedLocationId = null, onPick }) {
    const [locId, setLocId] = useState(selectedLocationId ? String(selectedLocationId) : 'home');
    useEffect(() => { setLocId(selectedLocationId ? String(selectedLocationId) : 'home'); }, [selectedLocationId, open]);

    const pick = (shiftId) => onPick(shiftId, locId === 'home' ? null : Number(locId));
```

Render, inside `Popover.Content`, after the `notice` callout and before "Assign shift":

```jsx
                {workLocations.length > 0 && (
                    <Box mb="2">
                        <Text size="1" color="gray">Post</Text>
                        <Select.Root value={locId} onValueChange={setLocId}>
                            <Select.Trigger placeholder="Home location" />
                            <Select.Content>
                                <Select.Item value="home">Home location</Select.Item>
                                {workLocations.map(l => <Select.Item key={l.id} value={String(l.id)}>{l.name}</Select.Item>)}
                            </Select.Content>
                        </Select.Root>
                    </Box>
                )}
```

Change the shift option buttons and the "Off" option to call `pick(...)` instead of `onPick(...)` (replace the existing `onClick={() => onPick(s.id)}` and the off `onClick={() => onPick(null)}` with `pick`). Add `Box` to the imports if not present.

- [ ] **Step 2: Thread it through RosterTab**

In `resources/js/Pages/Attendance/RosterTab.jsx`:

Fetch work locations:

```jsx
    const { data: locData } = useQuery({
        queryKey: ['work-locations'],
        queryFn: () => requestJson('get', '/attendance/work-locations'),
        enabled: isActive,
    });
    const workLocations = locData?.work_locations || [];
```

Update the `updateCell` mutation to send `work_location_id` (extend `mutationFn` data):

```jsx
        mutationFn: ({ userId, date, shiftId, workLocationId, expectedUpdatedAt }) => requestJson('put', '/attendance/roster/cell', {
            data: {
                user_id: Number(userId),
                date,
                shift_id: shiftId,
                work_location_id: workLocationId ?? null,
                expected_updated_at: expectedUpdatedAt ?? null,
            },
        }),
```

Update `handlePick` to accept the location and pass it:

```jsx
    const handlePick = (shiftId, workLocationId) => {
        if (!selectedCell) return;
        setPopoverOpen(false);
        const { userId, date } = selectedCell;
        const expectedUpdatedAt = roster?.[userId]?.days?.[date]?.updated_at ?? null;
        updateCell.mutate({ userId, date, shiftId, workLocationId, expectedUpdatedAt });
        setSelectedCell(null);
    };
```

Pass the new props to `<RosterCellPopover>`:

```jsx
                            workLocations={workLocations}
                            selectedLocationId={selectedCell ? (roster?.[selectedCell.userId]?.days?.[selectedCell.date]?.work_location_id ?? null) : null}
```

- [ ] **Step 3: Run the frontend suite (no regression)**

Run: `npx vitest run resources/js/Pages/Attendance`
Expected: PASS.

- [ ] **Step 4: Verify visually with the dev server**

Run `npm run dev`, open `https://aero-enterprise-suite.test` → Attendance → Roster. Confirm: the coverage panel shows the Location × Shift matrix with red/green/amber; "Coverage rules" opens the requirements dialog and add/delete work; clicking a cell shows the Post select and assigning persists the post (coverage updates after refetch).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Attendance/Components/RosterCellPopover.jsx resources/js/Pages/Attendance/RosterTab.jsx
git commit -m "feat(coverage): per-day post picker in roster cell popover"
```

---

### Task 10: Migrate dev DB + full-suite verification

**Files:** none (verification + migration only).

- [ ] **Step 1: Run the new migrations on dev MySQL**

Run: `php artisan migrate`
Expected: both `2026_07_01_000001_create_coverage_requirements_table` and `2026_07_01_000002_add_work_location_id_to_roster_days` run on MySQL `dbedc_guardian` (so live pages don't 500). Confirm with: `php artisan migrate:status | grep -E "coverage_requirements|work_location_id_to_roster_days"`.

- [ ] **Step 2: Backend suite**

Run: `php artisan test --filter="Coverage|Roster|Attendance"`
Expected: PASS, no new failures (pre-existing allowed failures per the leaves-holidays spec only).

- [ ] **Step 3: Frontend suite**

Run: `npx vitest run resources/js/Pages/Attendance`
Expected: PASS (coverage resolver + all roster tests).

- [ ] **Step 4: Confirm the attendance engine was not touched**

Run: `git diff --name-only main...HEAD`
Expected: only Phase 2 files (2 migrations, `CoverageRequirement` model, `RosterDay`, `CoverageService`, `CoverageController`, `CoverageRequirementController`, `RosterController`, `routes/web.php`, the 3 frontend components, `coverageCellDisplay.js`, `RosterTab.jsx`, tests, spec, plan). NOT `AttendanceStatusService.php`, `RosterScheduleResolver.php`, `RosterService.php`, `LeaveDayCalculator.php`.

- [ ] **Step 5: Final note**

Do NOT run `npm run build` (auto-commits/pushes on this machine); the production asset build + `php artisan migrate` on prod are the owner's deploy steps.

---

## Self-Review (completed during authoring)

- **Spec coverage:** tables + column (Task 1); resolution precedence + leave-aware counting + status + query bound (Task 2); coverage read + work-locations endpoints (Task 3); requirements CRUD + validation + roles-gate (Task 4); per-day post on cell (Task 5); pure display resolver (Task 6); coverage panel (Task 7); requirements admin (Task 8); post picker (Task 9); dev-DB migration + verification + engine-untouched check (Task 10). All spec sections mapped.
- **Placeholder scan:** none — every code step is complete. The one conditional note (designation label column name) instructs verification against the actual model, not a placeholder.
- **Type consistency:** `CoverageService::forRange(from,to,?locationIds): {date:{loc:{shift:{total:{required,assigned,status}, roles:{desig:{required,assigned,status}}}}}}` used identically across Tasks 2→3→7; cell shape `{required,assigned,status}` consumed by `resolveCoverageCellDisplay` (Task 6) and CoveragePanel (Task 7); `onPick(shiftId, workLocationId)` consistent between RosterCellPopover (Task 9) and RosterTab `handlePick`; `work_location_id` name consistent across migration, model, updateCell (Task 5), and popover payload (Task 9).
- **Verified facts:** designation label column is `title` (confirmed against `Designation` model + migration); `work_locations` and `designations` tables exist; `RosterDay` fillable extended for `work_location_id`.
