# Attendance Phase 2 — Leave Correctness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make leave a first-class, fractional, audited input to the single attendance engine — half-day (AM/PM) support, server-computed day-counts, canonical statuses, paid/unpaid split, and an immutable leave audit trail — so every surface (grid, dashboard, accounts summary) reconciles.

**Architecture:** Leaves remain *clean inputs* to the existing `AttendanceStatusService` engine pass (`AttendanceReportService::buildMonthlyDayResults` → `classifyDay`). Phase 2 enriches the leave input from a boolean to a **fraction (0 / 0.5 / 1.0) + session**, moves day-counting server-side into a new `LeaveDayCalculator` (reusing the Phase-1 `HolidayService` + the `ScheduleResolver` contract), normalizes `leaves.status` to a canonical enum, adds `is_paid` to leave types, and introduces a `leave_audit_logs` table/service mirroring the existing `attendance_audit_logs`.

**Tech Stack:** Laravel 11, PHP 8.2, MySQL (prod) / sqlite `:memory:` (tests), PHPUnit class-style + `RefreshDatabase`, Inertia v2 / React 18 (frontend only lightly touched: the summary export gains two columns).

## Global Constraints

- **Source of truth:** `docs/superpowers/specs/2026-06-23-attendance-leaves-holidays-10of10-design.md` (Phase 2 section, revised 2026-06-25). Locked decisions there govern; this plan implements them.
- **TZ:** Asia/Dhaka, single-tenant. All date math via `Carbon`.
- **DB:** New migrations MUST be run on dev MySQL `dbedc_guardian` via `php artisan migrate` (tests use sqlite `:memory:`). Data-conversion migrations are reversible/idempotent where possible.
- **Dev/test rules:** `npm run dev` (background) + verify at `https://aero-enterprise-suite.test`; **never** `npm run build` (postbuild auto-commits/pushes). Frontend builds via `npx vite build` only; one consolidated `public/build` commit at the very end if frontend changed.
- **Canonical leave status set:** `{pending, approved, rejected, cancelled}` (lowercase). No other values may be written after Task 1.
- **Reconciliation invariant:** summary == dashboard == grid. Every engine-touching task must preserve it.
- **Allowed pre-existing failures only** (add no new failure): `MobileSyncApiTest > sync push applies leave apply mutation`; `NavigationRoutesTest > any authenticated user can access organization directory`.
- **Commits:** one focused commit per task; a background auto-committer interleaves `ok`/`build` commits — don't fight it, just commit your own source-only changes.
- **Skills:** invoke `engineering-standards` + `current-tech-stack` before each code task (mandatory in this repo).

---

## File Structure

**New files:**
- `database/migrations/2026_06_25_000001_normalize_leaves_status.php` — data + (sqlite-safe) conversion of `leaves.status` to canonical set.
- `database/migrations/2026_06_25_000002_add_paid_to_leave_settings.php` — `is_paid` bool.
- `database/migrations/2026_06_25_000003_add_half_day_to_leaves.php` — `is_half_day`, `half_day_session`, widen `no_of_days` to decimal(5,1).
- `database/migrations/2026_06_25_000004_create_leave_audit_logs_table.php` — immutable audit log.
- `app/Services/Leave/LeaveDayCalculator.php` — server-side working-day count (fraction-aware).
- `app/Services/Leave/LeaveAuditService.php` — `record(...)` mirroring `AttendanceAuditService`.
- `app/Models/HRM/LeaveAuditLog.php` — immutable model (`UPDATED_AT = null`).
- `tests/Feature/Leave/LeaveStatusNormalizationTest.php`
- `tests/Feature/Leave/LeaveDayCalculatorTest.php`
- `tests/Feature/Leave/LeaveFractionEngineTest.php`
- `tests/Feature/Leave/LeaveAuditLogTest.php`
- `tests/Feature/Leave/LeaveValidationHardeningTest.php`

**Modified files:**
- `app/Models/HRM/Leave.php` — casts/fillable (`is_half_day`, `half_day_session`, `no_of_days` decimal), `status_color` accessor canonicalized.
- `app/Models/HRM/LeaveSetting.php` — `$fillable` += `symbol`, `is_earned`, `is_paid`; casts.
- `app/Services/Leave/LeaveCrudService.php` — server day-count, canonical statuses, audit hooks.
- `app/Services/Leave/LeaveApprovalService.php` — audit hooks on approve/reject (read first; same pattern).
- `app/Services/Leave/LeaveValidationService.php` — canonical status enum, half-day rules, drop `daysCount` authority.
- `app/Http/Controllers/LeaveController.php` — bulk-status `in:` rule + `'declined'` literals → canonical.
- `app/Services/Attendance/AttendanceStatusService.php` — `resolve()` gains `leaveFraction`/`leaveSession`; reconciliation rules.
- `app/Services/Attendance/DTO/DayAttendance.php` — `leave_fraction`, `leave_session` fields.
- `app/Services/Attendance/AttendanceReportService.php` — pass fraction into engine; fractional counting in dashboard + per-employee summary; paid/LWP split; `getLeaveCountsArray` status filter.
- `app/Exports/LeaveSummaryExport.php` / per-employee export — surface `paid_leave` / `lwp` columns (frontend/export only).

---

## Task 1: Normalize `leaves.status` to the canonical set

**Files:**
- Create: `database/migrations/2026_06_25_000001_normalize_leaves_status.php`
- Modify: `app/Models/HRM/Leave.php` (`getStatusColorAttribute`)
- Modify: `app/Services/Leave/LeaveCrudService.php` (`'new'` literal in `createLeave`; `'declined'` is already lowercased via `updateLeaveStatus`)
- Modify: `app/Http/Controllers/LeaveController.php` (`bulkReject` `'declined'`; `bulkStatusUpdate` `in:` rule + `$statusLabel`)
- Modify: `app/Services/Attendance/AttendanceReportService.php` (`getLeaveCountsArray` — only the status filter is added in Task 7; leave the approved filter at line 59 as-is here)
- Test: `tests/Feature/Leave/LeaveStatusNormalizationTest.php`

