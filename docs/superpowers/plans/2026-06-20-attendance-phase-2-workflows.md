# Attendance Phase 2 — Workflows Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the roster *affect the numbers and trigger workflows* (Task 0: status-engine surfaces out-of-schedule work — off-day, unscheduled, outside-window — and earns overtime for it), then add request/approval workflows: **regularization** (fix a missed/wrong punch), **overtime requests**, and a **comp-off (TOIL) ledger** — all using the same multi-level approval chain the Leave module already uses, with engine-flagged exception days surfaced in the approvals inbox.

**Architecture:** Task 0 enriches the pure `AttendanceStatusService` so out-of-schedule work is detected (flags) and off-day/over-threshold hours become overtime — this is the *detection* the workflows act on. A reusable `AttendanceApprovalService` then builds and advances a multi-level `approval_chain` (json) exactly like `App\Services\Leave\LeaveApprovalService` (Level 1 = `reportsTo`, Level 2 = department head, Level 3 = HR for escalations). Request-type services (`RegularizationService`, `OvertimeService`) own creation + the on-final-approval side effect (regularization writes the corrected `Attendance` + an `attendance_audit_logs` row via the existing `AttendanceAuditService`; overtime credits the comp-off ledger). Controllers stay thin; UI mirrors the existing Roster/Swap patterns.

**Out of scope (deferred to Phase 3 — Policy engine):** the *punch-strictness toggle* (warn-but-allow [current default] / flag-and-require-approval / restrict-punch-to-shift-window), per-dept/designation/employee policy rules, and grace tiers. Phase 2 keeps **capture permissive** (employees can always punch) and only adds **detection + interpretation + approval** — it never blocks a punch.

**Tech Stack:** PHP 8.3, Laravel 11, Inertia v2, React 18 + Radix Themes, MySQL (prod) / sqlite `:memory:` (tests), PHPUnit 11, Vite, `@tanstack/react-query`, dayjs.

## Global Constraints

- **Tests are PHPUnit class-style** (NOT Pest), sqlite `:memory:` + `RefreshDatabase`. Run `php artisan test --filter=<Class>`. Backend tasks are TDD.
- **2 KNOWN pre-existing failures** unrelated to this work: `MobileSyncApiTest > sync push applies leave apply mutation`, `NavigationRoutesTest > any authenticated user can access organization directory`. No task may add a NEW failure.
- **`requestJson` real signature is `requestJson(method, url, { params | data })`** (`resources/js/api/client.js`). NEVER the `(url,{method,body})` form.
- **Inertia props that are Eloquent models must be mapped to plain arrays** before passing to `Inertia::render` when the model has appended accessors that touch relations (e.g. `Designation` appends `department_name`). Passing raw models caused a dev 500 / prod N+1 — always `->get()->map(fn($m)=>[...])->values()`.
- **Run `php artisan migrate` on the MySQL dev DB `dbedc_guardian`** after adding migrations (mysql bin `/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin`); the sqlite test suite will NOT catch a missing MySQL table.
- **Verify frontend by HTTP status, not the rendered shell** (empty-state UIs render identically on 200 and 500).
- **Every multi-step write wraps in `DB::transaction`.** Approver-facing endpoints gated by `permission:attendance.manage` (or `attendance.correct` for applying corrections); employee endpoints by `permission:attendance.own.view` / `attendance.own.punch`.
- **Build frontend with `npx vite build`** — NEVER `npm run build`. Dev server at `https://aero-enterprise-suite.test`. Frontend task commits are SOURCE-ONLY (a single `public/build` rebuild is committed in the final sweep task).
- **Reuse, don't reinvent:** the chain logic mirrors `App\Services\Leave\LeaveApprovalService`; the reporting field on `User` is `report_to` (fallback `report_to_id`) with the `reportsTo()` belongsTo relation. The audit writer is `App\Services\Attendance\AttendanceAuditService::record(action, attendanceId, before, after, reason, request)`.
- Match existing code style; Radix Themes only; no new dependencies; YAGNI.

---

## File Structure

**Backend — new**
- `app/Services/Attendance/AttendanceApprovalService.php` — generic chain build/submit/approve/reject/canApprove/pendingFor over any model with the chain fields.
- `app/Models/HRM/AttendanceRegularization.php` + migration `..._create_attendance_regularizations_table.php`.
- `app/Services/Attendance/RegularizationService.php` — create/submit + apply-on-approval (writes Attendance + audit).
- `app/Http/Controllers/HRM/RegularizationController.php` + routes.
- `app/Models/HRM/OvertimeRequest.php` + migration.
- `app/Services/Attendance/OvertimeService.php` — create/submit + credit comp-off on approval.
- `app/Http/Controllers/HRM/OvertimeController.php` + routes.
- `app/Models/HRM/CompOffLedger.php` + migration; `app/Services/Attendance/CompOffService.php` (credit/debit/balance).
- Factories for each model.

**Frontend — new**
- `resources/js/Forms/RegularizationForm.jsx`, `resources/js/Forms/OvertimeRequestForm.jsx`.
- `resources/js/Pages/Attendance/Components/MyRequests.jsx` (employee: my regularizations + OT + comp-off balance).
- `resources/js/Pages/Attendance/Components/ApprovalsInbox.jsx` (approver: pending regularizations + OT, approve/reject).

**Frontend — modified**
- `resources/js/Pages/AttendanceEmployee.jsx` — mount `<MyRequests/>` + request buttons.
- `resources/js/Pages/Attendance/AttendancePage.jsx` — add an "Approvals" tab (gated by `attendance.manage`) hosting `<ApprovalsInbox/>`.

---

### Task 0: Status engine — detect out-of-schedule work + off-day overtime

**Files:**
- Modify: `app/Services/Attendance/DTO/ShiftSchedule.php` (add a trailing `bool $isScheduled = true` constructor arg; `nonWorking()` sets it false)
- Modify: `app/Services/Attendance/DefaultScheduleResolver.php` (pass `isScheduled: false` — the global-default fallback means "no explicit roster/shift")
- Modify: `app/Services/Attendance/AttendanceStatusService.php` (new flags + off-day OT)
- Test: `tests/Unit/Attendance/AttendanceStatusServiceTest.php` (extend)

