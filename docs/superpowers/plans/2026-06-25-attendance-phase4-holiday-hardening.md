# Attendance Phase 4 — Holiday Module Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Bring the holiday **module** to the same standard as attendance/leave — immutable audit trail, soft-delete (recoverable), copy-last-year bulk import (removes per-year manual re-entry drudgery), and validation robustness — without changing the already-correct `HolidayService` integration.

**Architecture:** Mirror the `leave_audit_logs`/`LeaveAuditService` pattern (Phase 2) for holidays. Add `SoftDeletes` to `Holiday`. Add a `HolidayImportService::copyYear(from, to)` that clones a source year's holidays into a target year (Gregorian dates shifted; HR then edits lunar dates). Tighten `HolidayController` validation. `HolidayService::forRange` already filters `is_active` + expands `annual_fixed` — soft-deleted rows must drop out automatically (they will, via the global scope).

**Tech Stack:** Laravel 11, PHP 8.2, MySQL/sqlite, PHPUnit class-style + RefreshDatabase, Inertia v2/React 18.

## Global Constraints

- **Source of truth:** design doc `docs/superpowers/specs/2026-06-23-attendance-leaves-holidays-10of10-design.md` (Phase 4 + "Relational model & wiring", "Cross-cutting — audit consistency").
- **Locked:** recurrence stays `{none, annual_fixed}` — `annual_fixed` auto-expands; lunar/Eid entered manually per year (NO auto-Hijri). `is_active` honored everywhere.
- **Audit shape** is standardized across HR: `actor_id / action / before / after / reason / ip`, `UPDATED_AT = null`.
- **DB:** new migrations run on dev MySQL `dbedc_guardian` (`php artisan migrate`); tests sqlite `:memory:`.
- **Dev/test:** never `npm run build`; frontend via `npx vite build` only. Verify live at `https://aero-enterprise-suite.test`.
- **No new test failure** beyond the two allowed pre-existing (`MobileSyncApiTest > sync push applies leave apply mutation`; `NavigationRoutesTest > organization directory`).
- **Reconciliation:** `HolidayService::forRange` must keep returning identical results for active, non-deleted holidays.
- Invoke `engineering-standards` + `current-tech-stack` before each code task.

---

## File Structure

**New:**
- `database/migrations/2026_06_25_000010_create_holiday_audit_logs_table.php`
- `database/migrations/2026_06_25_000011_add_soft_deletes_to_holidays.php`
- `app/Models/HRM/HolidayAuditLog.php`
- `app/Services/Holiday/HolidayAuditService.php`
- `app/Services/Holiday/HolidayImportService.php`
- `tests/Feature/Holiday/HolidayAuditLogTest.php`
- `tests/Feature/Holiday/HolidaySoftDeleteTest.php`
- `tests/Feature/Holiday/HolidayCopyYearTest.php`
- `tests/Feature/Holiday/HolidayValidationTest.php`

**Modified:**
- `app/Models/HRM/Holiday.php` — `use SoftDeletes`.
- `app/Http/Controllers/HolidayController.php` — audit hooks, validation tightening, copy-year endpoint, restore endpoint.
- `routes/web.php` — `holidays-copy-year` + `holidays-restore` routes (permission-gated).
- `resources/js/Pages/Holidays.jsx` / `resources/js/Forms/HolidayForm.jsx` — copy-year action + recurrence/active display (frontend task, last).

---

## Task 1: Holiday audit log (immutable trail)

**Files:**
- Create: `database/migrations/2026_06_25_000010_create_holiday_audit_logs_table.php`, `app/Models/HRM/HolidayAuditLog.php`, `app/Services/Holiday/HolidayAuditService.php`
- Modify: `app/Http/Controllers/HolidayController.php` (record on create/update/delete)
- Test: `tests/Feature/Holiday/HolidayAuditLogTest.php`