**Interfaces:**
- Produces: canonical `leaves.status` values `{pending, approved, rejected, cancelled}`. All later tasks assume these.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Leave/LeaveStatusNormalizationTest.php
namespace Tests\Feature\Leave;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeaveStatusNormalizationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_maps_legacy_status_values_to_the_canonical_set(): void
    {
        // Insert legacy-cased rows directly, bypassing the model, to mimic prod data.
        $userId = \App\Models\User::factory()->create()->id;
        $typeId = \App\Models\HRM\LeaveSetting::factory()->create()->id;

        foreach (['Approved', 'New', 'Pending', 'Declined', 'rejected', 'CANCELLED'] as $i => $legacy) {
            DB::table('leaves')->insert([
                'user_id' => $userId,
                'leave_type' => $typeId,
                'from_date' => '2026-01-0'.($i + 1),
                'to_date' => '2026-01-0'.($i + 1),
                'no_of_days' => 1,
                'reason' => 'x',
                'status' => $legacy,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Re-run the normalization migration logic against the seeded rows.
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_06_25_000001_normalize_leaves_status.php', '--force' => true]);

        $statuses = DB::table('leaves')->pluck('status')->map(fn ($s) => strtolower($s))->unique()->values()->all();
        sort($statuses);

        $this->assertSame(['approved', 'cancelled', 'pending', 'rejected'], $statuses);
        $this->assertSame(2, DB::table('leaves')->where('status', 'pending')->count()); // New + Pending
        $this->assertSame(1, DB::table('leaves')->where('status', 'rejected')->count() + DB::table('leaves')->where('status', 'rejected')->count() - DB::table('leaves')->where('status', 'rejected')->count() + 1) ; // Declined + rejected => 2, see note
    }
}
```

> Note: the second assert line above is intentionally simplified in Step 3's final test. Replace the last assertion with the clean version below before running — keep the file readable:

```php
        $this->assertSame(2, DB::table('leaves')->where('status', 'rejected')->count()); // Declined + rejected
        $this->assertSame(1, DB::table('leaves')->where('status', 'approved')->count());
        $this->assertSame(1, DB::table('leaves')->where('status', 'cancelled')->count());
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LeaveStatusNormalizationTest`
Expected: FAIL — migration file does not exist yet ("Unable to find" / no path).

- [ ] **Step 3: Write the normalization migration**

```php
<?php
// database/migrations/2026_06_25_000001_normalize_leaves_status.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canonicalize leaves.status to {pending, approved, rejected, cancelled}.
     * Idempotent: safe to re-run. Case-insensitive mapping of all known legacy values.
     */
    public function up(): void
    {
        if (! Schema::hasTable('leaves')) {
            return;
        }

        $map = [
            'new' => 'pending',
            'pending' => 'pending',
            'approved' => 'approved',
            'declined' => 'rejected',
            'rejected' => 'rejected',
            'cancelled' => 'cancelled',
            'canceled' => 'cancelled',
        ];

        // Pull distinct existing values, map case-insensitively, write canonical.
        $existing = DB::table('leaves')->select('status')->distinct()->pluck('status');
        foreach ($existing as $value) {
            $canonical = $map[strtolower((string) $value)] ?? 'pending';
            if ((string) $value !== $canonical) {
                DB::table('leaves')->where('status', $value)->update(['status' => $canonical]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: legacy casing is not restored.
    }
};
```

Then finalize the test's last three assertions to the clean version shown in the Step-1 note.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=LeaveStatusNormalizationTest`
Expected: PASS.

- [ ] **Step 5: Canonicalize the write-side literals**

In `app/Models/HRM/Leave.php`, replace the accessor:

```php
    public function getStatusColorAttribute(): string
    {
        return match (strtolower((string) $this->status)) {
            'pending' => 'warning',
            'approved' => 'success',
            'rejected' => 'danger',
            'cancelled' => 'secondary',
            default => 'default'
        };
    }
```

In `app/Services/Leave/LeaveCrudService.php` `createLeave`, change the initial status from `'new'` to `'pending'`:

```php
            $leave = Leave::create([
                'user_id' => $data['user_id'],
                'leave_type' => $leaveTypeId,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'no_of_days' => $data['daysCount'], // replaced in Task 5
                'reason' => $data['leaveReason'],
                'status' => 'pending',
            ]);
```

In `app/Http/Controllers/LeaveController.php`:
- `bulkReject` loop: `updateLeaveStatus($leaveId, 'rejected', Auth::id())` (was `'declined'`).
- `bulkStatusUpdate` validation rule: `'status' => 'required|string|in:approved,rejected,pending,cancelled'` (was `in:approved,declined,pending,new`); and the `$statusLabel` line becomes `$statusLabel = ucfirst($targetStatus);`.

In `app/Services/Leave/LeaveCrudService.php` `getRemainingDays`, the `whereRaw('LOWER(status) IN (?, ?)', ['approved', 'pending'])` already matches canonical values — leave as-is.

- [ ] **Step 6: Run the leave + attendance suites to verify no regression**

Run: `php artisan test --filter="Leave"` then `php artisan test --filter="Attendance"`
Expected: PASS (allowed pre-existing failures excepted). If `MobileSyncApiTest`/`NavigationRoutesTest` referenced `declined`/`new`, that is the allowed `MobileSyncApiTest` case only — add no new failure.

- [ ] **Step 7: Run the normalization migration on dev MySQL**

Run: `php artisan migrate`
Expected: `2026_06_25_000001_normalize_leaves_status` runs; dev rows `Approved/New/Pending` become `approved/pending/pending`.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_06_25_000001_normalize_leaves_status.php app/Models/HRM/Leave.php app/Services/Leave/LeaveCrudService.php app/Http/Controllers/LeaveController.php tests/Feature/Leave/LeaveStatusNormalizationTest.php
git commit -m "feat(leave): normalize leaves.status to canonical {pending,approved,rejected,cancelled}"
```

---

## Task 2: `LeaveSetting` fillable + `is_paid`

**Files:**
- Create: `database/migrations/2026_06_25_000002_add_paid_to_leave_settings.php`
- Modify: `app/Models/HRM/LeaveSetting.php`
- Test: `tests/Feature/Leave/LeaveDayCalculatorTest.php` is created in Task 4; for this task add a small assertion to an existing test or a focused one inline.

**Interfaces:**
- Produces: `LeaveSetting.is_paid` (bool, default true); `$fillable` includes `symbol`, `is_earned`, `is_paid`. Task 7's paid/LWP split consumes `is_paid`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Leave/LeaveSettingPaidTest.php
namespace Tests\Feature\Leave;

use App\Models\HRM\LeaveSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveSettingPaidTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function is_paid_symbol_and_is_earned_are_mass_assignable(): void
    {
        $setting = LeaveSetting::create([
            'type' => 'Casual', 'symbol' => 'C', 'days' => 10,
            'is_earned' => true, 'is_paid' => false,
        ]);

        $this->assertSame('C', $setting->symbol);
        $this->assertTrue($setting->is_earned);
        $this->assertFalse($setting->is_paid);
    }

    /** @test */
    public function is_paid_defaults_to_true(): void
    {
        $setting = LeaveSetting::create(['type' => 'Annual', 'days' => 20]);
        $this->assertTrue($setting->fresh()->is_paid);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LeaveSettingPaidTest`
Expected: FAIL — `is_paid` column/fillable missing; `symbol`/`is_earned` not fillable.

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_06_25_000002_add_paid_to_leave_settings.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_settings') || Schema::hasColumn('leave_settings', 'is_paid')) {
            return;
        }

        Schema::table('leave_settings', function (Blueprint $table) {
            $table->boolean('is_paid')->default(true)->after('is_earned');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_settings') && Schema::hasColumn('leave_settings', 'is_paid')) {
            Schema::table('leave_settings', function (Blueprint $table) {
                $table->dropColumn('is_paid');
            });
        }
    }
};
```

> sqlite has no `after()` enforcement but accepts the call; MySQL honors it.

- [ ] **Step 4: Update the model**

In `app/Models/HRM/LeaveSetting.php`:

```php
    protected $fillable = [
        'type',
        'symbol',
        'days',
        'eligibility',
        'carry_forward',
        'earned_leave',
        'is_earned',
        'is_paid',
        'requires_approval',
        'auto_approve',
        'special_conditions',
    ];

    protected $casts = [
        'id' => 'integer',
        'type' => 'string',
        'days' => 'integer',
        'eligibility' => 'string',
        'carry_forward' => 'boolean',
        'earned_leave' => 'boolean',
        'is_earned' => 'boolean',
        'is_paid' => 'boolean',
        'requires_approval' => 'boolean',
        'auto_approve' => 'boolean',
        'special_conditions' => 'string',
    ];
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=LeaveSettingPaidTest`
Expected: PASS.

- [ ] **Step 6: Run the migration on dev MySQL**

Run: `php artisan migrate`
Expected: `is_paid` column added.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_06_25_000002_add_paid_to_leave_settings.php app/Models/HRM/LeaveSetting.php tests/Feature/Leave/LeaveSettingPaidTest.php
git commit -m "feat(leave): add is_paid to leave_settings + expose symbol/is_earned/is_paid as fillable"
```

---

## Task 3: Half-day columns on `leaves` + decimal `no_of_days`

**Files:**
- Create: `database/migrations/2026_06_25_000003_add_half_day_to_leaves.php`
- Modify: `app/Models/HRM/Leave.php` (fillable + casts)
- Test: `tests/Feature/Leave/LeaveDayCalculatorTest.php` (created in Task 4 — for this task, assert column presence via a focused test)

**Interfaces:**
- Produces: `leaves.is_half_day` (bool, default false), `leaves.half_day_session` (string nullable, `first_half|second_half`), `leaves.no_of_days` widened to `decimal(5,1)`. Tasks 4–7 read these.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Leave/LeaveHalfDayColumnsTest.php
namespace Tests\Feature\Leave;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveHalfDayColumnsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function half_day_fields_persist_and_no_of_days_holds_a_half(): void
    {
        $user = User::factory()->create();
        $type = LeaveSetting::factory()->create();

        $leave = Leave::create([
            'user_id' => $user->id,
            'leave_type' => $type->id,
            'from_date' => '2026-02-10',
            'to_date' => '2026-02-10',
            'no_of_days' => 0.5,
            'reason' => 'half day',
            'status' => 'pending',
            'is_half_day' => true,
            'half_day_session' => 'first_half',
        ])->fresh();

        $this->assertTrue($leave->is_half_day);
        $this->assertSame('first_half', $leave->half_day_session);
        $this->assertSame(0.5, (float) $leave->no_of_days);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LeaveHalfDayColumnsTest`
Expected: FAIL — columns/fillable missing; `no_of_days` cast to integer truncates 0.5 → 0.

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_06_25_000003_add_half_day_to_leaves.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leaves')) {
            return;
        }

        Schema::table('leaves', function (Blueprint $table) {
            if (! Schema::hasColumn('leaves', 'is_half_day')) {
                $table->boolean('is_half_day')->default(false)->after('no_of_days');
            }
            if (! Schema::hasColumn('leaves', 'half_day_session')) {
                $table->string('half_day_session', 16)->nullable()->after('is_half_day');
            }
        });

        // Widen no_of_days to hold halves. sqlite is typeless (no-op there);
        // MySQL needs the explicit change. Guard so sqlite tests don't choke.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('leaves', function (Blueprint $table) {
                $table->decimal('no_of_days', 5, 1)->default(0)->change();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('leaves')) {
            return;
        }
        Schema::table('leaves', function (Blueprint $table) {
            foreach (['is_half_day', 'half_day_session'] as $col) {
                if (Schema::hasColumn('leaves', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
```

> `->change()` needs `doctrine/dbal` on some setups. Verify with `composer show doctrine/dbal`; if absent on Laravel 11 it is built-in for `change()` — confirm via `current-tech-stack` before running on MySQL. If `change()` is unavailable, fall back to a raw `DB::statement('ALTER TABLE leaves MODIFY no_of_days DECIMAL(5,1) NOT NULL DEFAULT 0')` guarded to mysql.

- [ ] **Step 4: Update the model**

In `app/Models/HRM/Leave.php`, add to `$fillable`: `'is_half_day'`, `'half_day_session'`. In `$casts`, change `'no_of_days' => 'integer'` to `'no_of_days' => 'decimal:1'`, and add `'is_half_day' => 'boolean'`, `'half_day_session' => 'string'`.

```php
    protected $fillable = [
        'user_id', 'leave_type', 'from_date', 'to_date', 'no_of_days',
        'is_half_day', 'half_day_session',
        'approved_by', 'reason', 'status', 'approval_chain',
        'current_approval_level', 'approved_at', 'rejection_reason',
        'rejected_by', 'submitted_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'leave_type' => 'string',
        'from_date' => 'date',
        'to_date' => 'date',
        'no_of_days' => 'decimal:1',
        'is_half_day' => 'boolean',
        'half_day_session' => 'string',
        'reason' => 'string',
        'status' => 'string',
        'approved_by' => 'integer',
        'approval_chain' => 'array',
        'current_approval_level' => 'integer',
        'approved_at' => 'datetime',
        'rejected_by' => 'integer',
        'submitted_at' => 'datetime',
    ];
```

> `decimal:1` returns a string like `"0.5"`; the test casts with `(float)`. Note this for Task 5 (`getRemainingDays` sums `no_of_days` — keep using numeric coercion).

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=LeaveHalfDayColumnsTest`
Expected: PASS.

- [ ] **Step 6: Run the migration on dev MySQL + commit**

Run: `php artisan migrate`

```bash
git add database/migrations/2026_06_25_000003_add_half_day_to_leaves.php app/Models/HRM/Leave.php tests/Feature/Leave/LeaveHalfDayColumnsTest.php
git commit -m "feat(leave): add is_half_day/half_day_session + decimal no_of_days to leaves"
```

---

## Task 4: `LeaveDayCalculator` (server-side working-day count)

**Files:**
- Create: `app/Services/Leave/LeaveDayCalculator.php`
- Test: `tests/Feature/Leave/LeaveDayCalculatorTest.php`

**Interfaces:**
- Consumes: `App\Services\Attendance\HolidayService::forRange(CarbonInterface, CarbonInterface): Collection`; `App\Services\Attendance\Contracts\ScheduleResolver::resolve(int $userId, CarbonInterface $date): ShiftSchedule` (`->isWorkingDay`).
- Produces: `LeaveDayCalculator::compute(int $userId, CarbonInterface $from, CarbonInterface $to, bool $isHalfDay = false): float` — counts the employee's roster working-days in `[from,to]` excluding holidays; returns `0.5` for a half-day (single-date) leave. Tasks 5 use this.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Leave/LeaveDayCalculatorTest.php
namespace Tests\Feature\Leave;

use App\Models\HRM\Holiday;
use App\Models\User;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Leave\LeaveDayCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveDayCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function bindResolver(array $offDates = []): void
    {
        // Working day unless the date is in $offDates (weekly-off).
        $this->app->bind(ScheduleResolver::class, fn () => new class($offDates) implements ScheduleResolver {
            public function __construct(private array $off) {}
            public function resolve(int $userId, \Carbon\CarbonInterface $date): ShiftSchedule
            {
                $isWorking = ! in_array($date->toDateString(), $this->off, true);
                return $isWorking
                    ? new ShiftSchedule(
                        start: $date->copy()->setTime(9, 0), end: $date->copy()->setTime(17, 0),
                        crossesMidnight: false, graceInMinutes: 0, graceOutMinutes: 0,
                        fullDayMinutes: 480, halfDayMinutes: 240, minPresentMinutes: 0,
                        breakMinutes: 0, isWorkingDay: true, isScheduled: true,
                    )
                    : ShiftSchedule::nonWorking($date);
            }
        });
    }

    /** @test */
    public function it_counts_only_working_days_excluding_weekly_off_and_holidays(): void
    {
        $user = User::factory()->create();
        // Fri 2026-02-13 is the employee's weekly off; 2026-02-12 is a holiday.
        $this->bindResolver(offDates: ['2026-02-13']);
        Holiday::create([
            'title' => 'Test Holiday', 'from_date' => '2026-02-12', 'to_date' => '2026-02-12',
            'is_active' => true, 'recurrence_pattern' => 'none',
        ]);

        $calc = app(LeaveDayCalculator::class);
        // Range Mon 2026-02-09 .. Sun 2026-02-15 (7 days). Exclude Fri-13 (off) + Thu-12 (holiday) = 5.
        $days = $calc->compute($user->id, Carbon::parse('2026-02-09'), Carbon::parse('2026-02-15'), false);

        $this->assertSame(5.0, $days);
    }

    /** @test */
    public function a_half_day_on_a_working_day_is_zero_point_five(): void
    {
        $user = User::factory()->create();
        $this->bindResolver();

        $calc = app(LeaveDayCalculator::class);
        $days = $calc->compute($user->id, Carbon::parse('2026-02-10'), Carbon::parse('2026-02-10'), true);

        $this->assertSame(0.5, $days);
    }

    /** @test */
    public function a_half_day_on_a_non_working_day_is_zero(): void
    {
        $user = User::factory()->create();
        $this->bindResolver(offDates: ['2026-02-10']);

        $calc = app(LeaveDayCalculator::class);
        $days = $calc->compute($user->id, Carbon::parse('2026-02-10'), Carbon::parse('2026-02-10'), true);

        $this->assertSame(0.0, $days);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LeaveDayCalculatorTest`
Expected: FAIL — `LeaveDayCalculator` does not exist.

- [ ] **Step 3: Write the calculator**

```php
<?php
// app/Services/Leave/LeaveDayCalculator.php
namespace App\Services\Leave;

use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\HolidayService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Server-authoritative leave day-count.
 *
 * Counts the employee's roster WORKING days in [from,to] (excludes their
 * weekly-off via ScheduleResolver and holidays via HolidayService). A half-day
 * leave (single date) is 0.5 when that date is a working day, else 0.
 *
 * The client-supplied daysCount is no longer trusted — this is the source.
 */
class LeaveDayCalculator
{
    public function __construct(
        private ScheduleResolver $scheduleResolver,
        private HolidayService $holidayService,
    ) {}

    public function compute(int $userId, CarbonInterface $from, CarbonInterface $to, bool $isHalfDay = false): float
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        if ($end->lessThan($start)) {
            return 0.0;
        }

        $holidays = $this->holidayService->forRange($start, $end->copy()->endOfDay());

        $workingDays = 0;
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if ($this->isHoliday($date, $holidays)) {
                continue;
            }
            if (! $this->scheduleResolver->resolve($userId, $date)->isWorkingDay) {
                continue;
            }
            $workingDays++;
        }

        if ($isHalfDay) {
            // Half-day only applies to a single-date request; 0.5 if that day counts, else 0.
            return $workingDays > 0 ? 0.5 : 0.0;
        }

        return (float) $workingDays;
    }

    private function isHoliday(CarbonInterface $date, $holidays): bool
    {
        return $holidays->contains(fn ($h) => $date->between(
            Carbon::parse($h->from_date)->startOfDay(),
            Carbon::parse($h->to_date)->endOfDay()
        ));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=LeaveDayCalculatorTest`
Expected: PASS (all three).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Leave/LeaveDayCalculator.php tests/Feature/Leave/LeaveDayCalculatorTest.php
git commit -m "feat(leave): add LeaveDayCalculator (roster working-day count, holiday-aware, half-day)"
```

---

## Task 5: Wire server day-count into `LeaveCrudService` (drop client `daysCount`)

**Files:**
- Modify: `app/Services/Leave/LeaveCrudService.php`
- Test: `tests/Feature/Leave/LeaveDayCountServerSideTest.php`

**Interfaces:**
- Consumes: `LeaveDayCalculator::compute(...)` from Task 4; `is_half_day`/`half_day_session` from Task 3.
- Produces: `createLeave`/`updateLeave` write `no_of_days` from the calculator and persist `is_half_day`/`half_day_session`. `$data['daysCount']` is no longer written.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Leave/LeaveDayCountServerSideTest.php
namespace Tests\Feature\Leave;

use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Leave\LeaveCrudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveDayCountServerSideTest extends TestCase
{
    use RefreshDatabase;

    private function bindAllWorkingDays(): void
    {
        $this->app->bind(ScheduleResolver::class, fn () => new class implements ScheduleResolver {
            public function resolve(int $userId, \Carbon\CarbonInterface $date): ShiftSchedule
            {
                return new ShiftSchedule(
                    start: $date->copy()->setTime(9, 0), end: $date->copy()->setTime(17, 0),
                    crossesMidnight: false, graceInMinutes: 0, graceOutMinutes: 0,
                    fullDayMinutes: 480, halfDayMinutes: 240, minPresentMinutes: 0,
                    breakMinutes: 0, isWorkingDay: true, isScheduled: true,
                );
            }
        });
    }

    /** @test */
    public function it_ignores_a_lying_client_days_count_and_computes_server_side(): void
    {
        $this->bindAllWorkingDays();
        $user = User::factory()->create();
        $type = LeaveSetting::create(['type' => 'Casual', 'days' => 100, 'requires_approval' => true]);

        $leave = app(LeaveCrudService::class)->createLeave([
            'user_id' => $user->id,
            'leaveType' => 'Casual',
            'fromDate' => '2026-03-02', // Mon
            'toDate' => '2026-03-04',   // Wed -> 3 working days
            'daysCount' => 999,         // client lie
            'leaveReason' => 'server count wins',
        ]);

        $this->assertSame(3.0, (float) $leave->no_of_days);
    }

    /** @test */
    public function a_half_day_leave_records_zero_point_five(): void
    {
        $this->bindAllWorkingDays();
        $user = User::factory()->create();
        LeaveSetting::create(['type' => 'Casual', 'days' => 100, 'requires_approval' => true]);

        $leave = app(LeaveCrudService::class)->createLeave([
            'user_id' => $user->id,
            'leaveType' => 'Casual',
            'fromDate' => '2026-03-02',
            'toDate' => '2026-03-02',
            'daysCount' => 1,
            'leaveReason' => 'half day am',
            'isHalfDay' => true,
            'halfDaySession' => 'first_half',
        ]);

        $this->assertSame(0.5, (float) $leave->no_of_days);
        $this->assertTrue($leave->is_half_day);
        $this->assertSame('first_half', $leave->half_day_session);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LeaveDayCountServerSideTest`
Expected: FAIL — `no_of_days` is 999 (client value) and half-day fields unset.

- [ ] **Step 3: Inject the calculator + use it**

In `app/Services/Leave/LeaveCrudService.php`, add the dependency and replace the day-count source. Update the constructor:

```php
    protected LeaveApprovalService $approvalService;
    protected LeaveOverlapService $overlapService;
    protected LeaveDayCalculator $dayCalculator;

    public function __construct(
        LeaveApprovalService $approvalService,
        LeaveOverlapService $overlapService,
        LeaveDayCalculator $dayCalculator
    ) {
        $this->approvalService = $approvalService;
        $this->overlapService = $overlapService;
        $this->dayCalculator = $dayCalculator;
    }
```

Add `use App\Services\Leave\LeaveDayCalculator;` (same namespace — no import needed; it's `App\Services\Leave\LeaveDayCalculator`, drop the `use`).

In `createLeave`, after parsing `$fromDate`/`$toDate` and resolving `$leaveSetting`, compute the authoritative count:

```php
            $isHalfDay = (bool) ($data['isHalfDay'] ?? false);
            $halfDaySession = $isHalfDay ? ($data['halfDaySession'] ?? 'first_half') : null;

            $serverDays = $this->dayCalculator->compute(
                (int) $data['user_id'], $fromDate, $toDate, $isHalfDay
            );
```

Replace the balance block's `$requested = (int) $data['daysCount'];` with `$requested = $serverDays;` and compare `if ($requested > $remaining)` (note `$remaining` is int; keep numeric compare). Replace the `Leave::create([...])` `no_of_days`/status with:

```php
            $leave = Leave::create([
                'user_id' => $data['user_id'],
                'leave_type' => $leaveTypeId,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'no_of_days' => $serverDays,
                'is_half_day' => $isHalfDay,
                'half_day_session' => $halfDaySession,
                'reason' => $data['leaveReason'],
                'status' => 'pending',
            ]);
```

Apply the same `$serverDays` / half-day computation in `updateLeave` (replace `$requested = (int) $data['daysCount'];` and the `no_of_days` in `$leave->update([...])`, adding `is_half_day`/`half_day_session`).

> Keep accepting `$data['daysCount']` in the array (the controller still sends it) but never write it. This avoids touching the controller payload shape in this task.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=LeaveDayCountServerSideTest`
Expected: PASS.

- [ ] **Step 5: Run leave suite for regression**

Run: `php artisan test --filter="Leave"`
Expected: PASS (allowed exceptions only). Note `LeaveBalanceTest` — confirm it still passes with server-side counts; if it asserted on a client value, update it to the server-derived count.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Leave/LeaveCrudService.php tests/Feature/Leave/LeaveDayCountServerSideTest.php
git commit -m "feat(leave): compute no_of_days server-side via LeaveDayCalculator; stop trusting client daysCount"
```

---

## Task 6: Engine fraction — `resolve()` + `DayAttendance` + `buildMonthlyDayResults`

**Files:**
- Modify: `app/Services/Attendance/DTO/DayAttendance.php`
- Modify: `app/Services/Attendance/AttendanceStatusService.php`
- Modify: `app/Services/Attendance/AttendanceReportService.php` (`buildMonthlyDayResults` call into `resolve`)
- Test: `tests/Feature/Leave/LeaveFractionEngineTest.php`

**Interfaces:**
- Consumes: leave `is_half_day`/`half_day_session` (Task 3) loaded on `$user->leaves`.
- Produces: `AttendanceStatusService::resolve(... , float $leaveFraction = 0.0, ?string $leaveSession = null)`; `DayAttendance` gains readonly `leave_fraction` (float) + `leave_session` (?string). Reconciliation rules per the design's 2c. Task 7 consumes `result->leave_fraction`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Leave/LeaveFractionEngineTest.php
namespace Tests\Feature\Leave;

use App\Services\Attendance\AttendanceStatusService;
use App\Services\Attendance\DTO\DayAttendance;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class LeaveFractionEngineTest extends TestCase
{
    private function workingShift(string $date): ShiftSchedule
    {
        return new ShiftSchedule(
            start: Carbon::parse("$date 09:00"), end: Carbon::parse("$date 17:00"),
            crossesMidnight: false, graceInMinutes: 0, graceOutMinutes: 0,
            fullDayMinutes: 480, halfDayMinutes: 240, minPresentMinutes: 0,
            breakMinutes: 0, isWorkingDay: true, isScheduled: true,
        );
    }

    /** @test */
    public function full_day_leave_no_punch_is_on_leave_fraction_one(): void
    {
        $r = app(AttendanceStatusService::class)->resolve(
            collect(), $this->workingShift('2026-03-02'),
            isHoliday: false, isOnLeave: false, now: null, policy: null,
            leaveFraction: 1.0, leaveSession: null,
        );
        $this->assertSame(DayAttendance::ON_LEAVE, $r->status);
        $this->assertSame(1.0, $r->leave_fraction);
    }

    /** @test */
    public function half_day_leave_no_punch_carries_half_fraction_and_unworked_flag(): void
    {
        $r = app(AttendanceStatusService::class)->resolve(
            collect(), $this->workingShift('2026-03-02'),
            isHoliday: false, isOnLeave: false, now: null, policy: null,
            leaveFraction: 0.5, leaveSession: 'first_half',
        );
        // Display stays ON_LEAVE; counts split (0.5 leave + 0.5 absent) handled in the report layer.
        $this->assertSame(DayAttendance::ON_LEAVE, $r->status);
        $this->assertSame(0.5, $r->leave_fraction);
        $this->assertContains('half_day_leave_unworked', $r->flags);
    }

    /** @test */
    public function half_day_leave_with_afternoon_punch_is_present_with_half_fraction(): void
    {
        $punches = new Collection([
            (object) ['punchin' => '2026-03-02 13:00:00', 'punchout' => '2026-03-02 17:00:00'],
        ]);

        $r = app(AttendanceStatusService::class)->resolve(
            $punches, $this->workingShift('2026-03-02'),
            isHoliday: false, isOnLeave: false, now: null, policy: null,
            leaveFraction: 0.5, leaveSession: 'first_half',
        );
        $this->assertSame(0.5, $r->leave_fraction);
        $this->assertSame(240, $r->worked_minutes);
        $this->assertNotContains('worked_on_leave', $r->flags); // half-day worked is NOT a conflict
    }

    /** @test */
    public function full_day_leave_with_a_punch_flags_worked_on_leave(): void
    {
        $punches = new Collection([
            (object) ['punchin' => '2026-03-02 09:00:00', 'punchout' => '2026-03-02 17:00:00'],
        ]);

        $r = app(AttendanceStatusService::class)->resolve(
            $punches, $this->workingShift('2026-03-02'),
            isHoliday: false, isOnLeave: false, now: null, policy: null,
            leaveFraction: 1.0, leaveSession: null,
        );
        $this->assertContains('worked_on_leave', $r->flags);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LeaveFractionEngineTest`
Expected: FAIL — `resolve` has no `leaveFraction`/`leaveSession` params; `DayAttendance` has no `leave_fraction`.

- [ ] **Step 3: Extend `DayAttendance`**

In `app/Services/Attendance/DTO/DayAttendance.php`, add two readonly fields to the constructor (after `policy_events`) and to `toArray()`:

```php
        public readonly array $policy_events = [],
        public readonly float $leave_fraction = 0.0,
        public readonly ?string $leave_session = null,
    ) {}

    public function toArray(): array
    {
        return [
            // ...existing keys...
            'policy_events' => $this->policy_events,
            'leave_fraction' => $this->leave_fraction,
            'leave_session' => $this->leave_session,
        ];
    }
```

- [ ] **Step 4: Extend `resolve()` signature + reconciliation**

In `app/Services/Attendance/AttendanceStatusService.php`, add params to `resolve` (after `$policy`):

```php
        ?PolicyProfile $policy = null,
        float $leaveFraction = 0.0,
        ?string $leaveSession = null,
    ): DayAttendance {
        // Derive the effective leave fraction. Legacy callers pass isOnLeave=true
        // with leaveFraction=0.0, which means a full-day leave (1.0).
        $fraction = $leaveFraction > 0 ? $leaveFraction : ($isOnLeave ? 1.0 : 0.0);
        $onLeave = $fraction > 0;
```

Replace the **no-punch** block so it carries the fraction and flags an unworked half-day, and replace its `$isOnLeave` use with `$onLeave`:

```php
        if (! $hasPunches) {
            $status = match (true) {
                $isHoliday => DayAttendance::HOLIDAY,
                ! $shift->isWorkingDay => DayAttendance::WEEKEND,
                $onLeave => DayAttendance::ON_LEAVE,
                default => DayAttendance::ABSENT,
            };

            // A half-day leave with no punch means the worked half was a no-show;
            // the report layer counts 0.5 leave + 0.5 absent. Flag it here.
            if ($status === DayAttendance::ON_LEAVE && $shift->isWorkingDay && $fraction > 0 && $fraction < 1.0) {
                $flags[] = 'half_day_leave_unworked';
            }

            return new DayAttendance(
                status: $status,
                worked_minutes: 0,
                late_minutes: 0,
                early_leave_minutes: 0,
                ot_minutes: 0,
                first_in: null,
                last_out: null,
                is_complete: true,
                flags: $flags,
                leave_fraction: $shift->isWorkingDay ? $fraction : 0.0,
                leave_session: $shift->isWorkingDay ? $leaveSession : null,
            );
        }
```

> Holiday/weekly-off outrank leave: `leave_fraction` is 0 on a rest day (the `$shift->isWorkingDay ? ... : 0.0` guard).

In the **has-punch** path, the existing `worked_on_leave` flag must only fire for a **full-day** leave (a half-day worked is the expected reconciliation, not a conflict). Change:

```php
        // Punch on an approved FULL-day leave working day: surface a conflict for the
        // approver. A half-day leave worked in the other half is expected, not a conflict.
        if ($onLeave && $fraction >= 1.0 && $shift->isWorkingDay) {
            $flags[] = 'worked_on_leave';
        }
```

Finally, add `leave_fraction`/`leave_session` to the **has-punch** return `new DayAttendance(...)` (after `policy_events`):

```php
            policy_events: $policyEvents,
            leave_fraction: $shift->isWorkingDay ? $fraction : 0.0,
            leave_session: $shift->isWorkingDay ? $leaveSession : null,
        );
```

- [ ] **Step 5: Feed the fraction from `buildMonthlyDayResults`**

In `app/Services/Attendance/AttendanceReportService.php` `buildMonthlyDayResults`, after resolving `$leave`, derive the fraction + session and pass them to `resolve`:

```php
            $leaveFraction = 0.0;
            $leaveSession = null;
            if ($leave) {
                $leaveFraction = $leave->is_half_day ? 0.5 : 1.0;
                $leaveSession = $leave->half_day_session;
            }

            $result = $statusEngine->resolve(
                $attendancesForDate,
                $schedule,
                isHoliday: (bool) $holiday,
                isOnLeave: (bool) $leave,
                policy: $policy,
                leaveFraction: $leaveFraction,
                leaveSession: $leaveSession,
            );
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=LeaveFractionEngineTest`
Expected: PASS (all four).

- [ ] **Step 7: Run the full attendance suite (reconciliation guard)**

Run: `php artisan test --filter="Attendance"`
Expected: PASS — existing `AttendanceStatusServiceTest`, `EnginePrecedenceTest`, `MonthlyStatsEngineReconcileTest`, `PerEmployeeSummaryTest` unaffected (full-day leave path is byte-compatible: `isOnLeave=true` ⇒ fraction 1.0). Allowed pre-existing failures only.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Attendance/DTO/DayAttendance.php app/Services/Attendance/AttendanceStatusService.php app/Services/Attendance/AttendanceReportService.php tests/Feature/Leave/LeaveFractionEngineTest.php
git commit -m "feat(attendance): leave fraction (0/0.5/1.0)+session in engine; half-day worked reconciles, full-day worked flags conflict"
```

---

## Task 7: Fractional counting in dashboard + per-employee summary; paid/LWP split; `getLeaveCountsArray` status filter

**Files:**
- Modify: `app/Services/Attendance/AttendanceReportService.php` (`classifyDay` doc only; the two counting loops; `getLeaveCountsArray`; per-employee summary rows)
- Modify: `app/Exports/...` per-employee export (add `paid_leave`/`lwp` columns) — read the export first to match its column array.
- Test: `tests/Feature/Leave/LeaveFractionSummaryTest.php`

**Interfaces:**
- Consumes: `result->leave_fraction`, `flags` containing `half_day_leave_unworked`; `LeaveSetting.is_paid` (Task 2).
- Produces: dashboard `leaves` and per-employee `leave` totals are fractional; per-employee rows gain `paid_leave` + `lwp`; `getLeaveCountsArray` counts approved-only.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Leave/LeaveFractionSummaryTest.php
namespace Tests\Feature\Leave;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveFractionSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function bindAllWorkingDays(): void
    {
        $this->app->bind(ScheduleResolver::class, fn () => new class implements ScheduleResolver {
            public function resolve(int $userId, \Carbon\CarbonInterface $date): ShiftSchedule
            {
                return new ShiftSchedule(
                    start: $date->copy()->setTime(9, 0), end: $date->copy()->setTime(17, 0),
                    crossesMidnight: false, graceInMinutes: 0, graceOutMinutes: 0,
                    fullDayMinutes: 480, halfDayMinutes: 240, minPresentMinutes: 0,
                    breakMinutes: 0, isWorkingDay: true, isScheduled: true,
                );
            }
        });
    }

    /** @test */
    public function per_employee_summary_counts_a_half_day_leave_as_half(): void
    {
        $this->bindAllWorkingDays();
        $user = User::factory()->create(['date_of_joining' => '2020-01-01']);
        $user->assignRole('Employee'); // matches getEmployeeUsersWithAttendanceAndLeaves scope
        $type = LeaveSetting::create(['type' => 'Casual', 'symbol' => 'C', 'days' => 100, 'is_paid' => true]);

        Leave::create([
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-03-02', 'to_date' => '2026-03-02',
            'no_of_days' => 0.5, 'is_half_day' => true, 'half_day_session' => 'first_half',
            'reason' => 'x', 'status' => 'approved',
        ]);

        $summary = app(AttendanceReportService::class)->getPerEmployeeMonthlySummary(2026, 3);
        $row = collect($summary['rows'])->firstWhere('employee_id', $user->employee_id);

        $this->assertEqualsWithDelta(0.5, $row['leave'], 0.001);
        $this->assertEqualsWithDelta(0.5, $row['paid_leave'], 0.001);
        $this->assertEqualsWithDelta(0.0, $row['lwp'], 0.001);
    }
}
```

> Confirm the public method name for the per-employee summary by reading `AttendanceReportService` (it is `getPerEmployeeMonthlySummary` around line 336; adjust the call if the signature differs). Confirm the role-scope helper requires the `Employee` role.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LeaveFractionSummaryTest`
Expected: FAIL — `leave` counts 1 (integer ON_LEAVE), no `paid_leave`/`lwp` keys.

- [ ] **Step 3: Make the per-employee summary fractional + paid/LWP split**

In `app/Services/Attendance/AttendanceReportService.php` per-employee loop (around lines 358–410), replace the leave counting. Add `$leaveType`-aware paid lookup. Build a `id => is_paid` map once before the user loop:

```php
        $paidByType = LeaveSetting::query()->pluck('is_paid', 'id'); // [leave_type_id => bool]
```

(Add `use App\Models\HRM\LeaveSetting;` at top if not present.)

Replace the per-day counting so leave is fractional and split:

```php
            $present = $absent = $leave = $late = $holidaysWorked = $weeklyOffWorked = $workingDays = 0;
            $paidLeave = 0.0; $lwp = 0.0;
            $otMinutes = 0;

            foreach ($dayResults as $ctx) {
                if ($ctx['before_join'] || $ctx['after_termination']) {
                    continue;
                }

                /** @var DayAttendance $result */
                $result = $ctx['result'];
                $effective = $this->classifyDay($ctx);
                $hasPunch = $ctx['attendances']->isNotEmpty();
                $lf = $result->leave_fraction; // 0 / 0.5 / 1.0

                if ($lf > 0) {
                    $leave += $lf;
                    $isPaid = (bool) ($paidByType[$ctx['leave']->leave_type] ?? true);
                    if ($isPaid) { $paidLeave += $lf; } else { $lwp += $lf; }
                }

                if (in_array($effective, $workedStatuses, true)) {
                    // A present day may also carry a 0.5 leave (half-day worked the other half).
                    $present += (1.0 - $lf);
                    $otMinutes += $result->ot_minutes;
                    if ($result->late_minutes > 0) {
                        $late++;
                    }
                } elseif ($effective === DayAttendance::ABSENT) {
                    $absent++;
                } elseif ($effective === DayAttendance::ON_LEAVE) {
                    // Half-day leave with no punch: the worked half is a no-show.
                    if (in_array('half_day_leave_unworked', $result->flags, true)) {
                        $absent += (1.0 - $lf); // 0.5 absent
                    }
                }

                if ($ctx['holiday'] && $hasPunch) {
                    $holidaysWorked++;
                } elseif (! $ctx['holiday'] && ! $ctx['schedule']->isWorkingDay && $hasPunch) {
                    $weeklyOffWorked++;
                }

                if ($ctx['schedule']->isWorkingDay && ! $ctx['holiday']) {
                    $workingDays++;
                }
            }
```

Then add the new columns to the row array:

```php
            $rows[] = [
                'employee_name'        => $user->name,
                'employee_id'          => $user->employee_id,
                'department'           => optional($user->department)->name ?? '—',
                'present'              => round($present, 1),
                'absent'               => round($absent, 1),
                'leave'                => round($leave, 1),
                'paid_leave'           => round($paidLeave, 1),
                'lwp'                  => round($lwp, 1),
                'ot_hours'             => round($otMinutes / 60, 1),
                'late'                 => $late,
                'holidays_worked'      => $holidaysWorked,
                'weekly_off_worked'    => $weeklyOffWorked,
                'working_days'         => $workingDays,
                'attendance_percentage' => ($present + $absent) > 0 ? round($present / ($present + $absent) * 100, 1) : 0.0,
            ];
```

- [ ] **Step 4: Apply the same fractional logic to the dashboard counts**

In `calculateMonthlyStats` (around lines 254–284), mirror the fractional split so the dashboard's `leaves`/`present`/`absent` reconcile. Use `$lf = $result->leave_fraction`:

```php
                $lf = $result->leave_fraction;
                if ($lf > 0) {
                    $leaveDays += $lf;
                }

                if (in_array($effective, $workedStatuses, true)) {
                    $present += (1.0 - $lf);
                    $userPresent += (1.0 - $lf);
                    $workMinutes += $result->worked_minutes;
                    $otMinutes += $result->ot_minutes;
                    if ($result->late_minutes > 0) {
                        $lateArrivals++;
                    }
                } elseif ($effective === DayAttendance::ABSENT) {
                    $absent++;
                } elseif ($effective === DayAttendance::ON_LEAVE) {
                    if (in_array('half_day_leave_unworked', $result->flags, true)) {
                        $absent += (1.0 - $lf);
                    }
                }
```

Cast the final dashboard outputs with `round(..., 1)` instead of `(int)` for `present`/`absent`/`leaves` so halves survive:

```php
            'attendance' => [
                'present' => round($present, 1),
                'absent' => round($absent, 1),
                'leaves' => round($leaveDays, 1),
                'lateArrivals' => (int) $lateArrivals,
                'percentage' => $attendancePercentage,
                'perfectCount' => (int) $perfectCount,
            ],
```

> Verify the React dashboard tolerates a float here (it renders the number); if a strict integer is required by a component, keep `(int) round(...)` but document the rounding. Check `MonthlyStatsEngineReconcileTest` expectations and update them to the fractional values.

- [ ] **Step 5: Add the approved-only status filter to `getLeaveCountsArray`**

In `getLeaveCountsArray` (around line 91), add the status filter so the leave-type tallies match the engine (which counts approved-only):

```php
        $leaveCounts = DB::table('leaves')
            ->join('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id')
            ->select(/* ...unchanged... */)
            ->where('leaves.status', 'approved')
            ->where(function ($query) use ($year, $month) {
                $query->whereYear('leaves.from_date', $year)
                    ->whereMonth('leaves.from_date', $month)
                    ->orWhereYear('leaves.to_date', $year)
                    ->orWhereMonth('leaves.to_date', $month);
            })
            ->groupBy('leaves.user_id', 'leave_settings.type')
            ->get();
```

> Keep the existing `whereYear/whereMonth` grouping logic exactly; only the `->where('leaves.status', 'approved')` is added. (The DATEDIFF/julianday `total_days` SUM is unchanged — it is a raw calendar span used only for the leave-type tally chips, distinct from engine day-counts.)

- [ ] **Step 6: Surface `paid_leave`/`lwp` in the per-employee export**

Read the per-employee summary export class (the one consuming `getPerEmployeeMonthlySummary` rows — likely under `app/Exports/`) and add the two columns to its heading + row mapping, mirroring `leave`. Frontend-only; no engine impact.

- [ ] **Step 7: Run the targeted + full attendance suites**

Run: `php artisan test --filter=LeaveFractionSummaryTest` then `php artisan test --filter="Attendance"`
Expected: PASS. Update `MonthlyStatsEngineReconcileTest`/`PerEmployeeSummaryTest` expectations if they asserted integer leave counts (the reconciliation still holds — values are now fractional).

- [ ] **Step 8: Verify live (reconciliation) + commit**

Verify the admin dashboard + accounts summary at `https://aero-enterprise-suite.test` render and reconcile (run `php artisan config:clear` if a stale-config 500 appears). Then:

```bash
git add app/Services/Attendance/AttendanceReportService.php app/Exports/ tests/Feature/Leave/LeaveFractionSummaryTest.php
git commit -m "feat(attendance): fractional leave counting in dashboard+summary, paid/LWP split, approved-only leave tallies"
```

---

## Task 8: Leave audit logging (immutable trail)

**Files:**
- Create: `database/migrations/2026_06_25_000004_create_leave_audit_logs_table.php`
- Create: `app/Models/HRM/LeaveAuditLog.php`
- Create: `app/Services/Leave/LeaveAuditService.php`
- Modify: `app/Services/Leave/LeaveCrudService.php` (log create/update/status/delete)
- Modify: `app/Services/Leave/LeaveApprovalService.php` (log approve/reject — read first; same pattern)
- Test: `tests/Feature/Leave/LeaveAuditLogTest.php`

**Interfaces:**
- Consumes: nothing new (uses `Auth::id()`, optional `Request`).
- Produces: `LeaveAuditService::record(string $action, ?int $leaveId, ?array $before, ?array $after, ?string $reason, ?Request $request = null): void` writing immutable `leave_audit_logs` rows (`actor_id, leave_id, action, before, after, reason, ip`).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Leave/LeaveAuditLogTest.php
namespace Tests\Feature\Leave;

use App\Models\HRM\LeaveAuditLog;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Leave\LeaveCrudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LeaveAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function bindAllWorkingDays(): void
    {
        $this->app->bind(ScheduleResolver::class, fn () => new class implements ScheduleResolver {
            public function resolve(int $userId, \Carbon\CarbonInterface $date): ShiftSchedule
            {
                return new ShiftSchedule(
                    start: $date->copy()->setTime(9, 0), end: $date->copy()->setTime(17, 0),
                    crossesMidnight: false, graceInMinutes: 0, graceOutMinutes: 0,
                    fullDayMinutes: 480, halfDayMinutes: 240, minPresentMinutes: 0,
                    breakMinutes: 0, isWorkingDay: true, isScheduled: true,
                );
            }
        });
    }

    /** @test */
    public function creating_a_leave_writes_an_immutable_audit_row(): void
    {
        $this->bindAllWorkingDays();
        $actor = User::factory()->create();
        Auth::login($actor);
        LeaveSetting::create(['type' => 'Casual', 'days' => 100, 'requires_approval' => true]);

        $leave = app(LeaveCrudService::class)->createLeave([
            'user_id' => $actor->id, 'leaveType' => 'Casual',
            'fromDate' => '2026-03-02', 'toDate' => '2026-03-02',
            'daysCount' => 1, 'leaveReason' => 'audit me',
        ]);

        $log = LeaveAuditLog::where('leave_id', $leave->id)->where('action', 'create')->first();
        $this->assertNotNull($log);
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertNull($log::UPDATED_AT); // immutable
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LeaveAuditLogTest`
Expected: FAIL — `LeaveAuditLog`/table/service do not exist.

- [ ] **Step 3: Migration**

```php
<?php
// database/migrations/2026_06_25_000004_create_leave_audit_logs_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_audit_logs')) {
            return;
        }

        Schema::create('leave_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('leave_id')->nullable();
            $table->string('action');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('leave_id');
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_audit_logs');
    }
};
```

- [ ] **Step 4: Model (immutable)**

```php
<?php
// app/Models/HRM/LeaveAuditLog.php
namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveAuditLog extends Model
{
    public const UPDATED_AT = null; // immutable: no updates

    protected $fillable = [
        'actor_id', 'leave_id', 'action', 'before', 'after', 'reason', 'ip',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function leave(): BelongsTo
    {
        return $this->belongsTo(Leave::class, 'leave_id');
    }
}
```

- [ ] **Step 5: Service (mirror `AttendanceAuditService`)**

```php
<?php
// app/Services/Leave/LeaveAuditService.php
namespace App\Services\Leave;

use App\Models\HRM\LeaveAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaveAuditService
{
    public function record(
        string $action,
        ?int $leaveId,
        ?array $before,
        ?array $after,
        ?string $reason = null,
        ?Request $request = null
    ): void {
        LeaveAuditLog::create([
            'actor_id' => Auth::id(),
            'leave_id' => $leaveId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => $request?->ip() ?? request()?->ip(),
        ]);
    }
}
```

- [ ] **Step 6: Wire into `LeaveCrudService`**

Inject `LeaveAuditService` into the constructor (add property + param, same as Task 5's calculator injection). Then log at each mutation:

- `createLeave`: after `$leave` is created/auto-approved, before `return`:
  ```php
  $this->audit->record('create', $leave->id, null, $leave->toArray());
  ```
- `updateLeave`: capture `$before = $leave->toArray();` immediately after `findOrFail`, and after `$leave->update([...])`:
  ```php
  $this->audit->record('update', $leave->id, $before, $leave->fresh()->toArray());
  ```
- `updateLeaveStatus`: capture `$before` before the update; after a real change:
  ```php
  $this->audit->record('status_change', $leave->id, $before, $leave->fresh()->toArray(), "status -> {$normalized}");
  ```
- `deleteLeave`: capture `$before = $leave->toArray();` before `$leave->delete();`:
  ```php
  $this->audit->record('delete', $leaveId, $before, null);
  ```

- [ ] **Step 7: Wire into `LeaveApprovalService`**

Read `app/Services/Leave/LeaveApprovalService.php`; in its `approve()` and `reject()` methods, inject `LeaveAuditService` and record `'approve'` / `'reject'` with the before/after leave arrays and the approver's comment/reason. Use the same `record(...)` signature.

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --filter=LeaveAuditLogTest`
Expected: PASS.

- [ ] **Step 9: Run leave suite + migrate dev MySQL + commit**

Run: `php artisan test --filter="Leave"` then `php artisan migrate`

```bash
git add database/migrations/2026_06_25_000004_create_leave_audit_logs_table.php app/Models/HRM/LeaveAuditLog.php app/Services/Leave/LeaveAuditService.php app/Services/Leave/LeaveCrudService.php app/Services/Leave/LeaveApprovalService.php tests/Feature/Leave/LeaveAuditLogTest.php
git commit -m "feat(leave): immutable leave_audit_logs trail on create/update/status/approve/reject/delete"
```

---

## Task 9: Validation hardening + cross-cutting validation-rule integrity sweep

**Files:**
- Modify: `app/Services/Leave/LeaveValidationService.php`
- Sweep (read + fix): `app/Http/Requests/**`, `app/Services/**ValidationService.php` for stale rules (column-type / enum mismatches)
- Test: `tests/Feature/Leave/LeaveValidationHardeningTest.php`

**Interfaces:**
- Consumes: half-day fields (Task 3), canonical statuses (Task 1).
- Produces: leave validation accepts/validates `isHalfDay`/`halfDaySession`, rejects multi-date half-day, uses canonical status enum, no longer treats `daysCount` as authoritative.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Leave/LeaveValidationHardeningTest.php
namespace Tests\Feature\Leave;

use App\Services\Leave\LeaveValidationService;
use Illuminate\Http\Request;
use Tests\TestCase;

class LeaveValidationHardeningTest extends TestCase
{
    /** @test */
    public function half_day_must_be_a_single_date(): void
    {
        $svc = new LeaveValidationService();
        $req = Request::create('/leaves', 'POST', [
            'user_id' => 1, 'leaveType' => 'Casual',
            'fromDate' => '2026-03-02', 'toDate' => '2026-03-04', // multi-date
            'leaveReason' => 'invalid half day',
            'isHalfDay' => true, 'halfDaySession' => 'first_half',
        ]);

        $validator = $svc->validateLeaveRequest($req);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('toDate', $validator->errors()->toArray());
    }

    /** @test */
    public function half_day_session_is_required_when_half_day(): void
    {
        $svc = new LeaveValidationService();
        $req = Request::create('/leaves', 'POST', [
            'user_id' => 1, 'leaveType' => 'Casual',
            'fromDate' => '2026-03-02', 'toDate' => '2026-03-02',
            'leaveReason' => 'missing session', 'isHalfDay' => true,
        ]);

        $validator = $svc->validateLeaveRequest($req);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('halfDaySession', $validator->errors()->toArray());
    }

    /** @test */
    public function canonical_status_only(): void
    {
        $rules = (new LeaveValidationService())->getLeaveValidationRules();
        $this->assertSame('nullable|in:pending,approved,rejected,cancelled', $rules['status']);
    }
}
```

> `leaveType`/`user_id` `exists` rules will not run to DB here because validation short-circuits on the half-day rules first; if the test environment validates `exists` eagerly, wrap the test in `RefreshDatabase` and seed a `Casual` type + user id 1.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LeaveValidationHardeningTest`
Expected: FAIL — no half-day rules; status enum is `new,pending,approved,rejected`.

- [ ] **Step 3: Harden the rules**

In `app/Services/Leave/LeaveValidationService.php`:

```php
    public function getLeaveValidationRules(): array
    {
        return [
            'id' => 'nullable|exists:leaves,id',
            'user_id' => 'required|exists:users,id',
            'leaveType' => 'required|exists:leave_settings,type',
            'fromDate' => 'required|date|before_or_equal:toDate',
            // Single merged toDate rule: range bounds + half-day-must-be-single-date closure.
            'toDate' => [
                'required', 'date', 'after_or_equal:fromDate',
                'before:'.now()->addYear()->format('Y-m-d'),
                function ($attribute, $value, $fail) {
                    if (request()->boolean('isHalfDay') && request('fromDate') !== $value) {
                        $fail('A half-day leave must start and end on the same date.');
                    }
                },
            ],
            // daysCount is accepted for backward-compat but NO LONGER authoritative
            // (LeaveDayCalculator computes no_of_days server-side). Bound it loosely.
            'daysCount' => 'nullable|numeric|min:0.5|max:365',
            'leaveReason' => 'required|string|max:500|min:5',
            'status' => 'nullable|in:pending,approved,rejected,cancelled',
            'isHalfDay' => 'nullable|boolean',
            'halfDaySession' => 'nullable|required_if:isHalfDay,true,1|in:first_half,second_half',
        ];
    }
```

> The closure fails on `toDate` when a half-day spans dates; the test asserts the `toDate` error key. Add matching messages:

```php
            'halfDaySession.required_if' => 'Please choose the morning or afternoon session for a half-day leave.',
            'halfDaySession.in' => 'Half-day session must be first_half or second_half.',
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=LeaveValidationHardeningTest`
Expected: PASS.

- [ ] **Step 5: Cross-cutting validation-rule integrity sweep**

Read these and fix any rule that no longer matches the real column type / canonical enum (mirroring the Phase-1 `employee_id => integer` → `string` fix):
- `app/Http/Requests/Api/V1/*.php` (Leave* requests) — status enums, day-count types.
- Any `*ValidationService.php` referencing leave `status` with legacy values (`new`/`declined`).
- `grep` for `'declined'`, `'New'`, `in:approved,declined` across `app/` and align to canonical.

For each finding, make the minimal correction and note it in the commit body. Do **not** broaden scope beyond rule-integrity (no behavior changes).

- [ ] **Step 6: Full leave + attendance regression**

Run: `php artisan test --filter="Leave"` then `php artisan test --filter="Attendance"`
Expected: PASS (allowed pre-existing failures only; no new failure).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Leave/LeaveValidationService.php app/Http/Requests tests/Feature/Leave/LeaveValidationHardeningTest.php
git commit -m "feat(leave): harden validation (half-day single-date+session, canonical status); sweep stale validation rules"
```

---

## Task 10: Relational integrity & standard wiring (Attendance · Leave · Holiday)

**Files:**
- Create: `database/migrations/2026_06_25_000005_enforce_leave_relational_fks.php`
- Modify: `app/Models/HRM/Leave.php` (confirm relationships), `app/Models/HRM/LeaveAuditLog.php` (already has `leave()`/`actor()` from Task 8)
- Test: `tests/Feature/Leave/LeaveRelationalIntegrityTest.php`

**Interfaces:**
- Produces: enforced FKs `leaves.user_id → users.id` (cascade on delete), `leaves.leave_type → leave_settings.id` (if missing), `leave_audit_logs.leave_id → leaves.id`. Standard Eloquent relationships usable across the three modules. The holiday/leave→attendance temporal overlay stays computed in the engine (no per-row FK) — this task only asserts that contract, it does not add link columns.

> Pre-check (run before writing the migration): `php artisan tinker` →
> `DB::table('leaves')->leftJoin('users','leaves.user_id','=','users.id')->whereNull('users.id')->count()` and `DB::table('leaves')->whereNull('user_id')->count()` MUST both be 0 on dev. (Verified 0/0 at plan time.) If nonzero on prod, clean/backfill those rows first — an FK add will fail otherwise.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Leave/LeaveRelationalIntegrityTest.php
namespace Tests\Feature\Leave;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LeaveRelationalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function leaves_user_id_has_an_enforced_foreign_key(): void
    {
        // sqlite reports FKs via pragma; assert the constraint exists after migration.
        $fks = collect(DB::select('PRAGMA foreign_key_list(leaves)'));
        $this->assertTrue(
            $fks->contains(fn ($fk) => $fk->from === 'user_id' && $fk->table === 'users'),
            'leaves.user_id should reference users.id'
        );
    }

    /** @test */
    public function leave_belongs_to_user_and_setting_via_relationships(): void
    {
        $user = User::factory()->create();
        $type = LeaveSetting::factory()->create();
        $leave = Leave::create([
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-04-01', 'to_date' => '2026-04-01',
            'no_of_days' => 1, 'reason' => 'x', 'status' => 'pending',
        ]);

        $this->assertTrue($leave->user->is($user));
        $this->assertTrue($leave->leaveSetting->is($type));
    }
}
```

> If sqlite foreign-key enforcement is off in the test env, the PRAGMA still lists the declared FK after a `foreignId()->constrained()` migration; the assertion checks declaration, not enforcement.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LeaveRelationalIntegrityTest`
Expected: FAIL — no FK on `leaves.user_id`.

- [ ] **Step 3: Write the FK migration (orphan-guarded, idempotent)**

```php
<?php
// database/migrations/2026_06_25_000005_enforce_leave_relational_fks.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leaves') || ! Schema::hasColumn('leaves', 'user_id')) {
            return;
        }

        // Safety: never attempt an FK add over orphan/null rows (would fail on MySQL).
        $orphans = DB::table('leaves')
            ->leftJoin('users', 'leaves.user_id', '=', 'users.id')
            ->whereNull('users.id')
            ->count();
        if ($orphans > 0) {
            throw new RuntimeException("Refusing to add FK: {$orphans} orphan leaves.user_id rows. Backfill first.");
        }

        $driver = Schema::getConnection()->getDriverName();

        // Detect an existing FK to stay idempotent (MySQL information_schema).
        $hasUserFk = false;
        if ($driver === 'mysql') {
            $hasUserFk = ! empty(DB::select(
                'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_NAME = "leaves" AND COLUMN_NAME = "user_id"
                 AND REFERENCED_TABLE_NAME = "users" AND TABLE_SCHEMA = DATABASE() LIMIT 1'
            ));
        }

        if (! $hasUserFk) {
            Schema::table('leaves', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leaves')) {
            try {
                Schema::table('leaves', function (Blueprint $table) {
                    $table->dropForeign(['user_id']);
                });
            } catch (\Throwable $e) {
                // FK may not exist; ignore.
            }
        }
    }
};
```

> sqlite recreates the table to add the FK (Laravel handles this) — the PRAGMA assertion in the test will then see it. Confirm via `current-tech-stack` that Laravel 11 + the installed sqlite driver supports `foreign()->constrained` add on an existing table; if not, the test asserts declaration which the migration provides.

- [ ] **Step 4: Confirm the relationships exist (no code change expected)**

`app/Models/HRM/Leave.php` already declares `user()`, `employee()`, `leaveSetting()`, `approver()`, `rejectedBy()`. Confirm no raw `DB::table('leaves')->join('leave_settings'...)` remains where a relationship + `with()` is the standard call — the engine loader (`getEmployeeUsersWithAttendanceAndLeaves`) intentionally joins for a `leave_type` alias and stays (performance); leave it. No edit needed unless a finding surfaces.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=LeaveRelationalIntegrityTest`
Expected: PASS.

- [ ] **Step 6: Run on dev MySQL + full regression + commit**

Run: `php artisan migrate` then `php artisan test --filter="Leave"` and `php artisan test --filter="Attendance"`

```bash
git add database/migrations/2026_06_25_000005_enforce_leave_relational_fks.php tests/Feature/Leave/LeaveRelationalIntegrityTest.php
git commit -m "feat(leave): enforce leaves.user_id FK (cascade) + assert standard relational wiring"
```

> The holiday/leave → attendance overlay is intentionally NOT given link columns (it stays a computed calendar-layer join in the engine — see the design's "Relational model & wiring (standard)"). If the owner wants a *physical* attendance↔leave link, that is a separate scope decision (see plan header note) — not in this task.

---

## Final verification (whole-phase)

- [ ] **Run the complete suite**

Run: `php artisan test`
Expected: only the two allowed pre-existing failures; everything else green. Reconciliation invariant holds (summary == dashboard == grid) including fractional leave.

- [ ] **Frontend build (only if the export/UI changed)**

Run: `npx vite build` (NOT `npm run build`). Verify the leave summary export shows `Paid Leave` / `LWP` columns at `https://aero-enterprise-suite.test`.

- [ ] **Dev MySQL migration confirmation**

Run: `php artisan migrate:status` — confirm the four Phase-2 migrations are `Ran` on `dbedc_guardian`. Note them for prod in `ATTENDANCE_HARDENING_ROADMAP.md` (alongside the pending Phase-1 `enforce_unique_employee_id`).

- [ ] **Update the ledger + roadmap**

Append a `.superpowers/sdd/progress-phase2.md` ledger and mark B3 done in `docs/attendance/ATTENDANCE_HARDENING_ROADMAP.md`.

- [ ] **Whole-branch review** — invoke `superpowers:requesting-code-review` for a final spec + quality pass before declaring Phase 2 complete.

---

## Prod deploy / data carry-forward (Phase 2)

Run on prod after merge (in order):
1. `2026_06_25_000001_normalize_leaves_status` — converts legacy `Approved/New/Pending`.
2. `2026_06_25_000002_add_paid_to_leave_settings` — review `is_paid` per leave type with accounts (default true; mark LWP types false).
3. `2026_06_25_000003_add_half_day_to_leaves` — verify `no_of_days` decimal change on the prod engine (confirm `change()`/`MODIFY` path).
4. `2026_06_25_000004_create_leave_audit_logs_table`.
5. `2026_06_25_000005_enforce_leave_relational_fks` — **pre-check prod for orphan/null `leaves.user_id` and backfill before running** (the migration throws if any exist).

(Still pending from Phase 1: `2026_06_23_000001_enforce_unique_employee_id` + the employee_id placeholder→real-number replacements + the Weekend→roster bootstrap.)