**Interfaces:**
- Consumes: `ShiftSchedule` (now carries `isScheduled`), the day's punches.
- Produces: `DayAttendance.flags` may include `worked_on_off_day` (punches on a non-working day), `unscheduled` (punches while `isScheduled=false`, i.e. no roster/assignment — scored against the global default), `outside_shift_window` (first-in earlier than `start − OUTSIDE_WINDOW_MINUTES` or last-out later than `end + OUTSIDE_WINDOW_MINUTES`, `OUTSIDE_WINDOW_MINUTES = 120`). And: **off-day work earns overtime** — when `! isWorkingDay` and there are punches, `ot_minutes = worked_minutes` (all hours on a day off are OT-eligible). Capture is unchanged — this is interpretation only; nothing blocks a punch.

- [ ] **Step 1: Extend the failing tests** (add to `AttendanceStatusServiceTest`)

```php
    public function test_off_day_work_is_flagged_and_all_overtime(): void
    {
        $r = (new \App\Services\Attendance\AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-20 10:00', '2026-06-20 16:00')]),     // 6h on a day off
            \App\Services\Attendance\DTO\ShiftSchedule::nonWorking(\Carbon\Carbon::parse('2026-06-20')),
        );
        $this->assertSame(\App\Services\Attendance\DTO\DayAttendance::PRESENT, $r->status);
        $this->assertContains('worked_on_off_day', $r->flags);
        $this->assertSame(360, $r->ot_minutes); // all 6h are OT on an off day
    }

    public function test_unscheduled_flag_when_schedule_not_explicit(): void
    {
        // a working window but not explicitly rostered/assigned (isScheduled=false)
        $shift = new \App\Services\Attendance\DTO\ShiftSchedule(
            start: \Carbon\Carbon::parse('2026-06-19 09:00'), end: \Carbon\Carbon::parse('2026-06-19 17:00'),
            crossesMidnight: false, graceInMinutes: 15, graceOutMinutes: 0, fullDayMinutes: 0, halfDayMinutes: 0,
            minPresentMinutes: 0, breakMinutes: 0, isWorkingDay: true, isScheduled: false,
        );
        $r = (new \App\Services\Attendance\AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 09:05', '2026-06-19 17:30')]), $shift,
        );
        $this->assertContains('unscheduled', $r->flags);
    }

    public function test_outside_shift_window_flag(): void
    {
        // shift 09:00-17:00; punched in 06:30 (> 120 min early)
        $r = (new \App\Services\Attendance\AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 06:30', '2026-06-19 17:00')]),
            $this->shift('2026-06-19', '09:00', '17:00'),
        );
        $this->assertContains('outside_shift_window', $r->flags);
    }
```
(`$this->shift(...)`/`$this->punch(...)` helpers already exist in the test file. The default `shift()` helper builds a schedule with `isScheduled` defaulting to true.)

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter=AttendanceStatusServiceTest`
Expected: FAIL — flags absent, off-day ot_minutes is 0, `isScheduled` arg unknown.

- [ ] **Step 3: Add `isScheduled` to `ShiftSchedule`**

Append a trailing promoted arg `public readonly bool $isScheduled = true,` to the constructor; in `nonWorking()` pass `isScheduled: false`. (Default `true` keeps every existing caller compiling.)

- [ ] **Step 4: Default resolver marks itself non-explicit**

In `DefaultScheduleResolver::resolve`, when building the working-day `ShiftSchedule`, pass `isScheduled: false` (the global default = no explicit roster/shift). Leave `nonWorking` as-is (already false).

- [ ] **Step 5: Engine — flags + off-day OT**

In `AttendanceStatusService::resolve`, after computing `firstIn`/`lastOut`/`workedMinutes` for the has-punches branch:
```php
        // out-of-schedule detection (interpretation only; never blocks capture)
        if (! $shift->isWorkingDay) {
            $flags[] = 'worked_on_off_day';
            $otMinutes = $workedMinutes;            // all hours on a day off are OT-eligible
        }
        if (! $shift->isScheduled) {
            $flags[] = 'unscheduled';
        }
        if ($shift->isWorkingDay && $firstIn) {
            $windowStart = $shift->start->copy()->subMinutes(self::OUTSIDE_WINDOW_MINUTES);
            $windowEnd = $shift->end->copy()->addMinutes(self::OUTSIDE_WINDOW_MINUTES);
            if ($firstIn->lessThan($windowStart) || ($lastOut && $lastOut->greaterThan($windowEnd))) {
                $flags[] = 'outside_shift_window';
            }
        }
```
Add the const `private const OUTSIDE_WINDOW_MINUTES = 120;`. Ensure `$otMinutes` set here is not overwritten by the existing working-day OT block (that block already only runs when `isWorkingDay`, so the off-day branch is exclusive — verify). Keep `array_values(array_unique($flags))` at the end.

- [ ] **Step 6: Run to verify they pass**

Run: `php artisan test --filter=AttendanceStatusServiceTest`
Expected: PASS (existing + 3 new). Then `php artisan test --filter=Attendance` (no regressions — the daily/monthly/resolver tests still pass; only new flags/off-day-OT added).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Attendance/DTO/ShiftSchedule.php app/Services/Attendance/DefaultScheduleResolver.php app/Services/Attendance/AttendanceStatusService.php tests/Unit/Attendance/AttendanceStatusServiceTest.php
git commit -m "feat(attendance): engine flags out-of-schedule work + off-day overtime"
```

> The approvals inbox (Task 8) should surface days carrying `worked_on_off_day`/`unscheduled`/`outside_shift_window` (from the daily/monthly engine output) as suggested OT/regularization items, so managers act on real exceptions. The OvertimeService (Task 6) can pre-fill `requested_minutes` from a flagged day's `ot_minutes`.

---

### Task 1: Generic `AttendanceApprovalService` (reusable chain)

**Files:**
- Create: `app/Services/Attendance/AttendanceApprovalService.php`
- Test: `tests/Feature/Attendance/AttendanceApprovalServiceTest.php`

**Interfaces:**
- Consumes: `User` (`report_to`/`report_to_id`, `reportsTo()`, `department_id`), Eloquent models exposing `user_id`, `approval_chain` (array cast), `current_approval_level` (int), `status` (string), `approved_by` (int), `approved_at` (datetime).
- Produces:
  - `buildChain(User $requester, bool $escalate = false): array` — Level 1 `reportsTo`, Level 2 department head (highest-rank user in dept, ≠ requester/manager), Level 3 HR (role HR Manager/HR Head/Super Administrator) when `$escalate`. Each entry `['level','approver_id','approver_name','status'=>'pending','approved_at'=>null,'comments'=>null]`.
  - `submit(Model $m, bool $escalate = false): void` — sets `approval_chain`, `current_approval_level=1`, `status='pending'`; if chain empty, `status='approved'`, `approved_at=now()`.
  - `approve(Model $m, User $approver, ?string $comments = null): array` — `{success,message,status}`; advances level or finalizes (`status='approved'`,`approved_by`,`approved_at`).
  - `reject(Model $m, User $approver, string $reason): array`.
  - `canApprove(Model $m, User $u): bool`; `pendingFor(User $u, string $modelClass): \Illuminate\Support\Collection`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendanceRegularization;