**Interfaces:**
- Produces: `HolidayAuditService::record(string $action, ?int $holidayId, ?array $before, ?array $after, ?string $reason = null, ?\Illuminate\Http\Request $request = null): void` writing immutable `holiday_audit_logs` (`actor_id, holiday_id, action, before, after, reason, ip`).

- [ ] **Step 1: Failing test** — `tests/Feature/Holiday/HolidayAuditLogTest.php`:

```php
<?php
namespace Tests\Feature\Holiday;

use App\Models\HRM\Holiday;
use App\Models\HRM\HolidayAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class HolidayAuditLogTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function recording_writes_an_immutable_row(): void
    {
        $actor = User::factory()->create();
        Auth::login($actor);
        $holiday = Holiday::create([
            'title' => 'Victory Day', 'from_date' => '2026-12-16', 'to_date' => '2026-12-16',
            'type' => 'national', 'is_active' => true,
        ]);

        app(\App\Services\Holiday\HolidayAuditService::class)
            ->record('create', $holiday->id, null, $holiday->toArray());

        $log = HolidayAuditLog::where('holiday_id', $holiday->id)->where('action', 'create')->first();
        $this->assertNotNull($log);
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertNull(HolidayAuditLog::UPDATED_AT);
    }
}
```

- [ ] **Step 2: Run — expect FAIL** (`HolidayAuditLog`/table/service missing). `php artisan test --filter=HolidayAuditLogTest`

- [ ] **Step 3: Migration** `2026_06_25_000010_create_holiday_audit_logs_table.php`:

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('holiday_audit_logs')) {
            return;
        }
        Schema::create('holiday_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // Not an FK: an immutable audit trail must survive deletion of its holiday. Do NOT add constrained().
            $table->unsignedBigInteger('holiday_id')->nullable();
            $table->string('action');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('holiday_id');
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_audit_logs');
    }
};
```

- [ ] **Step 4: Model** `app/Models/HRM/HolidayAuditLog.php`:

```php
<?php
namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HolidayAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'holiday_id', 'action', 'before', 'after', 'reason', 'ip'];

    protected $casts = ['before' => 'array', 'after' => 'array'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class, 'holiday_id');
    }
}
```

- [ ] **Step 5: Service** `app/Services/Holiday/HolidayAuditService.php` (mirror `LeaveAuditService`):

```php
<?php
namespace App\Services\Holiday;

use App\Models\HRM\HolidayAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HolidayAuditService
{
    public function record(
        string $action,
        ?int $holidayId,
        ?array $before,
        ?array $after,
        ?string $reason = null,
        ?Request $request = null
    ): void {
        HolidayAuditLog::create([
            'actor_id' => Auth::id(),
            'holiday_id' => $holidayId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => $request?->ip() ?? request()?->ip(),
        ]);
    }
}
```

- [ ] **Step 6: Wire `HolidayController`** — inject `HolidayAuditService` (constructor) and record:
  - In `create`: on the create branch `$this->audit->record('create', $holiday->id, null, $holiday->fresh()->toArray());`; on the update branch capture `$before = $holiday->toArray();` before `$holiday->update($data);` then `$this->audit->record('update', $holiday->id, $before, $holiday->fresh()->toArray());`.
  - In `delete`: capture `$before = $holiday->toArray();` before `$holiday->delete();` then `$this->audit->record('delete', $holiday->id, $before, null);`.
  Add the constructor (the controller currently has none):

```php
    public function __construct(private \App\Services\Holiday\HolidayAuditService $audit) {}