use App\Models\User;
use App\Services\Attendance\AttendanceApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_single_level_chain_to_direct_manager(): void
    {
        $manager = User::factory()->create();
        $emp = User::factory()->create(['report_to' => $manager->id]);

        $chain = app(AttendanceApprovalService::class)->buildChain($emp);

        $this->assertCount(1, $chain);
        $this->assertSame($manager->id, $chain[0]['approver_id']);
        $this->assertSame('pending', $chain[0]['status']);
    }

    public function test_submit_then_approve_finalizes_single_level(): void
    {
        $manager = User::factory()->create();
        $emp = User::factory()->create(['report_to' => $manager->id]);
        $svc = app(AttendanceApprovalService::class);

        $m = AttendanceRegularization::create([
            'user_id' => $emp->id, 'date' => '2026-06-18', 'type' => 'missing_punchout',
            'requested_punchout' => '2026-06-18 18:00:00', 'reason' => 'forgot',
        ]);
        $svc->submit($m);
        $this->assertSame('pending', $m->fresh()->status);
        $this->assertTrue($svc->canApprove($m->fresh(), $manager));

        $res = $svc->approve($m->fresh(), $manager, 'ok');
        $this->assertTrue($res['success']);
        $this->assertSame('approved', $m->fresh()->status);
        $this->assertSame($manager->id, $m->fresh()->approved_by);
    }

    public function test_non_approver_cannot_approve(): void
    {
        $manager = User::factory()->create();
        $other = User::factory()->create();
        $emp = User::factory()->create(['report_to' => $manager->id]);
        $svc = app(AttendanceApprovalService::class);
        $m = AttendanceRegularization::create(['user_id'=>$emp->id,'date'=>'2026-06-18','type'=>'other','reason'=>'x']);
        $svc->submit($m);

        $this->assertFalse($svc->canApprove($m->fresh(), $other));
        $this->assertFalse($svc->approve($m->fresh(), $other)['success']);
    }
}
```

> This test depends on Task 2's `AttendanceRegularization` model+table. If executing strictly in order, implement Task 2's migration+model first, then return here — OR run Tasks 1 and 2 as one combined task. The reviewer should treat 1+2 as a unit.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AttendanceApprovalServiceTest`
Expected: FAIL — service (and/or model) not found.

- [ ] **Step 3: Implement the service**

```php
<?php

namespace App\Services\Attendance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceApprovalService
{
    public function buildChain(User $requester, bool $escalate = false): array
    {
        $chain = [];
        $managerId = $requester->report_to ?? $requester->report_to_id ?? null;

        if ($managerId) {
            $chain[] = $this->entry(1, $managerId, optional($requester->reportsTo)->name ?? User::find($managerId)?->name ?? 'Manager');
        }

        if ($requester->department_id) {
            $head = User::where('department_id', $requester->department_id)
                ->where('id', '!=', $requester->id)
                ->when($managerId, fn ($q) => $q->where('id', '!=', $managerId))
                ->whereNotNull('designation_id')
                ->orderBy('designation_id')
                ->first();
            if ($head) {
                $chain[] = $this->entry(2, $head->id, $head->name);
            }
        }

        if ($escalate) {
            $hr = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['HR Manager', 'HR Head', 'Super Administrator']))
                ->where('id', '!=', $requester->id)
                ->first();
            if ($hr && ! collect($chain)->pluck('approver_id')->contains($hr->id)) {
                $chain[] = $this->entry(count($chain) + 1, $hr->id, $hr->name);
            }
        }

        return $chain;
    }

    private function entry(int $level, int $approverId, string $name): array
    {
        return ['level' => $level, 'approver_id' => $approverId, 'approver_name' => $name, 'status' => 'pending', 'approved_at' => null, 'comments' => null];
    }

    public function submit(Model $m, bool $escalate = false): void
    {
        $requester = User::find($m->user_id);
        $chain = $requester ? $this->buildChain($requester, $escalate) : [];

        if (empty($chain)) {
            $m->update(['status' => 'approved', 'current_approval_level' => 0, 'approval_chain' => [], 'approved_at' => now()]);

            return;
        }

        $m->update(['approval_chain' => $chain, 'current_approval_level' => 1, 'status' => 'pending']);
    }

    public function canApprove(Model $m, User $u): bool
    {
        if ($m->status !== 'pending') {
            return false;
        }
        foreach ($m->approval_chain ?? [] as $lvl) {
            if ($lvl['level'] === $m->current_approval_level && $lvl['approver_id'] === $u->id && $lvl['status'] === 'pending') {
                return true;
            }
        }

        return false;
    }

    public function approve(Model $m, User $approver, ?string $comments = null): array
    {
        if (! $this->canApprove($m, $approver)) {
            return ['success' => false, 'message' => 'Not authorized to approve.', 'status' => $m->status];
        }

        return DB::transaction(function () use ($m, $approver, $comments) {
            $chain = $m->approval_chain;
            foreach ($chain as &$lvl) {
                if ($lvl['level'] === $m->current_approval_level && $lvl['approver_id'] === $approver->id) {
                    $lvl['status'] = 'approved';
                    $lvl['approved_at'] = now()->toDateTimeString();
                    $lvl['comments'] = $comments;
                    break;
                }
            }
            unset($lvl);

            $more = collect($chain)->firstWhere('level', $m->current_approval_level + 1);
            if ($more) {
                $m->update(['approval_chain' => $chain, 'current_approval_level' => $m->current_approval_level + 1]);

                return ['success' => true, 'message' => 'Approved; forwarded to next level.', 'status' => 'pending'];
            }

            $m->update(['approval_chain' => $chain, 'status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now()]);

            return ['success' => true, 'message' => 'Approved.', 'status' => 'approved'];
        });
    }

    public function reject(Model $m, User $approver, string $reason): array
    {
        if (! $this->canApprove($m, $approver)) {
            return ['success' => false, 'message' => 'Not authorized to reject.', 'status' => $m->status];
        }

        return DB::transaction(function () use ($m, $approver, $reason) {
            $chain = $m->approval_chain;
            foreach ($chain as &$lvl) {
                if ($lvl['level'] === $m->current_approval_level && $lvl['approver_id'] === $approver->id) {
                    $lvl['status'] = 'rejected';
                    $lvl['approved_at'] = now()->toDateTimeString();
                    $lvl['comments'] = $reason;
                    break;
                }
            }
            unset($lvl);
            $m->update(['approval_chain' => $chain, 'status' => 'rejected', 'approved_by' => $approver->id]);

            return ['success' => true, 'message' => 'Rejected.', 'status' => 'rejected'];
        });
    }

    public function pendingFor(User $u, string $modelClass): Collection
    {
        return $modelClass::where('status', 'pending')->whereNotNull('approval_chain')->get()
            ->filter(fn ($m) => $this->canApprove($m, $u))->values();
    }
}
```