```

- [ ] **Step 7: Run — expect PASS.** `php artisan test --filter=HolidayAuditLogTest`
- [ ] **Step 8: Migrate dev MySQL + commit.** `php artisan migrate`

```bash
git add database/migrations/2026_06_25_000010_create_holiday_audit_logs_table.php app/Models/HRM/HolidayAuditLog.php app/Services/Holiday/HolidayAuditService.php app/Http/Controllers/HolidayController.php tests/Feature/Holiday/HolidayAuditLogTest.php
git commit -m "feat(holiday): immutable holiday_audit_logs trail on create/update/delete"
```

---

## Task 2: Soft-delete holidays (recoverable)

**Files:**
- Create: `database/migrations/2026_06_25_000011_add_soft_deletes_to_holidays.php`, `tests/Feature/Holiday/HolidaySoftDeleteTest.php`
- Modify: `app/Models/HRM/Holiday.php` (`use SoftDeletes`), `app/Http/Controllers/HolidayController.php` (restore endpoint), `routes/web.php`

**Interfaces:**
- Produces: `holidays.deleted_at`; `Holiday` uses `SoftDeletes`; a `restore` controller action. `HolidayService::forRange` (queries `Holiday::query()`) automatically excludes soft-deleted rows via the global scope.

- [ ] **Step 1: Failing test** — `tests/Feature/Holiday/HolidaySoftDeleteTest.php`:

```php
<?php
namespace Tests\Feature\Holiday;