- [ ] **Step 4: Run test to verify it passes** (after Task 2 model exists)

Run: `php artisan test --filter=AttendanceApprovalServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/AttendanceApprovalService.php tests/Feature/Attendance/AttendanceApprovalServiceTest.php
git commit -m "feat(attendance): reusable multi-level approval chain service"
```

---

### Task 2: `attendance_regularizations` table + model + factory

**Files:**
- Create: `database/migrations/2026_06_20_000001_create_attendance_regularizations_table.php`, `app/Models/HRM/AttendanceRegularization.php`, `database/factories/HRM/AttendanceRegularizationFactory.php`
- Test: `tests/Feature/Attendance/RegularizationModelTest.php`

**Interfaces:**
- Produces: table `attendance_regularizations(id, user_id FK users cascade, date, attendance_id nullable, type enum[missing_punchin|missing_punchout|wrong_time|missed_day|other], requested_punchin datetime null, requested_punchout datetime null, reason, status enum[pending|approved|rejected|cancelled] default pending, approval_chain json null, current_approval_level int default 0, approved_by nullable, approved_at datetime null, applied bool default false, timestamps)`. Model casts `date`→date:Y-m-d, `requested_punchin/out`→datetime, `approval_chain`→array, `applied`→bool; relations `user()`, `attendance()`, `approver()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendanceRegularization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegularizationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_and_casts(): void
    {
        $u = User::factory()->create();
        $r = AttendanceRegularization::create([
            'user_id' => $u->id, 'date' => '2026-06-18', 'type' => 'missing_punchout',
            'requested_punchout' => '2026-06-18 18:00:00', 'reason' => 'forgot',
            'approval_chain' => [['level' => 1, 'approver_id' => 9]],
        ]);
        $fresh = $r->fresh();
        $this->assertSame('2026-06-18', $fresh->date->toDateString());
        $this->assertSame(9, $fresh->approval_chain[0]['approver_id']);
        $this->assertFalse($fresh->applied);
        $this->assertSame('pending', $fresh->status);
        $this->assertSame($u->id, $fresh->user->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RegularizationModelTest`
Expected: FAIL — table/model missing.

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_regularizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('attendance_id')->nullable();
            $table->enum('type', ['missing_punchin', 'missing_punchout', 'wrong_time', 'missed_day', 'other']);
            $table->dateTime('requested_punchin')->nullable();
            $table->dateTime('requested_punchout')->nullable();
            $table->string('reason');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->json('approval_chain')->nullable();
            $table->integer('current_approval_level')->default(0);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->boolean('applied')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'date']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_regularizations');
    }
};
```

- [ ] **Step 4: Model**

```php
<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRegularization extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'date', 'attendance_id', 'type', 'requested_punchin', 'requested_punchout',
        'reason', 'status', 'approval_chain', 'current_approval_level', 'approved_by', 'approved_at', 'applied',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'requested_punchin' => 'datetime',
        'requested_punchout' => 'datetime',
        'approval_chain' => 'array',
        'current_approval_level' => 'integer',
        'approved_at' => 'datetime',
        'applied' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
```

- [ ] **Step 5: Factory** `database/factories/HRM/AttendanceRegularizationFactory.php`

```php
<?php

namespace Database\Factories\HRM;

use App\Models\HRM\AttendanceRegularization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceRegularizationFactory extends Factory
{
    protected $model = AttendanceRegularization::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => '2026-06-18',
            'type' => 'missing_punchout',
            'requested_punchout' => '2026-06-18 18:00:00',
            'reason' => 'forgot to punch out',
            'status' => 'pending',
        ];
    }
}
```

- [ ] **Step 6: Run test + migrate dev DB**

Run: `php artisan test --filter=RegularizationModelTest` (PASS). Then `php artisan migrate` against `dbedc_guardian`.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_06_20_000001_create_attendance_regularizations_table.php app/Models/HRM/AttendanceRegularization.php database/factories/HRM/AttendanceRegularizationFactory.php tests/Feature/Attendance/RegularizationModelTest.php
git commit -m "feat(attendance): attendance_regularizations table + model"
```

---

### Task 3: `RegularizationService` — create, submit, apply-on-approval

**Files:**
- Create: `app/Services/Attendance/RegularizationService.php`
- Test: `tests/Feature/Attendance/RegularizationServiceTest.php`

**Interfaces:**
- Consumes: `AttendanceApprovalService`, `App\Services\Attendance\AttendanceAuditService`, `Attendance`, `AttendanceRegularization`.
- Produces:
  - `request(int $userId, array $data): AttendanceRegularization` — creates the row + `submit()`s it through the approval service.
  - `applyApproved(AttendanceRegularization $r): void` — when `status==='approved'` and `! applied`: create/update the `Attendance` row for `(user_id,date)` with the requested punchin/punchout, write an `attendance_audit_logs` row (`action='regularize'`, before/after), set `applied=true`, link `attendance_id`. Idempotent.
  - `approve(AttendanceRegularization $r, User $approver, ?string $comments): array` — delegates to the approval service; if it returns `status==='approved'`, calls `applyApproved`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceRegularization;