use App\Models\HRM\Holiday;
use App\Services\Attendance\HolidayService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidaySoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_soft_deleted_holiday_is_recoverable_and_drops_out_of_forRange(): void
    {
        $h = Holiday::create([
            'title' => 'Test', 'from_date' => '2026-05-01', 'to_date' => '2026-05-01',
            'type' => 'public', 'is_active' => true, 'recurrence_pattern' => 'none',
        ]);

        $h->delete(); // soft

        $this->assertSoftDeleted('holidays', ['id' => $h->id]);
        $occ = app(HolidayService::class)->forRange(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'));
        $this->assertCount(0, $occ);

        $h->restore();
        $occ = app(HolidayService::class)->forRange(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'));
        $this->assertCount(1, $occ);
    }
}
```

- [ ] **Step 2: Run — expect FAIL** (no `deleted_at`; `assertSoftDeleted` fails / forRange still returns it). `php artisan test --filter=HolidaySoftDeleteTest`

- [ ] **Step 3: Migration** `2026_06_25_000011_add_soft_deletes_to_holidays.php`:

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('holidays') && ! Schema::hasColumn('holidays', 'deleted_at')) {
            Schema::table('holidays', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('holidays') && Schema::hasColumn('holidays', 'deleted_at')) {
            Schema::table('holidays', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
```

- [ ] **Step 4: Model** — add `use Illuminate\Database\Eloquent\SoftDeletes;` and `use SoftDeletes;` in the trait list of `app/Models/HRM/Holiday.php`.

- [ ] **Step 5: Restore endpoint** — `HolidayController::restore(Request $request)`:

```php
    public function restore(Request $request): JsonResponse
    {
        $request->validate(['id' => 'required|integer']);
        $holiday = Holiday::onlyTrashed()->findOrFail($request->input('id'));
        $holiday->restore();
        $this->audit->record('restore', $holiday->id, null, $holiday->fresh()->toArray());

        return response()->json(['message' => 'Holiday restored successfully']);
    }
```

Add the route in `routes/web.php` inside the `permission:holidays.create` group:
```php
Route::post('/holidays-restore', [HolidayController::class, 'restore'])->name('holidays-restore');
```

- [ ] **Step 6: Run — expect PASS.** `php artisan test --filter=HolidaySoftDeleteTest` then `php artisan test --filter="Holiday"` (HolidayServiceTest/HolidayIntegrationTest must still pass — soft-delete is transparent to active queries).
- [ ] **Step 7: Migrate dev MySQL + commit.**

```bash
git add database/migrations/2026_06_25_000011_add_soft_deletes_to_holidays.php app/Models/HRM/Holiday.php app/Http/Controllers/HolidayController.php routes/web.php tests/Feature/Holiday/HolidaySoftDeleteTest.php
git commit -m "feat(holiday): soft-delete holidays (recoverable) + restore endpoint"
```

---

## Task 3: Copy-last-year bulk import

**Files:**
- Create: `app/Services/Holiday/HolidayImportService.php`, `tests/Feature/Holiday/HolidayCopyYearTest.php`
- Modify: `app/Http/Controllers/HolidayController.php` (`copyYear` endpoint), `routes/web.php`

**Interfaces:**
- Produces: `HolidayImportService::copyYear(int $fromYear, int $toYear): int` — clones every active holiday whose `from_date` year == `$fromYear` into `$toYear` (shifting both dates by the year delta, Feb-29 clamped), skipping any whose shifted range would overlap an existing `$toYear` holiday; returns the count created. Recurrence/type/title/description preserved; `created_by`/`updated_by` set to the actor.

- [ ] **Step 1: Failing test** — `tests/Feature/Holiday/HolidayCopyYearTest.php`:

```php
<?php
namespace Tests\Feature\Holiday;

use App\Models\HRM\Holiday;
use App\Services\Holiday\HolidayImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidayCopyYearTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_clones_a_source_years_holidays_into_the_target_year(): void
    {
        Holiday::create(['title' => 'New Year', 'from_date' => '2025-01-01', 'to_date' => '2025-01-01', 'type' => 'national', 'is_active' => true]);
        Holiday::create(['title' => 'May Day', 'from_date' => '2025-05-01', 'to_date' => '2025-05-02', 'type' => 'public', 'is_active' => true]);

        $created = app(HolidayImportService::class)->copyYear(2025, 2026);

        $this->assertSame(2, $created);
        $this->assertDatabaseHas('holidays', ['title' => 'New Year', 'from_date' => '2026-01-01']);
        $this->assertDatabaseHas('holidays', ['title' => 'May Day', 'from_date' => '2026-05-01', 'to_date' => '2026-05-02']);
    }

    /** @test */
    public function it_skips_a_clone_that_would_overlap_an_existing_target_holiday(): void
    {
        Holiday::create(['title' => 'New Year', 'from_date' => '2025-01-01', 'to_date' => '2025-01-01', 'type' => 'national', 'is_active' => true]);
        Holiday::create(['title' => 'Already there', 'from_date' => '2026-01-01', 'to_date' => '2026-01-01', 'type' => 'company', 'is_active' => true]);

        $created = app(HolidayImportService::class)->copyYear(2025, 2026);

        $this->assertSame(0, $created);
        $this->assertSame(1, Holiday::whereYear('from_date', 2026)->count());
    }
}
```

- [ ] **Step 2: Run — expect FAIL** (service missing). `php artisan test --filter=HolidayCopyYearTest`

- [ ] **Step 3: Service** `app/Services/Holiday/HolidayImportService.php`:

```php
<?php
namespace App\Services\Holiday;

use App\Models\HRM\Holiday;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class HolidayImportService
{
    /**
     * Clone $fromYear's active holidays into $toYear (shift both dates by the year
     * delta; Feb-29 clamped). Skips a clone whose shifted range overlaps an existing
     * $toYear holiday. Returns the number created.
     */
    public function copyYear(int $fromYear, int $toYear): int
    {
        if ($fromYear === $toYear) {
            return 0;
        }

        $source = Holiday::query()
            ->where('is_active', true)
            ->whereYear('from_date', $fromYear)
            ->orderBy('from_date')
            ->get();

        $created = 0;

        DB::transaction(function () use ($source, $fromYear, $toYear, &$created) {
            foreach ($source as $h) {
                $newFrom = $this->shiftYear(Carbon::parse($h->from_date), $toYear);
                $newTo = $this->shiftYear(Carbon::parse($h->to_date), $toYear);
                if ($newTo->lessThan($newFrom)) {
                    $newTo = $newFrom->copy();
                }

                $overlaps = Holiday::query()
                    ->where(function ($q) use ($newFrom, $newTo) {
                        $q->whereBetween('from_date', [$newFrom->toDateString(), $newTo->toDateString()])
                            ->orWhereBetween('to_date', [$newFrom->toDateString(), $newTo->toDateString()])
                            ->orWhere(function ($q) use ($newFrom, $newTo) {
                                $q->where('from_date', '<=', $newFrom->toDateString())
                                    ->where('to_date', '>=', $newTo->toDateString());
                            });
                    })
                    ->exists();

                if ($overlaps) {
                    continue;
                }

                Holiday::create([
                    'title' => $h->title,
                    'description' => $h->description,
                    'from_date' => $newFrom->toDateString(),
                    'to_date' => $newTo->toDateString(),
                    'type' => $h->type,
                    'is_recurring' => $h->is_recurring,
                    'recurrence_pattern' => $h->recurrence_pattern,
                    'is_active' => true,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
                $created++;
            }
        });

        return $created;
    }

    private function shiftYear(Carbon $date, int $targetYear): Carbon
    {
        $daysInMonth = Carbon::create($targetYear, $date->month, 1)->daysInMonth;
        $day = min($date->day, $daysInMonth); // Feb-29 -> Feb-28 in a non-leap year

        return Carbon::create($targetYear, $date->month, $day)->startOfDay();
    }
}
```

- [ ] **Step 4: Run — expect PASS.** `php artisan test --filter=HolidayCopyYearTest`

- [ ] **Step 5: Controller endpoint + route.** `HolidayController::copyYear(Request $request)`:

```php
    public function copyYear(Request $request, \App\Services\Holiday\HolidayImportService $import): JsonResponse
    {
        $validated = $request->validate([
            'fromYear' => 'required|integer|between:2000,2100',
            'toYear' => 'required|integer|between:2000,2100|different:fromYear',
        ]);

        $created = $import->copyYear((int) $validated['fromYear'], (int) $validated['toYear']);
        $this->audit->record('copy_year', null, null,
            ['from' => $validated['fromYear'], 'to' => $validated['toYear'], 'created' => $created]);

        return response()->json([
            'message' => "{$created} holiday(s) copied from {$validated['fromYear']} to {$validated['toYear']}.",
            'created' => $created,
            'holidays' => Holiday::active()->orderBy('from_date')->get(),
        ]);
    }
```

Route in `routes/web.php` (inside `permission:holidays.create`):
```php
Route::post('/holidays-copy-year', [HolidayController::class, 'copyYear'])->name('holidays-copy-year');
```

- [ ] **Step 6: Run holiday suite + commit.** `php artisan test --filter="Holiday"`

```bash
git add app/Services/Holiday/HolidayImportService.php app/Http/Controllers/HolidayController.php routes/web.php tests/Feature/Holiday/HolidayCopyYearTest.php
git commit -m "feat(holiday): copy-last-year bulk import (clones a year's holidays, overlap-safe, Feb-29 clamped)"
```

---

## Task 4: Validation robustness

**Files:**
- Modify: `app/Http/Controllers/HolidayController.php` (`create` validation)
- Test: `tests/Feature/Holiday/HolidayValidationTest.php`

**Interfaces:** consumes nothing new; tightens `recurrence_pattern` to `{none, annual_fixed}` and keeps `is_recurring`/`recurrence_pattern` coherent.

- [ ] **Step 1: Failing test** — `tests/Feature/Holiday/HolidayValidationTest.php`:

```php
<?php
namespace Tests\Feature\Holiday;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HolidayValidationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function recurrence_pattern_rejects_unsupported_values(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('holidays.create');
        $user->givePermissionTo('holidays.create');
        $this->actingAs($user);

        $res = $this->postJson(route('holiday-add'), [
            'title' => 'Bad', 'fromDate' => '2026-07-01', 'toDate' => '2026-07-01',
            'type' => 'public', 'is_recurring' => true, 'recurrence_pattern' => 'nth_weekday',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['recurrence_pattern']);
    }
}
```

> Confirm the create route name is `holiday-add` (it is — `routes/web.php:401`); the `holidays-add` alias also maps to `create`.

- [ ] **Step 2: Run — expect FAIL** (current rule `in:annual_fixed` allows null but `nth_weekday` already fails... verify: actual gap is `recurrence_pattern` should also accept `none` and the controller should not silently coerce). `php artisan test --filter=HolidayValidationTest`

> If the test already passes (because `in:annual_fixed` rejects `nth_weekday`), strengthen it to assert that `recurrence_pattern => 'none'` is ACCEPTED and `is_recurring=false` forces `recurrence_pattern=null` — adjust the rule below accordingly and keep a meaningful failing assertion first.

- [ ] **Step 3: Tighten validation** in `HolidayController::create`:

```php
            'recurrence_pattern' => 'nullable|in:none,annual_fixed',
```

And make storage coherent (already partly there) — `recurrence_pattern` is `annual_fixed` only when `is_recurring`, else `null`:

```php
                'is_recurring' => $request->boolean('is_recurring', false),
                'recurrence_pattern' => $request->boolean('is_recurring', false) ? 'annual_fixed' : null,
```

- [ ] **Step 4: Run — expect PASS.** `php artisan test --filter=HolidayValidationTest` then `php artisan test --filter="Holiday"`
- [ ] **Step 5: Commit.**

```bash
git add app/Http/Controllers/HolidayController.php tests/Feature/Holiday/HolidayValidationTest.php
git commit -m "feat(holiday): constrain recurrence_pattern to {none,annual_fixed}; keep is_recurring coherent"
```

---

## Task 5: UI — copy-year action + recurrence/active display (frontend)

**Files:**
- Modify: `resources/js/Pages/Holidays.jsx`, `resources/js/Forms/HolidayForm.jsx`, `resources/js/Tables/HolidayTable.jsx`

**Interfaces:** consumes the `holidays-copy-year` + `holidays-restore` endpoints (Tasks 2–3) and the recurrence/active fields.

- [ ] **Step 1:** Add a "Copy last year" control to `Holidays.jsx` — a small form (from-year, to-year) POSTing to `route('holidays-copy-year')`, showing the returned `message`, refreshing the list. Follow the existing Inertia/axios pattern used by `HolidayForm.jsx`.
- [ ] **Step 2:** In `HolidayTable.jsx`/`HolidayForm.jsx`, surface `is_recurring`/`recurrence_pattern` (a "Recurring (annual)" badge) and `is_active` state; for trashed rows (if shown) a Restore button POSTing to `route('holidays-restore')`. Match the table's existing column/badge styling — do not restyle the page.
- [ ] **Step 3: Build + verify.** `npx vite build`; verify at `https://aero-enterprise-suite.test/holidays` (run `php artisan config:clear` if a stale-config 500 appears): add a holiday, copy a year, see the badge.
- [ ] **Step 4: Commit** (source only; one consolidated `public/build` commit at the very end of the phase).

```bash
git add resources/js/Pages/Holidays.jsx resources/js/Forms/HolidayForm.jsx resources/js/Tables/HolidayTable.jsx
git commit -m "feat(holiday): UI — copy-last-year action + recurrence/active display + restore"
```

---

## Final verification

- [ ] `php artisan test --filter="Holiday"` green; full `php artisan test` adds no new failure beyond the two allowed.
- [ ] `php artisan migrate:status` — both Phase-4 migrations `Ran` on dev MySQL; note for prod.
- [ ] One consolidated `public/build` commit if frontend changed.
- [ ] Whole-branch review (`superpowers:requesting-code-review`) before declaring Phase 4 complete.
- [ ] Update `docs/attendance/ATTENDANCE_HARDENING_ROADMAP.md` (Phase 4 done) + design doc status.

## Prod deploy (Phase 4)
Run on prod after merge: `2026_06_25_000010_create_holiday_audit_logs_table`, `2026_06_25_000011_add_soft_deletes_to_holidays`. No data backfill needed.