use App\Models\User;
use App\Services\Attendance\RegularizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegularizationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_regularization_applies_the_punch_and_audits(): void
    {
        $manager = User::factory()->create();
        $emp = User::factory()->create(['report_to' => $manager->id]);
        $svc = app(RegularizationService::class);

        // existing record with a missing punch-out
        Attendance::factory()->for($emp)->create([
            'date' => '2026-06-18', 'punchin' => '2026-06-18 09:00:00', 'punchout' => null,
        ]);

        $r = $svc->request($emp->id, [
            'date' => '2026-06-18', 'type' => 'missing_punchout',
            'requested_punchout' => '2026-06-18 18:00:00', 'reason' => 'forgot',
        ]);
        $this->assertSame('pending', $r->status);

        $res = $svc->approve($r->fresh(), $manager, 'ok');
        $this->assertTrue($res['success']);

        $r = $r->fresh();
        $this->assertSame('approved', $r->status);
        $this->assertTrue($r->applied);

        $att = Attendance::where('user_id', $emp->id)->whereDate('date', '2026-06-18')->first();
        $this->assertSame('18:00', \Carbon\Carbon::parse($att->punchout)->format('H:i'));
        $this->assertDatabaseHas('attendance_audit_logs', ['action' => 'regularize', 'attendance_id' => $att->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RegularizationServiceTest`
Expected: FAIL — service not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceRegularization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RegularizationService
{
    public function __construct(
        private readonly AttendanceApprovalService $approvals,
        private readonly AttendanceAuditService $audit,
    ) {}

    public function request(int $userId, array $data): AttendanceRegularization
    {
        $r = AttendanceRegularization::create([
            'user_id' => $userId,
            'date' => $data['date'],
            'type' => $data['type'],
            'requested_punchin' => $data['requested_punchin'] ?? null,
            'requested_punchout' => $data['requested_punchout'] ?? null,
            'reason' => $data['reason'],
        ]);
        $this->approvals->submit($r);

        // If auto-approved (no chain), apply immediately.
        $r->refresh();
        if ($r->status === 'approved') {
            $this->applyApproved($r);
        }

        return $r;
    }

    public function approve(AttendanceRegularization $r, User $approver, ?string $comments = null): array
    {
        $res = $this->approvals->approve($r, $approver, $comments);
        if (($res['status'] ?? null) === 'approved') {
            $this->applyApproved($r->fresh());
        }

        return $res;
    }

    public function applyApproved(AttendanceRegularization $r): void
    {
        if ($r->status !== 'approved' || $r->applied) {
            return;
        }

        DB::transaction(function () use ($r) {
            $att = Attendance::where('user_id', $r->user_id)->whereDate('date', $r->date->toDateString())->latest()->first();
            $before = $att ? $att->only(['punchin', 'punchout', 'date']) : null;

            $payload = ['user_id' => $r->user_id, 'date' => $r->date->toDateString()];
            if ($r->requested_punchin) {
                $payload['punchin'] = $r->requested_punchin;
            }
            if ($r->requested_punchout) {
                $payload['punchout'] = $r->requested_punchout;
            }

            if ($att) {
                $att->update($payload);
            } else {
                $att = Attendance::create($payload + ['symbol' => '√']);
            }

            $this->audit->record('regularize', $att->id, $before, $att->only(['punchin', 'punchout', 'date']), 'Regularization #'.$r->id, null);
            $r->update(['applied' => true, 'attendance_id' => $att->id]);
        });
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=RegularizationServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/RegularizationService.php tests/Feature/Attendance/RegularizationServiceTest.php
git commit -m "feat(attendance): regularization service applies approved punch corrections"
```

---

### Task 4: Regularization controller + routes

**Files:**
- Create: `app/Http/Controllers/HRM/RegularizationController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Attendance/RegularizationApiTest.php`

**Interfaces:**
- Produces:
  - `POST /attendance/regularizations` (employee, `permission:attendance.own.view`) → `RegularizationService::request($request->user()->id, validated)`; returns `{message, request}` 201.
  - `GET /attendance/regularizations/mine` (employee) → the user's requests.
  - `GET /attendance/regularizations/pending` (approver, `permission:attendance.manage`) → `AttendanceApprovalService::pendingFor(user, AttendanceRegularization::class)` with user/date.
  - `POST /attendance/regularizations/{id}/approve` + `/reject` (approver) → `RegularizationService::approve` / `AttendanceApprovalService::reject`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceRegularization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RegularizationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Manager']);
        foreach (['attendance.own.view', 'attendance.manage'] as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }
    }

    public function test_employee_requests_then_manager_approves(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Manager');
        $manager->givePermissionTo('attendance.manage');
        $emp = User::factory()->create(['report_to' => $manager->id]);
        $emp->assignRole('Employee');
        $emp->givePermissionTo('attendance.own.view');
        Attendance::factory()->for($emp)->create(['date' => '2026-06-18', 'punchin' => '2026-06-18 09:00:00', 'punchout' => null]);

        $this->actingAs($emp)->postJson(route('attendance.regularizations.store'), [
            'date' => '2026-06-18', 'type' => 'missing_punchout',
            'requested_punchout' => '2026-06-18 18:00:00', 'reason' => 'forgot',
        ])->assertCreated();

        $req = AttendanceRegularization::first();
        $this->actingAs($manager)->postJson(route('attendance.regularizations.approve', $req->id))->assertOk();

        $this->assertSame('approved', $req->fresh()->status);
        $this->assertTrue($req->fresh()->applied);
    }

    public function test_employee_cannot_access_pending_queue(): void
    {
        $emp = User::factory()->create();
        $emp->assignRole('Employee');
        $emp->givePermissionTo('attendance.own.view');
        $this->actingAs($emp)->getJson(route('attendance.regularizations.pending'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RegularizationApiTest`
Expected: FAIL — routes/controller missing.

- [ ] **Step 3: Controller**

```php
<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\AttendanceRegularization;
use App\Services\Attendance\AttendanceApprovalService;
use App\Services\Attendance\RegularizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegularizationController extends Controller
{
    public function __construct(
        private readonly RegularizationService $service,
        private readonly AttendanceApprovalService $approvals,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => 'required|date',
            'type' => 'required|in:missing_punchin,missing_punchout,wrong_time,missed_day,other',
            'requested_punchin' => 'nullable|date',
            'requested_punchout' => 'nullable|date',
            'reason' => 'required|string|max:500',
        ]);

        $req = $this->service->request($request->user()->id, $data);

        return response()->json(['message' => 'Regularization submitted.', 'request' => $req], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json([
            'requests' => AttendanceRegularization::where('user_id', $request->user()->id)
                ->orderByDesc('created_at')->get(),
        ]);
    }

    public function pending(Request $request): JsonResponse
    {
        $pending = $this->approvals->pendingFor($request->user(), AttendanceRegularization::class)
            ->load('user:id,name');

        return response()->json(['requests' => $pending->values()]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $r = AttendanceRegularization::findOrFail($id);
        $res = $this->service->approve($r, $request->user(), $request->input('comments'));

        return response()->json($res, $res['success'] ? 200 : 422);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $r = AttendanceRegularization::findOrFail($id);
        $res = $this->approvals->reject($r, $request->user(), $data['reason']);

        return response()->json($res, $res['success'] ? 200 : 422);
    }
}
```

- [ ] **Step 4: Routes** — in `routes/web.php`:

Employee group (`permission:attendance.own.view`, near `attendance.myRoster`):
```php
        Route::post('/attendance/regularizations', [\App\Http\Controllers\HRM\RegularizationController::class, 'store'])->name('attendance.regularizations.store');
        Route::get('/attendance/regularizations/mine', [\App\Http\Controllers\HRM\RegularizationController::class, 'mine'])->name('attendance.regularizations.mine');
```
Approver group (`permission:attendance.manage`, near `attendance.mark-as-present`):
```php
        Route::get('/attendance/regularizations/pending', [\App\Http\Controllers\HRM\RegularizationController::class, 'pending'])->name('attendance.regularizations.pending');
        Route::post('/attendance/regularizations/{id}/approve', [\App\Http\Controllers\HRM\RegularizationController::class, 'approve'])->name('attendance.regularizations.approve');
        Route::post('/attendance/regularizations/{id}/reject', [\App\Http\Controllers\HRM\RegularizationController::class, 'reject'])->name('attendance.regularizations.reject');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=RegularizationApiTest`
Expected: PASS. Then `php artisan test --filter=NavigationRoutesTest` (route registration OK).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/HRM/RegularizationController.php routes/web.php tests/Feature/Attendance/RegularizationApiTest.php
git commit -m "feat(attendance): regularization request + approval API"
```

---

### Task 5: `comp_off_ledger` + `CompOffService`

**Files:**
- Create: `database/migrations/2026_06_20_000002_create_comp_off_ledger_table.php`, `app/Models/HRM/CompOffLedger.php`, `app/Services/Attendance/CompOffService.php`
- Test: `tests/Feature/Attendance/CompOffServiceTest.php`

**Interfaces:**
- Produces: table `comp_off_ledger(id, user_id FK, minutes int [+credit/-debit], source_type enum[overtime|holiday_work|manual|used], source_id nullable, note nullable, expires_at date null, created_at)`. `CompOffService::credit(userId, minutes, sourceType, sourceId=null, note=null): CompOffLedger`; `debit(userId, minutes, note=null): CompOffLedger`; `balance(userId): int` (sum of minutes).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use App\Services\Attendance\CompOffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompOffServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_and_debit_track_balance(): void
    {
        $u = User::factory()->create();
        $svc = app(CompOffService::class);

        $svc->credit($u->id, 120, 'overtime', 7);
        $svc->credit($u->id, 60, 'holiday_work');
        $this->assertSame(180, $svc->balance($u->id));

        $svc->debit($u->id, 90, 'took half day');
        $this->assertSame(90, $svc->balance($u->id));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CompOffServiceTest`
Expected: FAIL.

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comp_off_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('minutes'); // + credit, - debit
            $table->enum('source_type', ['overtime', 'holiday_work', 'manual', 'used']);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('note')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comp_off_ledger');
    }
};
```

- [ ] **Step 4: Model + service**

`app/Models/HRM/CompOffLedger.php`:
```php
<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompOffLedger extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'comp_off_ledger';

    protected $fillable = ['user_id', 'minutes', 'source_type', 'source_id', 'note', 'expires_at'];

    protected $casts = ['minutes' => 'integer', 'expires_at' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

`app/Services/Attendance/CompOffService.php`:
```php
<?php

namespace App\Services\Attendance;

use App\Models\HRM\CompOffLedger;

class CompOffService
{
    public function credit(int $userId, int $minutes, string $sourceType, ?int $sourceId = null, ?string $note = null): CompOffLedger
    {
        return CompOffLedger::create([
            'user_id' => $userId, 'minutes' => abs($minutes), 'source_type' => $sourceType,
            'source_id' => $sourceId, 'note' => $note,
        ]);
    }

    public function debit(int $userId, int $minutes, ?string $note = null): CompOffLedger
    {
        return CompOffLedger::create([
            'user_id' => $userId, 'minutes' => -abs($minutes), 'source_type' => 'used', 'note' => $note,
        ]);
    }

    public function balance(int $userId): int
    {
        return (int) CompOffLedger::where('user_id', $userId)->sum('minutes');
    }
}
```

- [ ] **Step 5: Run test + migrate dev DB + commit**

Run: `php artisan test --filter=CompOffServiceTest` (PASS); `php artisan migrate`.
```bash
git add database/migrations/2026_06_20_000002_create_comp_off_ledger_table.php app/Models/HRM/CompOffLedger.php app/Services/Attendance/CompOffService.php tests/Feature/Attendance/CompOffServiceTest.php
git commit -m "feat(attendance): comp-off (TOIL) ledger + service"
```

---

### Task 6: `overtime_requests` table/model + `OvertimeService` + controller + routes

**Files:**
- Create: migration `2026_06_20_000003_create_overtime_requests_table.php`, `app/Models/HRM/OvertimeRequest.php`, `database/factories/HRM/OvertimeRequestFactory.php`, `app/Services/Attendance/OvertimeService.php`, `app/Http/Controllers/HRM/OvertimeController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Attendance/OvertimeApiTest.php`

**Interfaces:**
- Produces:
  - table `overtime_requests(id, user_id FK, date, requested_minutes int, reason, status enum[pending|approved|rejected|cancelled] default pending, approval_chain json null, current_approval_level int default 0, approved_by nullable, approved_at null, comp_off_granted bool default false, timestamps)`. Model casts dates/chain/bools; relations user(), approver().
  - `OvertimeService::request(userId, data): OvertimeRequest` (creates + submit); `approve(OvertimeRequest, approver, comments, bool $grantCompOff): array` — on final approval, if `$grantCompOff`, `CompOffService::credit(userId, requested_minutes, 'overtime', request id)` and set `comp_off_granted=true`.
  - Routes: `POST /attendance/overtime` (employee), `GET /attendance/overtime/mine` (employee), `GET /attendance/overtime/pending` (approver), `POST /attendance/overtime/{id}/approve` (approver, accepts `grant_comp_off` bool), `POST /attendance/overtime/{id}/reject` (approver).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\OvertimeRequest;
use App\Models\User;
use App\Services\Attendance\CompOffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OvertimeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Manager']);
        foreach (['attendance.own.view', 'attendance.manage'] as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }
    }

    public function test_overtime_approval_grants_comp_off(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Manager');
        $manager->givePermissionTo('attendance.manage');
        $emp = User::factory()->create(['report_to' => $manager->id]);
        $emp->assignRole('Employee');
        $emp->givePermissionTo('attendance.own.view');

        $this->actingAs($emp)->postJson(route('attendance.overtime.store'), [
            'date' => '2026-06-18', 'requested_minutes' => 120, 'reason' => 'release',
        ])->assertCreated();

        $ot = OvertimeRequest::first();
        $this->actingAs($manager)->postJson(route('attendance.overtime.approve', $ot->id), [
            'grant_comp_off' => true,
        ])->assertOk();

        $this->assertSame('approved', $ot->fresh()->status);
        $this->assertTrue($ot->fresh()->comp_off_granted);
        $this->assertSame(120, app(CompOffService::class)->balance($emp->id));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OvertimeApiTest`
Expected: FAIL.

- [ ] **Step 3: Migration** — `overtime_requests` per the Interfaces block:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->integer('requested_minutes');
            $table->string('reason');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->json('approval_chain')->nullable();
            $table->integer('current_approval_level')->default(0);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->boolean('comp_off_granted')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'date']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
    }
};
```

- [ ] **Step 4: Model + factory + service + controller**

`app/Models/HRM/OvertimeRequest.php` — same shape as `AttendanceRegularization` (fillable: user_id,date,requested_minutes,reason,status,approval_chain,current_approval_level,approved_by,approved_at,comp_off_granted; casts: date→date:Y-m-d, approval_chain→array, current_approval_level→int, approved_at→datetime, requested_minutes→int, comp_off_granted→bool; relations user(), approver()).

`database/factories/HRM/OvertimeRequestFactory.php` — `{user_id=>User::factory(), date=>'2026-06-18', requested_minutes=>120, reason=>'ot', status=>'pending'}`.

`app/Services/Attendance/OvertimeService.php`:
```php
<?php

namespace App\Services\Attendance;

use App\Models\HRM\OvertimeRequest;
use App\Models\User;

class OvertimeService
{
    public function __construct(
        private readonly AttendanceApprovalService $approvals,
        private readonly CompOffService $compOff,
    ) {}

    public function request(int $userId, array $data): OvertimeRequest
    {
        $ot = OvertimeRequest::create([
            'user_id' => $userId, 'date' => $data['date'],
            'requested_minutes' => (int) $data['requested_minutes'], 'reason' => $data['reason'],
        ]);
        $this->approvals->submit($ot);

        return $ot->refresh();
    }

    public function approve(OvertimeRequest $ot, User $approver, ?string $comments, bool $grantCompOff): array
    {
        $res = $this->approvals->approve($ot, $approver, $comments);
        if (($res['status'] ?? null) === 'approved') {
            $ot->refresh();
            if ($grantCompOff && ! $ot->comp_off_granted) {
                $this->compOff->credit($ot->user_id, $ot->requested_minutes, 'overtime', $ot->id, 'OT #'.$ot->id);
                $ot->update(['comp_off_granted' => true]);
            }
        }

        return $res;
    }
}
```

`app/Http/Controllers/HRM/OvertimeController.php` — mirror `RegularizationController` (store → `OvertimeService::request`; mine; pending → `pendingFor(user, OvertimeRequest::class)`; approve → `OvertimeService::approve($ot, user, comments, (bool)$request->boolean('grant_comp_off'))`; reject → `AttendanceApprovalService::reject`). Validation for store: `date required|date`, `requested_minutes required|integer|min:1|max:1440`, `reason required|string|max:500`.

- [ ] **Step 5: Routes** (mirror Task 4 placement; employee `attendance.own.view`, approver `attendance.manage`):
```php
        Route::post('/attendance/overtime', [\App\Http\Controllers\HRM\OvertimeController::class, 'store'])->name('attendance.overtime.store');
        Route::get('/attendance/overtime/mine', [\App\Http\Controllers\HRM\OvertimeController::class, 'mine'])->name('attendance.overtime.mine');
        Route::get('/attendance/overtime/pending', [\App\Http\Controllers\HRM\OvertimeController::class, 'pending'])->name('attendance.overtime.pending');
        Route::post('/attendance/overtime/{id}/approve', [\App\Http\Controllers\HRM\OvertimeController::class, 'approve'])->name('attendance.overtime.approve');
        Route::post('/attendance/overtime/{id}/reject', [\App\Http\Controllers\HRM\OvertimeController::class, 'reject'])->name('attendance.overtime.reject');
```

- [ ] **Step 6: Run test + migrate dev DB + commit**

Run: `php artisan test --filter=OvertimeApiTest` (PASS); `php artisan migrate`.
```bash
git add database/migrations/2026_06_20_000003_create_overtime_requests_table.php app/Models/HRM/OvertimeRequest.php database/factories/HRM/OvertimeRequestFactory.php app/Services/Attendance/OvertimeService.php app/Http/Controllers/HRM/OvertimeController.php routes/web.php tests/Feature/Attendance/OvertimeApiTest.php
git commit -m "feat(attendance): overtime requests with approval + comp-off grant"
```

---

### Task 7: Frontend — employee request forms + My Requests + comp-off balance

**Files:**
- Create: `resources/js/Forms/RegularizationForm.jsx`, `resources/js/Forms/OvertimeRequestForm.jsx`, `resources/js/Pages/Attendance/Components/MyRequests.jsx`
- Modify: `resources/js/Pages/AttendanceEmployee.jsx` (mount the two request buttons + `<MyRequests/>`); add a `compOffBalanceMinutes` + lists endpoints
- Backend add: `GET /attendance/comp-off/mine` (employee) → `{ balance_minutes, entries }` via `CompOffService::balance` + recent ledger entries (add to a controller — extend `OvertimeController` or a small `CompOffController`; gate `attendance.own.view`).
- Verify: `npx vite build` + Playwright (submit a regularization → 201 → appears in My Requests).

**Interfaces:**
- Consumes: `POST /attendance/regularizations`, `GET /attendance/regularizations/mine`, `POST /attendance/overtime`, `GET /attendance/overtime/mine`, `GET /attendance/comp-off/mine`. requestJson `(method,url,{data|params})`.
- Produces: `RegularizationForm` (Radix Dialog: date, type Select, requested punch-in/out datetimes conditionally, reason → POST); `OvertimeRequestForm` (date, minutes, reason → POST); `MyRequests` (tabs/sections listing the user's regularizations + OT with status badges + comp-off balance).

- [ ] **Step 1: Backend comp-off endpoint** — add `CompOffController::mine` (or method on OvertimeController):

```php
    public function compOffMine(Request $request): JsonResponse
    {
        $uid = $request->user()->id;
        return response()->json([
            'balance_minutes' => app(\App\Services\Attendance\CompOffService::class)->balance($uid),
            'entries' => \App\Models\HRM\CompOffLedger::where('user_id', $uid)->orderByDesc('id')->limit(50)->get(),
        ]);
    }
```
Route (employee group): `Route::get('/attendance/comp-off/mine', ...)->name('attendance.compoff.mine');`. Add a quick feature test asserting `attendance.own.view` employee gets `balance_minutes` (and a forbidden user gets 403 — actually any authenticated employee with the permission). Commit backend separately or with the frontend; if backend-only here, add `tests/Feature/Attendance/CompOffApiTest.php`.

- [ ] **Step 2: Create the components** (Radix dialogs mirroring `ShiftAssignmentForm.jsx`/`SwapRequestForm.jsx`; use the real `requestJson` form; surface 422 messages). Keep each small. `MyRequests` uses `useQuery` for the three endpoints and renders status `Badge`s.

- [ ] **Step 3: Mount in `AttendanceEmployee.jsx`** — read it first; add a "Requests" card with "Regularize a day" + "Request overtime" buttons opening the dialogs, a comp-off balance stat, and `<MyRequests/>` below. Match the page's existing React Query + toast patterns.

- [ ] **Step 4: Build + verify** — `npx vite build`; on the dev server submit a regularization as an employee → confirm 201 and it appears under My Requests (verify by HTTP status). Confirm `/attendance-employee` still renders 200 (you touched its controller props if you added employees there — not required for this task).

- [ ] **Step 5: Commit** (source-only)
```bash
git add resources/js/Forms/RegularizationForm.jsx resources/js/Forms/OvertimeRequestForm.jsx resources/js/Pages/Attendance/Components/MyRequests.jsx resources/js/Pages/AttendanceEmployee.jsx app/Http/Controllers/HRM/*.php routes/web.php tests/Feature/Attendance/CompOffApiTest.php
git commit -m "feat(attendance): employee regularization/OT request UI + comp-off balance"
```

---

### Task 8: Frontend — Approvals inbox (manager/admin)

**Files:**
- Create: `resources/js/Pages/Attendance/Components/ApprovalsInbox.jsx`
- Modify: `resources/js/Pages/Attendance/AttendancePage.jsx` (add an "Approvals" tab gated by `attendance.manage`)
- Verify: `npx vite build` + Playwright.

**Interfaces:**
- Consumes: `GET /attendance/regularizations/pending`, `POST .../{id}/approve|reject`, `GET /attendance/overtime/pending`, `POST .../{id}/approve|reject` (OT approve sends `{ grant_comp_off }`). requestJson `(method,url,{data})`.
- Produces: an Approvals tab with two sections (Regularizations, Overtime) listing pending items (requester, date, details) with Approve / Reject; OT approve offers a "grant comp-off" checkbox; on success invalidate the relevant query.

- [ ] **Step 1: Create `ApprovalsInbox.jsx`** — two `useQuery` lists + `useMutation` for approve/reject; Radix `Table` + `Button`s; a reject opens a small reason prompt (Dialog or `window.prompt` acceptable for v1); OT approve includes a `Checkbox` "Grant comp-off". Mirror `SwapApprovals.jsx`.

- [ ] **Step 2: Add the Approvals tab in `AttendancePage.jsx`** — lazy import + a `tabs` entry gated by `auth.permissions?.includes('attendance.manage')` + a `Tabs.Content value="approvals"` panel with `ErrorBoundary` + `Suspense`, mirroring the existing Roster/Settings tab wiring.

- [ ] **Step 3: Build + verify** — `npx vite build`; as a manager, submit a regularization (as an employee in another session/seed) then Approve from the inbox; confirm the request flips to approved and the punch was applied (HTTP 200 on approve; spot-check the attendance record).

- [ ] **Step 4: Commit** (source-only)
```bash
git add resources/js/Pages/Attendance/Components/ApprovalsInbox.jsx resources/js/Pages/Attendance/AttendancePage.jsx
git commit -m "feat(attendance): manager approvals inbox (regularization + overtime)"
```

---

### Task 9: Phase 2 acceptance sweep

**Files:** Verify only + one consolidated `public/build` rebuild.

- [ ] **Step 1: Full suite** — `php artisan test`; only the 2 known pre-existing failures.
- [ ] **Step 2: Migrate dev DB** — confirm `php artisan migrate:status` shows the 3 new migrations Ran on `dbedc_guardian`.
- [ ] **Step 3: Build + commit assets** — `npx vite build`; `git add public/build && git commit -m "build(attendance): rebuild assets for Phase 2 workflows"`.
- [ ] **Step 4: End-to-end browser pass (HTTP status, not shells)** — employee submits regularization + OT; comp-off balance shows; manager approves both from the inbox; regularization applied the punch (audit row written); OT granted comp-off. Confirm each endpoint 2xx.
- [ ] **Step 5: Closeout** — `git commit --allow-empty -m "test(attendance): Phase 2 workflows acceptance sweep"`.

---

## Self-Review

**Spec coverage (design spec §4 Phase 2 — workflows):** regularization → Tasks 2–4, 7–8; OT request/approval → Task 6, 7–8; comp-off/TOIL → Tasks 5–6 (credit on OT approval), 7 (balance). Approval chains reuse the Leave pattern → Task 1. ✅

**Placeholder scan:** Backend tasks carry full code + tests. Frontend Tasks 7–8 give exact endpoints + component contracts and require reading the sibling components (`SwapApprovals.jsx`, `ShiftAssignmentForm.jsx`, `AttendanceEmployee.jsx`) for the established patterns — the reviewer should confirm requestJson form + mounts, as in the completion plan. The OvertimeRequest model/controller reuse the Regularization shape verbatim (described, not re-pasted, to avoid drift — implementer mirrors the committed Regularization files).

**Type consistency:** `AttendanceApprovalService` methods (`buildChain/submit/approve/reject/canApprove/pendingFor`) are used identically in Tasks 3, 4, 6. Status strings `pending|approved|rejected|cancelled`, the chain entry shape, and `report_to` field match the Leave module. Route names (`attendance.regularizations.*`, `attendance.overtime.*`, `attendance.compoff.mine`) match between backend and frontend tasks.
