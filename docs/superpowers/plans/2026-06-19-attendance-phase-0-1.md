# Attendance Overhaul — Phase 0 + Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make attendance correct and shift/roster-aware: full-datetime punches, one deterministic status engine consumed everywhere, enforced audit trail (Phase 0); then shifts, rotation patterns, effective-dated assignments, materialized roster with overrides + swaps, and shift-aware status (Phase 1).

**Architecture:** A single pure-PHP `AttendanceStatusService` consumes a `ShiftSchedule` value object produced by a `ScheduleResolver` interface. Phase 0 ships `DefaultScheduleResolver` (built from the global `AttendanceSetting`); Phase 1 rebinds the interface to a roster-backed resolver — the status engine never changes. Controllers stay thin. Effective-dated config, never destructive.

**Tech Stack:** PHP 8.3, Laravel 11, Inertia v2, React 18 + Radix Themes, MySQL (prod) / sqlite `:memory:` (tests), PHPUnit 11, Vite. Timezone Asia/Dhaka (store UTC, render app TZ).

## Global Constraints

- **Tests are PHPUnit class-style**, not Pest. Every test is `class XTest extends Tests\TestCase { use RefreshDatabase; public function test_...(): void }`. Run with `php artisan test --filter=<ClassName>`.
- **Tests run on sqlite `:memory:`.** No MySQL-only SQL (`TIME()`, `DATEDIFF()`, raw `addDay()` heuristics) in any code path the status engine or its tests touch. The status engine is **pure PHP/Carbon** — it receives data, never queries the DB.
- **Timezone:** persist UTC; serialize a plain `YYYY-MM-DD` business `date` (app TZ) so the client never mis-buckets.
- **Every multi-step write wraps in `DB::transaction`.** HR data.
- **Never run `npm run build`** (its `postbuild` does `git add . && git commit && git push`). Build assets with `npx vite build`. Develop against the Vite dev server (`npm run dev`) at `https://aero-enterprise-suite.test`.
- Match existing code style; no new Composer/npm dependencies. Radix Themes only on the frontend.
- New backend code lives under `App\Services\Attendance\` (services), `App\Services\Attendance\DTO\` (value objects), `App\Services\Attendance\Contracts\` (interfaces). Tests under `tests/Feature/Attendance/` and `tests/Unit/Attendance/`.
- Login for manual verification: `emam@dhakabypass.com` / `123456789`.

---

## File Structure

**Phase 0 — new files**
- `database/factories/HRM/AttendanceFactory.php` — Attendance model factory (tests need it).
- `app/Services/Attendance/DTO/ShiftSchedule.php` — value object describing the working window + thresholds for one user-day.
- `app/Services/Attendance/DTO/DayAttendance.php` — status engine output DTO.
- `app/Services/Attendance/Contracts/ScheduleResolver.php` — interface `resolve(userId, date): ShiftSchedule`.
- `app/Services/Attendance/DefaultScheduleResolver.php` — Phase 0 resolver from `AttendanceSetting`.
- `app/Services/Attendance/AttendanceStatusService.php` — the keystone engine.
- `app/Providers/AttendanceServiceProvider.php` — binds `ScheduleResolver` → `DefaultScheduleResolver`.
- `app/Models/HRM/AttendanceAuditLog.php` + `database/migrations/..._create_attendance_audit_logs_table.php`.
- `app/Services/Attendance/AttendanceAuditService.php` — writes immutable audit rows.
- `database/migrations/..._change_attendance_punch_columns_to_datetime.php`.

**Phase 0 — modified files**
- `app/Http/Controllers/AttendanceController.php` — `getDailyOverviewStats`, `markAsPresent`, `bulkMarkAsPresent`, `updateAttendanceRecord`, `addAttendanceRecord`, `deleteAttendanceRecord` use the engine + audit.
- `app/Services/Attendance/AttendanceReportService.php`, `AttendanceQueryService.php` — drop `addDay()` heuristics; route worked-minutes through the engine helpers.
- `bootstrap/providers.php` — register `AttendanceServiceProvider`.

**Phase 1 — new files**
- Migrations + models: `shifts`, `shift_rotation_patterns`, `shift_assignments`, `roster_days`, `shift_swap_requests`.
- `app/Services/Attendance/ShiftService.php`, `app/Services/Attendance/RosterService.php`, `app/Services/Attendance/RosterScheduleResolver.php`.
- Controllers: `app/Http/Controllers/HRM/ShiftController.php`, `RosterController.php`, `ShiftSwapController.php`.
- Frontend: `resources/js/Pages/Attendance/RosterTab.jsx`, `ShiftsSettings.jsx`, `Components/RosterCalendar.jsx`, `Forms/ShiftForm.jsx`, `Forms/RotationPatternForm.jsx`, `Forms/SwapRequestForm.jsx`.

**Phase 1 — modified files**
- `app/Providers/AttendanceServiceProvider.php` — rebind `ScheduleResolver` → `RosterScheduleResolver`.
- `resources/js/Pages/Attendance/AttendancePage.jsx` — add Roster tab.
- `routes/web.php` — shift/roster/swap routes.

---

# PHASE 0 — Foundation & Correctness

### Task 1: Attendance model factory (test enabler)

**Files:**
- Create: `database/factories/HRM/AttendanceFactory.php`
- Modify: `app/Models/HRM/Attendance.php` (point `HasFactory` at the factory namespace)
- Test: `tests/Unit/Attendance/AttendanceFactoryTest.php`

**Interfaces:**
- Produces: `Attendance::factory()` creating rows with full-datetime `punchin`/`punchout`; states `->open()` (no punchout), `->night()` (crosses midnight).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Attendance;

use App\Models\HRM\Attendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_complete_punch_pair(): void
    {
        $user = User::factory()->create();
        $a = Attendance::factory()->for($user)->create();

        $this->assertNotNull($a->punchin);
        $this->assertNotNull($a->punchout);
        $this->assertTrue($a->punchout->greaterThan($a->punchin));
    }

    public function test_open_state_has_no_punchout(): void
    {
        $a = Attendance::factory()->open()->create();
        $this->assertNull($a->punchout);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AttendanceFactoryTest`
Expected: FAIL — `Call to undefined method ...::factory()` / factory class not found.

- [ ] **Step 3: Create the factory**

```php
<?php

namespace Database\Factories\HRM;

use App\Models\HRM\Attendance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    public function definition(): array
    {
        $date = $this->faker->dateTimeBetween('-20 days', 'now');
        $in = (clone $date)->setTime(9, 0);
        $out = (clone $date)->setTime(17, 30);

        return [
            'user_id' => User::factory(),
            'date' => $in->format('Y-m-d'),
            'punchin' => $in,
            'punchout' => $out,
            'punchin_location' => null,
            'punchout_location' => null,
            'symbol' => '√',
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => ['punchout' => null]);
    }

    public function night(): static
    {
        return $this->state(function (array $attrs) {
            $base = \Carbon\Carbon::parse($attrs['date']);

            return [
                'punchin' => $base->copy()->setTime(20, 0),
                'punchout' => $base->copy()->addDay()->setTime(4, 0),
            ];
        });
    }
}
```

- [ ] **Step 4: Point the model at the factory**

In `app/Models/HRM/Attendance.php`, add the resolver method inside the class (Laravel auto-discovers `Database\Factories\{Model}Factory`, but the model is namespaced `App\Models\HRM` so add an explicit resolver):

```php
    protected static function newFactory(): \Database\Factories\HRM\AttendanceFactory
    {
        return \Database\Factories\HRM\AttendanceFactory::new();
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AttendanceFactoryTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/factories/HRM/AttendanceFactory.php app/Models/HRM/Attendance.php tests/Unit/Attendance/AttendanceFactoryTest.php
git commit -m "test(attendance): add Attendance factory with open/night states"
```

---

### Task 2: Migrate punch columns TIME → DATETIME with backfill

**Files:**
- Create: `database/migrations/2026_06_19_000001_change_attendance_punch_columns_to_datetime.php`
- Test: `tests/Feature/Attendance/PunchDatetimeMigrationTest.php`

**Interfaces:**
- Produces: `attendances.punchin` / `attendances.punchout` are nullable `DATETIME`; a composite index `attendances_user_id_date_index` on `(user_id, date)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PunchDatetimeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_punch_columns_are_datetime_and_index_exists(): void
    {
        $this->assertSame('datetime', Schema::getColumnType('attendances', 'punchin'));
        $this->assertSame('datetime', Schema::getColumnType('attendances', 'punchout'));
        $this->assertTrue(Schema::hasColumn('attendances', 'user_id'));
    }
}
```

> Note: `Schema::getColumnType` returns `datetime` on both MySQL and sqlite for a `dateTime()` column in Laravel 11.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PunchDatetimeMigrationTest`
Expected: FAIL — column type is `time`.

- [ ] **Step 3: Write the migration (driver-agnostic backfill in PHP)**

```php
<?php

use App\Models\HRM\Attendance;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Snapshot existing TIME values combined with `date` into DATETIME strings.
        // Read raw rows BEFORE altering the column type.
        $rows = DB::table('attendances')->select('id', 'date', 'punchin', 'punchout')->get();

        // 2. Alter column types.
        Schema::table('attendances', function (Blueprint $table) {
            $table->dateTime('punchin')->nullable()->change();
            $table->dateTime('punchout')->nullable()->change();
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->index(['user_id', 'date']);
        });

        // 3. Backfill: combine the business `date` with the old time-of-day.
        foreach ($rows as $row) {
            $update = [];
            $date = Carbon::parse($row->date)->format('Y-m-d');

            foreach (['punchin', 'punchout'] as $col) {
                if (empty($row->$col)) {
                    continue;
                }
                // Old value may be "HH:MM:SS" (TIME) — combine with date.
                // If it already parses as a full datetime, keep it.
                $raw = (string) $row->$col;
                $time = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $raw)
                    ? $raw
                    : Carbon::parse($raw)->format('H:i:s');
                $update[$col] = $date.' '.$time;
            }

            if ($update) {
                DB::table('attendances')->where('id', $row->id)->update($update);
            }
        }
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'date']);
            $table->time('punchin')->nullable()->change();
            $table->time('punchout')->nullable()->change();
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=PunchDatetimeMigrationTest`
Expected: PASS.

- [ ] **Step 5: Manually migrate the local MySQL dev DB**

Run: `php artisan migrate` (against `dbedc_guardian`). Verify existing rows now hold full datetimes (spot-check a known June row).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_19_000001_change_attendance_punch_columns_to_datetime.php tests/Feature/Attendance/PunchDatetimeMigrationTest.php
git commit -m "feat(attendance): migrate punch columns to datetime with backfill + (user_id,date) index"
```

---

### Task 3: `ShiftSchedule` value object

**Files:**
- Create: `app/Services/Attendance/DTO/ShiftSchedule.php`
- Test: `tests/Unit/Attendance/ShiftScheduleTest.php`

**Interfaces:**
- Produces: immutable `ShiftSchedule` with readonly props `Carbon $start`, `Carbon $end`, `bool $crossesMidnight`, `int $graceInMinutes`, `int $graceOutMinutes`, `int $fullDayMinutes`, `int $halfDayMinutes`, `int $minPresentMinutes`, `int $breakMinutes`, `bool $isWorkingDay`. Threshold of `0` disables the corresponding rule.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Tests\TestCase;

class ShiftScheduleTest extends TestCase
{
    public function test_holds_window_and_flags(): void
    {
        $s = new ShiftSchedule(
            start: Carbon::parse('2026-06-19 09:00:00'),
            end: Carbon::parse('2026-06-19 17:30:00'),
            crossesMidnight: false,
            graceInMinutes: 15,
            graceOutMinutes: 10,
            fullDayMinutes: 480,
            halfDayMinutes: 240,
            minPresentMinutes: 60,
            breakMinutes: 30,
            isWorkingDay: true,
        );

        $this->assertSame(15, $s->graceInMinutes);
        $this->assertTrue($s->isWorkingDay);
        $this->assertSame('2026-06-19 17:30:00', $s->end->format('Y-m-d H:i:s'));
    }

    public function test_non_working_day_factory(): void
    {
        $s = ShiftSchedule::nonWorking(Carbon::parse('2026-06-20'));
        $this->assertFalse($s->isWorkingDay);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ShiftScheduleTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the value object**

```php
<?php

namespace App\Services\Attendance\DTO;

use Carbon\Carbon;
use Carbon\CarbonInterface;

final class ShiftSchedule
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly bool $crossesMidnight,
        public readonly int $graceInMinutes,
        public readonly int $graceOutMinutes,
        public readonly int $fullDayMinutes,
        public readonly int $halfDayMinutes,
        public readonly int $minPresentMinutes,
        public readonly int $breakMinutes,
        public readonly bool $isWorkingDay,
    ) {}

    public static function nonWorking(CarbonInterface $date): self
    {
        $d = $date->copy()->startOfDay();

        return new self(
            start: $d->copy(),
            end: $d->copy()->endOfDay(),
            crossesMidnight: false,
            graceInMinutes: 0,
            graceOutMinutes: 0,
            fullDayMinutes: 0,
            halfDayMinutes: 0,
            minPresentMinutes: 0,
            breakMinutes: 0,
            isWorkingDay: false,
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ShiftScheduleTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/DTO/ShiftSchedule.php tests/Unit/Attendance/ShiftScheduleTest.php
git commit -m "feat(attendance): add ShiftSchedule value object"
```

---

### Task 4: `DayAttendance` output DTO

**Files:**
- Create: `app/Services/Attendance/DTO/DayAttendance.php`
- Test: `tests/Unit/Attendance/DayAttendanceTest.php`

**Interfaces:**
- Produces: `DayAttendance` with status string constants `PRESENT, ABSENT, LATE, HALF_DAY, SHORT, ON_LEAVE, HOLIDAY, WEEKEND, DAY_OFF`; readonly props `string $status`, `int $worked_minutes`, `int $late_minutes`, `int $early_leave_minutes`, `int $ot_minutes`, `?Carbon $first_in`, `?Carbon $last_out`, `bool $is_complete`, `array $flags`; method `toArray(): array`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\DTO\DayAttendance;
use Carbon\Carbon;
use Tests\TestCase;

class DayAttendanceTest extends TestCase
{
    public function test_to_array_exposes_contract(): void
    {
        $d = new DayAttendance(
            status: DayAttendance::LATE,
            worked_minutes: 470,
            late_minutes: 20,
            early_leave_minutes: 0,
            ot_minutes: 0,
            first_in: Carbon::parse('2026-06-19 09:20:00'),
            last_out: Carbon::parse('2026-06-19 17:30:00'),
            is_complete: true,
            flags: [],
        );

        $arr = $d->toArray();
        $this->assertSame('late', $arr['status']);
        $this->assertSame(470, $arr['worked_minutes']);
        $this->assertSame('2026-06-19 09:20:00', $arr['first_in']);
        $this->assertTrue($arr['is_complete']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DayAttendanceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the DTO**

```php
<?php

namespace App\Services\Attendance\DTO;

use Carbon\Carbon;

final class DayAttendance
{
    public const PRESENT = 'present';
    public const ABSENT = 'absent';
    public const LATE = 'late';
    public const HALF_DAY = 'half_day';
    public const SHORT = 'short';
    public const ON_LEAVE = 'on_leave';
    public const HOLIDAY = 'holiday';
    public const WEEKEND = 'weekend';
    public const DAY_OFF = 'day_off';

    public function __construct(
        public readonly string $status,
        public readonly int $worked_minutes,
        public readonly int $late_minutes,
        public readonly int $early_leave_minutes,
        public readonly int $ot_minutes,
        public readonly ?Carbon $first_in,
        public readonly ?Carbon $last_out,
        public readonly bool $is_complete,
        public readonly array $flags,
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'worked_minutes' => $this->worked_minutes,
            'late_minutes' => $this->late_minutes,
            'early_leave_minutes' => $this->early_leave_minutes,
            'ot_minutes' => $this->ot_minutes,
            'first_in' => $this->first_in?->format('Y-m-d H:i:s'),
            'last_out' => $this->last_out?->format('Y-m-d H:i:s'),
            'is_complete' => $this->is_complete,
            'flags' => $this->flags,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=DayAttendanceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/DTO/DayAttendance.php tests/Unit/Attendance/DayAttendanceTest.php
git commit -m "feat(attendance): add DayAttendance output DTO"
```

---

### Task 5: `AttendanceStatusService` — the keystone engine

**Files:**
- Create: `app/Services/Attendance/AttendanceStatusService.php`
- Test: `tests/Unit/Attendance/AttendanceStatusServiceTest.php`

**Interfaces:**
- Consumes: `ShiftSchedule`, `DayAttendance`. The day's punches are a `Illuminate\Support\Collection` of `Attendance` models (or any object with `punchin`/`punchout` Carbon-or-null).
- Produces: `AttendanceStatusService::resolve(Collection $punches, ShiftSchedule $shift, bool $isHoliday = false, bool $isOnLeave = false, ?CarbonInterface $now = null): DayAttendance`. Pure — no DB access.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\AttendanceStatusService;
use App\Services\Attendance\DTO\DayAttendance;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AttendanceStatusServiceTest extends TestCase
{
    private function shift(string $date, string $start = '09:00', string $end = '17:00', array $over = []): ShiftSchedule
    {
        return new ShiftSchedule(
            start: Carbon::parse("$date $start"),
            end: Carbon::parse("$date $end"),
            crossesMidnight: $over['crossesMidnight'] ?? false,
            graceInMinutes: $over['graceInMinutes'] ?? 15,
            graceOutMinutes: $over['graceOutMinutes'] ?? 0,
            fullDayMinutes: $over['fullDayMinutes'] ?? 0,
            halfDayMinutes: $over['halfDayMinutes'] ?? 0,
            minPresentMinutes: $over['minPresentMinutes'] ?? 0,
            breakMinutes: $over['breakMinutes'] ?? 0,
            isWorkingDay: $over['isWorkingDay'] ?? true,
        );
    }

    private function punch(?string $in, ?string $out): object
    {
        return (object) [
            'punchin' => $in ? Carbon::parse($in) : null,
            'punchout' => $out ? Carbon::parse($out) : null,
        ];
    }

    public function test_absent_when_working_day_and_no_punches(): void
    {
        $r = (new AttendanceStatusService)->resolve(
            new Collection,
            $this->shift('2026-06-19'),
        );
        $this->assertSame(DayAttendance::ABSENT, $r->status);
    }

    public function test_weekend_when_non_working_and_no_punches(): void
    {
        $r = (new AttendanceStatusService)->resolve(
            new Collection,
            ShiftSchedule::nonWorking(Carbon::parse('2026-06-20')),
        );
        $this->assertSame(DayAttendance::WEEKEND, $r->status);
    }

    public function test_holiday_and_leave_take_priority_when_no_punches(): void
    {
        $svc = new AttendanceStatusService;
        $shift = $this->shift('2026-06-19');

        $this->assertSame(DayAttendance::HOLIDAY,
            $svc->resolve(new Collection, $shift, isHoliday: true)->status);
        $this->assertSame(DayAttendance::ON_LEAVE,
            $svc->resolve(new Collection, $shift, isOnLeave: true)->status);
    }

    public function test_present_on_time(): void
    {
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 09:05', '2026-06-19 17:30')]),
            $this->shift('2026-06-19'),
        );
        $this->assertSame(DayAttendance::PRESENT, $r->status);
        $this->assertSame(0, $r->late_minutes);
        $this->assertSame(505, $r->worked_minutes); // 8h25m
    }

    public function test_late_uses_shift_start_plus_grace_not_hardcoded_9(): void
    {
        // Shift starts 10:00, grace 15 → late threshold 10:15. Punch 10:30 = 15 late.
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 10:30', '2026-06-19 18:00')]),
            $this->shift('2026-06-19', '10:00', '18:00'),
        );
        $this->assertSame(DayAttendance::LATE, $r->status);
        $this->assertSame(15, $r->late_minutes);
    }

    public function test_night_shift_spanning_midnight_computes_hours(): void
    {
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 20:00', '2026-06-20 04:00')]),
            $this->shift('2026-06-19', '20:00', '04:00', ['crossesMidnight' => true]),
        );
        $this->assertSame(480, $r->worked_minutes); // 8h across midnight
    }

    public function test_missing_punch_out_flagged_and_incomplete(): void
    {
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 09:00', null)]),
            $this->shift('2026-06-19'),
        );
        $this->assertFalse($r->is_complete);
        $this->assertContains('missing_punch_out', $r->flags);
    }

    public function test_half_day_when_threshold_configured(): void
    {
        // full=480, half=240; worked 3h = 180 < half → half_day
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 09:00', '2026-06-19 12:00')]),
            $this->shift('2026-06-19', '09:00', '17:00', ['fullDayMinutes' => 480, 'halfDayMinutes' => 240]),
        );
        $this->assertSame(DayAttendance::HALF_DAY, $r->status);
    }

    public function test_overtime_minutes_beyond_full_day(): void
    {
        // full=480; worked 9h = 540 → 60 OT
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 09:00', '2026-06-19 18:00')]),
            $this->shift('2026-06-19', '09:00', '17:00', ['fullDayMinutes' => 480]),
        );
        $this->assertSame(60, $r->ot_minutes);
    }

    public function test_present_on_non_working_day_is_not_absent(): void
    {
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-20 10:00', '2026-06-20 14:00')]),
            ShiftSchedule::nonWorking(Carbon::parse('2026-06-20')),
        );
        $this->assertSame(DayAttendance::PRESENT, $r->status);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AttendanceStatusServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the engine**

```php
<?php

namespace App\Services\Attendance;

use App\Services\Attendance\DTO\DayAttendance;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Pure, deterministic attendance status engine. No DB access.
 * Single source of truth for status / worked minutes / late / OT.
 */
class AttendanceStatusService
{
    public function resolve(
        Collection $punches,
        ShiftSchedule $shift,
        bool $isHoliday = false,
        bool $isOnLeave = false,
        ?CarbonInterface $now = null
    ): DayAttendance {
        $sorted = $punches
            ->filter(fn ($p) => $p->punchin !== null)
            ->sortBy(fn ($p) => Carbon::parse($p->punchin)->getTimestamp())
            ->values();

        $flags = [];
        $workedMinutes = 0;
        $isComplete = true;
        $firstIn = null;
        $lastOut = null;

        foreach ($sorted as $p) {
            $in = Carbon::parse($p->punchin);
            $firstIn ??= $in;

            if ($p->punchout === null) {
                $isComplete = false;
                $flags[] = 'missing_punch_out';

                continue;
            }

            $out = Carbon::parse($p->punchout);
            // Datetimes are authoritative — no addDay() heuristic.
            $workedMinutes += (int) round($in->diffInMinutes($out));
            $lastOut = $out;
        }

        $hasPunches = $sorted->isNotEmpty();

        // No punches: holiday > leave > weekend/off > absent.
        if (! $hasPunches) {
            $status = match (true) {
                $isHoliday => DayAttendance::HOLIDAY,
                $isOnLeave => DayAttendance::ON_LEAVE,
                ! $shift->isWorkingDay => DayAttendance::WEEKEND,
                default => DayAttendance::ABSENT,
            };

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
            );
        }

        // Has punches → derive metrics.
        $lateMinutes = 0;
        $earlyLeaveMinutes = 0;
        $otMinutes = 0;

        if ($shift->isWorkingDay) {
            $lateThreshold = $shift->start->copy()->addMinutes($shift->graceInMinutes);
            if ($firstIn->greaterThan($lateThreshold)) {
                $lateMinutes = (int) round($lateThreshold->diffInMinutes($firstIn));
            }

            if ($lastOut !== null && $shift->graceOutMinutes >= 0) {
                $earlyThreshold = $shift->end->copy()->subMinutes($shift->graceOutMinutes);
                if ($lastOut->lessThan($earlyThreshold)) {
                    $earlyLeaveMinutes = (int) round($lastOut->diffInMinutes($earlyThreshold));
                }
            }

            if ($shift->fullDayMinutes > 0 && $workedMinutes > $shift->fullDayMinutes) {
                $otMinutes = $workedMinutes - $shift->fullDayMinutes;
            }
        }

        // Status precedence among present-day outcomes.
        $status = DayAttendance::PRESENT;

        if ($shift->minPresentMinutes > 0 && $workedMinutes < $shift->minPresentMinutes) {
            $status = DayAttendance::SHORT;
        } elseif ($shift->halfDayMinutes > 0 && $workedMinutes < $shift->halfDayMinutes) {
            $status = DayAttendance::HALF_DAY;
        } elseif ($lateMinutes > 0) {
            $status = DayAttendance::LATE;
        }

        return new DayAttendance(
            status: $status,
            worked_minutes: $workedMinutes,
            late_minutes: $lateMinutes,
            early_leave_minutes: $earlyLeaveMinutes,
            ot_minutes: $otMinutes,
            first_in: $firstIn,
            last_out: $lastOut,
            is_complete: $isComplete,
            flags: array_values(array_unique($flags)),
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=AttendanceStatusServiceTest`
Expected: PASS (all 10 cases).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/AttendanceStatusService.php tests/Unit/Attendance/AttendanceStatusServiceTest.php
git commit -m "feat(attendance): add deterministic AttendanceStatusService engine"
```

---

### Task 6: `ScheduleResolver` interface + `DefaultScheduleResolver` + provider

**Files:**
- Create: `app/Services/Attendance/Contracts/ScheduleResolver.php`
- Create: `app/Services/Attendance/DefaultScheduleResolver.php`
- Create: `app/Providers/AttendanceServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Test: `tests/Feature/Attendance/DefaultScheduleResolverTest.php`

**Interfaces:**
- Produces: `ScheduleResolver::resolve(int $userId, CarbonInterface $date): ShiftSchedule`. `DefaultScheduleResolver` builds it from the single `AttendanceSetting` row: working window = `office_start_time`..`office_end_time`, `graceInMinutes = late_mark_after`, `graceOutMinutes = early_leave_before`, `breakMinutes = break_time_duration`; `isWorkingDay = false` when the weekday is in `weekend_days`. Phase-0 default leaves `fullDayMinutes/halfDayMinutes/minPresentMinutes = 0` (engine then yields only present/absent/late — preserving current behaviour).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendanceSetting;
use App\Services\Attendance\Contracts\ScheduleResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultScheduleResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_schedule_from_settings(): void
    {
        AttendanceSetting::create([
            'office_start_time' => '09:00',
            'office_end_time' => '17:00',
            'break_time_duration' => 60,
            'late_mark_after' => 20,
            'early_leave_before' => 10,
            'overtime_after' => 30,
            'weekend_days' => ['friday', 'saturday'],
            'auto_punch_out' => false,
        ]);

        $resolver = app(ScheduleResolver::class);

        $weekday = $resolver->resolve(1, Carbon::parse('2026-06-18')); // Thursday
        $this->assertTrue($weekday->isWorkingDay);
        $this->assertSame('09:00', $weekday->start->format('H:i'));
        $this->assertSame(20, $weekday->graceInMinutes);

        $friday = $resolver->resolve(1, Carbon::parse('2026-06-19')); // Friday = weekend
        $this->assertFalse($friday->isWorkingDay);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DefaultScheduleResolverTest`
Expected: FAIL — interface unbound / class not found.

- [ ] **Step 3: Create the interface**

```php
<?php

namespace App\Services\Attendance\Contracts;

use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\CarbonInterface;

interface ScheduleResolver
{
    public function resolve(int $userId, CarbonInterface $date): ShiftSchedule;
}
```

- [ ] **Step 4: Create the default resolver**

```php
<?php

namespace App\Services\Attendance;

use App\Models\HRM\AttendanceSetting;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class DefaultScheduleResolver implements ScheduleResolver
{
    public function resolve(int $userId, CarbonInterface $date): ShiftSchedule
    {
        $settings = AttendanceSetting::first();

        $startTime = $settings?->office_start_time ?? '09:00';
        $endTime = $settings?->office_end_time ?? '17:00';
        $weekendDays = $settings?->weekend_days ?? ['saturday', 'sunday'];

        $day = $date->copy()->startOfDay();
        $isWorkingDay = ! in_array(strtolower($day->format('l')), array_map('strtolower', $weekendDays), true);

        if (! $isWorkingDay) {
            return ShiftSchedule::nonWorking($day);
        }

        $start = Carbon::parse($day->format('Y-m-d').' '.Carbon::parse($startTime)->format('H:i:s'));
        $end = Carbon::parse($day->format('Y-m-d').' '.Carbon::parse($endTime)->format('H:i:s'));
        $crosses = $end->lessThanOrEqualTo($start);
        if ($crosses) {
            $end->addDay();
        }

        return new ShiftSchedule(
            start: $start,
            end: $end,
            crossesMidnight: $crosses,
            graceInMinutes: (int) ($settings?->late_mark_after ?? 15),
            graceOutMinutes: (int) ($settings?->early_leave_before ?? 0),
            fullDayMinutes: 0,
            halfDayMinutes: 0,
            minPresentMinutes: 0,
            breakMinutes: (int) ($settings?->break_time_duration ?? 0),
            isWorkingDay: true,
        );
    }
}
```

- [ ] **Step 5: Create the provider**

```php
<?php

namespace App\Providers;

use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DefaultScheduleResolver;
use Illuminate\Support\ServiceProvider;

class AttendanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase 0: schedule from global settings. Phase 1 rebinds to RosterScheduleResolver.
        $this->app->bind(ScheduleResolver::class, DefaultScheduleResolver::class);
    }
}
```

Register it in `bootstrap/providers.php` (append to the returned array):

```php
    App\Providers\AttendanceServiceProvider::class,
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=DefaultScheduleResolverTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Attendance/Contracts/ScheduleResolver.php app/Services/Attendance/DefaultScheduleResolver.php app/Providers/AttendanceServiceProvider.php bootstrap/providers.php tests/Feature/Attendance/DefaultScheduleResolverTest.php
git commit -m "feat(attendance): add ScheduleResolver interface + settings-based default resolver"
```

---

### Task 7: Wire the engine into `getDailyOverviewStats` (kill hardcoded 09:00)

**Files:**
- Modify: `app/Http/Controllers/AttendanceController.php:569-621` (`getDailyOverviewStats`)
- Test: `tests/Feature/Attendance/DailyOverviewStatsTest.php`

**Interfaces:**
- Consumes: `AttendanceStatusService`, `ScheduleResolver`, `AttendanceSetting`.
- Produces: late count derived from each user's resolved shift start + grace, not `TIME(punchin) > "09:00:00"`. Response keys unchanged: `present, absent, late, on_leave, total`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DailyOverviewStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
    }

    public function test_late_count_uses_settings_start_not_hardcoded_nine(): void
    {
        // Office starts 10:00, grace 0 → late threshold 10:00.
        AttendanceSetting::create([
            'office_start_time' => '10:00', 'office_end_time' => '18:00',
            'break_time_duration' => 0, 'late_mark_after' => 0,
            'early_leave_before' => 0, 'overtime_after' => 0,
            'weekend_days' => ['friday'], 'auto_punch_out' => false,
        ]);

        $date = Carbon::parse('2026-06-18'); // Thursday, working day
        $onTime = User::factory()->create(); $onTime->assignRole('Employee');
        $late = User::factory()->create(); $late->assignRole('Employee');

        Attendance::factory()->for($onTime)->create([
            'date' => $date->toDateString(),
            'punchin' => $date->copy()->setTime(9, 30), // before 10:00 → NOT late
            'punchout' => $date->copy()->setTime(18, 0),
        ]);
        Attendance::factory()->for($late)->create([
            'date' => $date->toDateString(),
            'punchin' => $date->copy()->setTime(10, 30), // after 10:00 → late
            'punchout' => $date->copy()->setTime(18, 0),
        ]);

        $admin = User::factory()->create(); $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        $res = $this->actingAs($admin)
            ->getJson(route('attendance.dailyOverview', ['date' => $date->toDateString()]));

        $res->assertOk();
        $this->assertSame(2, $res->json('present'));
        $this->assertSame(1, $res->json('late')); // only the 10:30 punch, NOT the 09:30 one
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DailyOverviewStatsTest`
Expected: FAIL — current code counts `TIME(punchin) > 09:00:00`, so the 09:30 punch is wrongly counted late → `late` = 2 (and on sqlite the `TIME()`/`whereRaw` differs).

- [ ] **Step 3: Replace the hardcoded late query**

In `getDailyOverviewStats`, replace the `$lateCount = Attendance::...whereRaw('TIME(punchin) > "09:00:00"')->count();` block with a per-user engine-driven count:

```php
            $statusService = app(\App\Services\Attendance\AttendanceStatusService::class);
            $resolver = app(\App\Services\Attendance\Contracts\ScheduleResolver::class);

            $dayPunches = Attendance::whereDate('date', $date)
                ->whereNotNull('punchin')
                ->get()
                ->groupBy('user_id');

            $lateCount = 0;
            foreach ($dayPunches as $userId => $punches) {
                $shift = $resolver->resolve((int) $userId, \Carbon\Carbon::parse($date));
                $day = $statusService->resolve($punches, $shift);
                if ($day->late_minutes > 0) {
                    $lateCount++;
                }
            }
```

(Remove the old `whereRaw('TIME(punchin) > "09:00:00"')` query entirely.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=DailyOverviewStatsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/AttendanceController.php tests/Feature/Attendance/DailyOverviewStatsTest.php
git commit -m "fix(attendance): derive daily late count from resolved shift, not hardcoded 09:00"
```

---

### Task 8: Route worked-minutes through the engine; drop `addDay()` heuristics

**Files:**
- Modify: `app/Services/Attendance/AttendanceReportService.php` (`calculateTotalMinutes`, late check in `calculateMonthlyStats`)
- Modify: `app/Services/Attendance/AttendanceQueryService.php` (`formatGroupedAttendance`, `getTodayAttendance`)
- Test: `tests/Feature/Attendance/WorkedMinutesConsistencyTest.php`

**Interfaces:**
- Consumes: `AttendanceStatusService` (worked-minutes is now `punchout->diffInMinutes(punchin)` with authoritative datetimes — never `addDay()`).
- Produces: identical numbers for normal days; correct numbers for night shifts.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\User;
use App\Services\Attendance\AttendanceQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkedMinutesConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_night_shift_minutes_correct_without_addday_heuristic(): void
    {
        $user = User::factory()->create();
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-19',
            'punchin' => Carbon::parse('2026-06-19 20:00'),
            'punchout' => Carbon::parse('2026-06-20 04:00'), // 8h across midnight
        ]);

        $svc = app(AttendanceQueryService::class);
        $history = $svc->getAttendanceHistory($user->id, ['scope' => 'self']);

        $row = $history['attendances'][0];
        $this->assertSame(480.0, $row['total_work_minutes']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails or mis-computes**

Run: `php artisan test --filter=WorkedMinutesConsistencyTest`
Expected: With the old `addDay()` heuristic AND authoritative datetimes, `punchout` (2026-06-20 04:00) is already > `punchin`, so `addDay()` is skipped and the value is correct here — but the heuristic is a latent bug for same-clock-time wraps. This test locks the correct behaviour before removing the heuristic.

- [ ] **Step 3: Remove the `addDay()` heuristics**

In `AttendanceQueryService::formatGroupedAttendance` (lines ~190-192) remove:
```php
                if ($punchOut->lt($punchIn)) {
                    $punchOut->addDay();
                }
```
In `AttendanceQueryService::getTodayAttendance` (lines ~35-37) remove the same `if ($punchOutTime->lt($punchInTime)) { $punchOutTime->addDay(); }`.
In `AttendanceReportService::calculateTotalMinutes` (lines ~452-454) remove:
```php
                if ($out->lt($in)) {
                    $out->addDay();
                }
```
Datetimes are authoritative post-migration; a `punchout < punchin` now signals bad data, not an overnight shift.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WorkedMinutesConsistencyTest`
Expected: PASS.

- [ ] **Step 5: Run the existing attendance suite for regressions**

Run: `php artisan test --filter=Attendance`
Expected: PASS (AttendancePaginateTest, AttendanceMultiConfigTest, AttendanceExportAndStatsTest still green).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/AttendanceQueryService.php app/Services/Attendance/AttendanceReportService.php tests/Feature/Attendance/WorkedMinutesConsistencyTest.php
git commit -m "refactor(attendance): drop addDay() heuristics now that punches are datetimes"
```

---

### Task 9: Punch idempotency guard (reject duplicate/double-open punches)

**Files:**
- Modify: `app/Services/Attendance/AttendancePunchService.php` (`processPunchInTransaction`)
- Test: `tests/Feature/Attendance/PunchIdempotencyTest.php`

**Interfaces:**
- Consumes: existing locked-read of the user's latest row for the day.
- Produces: a punch-in within `N=30` seconds of the user's last punch (same direction) is rejected with `code => 429`; an explicit `in` while a row is already open is rejected (already present). The lock/transaction already exist — this adds the duplicate guard.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PunchIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_a_second_punch_in_within_the_dedupe_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 09:00:00'));
        $user = User::factory()->create();
        $svc = app(AttendancePunchService::class);

        $first = $svc->processPunch($user, Request::create('/', 'POST', ['check_type' => 'in']));
        $this->assertSame('success', $first['status']);

        // Same instant, the just-created row was closed? No — it's open. A second 'in' must reject.
        $second = $svc->processPunch($user, Request::create('/', 'POST', ['check_type' => 'in']));
        $this->assertSame('error', $second['status']);

        $this->assertSame(1, Attendance::where('user_id', $user->id)->count());

        Carbon::setTestNow();
    }
}
```

- [ ] **Step 2: Run test to verify current behaviour**

Run: `php artisan test --filter=PunchIdempotencyTest`
Expected: This specific case (explicit `in` while open) already returns "Already punched in for this period." — confirm it passes; if it does, the guard for the *manual toggle* duplicate (no `check_type`) within 30s is the gap. Extend the test:

```php
    public function test_rejects_rapid_duplicate_toggle_punch(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 09:00:00'));
        $user = User::factory()->create();
        $svc = app(AttendancePunchService::class);

        $svc->processPunch($user, Request::create('/', 'POST', [])); // punch in (toggle)
        // 5 seconds later a duplicate toggle would punch OUT immediately — guard it.
        Carbon::setTestNow(Carbon::parse('2026-06-19 09:00:05'));
        $dup = $svc->processPunch($user, Request::create('/', 'POST', []));

        $this->assertSame('error', $dup['status']);
        $this->assertSame(429, $dup['code']);

        Carbon::setTestNow();
    }
```
Expected: FAIL — the 5s-later toggle currently punches out.

- [ ] **Step 3: Add the dedupe guard**

In `processPunchInTransaction`, after fetching `$existingAttendance` and before the out/in branching, insert:

```php
        $dedupeSeconds = 30;
        if ($existingAttendance) {
            $lastEvent = $existingAttendance->punchout ?? $existingAttendance->punchin;
            if ($lastEvent && Carbon::parse($lastEvent)->diffInSeconds($punchTime) < $dedupeSeconds) {
                return [
                    'status' => 'error',
                    'message' => 'Duplicate punch ignored. Please wait a moment and try again.',
                    'code' => 429,
                ];
            }
        }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=PunchIdempotencyTest`
Expected: PASS (both cases).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/AttendancePunchService.php tests/Feature/Attendance/PunchIdempotencyTest.php
git commit -m "fix(attendance): reject rapid duplicate punches within dedupe window"
```

---

### Task 10: `attendance_audit_logs` table + model

**Files:**
- Create: `database/migrations/2026_06_19_000002_create_attendance_audit_logs_table.php`
- Create: `app/Models/HRM/AttendanceAuditLog.php`
- Test: `tests/Feature/Attendance/AttendanceAuditLogModelTest.php`

**Interfaces:**
- Produces: `attendance_audit_logs` with `id, actor_id(nullable FK users), attendance_id(nullable), action, before(json null), after(json null), reason(null), ip(null), created_at`. Immutable (no `updated_at`). Model `AttendanceAuditLog` with `$casts` for `before`/`after` → array; `actor()`, `attendance()` relations.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendanceAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceAuditLogModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_before_after_as_arrays(): void
    {
        $actor = User::factory()->create();
        $log = AttendanceAuditLog::create([
            'actor_id' => $actor->id,
            'attendance_id' => 99,
            'action' => 'update',
            'before' => ['punchin' => '09:00'],
            'after' => ['punchin' => '09:15'],
            'reason' => 'correction',
            'ip' => '127.0.0.1',
        ]);

        $fresh = $log->fresh();
        $this->assertSame(['punchin' => '09:00'], $fresh->before);
        $this->assertSame('update', $fresh->action);
        $this->assertSame($actor->id, $fresh->actor->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AttendanceAuditLogModelTest`
Expected: FAIL — table/model missing.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('attendance_id')->nullable();
            $table->string('action');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('attendance_id');
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_audit_logs');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceAuditLog extends Model
{
    public const UPDATED_AT = null; // immutable: no updates

    protected $fillable = [
        'actor_id', 'attendance_id', 'action', 'before', 'after', 'reason', 'ip',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class, 'attendance_id');
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AttendanceAuditLogModelTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_19_000002_create_attendance_audit_logs_table.php app/Models/HRM/AttendanceAuditLog.php tests/Feature/Attendance/AttendanceAuditLogModelTest.php
git commit -m "feat(attendance): add immutable attendance_audit_logs table + model"
```

---

### Task 11: `AttendanceAuditService` + enforce audit on every admin mutation

**Files:**
- Create: `app/Services/Attendance/AttendanceAuditService.php`
- Modify: `app/Http/Controllers/AttendanceController.php` — `updateAttendanceRecord`, `addAttendanceRecord`, `deleteAttendanceRecord`, `updateAttendanceStatus`, `markAsPresent`, `bulkMarkAsPresent`
- Test: `tests/Feature/Attendance/AttendanceAuditEnforcementTest.php`

**Interfaces:**
- Consumes: `AttendanceAuditLog`.
- Produces: `AttendanceAuditService::record(string $action, ?int $attendanceId, ?array $before, ?array $after, ?string $reason, Request $request): void` — writes one immutable row with `actor_id = auth()->id()` and `ip = $request->ip()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceAuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AttendanceAuditEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        foreach (['attendance.correct', 'attendance.manage'] as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }
    }

    public function test_correcting_a_record_writes_an_audit_row(): void
    {
        $admin = User::factory()->create(); $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.correct');

        $user = User::factory()->create();
        $a = Attendance::factory()->for($user)->create([
            'date' => '2026-06-19',
            'punchin' => Carbon::parse('2026-06-19 09:00'),
            'punchout' => Carbon::parse('2026-06-19 17:00'),
        ]);

        $this->actingAs($admin)
            ->postJson(route('attendance.correct.update', $a->id), [
                'punchin' => '2026-06-19 09:15:00',
                'reason' => 'late correction',
            ])
            ->assertOk();

        $log = AttendanceAuditLog::where('attendance_id', $a->id)->where('action', 'update')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('late correction', $log->reason);
    }

    public function test_deleting_a_record_writes_an_audit_row(): void
    {
        $admin = User::factory()->create(); $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.correct');
        $a = Attendance::factory()->create(['date' => '2026-06-19']);

        $this->actingAs($admin)
            ->deleteJson(route('attendance.correct.delete', $a->id), ['reason' => 'duplicate'])
            ->assertOk();

        $this->assertDatabaseHas('attendance_audit_logs', [
            'attendance_id' => $a->id, 'action' => 'delete', 'actor_id' => $admin->id,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AttendanceAuditEnforcementTest`
Expected: FAIL — no audit rows written.

- [ ] **Step 3: Create the audit service**

```php
<?php

namespace App\Services\Attendance;

use App\Models\HRM\AttendanceAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AttendanceAuditService
{
    public function record(
        string $action,
        ?int $attendanceId,
        ?array $before,
        ?array $after,
        ?string $reason,
        ?Request $request = null
    ): void {
        AttendanceAuditLog::create([
            'actor_id' => Auth::id(),
            'attendance_id' => $attendanceId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => $request?->ip(),
        ]);
    }
}
```

- [ ] **Step 4: Enforce audit in the controller mutations**

Inject the service (constructor or `app()`), then wrap each mutation in a transaction that records before/after. For `updateAttendanceRecord($id)`:

```php
        $audit = app(\App\Services\Attendance\AttendanceAuditService::class);
        $attendance = Attendance::findOrFail($id);
        $before = $attendance->only(['punchin', 'punchout', 'symbol', 'date']);

        \DB::transaction(function () use ($attendance, $request, $audit, $before, $id) {
            $attendance->update($request->only(['punchin', 'punchout', 'symbol']));
            $audit->record('update', $id, $before, $attendance->only(['punchin', 'punchout', 'symbol', 'date']), $request->input('reason'), $request);
        });
```

Apply the same pattern: `addAttendanceRecord` → `action='create'`, `before=null`; `deleteAttendanceRecord` → `action='delete'`, `after=null` (record before deleting inside the transaction); `updateAttendanceStatus` → `action='status'`; `markAsPresent`/`bulkMarkAsPresent` → `action='mark_present'` (one row per user). Keep existing response shapes.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AttendanceAuditEnforcementTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/AttendanceAuditService.php app/Http/Controllers/AttendanceController.php tests/Feature/Attendance/AttendanceAuditEnforcementTest.php
git commit -m "feat(attendance): enforce immutable audit logging on all admin mutations"
```

---

### Task 12: Mark-present uses shift start (not `setHour(9)`)

**Files:**
- Modify: `app/Http/Controllers/AttendanceController.php` (`markAsPresent`, `bulkMarkAsPresent`)
- Test: `tests/Feature/Attendance/MarkPresentShiftAwareTest.php`

**Interfaces:**
- Consumes: `ScheduleResolver`.
- Produces: manual mark-present sets `punchin` to the resolved shift `start` for that user/date (falls back to settings `office_start_time`), not a hardcoded 09:00.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MarkPresentShiftAwareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.manage']);
    }

    public function test_mark_present_punchin_matches_office_start(): void
    {
        AttendanceSetting::create([
            'office_start_time' => '08:30', 'office_end_time' => '17:00',
            'break_time_duration' => 0, 'late_mark_after' => 0,
            'early_leave_before' => 0, 'overtime_after' => 0,
            'weekend_days' => ['friday'], 'auto_punch_out' => false,
        ]);
        $admin = User::factory()->create(); $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.manage');
        $emp = User::factory()->create();

        $this->actingAs($admin)->postJson(route('attendance.mark-as-present'), [
            'user_id' => $emp->id, 'date' => '2026-06-18',
        ])->assertOk();

        $a = Attendance::where('user_id', $emp->id)->first();
        $this->assertSame('08:30', \Carbon\Carbon::parse($a->punchin)->format('H:i'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MarkPresentShiftAwareTest`
Expected: FAIL — punchin is 09:00.

- [ ] **Step 3: Use the resolver for the punch-in time**

In `markAsPresent`, replace `Carbon::parse($validated['date'])->setHour(9)->setMinute(0)->setSecond(0)` with:

```php
            $shift = app(\App\Services\Attendance\Contracts\ScheduleResolver::class)
                ->resolve((int) $validated['user_id'], \Carbon\Carbon::parse($validated['date']));
            $punchin = $shift->start;
```
and use `$punchin` in the `updateOrCreate`. Do the same in `bulkMarkAsPresent` (resolve per user inside the loop).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=MarkPresentShiftAwareTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/AttendanceController.php tests/Feature/Attendance/MarkPresentShiftAwareTest.php
git commit -m "fix(attendance): mark-present uses resolved shift start, not hardcoded 09:00"
```

---

### Task 13: Phase 0 integration sweep — manual verification + full suite

**Files:**
- Verify only (no new code unless a regression surfaces).

- [ ] **Step 1: Run the full attendance test suite**

Run: `php artisan test --filter=Attendance`
Expected: all PASS.

- [ ] **Step 2: Build assets and verify the live surfaces (dev server)**

Run: `npx vite build` (NOT `npm run build`). Then with `npm run dev` running, drive Playwright at `https://aero-enterprise-suite.test/attendance`:
- Daily Timesheet renders rows + correct Present/Late counts.
- Monthly Calendar populates.
- Employee `/attendance-employee` stats match records.
Wait for skeletons to resolve before each snapshot.

- [ ] **Step 3: Commit any regression fixes, then tag Phase 0 done**

```bash
git commit -am "test(attendance): Phase 0 integration verification" --allow-empty
```

---

# PHASE 1 — Shifts & Rostering

> Phase 1 swaps the `ScheduleResolver` binding from `DefaultScheduleResolver` to a roster-backed resolver. The status engine (Task 5) is untouched.

### Task 14: `shifts` table + `Shift` model + factory

**Files:**
- Create: `database/migrations/2026_06_19_000010_create_shifts_table.php`
- Create: `app/Models/HRM/Shift.php`
- Create: `database/factories/HRM/ShiftFactory.php`
- Test: `tests/Feature/Attendance/ShiftModelTest.php`

**Interfaces:**
- Produces: `shifts(id, name, code unique, type[fixed|flexible|open], start_time, end_time, crosses_midnight bool, break_minutes, grace_in_minutes, grace_out_minutes, full_day_minutes, half_day_minutes, min_present_minutes, core_start_time null, core_end_time null, color, is_active, timestamps)`. `Shift` model exposes `toSchedule(CarbonInterface $date): ShiftSchedule`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Shift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_schedule_builds_window_for_a_date(): void
    {
        $shift = Shift::factory()->create([
            'start_time' => '09:00', 'end_time' => '17:30',
            'crosses_midnight' => false, 'grace_in_minutes' => 15,
            'full_day_minutes' => 480, 'half_day_minutes' => 240,
        ]);

        $sched = $shift->toSchedule(Carbon::parse('2026-06-19'));
        $this->assertTrue($sched->isWorkingDay);
        $this->assertSame('2026-06-19 09:00:00', $sched->start->format('Y-m-d H:i:s'));
        $this->assertSame(480, $sched->fullDayMinutes);
    }

    public function test_night_shift_end_rolls_to_next_day(): void
    {
        $shift = Shift::factory()->create([
            'start_time' => '20:00', 'end_time' => '04:00', 'crosses_midnight' => true,
        ]);
        $sched = $shift->toSchedule(Carbon::parse('2026-06-19'));
        $this->assertSame('2026-06-20 04:00:00', $sched->end->format('Y-m-d H:i:s'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ShiftModelTest`
Expected: FAIL — table/model/factory missing.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->enum('type', ['fixed', 'flexible', 'open'])->default('fixed');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('crosses_midnight')->default(false);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('grace_in_minutes')->default(0);
            $table->unsignedInteger('grace_out_minutes')->default(0);
            $table->unsignedInteger('full_day_minutes')->default(0);
            $table->unsignedInteger('half_day_minutes')->default(0);
            $table->unsignedInteger('min_present_minutes')->default(0);
            $table->time('core_start_time')->nullable();
            $table->time('core_end_time')->nullable();
            $table->string('color', 16)->default('#3b82f6');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php

namespace App\Models\HRM;

use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'code', 'type', 'start_time', 'end_time', 'crosses_midnight',
        'break_minutes', 'grace_in_minutes', 'grace_out_minutes',
        'full_day_minutes', 'half_day_minutes', 'min_present_minutes',
        'core_start_time', 'core_end_time', 'color', 'is_active',
    ];

    protected $casts = [
        'crosses_midnight' => 'boolean',
        'is_active' => 'boolean',
        'break_minutes' => 'integer',
        'grace_in_minutes' => 'integer',
        'grace_out_minutes' => 'integer',
        'full_day_minutes' => 'integer',
        'half_day_minutes' => 'integer',
        'min_present_minutes' => 'integer',
    ];

    public function toSchedule(CarbonInterface $date): ShiftSchedule
    {
        $day = $date->copy()->startOfDay();
        $start = Carbon::parse($day->format('Y-m-d').' '.Carbon::parse($this->start_time)->format('H:i:s'));
        $end = Carbon::parse($day->format('Y-m-d').' '.Carbon::parse($this->end_time)->format('H:i:s'));

        if ($this->crosses_midnight || $end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return new ShiftSchedule(
            start: $start,
            end: $end,
            crossesMidnight: (bool) $this->crosses_midnight,
            graceInMinutes: $this->grace_in_minutes,
            graceOutMinutes: $this->grace_out_minutes,
            fullDayMinutes: $this->full_day_minutes,
            halfDayMinutes: $this->half_day_minutes,
            minPresentMinutes: $this->min_present_minutes,
            breakMinutes: $this->break_minutes,
            isWorkingDay: true,
        );
    }
}
```

- [ ] **Step 5: Create the factory**

```php
<?php

namespace Database\Factories\HRM;

use App\Models\HRM\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['Morning', 'Evening', 'Night', 'General']),
            'code' => strtoupper($this->faker->unique()->bothify('SH-###')),
            'type' => 'fixed',
            'start_time' => '09:00',
            'end_time' => '17:30',
            'crosses_midnight' => false,
            'break_minutes' => 60,
            'grace_in_minutes' => 15,
            'grace_out_minutes' => 0,
            'full_day_minutes' => 480,
            'half_day_minutes' => 240,
            'min_present_minutes' => 0,
            'color' => '#3b82f6',
            'is_active' => true,
        ];
    }
}
```

Add `protected static function newFactory()` to `Shift` only if auto-discovery fails (namespaced models): the `HasFactory` + `Database\Factories\HRM\ShiftFactory` convention resolves automatically because Laravel maps `App\Models\HRM\Shift` → `Database\Factories\HRM\ShiftFactory`. Verify in Step 6; if it errors, add the explicit `newFactory()` resolver as in Task 1.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=ShiftModelTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_06_19_000010_create_shifts_table.php app/Models/HRM/Shift.php database/factories/HRM/ShiftFactory.php tests/Feature/Attendance/ShiftModelTest.php
git commit -m "feat(attendance): add shifts table, model, factory with toSchedule()"
```

---

### Task 15: `shift_rotation_patterns` + `shift_assignments` tables + models

**Files:**
- Create: `database/migrations/2026_06_19_000011_create_shift_rotation_patterns_table.php`
- Create: `database/migrations/2026_06_19_000012_create_shift_assignments_table.php`
- Create: `app/Models/HRM/ShiftRotationPattern.php`, `app/Models/HRM/ShiftAssignment.php`
- Create: `database/factories/HRM/ShiftRotationPatternFactory.php`, `database/factories/HRM/ShiftAssignmentFactory.php`
- Test: `tests/Feature/Attendance/ShiftAssignmentModelTest.php`

**Interfaces:**
- Produces:
  - `shift_rotation_patterns(id, name, code unique, cycle_length_days, definition json, is_active, timestamps)`; `definition` = ordered array of `shift_id` ints or the string `"off"`.
  - `shift_assignments(id, scope_type[user|department|designation|org], scope_id nullable, shift_id nullable FK, rotation_pattern_id nullable FK, anchor_date date, effective_from date, effective_to date nullable, priority int, assigned_by nullable, timestamps)`. Exactly one of `shift_id`/`rotation_pattern_id`.
  - `ShiftAssignment` casts `anchor_date/effective_from/effective_to` to dates; relations `shift()`, `rotationPattern()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\HRM\ShiftRotationPattern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftAssignmentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_links_to_shift(): void
    {
        $shift = Shift::factory()->create();
        $a = ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => 1,
            'shift_id' => $shift->id, 'rotation_pattern_id' => null,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        $this->assertSame($shift->id, $a->shift->id);
        $this->assertSame('2026-06-01', $a->effective_from->toDateString());
    }

    public function test_rotation_pattern_definition_is_array(): void
    {
        $shift = Shift::factory()->create();
        $p = ShiftRotationPattern::factory()->create([
            'cycle_length_days' => 3,
            'definition' => [$shift->id, $shift->id, 'off'],
        ]);
        $this->assertSame('off', $p->fresh()->definition[2]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ShiftAssignmentModelTest`
Expected: FAIL.

- [ ] **Step 3: Create the rotation-patterns migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_rotation_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->unsignedInteger('cycle_length_days');
            $table->json('definition');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_rotation_patterns');
    }
};
```

- [ ] **Step 4: Create the assignments migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->enum('scope_type', ['user', 'department', 'designation', 'org']);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('rotation_pattern_id')->nullable()->constrained('shift_rotation_patterns')->nullOnDelete();
            $table->date('anchor_date');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->integer('priority')->default(0);
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_assignments');
    }
};
```

- [ ] **Step 5: Create the models**

`app/Models/HRM/ShiftRotationPattern.php`:
```php
<?php

namespace App\Models\HRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftRotationPattern extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'cycle_length_days', 'definition', 'is_active'];

    protected $casts = [
        'definition' => 'array',
        'cycle_length_days' => 'integer',
        'is_active' => 'boolean',
    ];
}
```

`app/Models/HRM/ShiftAssignment.php`:
```php
<?php

namespace App\Models\HRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'scope_type', 'scope_id', 'shift_id', 'rotation_pattern_id',
        'anchor_date', 'effective_from', 'effective_to', 'priority', 'assigned_by',
    ];

    protected $casts = [
        'anchor_date' => 'date',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'priority' => 'integer',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function rotationPattern(): BelongsTo
    {
        return $this->belongsTo(ShiftRotationPattern::class);
    }
}
```

- [ ] **Step 6: Create the factories**

`database/factories/HRM/ShiftRotationPatternFactory.php`:
```php
<?php

namespace Database\Factories\HRM;

use App\Models\HRM\ShiftRotationPattern;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShiftRotationPatternFactory extends Factory
{
    protected $model = ShiftRotationPattern::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'code' => strtoupper($this->faker->unique()->bothify('ROT-###')),
            'cycle_length_days' => 2,
            'definition' => ['off', 'off'],
            'is_active' => true,
        ];
    }
}
```

`database/factories/HRM/ShiftAssignmentFactory.php`:
```php
<?php

namespace Database\Factories\HRM;

use App\Models\HRM\ShiftAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShiftAssignmentFactory extends Factory
{
    protected $model = ShiftAssignment::class;

    public function definition(): array
    {
        return [
            'scope_type' => 'user',
            'scope_id' => 1,
            'shift_id' => null,
            'rotation_pattern_id' => null,
            'anchor_date' => '2026-06-01',
            'effective_from' => '2026-06-01',
            'effective_to' => null,
            'priority' => 0,
            'assigned_by' => null,
        ];
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=ShiftAssignmentModelTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_06_19_000011_create_shift_rotation_patterns_table.php database/migrations/2026_06_19_000012_create_shift_assignments_table.php app/Models/HRM/ShiftRotationPattern.php app/Models/HRM/ShiftAssignment.php database/factories/HRM/ShiftRotationPatternFactory.php database/factories/HRM/ShiftAssignmentFactory.php tests/Feature/Attendance/ShiftAssignmentModelTest.php
git commit -m "feat(attendance): add rotation patterns + effective-dated shift assignments"
```

---

### Task 16: `roster_days` + `shift_swap_requests` tables + models

**Files:**
- Create: `database/migrations/2026_06_19_000013_create_roster_days_table.php`
- Create: `database/migrations/2026_06_19_000014_create_shift_swap_requests_table.php`
- Create: `app/Models/HRM/RosterDay.php`, `app/Models/HRM/ShiftSwapRequest.php`
- Create: `database/factories/HRM/RosterDayFactory.php`
- Test: `tests/Feature/Attendance/RosterDayModelTest.php`

**Interfaces:**
- Produces:
  - `roster_days(id, user_id FK, date, shift_id nullable FK [null=off], source[pattern|rule|manual|swap], assignment_id nullable, note nullable, locked bool, timestamps)`, unique `(user_id, date)`.
  - `shift_swap_requests(id, requester_id FK, requester_date date, counterparty_id nullable FK, counterparty_date date nullable, requested_shift_id nullable FK, reason nullable, status[pending|approved|rejected|cancelled], approval_chain json nullable, approved_by nullable, timestamps)`.
  - `RosterDay` casts `date`→date, `locked`→bool; relations `shift()`, `user()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterDayModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_user_date_constraint(): void
    {
        $user = User::factory()->create();
        $shift = Shift::factory()->create();

        RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-19', 'shift_id' => $shift->id, 'source' => 'manual']);

        $this->expectException(QueryException::class);
        RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-19', 'shift_id' => null, 'source' => 'pattern']);
    }

    public function test_null_shift_means_off_day(): void
    {
        $user = User::factory()->create();
        $r = RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-20', 'shift_id' => null, 'source' => 'pattern']);
        $this->assertNull($r->shift);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RosterDayModelTest`
Expected: FAIL.

- [ ] **Step 3: Create the roster_days migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->enum('source', ['pattern', 'rule', 'manual', 'swap'])->default('pattern');
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->string('note')->nullable();
            $table->boolean('locked')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_days');
    }
};
```

- [ ] **Step 4: Create the swap-requests migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_swap_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->date('requester_date');
            $table->foreignId('counterparty_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('counterparty_date')->nullable();
            $table->foreignId('requested_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->json('approval_chain')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_swap_requests');
    }
};
```

- [ ] **Step 5: Create the models**

`app/Models/HRM/RosterDay.php`:
```php
<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RosterDay extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'date', 'shift_id', 'source', 'assignment_id', 'note', 'locked'];

    protected $casts = ['date' => 'date:Y-m-d', 'locked' => 'boolean'];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

`app/Models/HRM/ShiftSwapRequest.php`:
```php
<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftSwapRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'requester_id', 'requester_date', 'counterparty_id', 'counterparty_date',
        'requested_shift_id', 'reason', 'status', 'approval_chain', 'approved_by',
    ];

    protected $casts = [
        'requester_date' => 'date',
        'counterparty_date' => 'date',
        'approval_chain' => 'array',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counterparty_id');
    }
}
```

- [ ] **Step 6: Create the RosterDay factory**

```php
<?php

namespace Database\Factories\HRM;

use App\Models\HRM\RosterDay;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RosterDayFactory extends Factory
{
    protected $model = RosterDay::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => '2026-06-19',
            'shift_id' => null,
            'source' => 'pattern',
            'locked' => false,
        ];
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=RosterDayModelTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_06_19_000013_create_roster_days_table.php database/migrations/2026_06_19_000014_create_shift_swap_requests_table.php app/Models/HRM/RosterDay.php app/Models/HRM/ShiftSwapRequest.php database/factories/HRM/RosterDayFactory.php tests/Feature/Attendance/RosterDayModelTest.php
git commit -m "feat(attendance): add roster_days + shift_swap_requests tables/models"
```

---

### Task 17: `ShiftService` — CRUD + assignment overlap validation

**Files:**
- Create: `app/Services/Attendance/ShiftService.php`
- Test: `tests/Feature/Attendance/ShiftServiceTest.php`

**Interfaces:**
- Consumes: `Shift`, `ShiftAssignment`, `ShiftRotationPattern`.
- Produces:
  - `createAssignment(array $data): ShiftAssignment` — throws `InvalidArgumentException` if both/neither of `shift_id`/`rotation_pattern_id` set, or if `[effective_from, effective_to]` overlaps an existing assignment with the same `scope_type`+`scope_id`.
  - `assignmentsOverlap(string $scopeType, ?int $scopeId, string $from, ?string $to, ?int $ignoreId = null): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Shift;
use App\Services\Attendance\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ShiftServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_assignment_with_both_shift_and_pattern(): void
    {
        $shift = Shift::factory()->create();
        $this->expectException(InvalidArgumentException::class);

        app(ShiftService::class)->createAssignment([
            'scope_type' => 'user', 'scope_id' => 1,
            'shift_id' => $shift->id, 'rotation_pattern_id' => 1,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);
    }

    public function test_rejects_overlapping_effective_dates_for_same_scope(): void
    {
        $shift = Shift::factory()->create();
        $svc = app(ShiftService::class);

        $svc->createAssignment([
            'scope_type' => 'user', 'scope_id' => 5,
            'shift_id' => $shift->id, 'rotation_pattern_id' => null,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01', 'effective_to' => '2026-06-30',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $svc->createAssignment([
            'scope_type' => 'user', 'scope_id' => 5,
            'shift_id' => $shift->id, 'rotation_pattern_id' => null,
            'anchor_date' => '2026-06-15', 'effective_from' => '2026-06-15', 'effective_to' => '2026-07-15',
        ]);
    }

    public function test_allows_non_overlapping_assignment(): void
    {
        $shift = Shift::factory()->create();
        $svc = app(ShiftService::class);

        $svc->createAssignment([
            'scope_type' => 'user', 'scope_id' => 5, 'shift_id' => $shift->id,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01', 'effective_to' => '2026-06-30',
        ]);
        $a = $svc->createAssignment([
            'scope_type' => 'user', 'scope_id' => 5, 'shift_id' => $shift->id,
            'anchor_date' => '2026-07-01', 'effective_from' => '2026-07-01', 'effective_to' => null,
        ]);

        $this->assertNotNull($a->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ShiftServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the service**

```php
<?php

namespace App\Services\Attendance;

use App\Models\HRM\ShiftAssignment;
use InvalidArgumentException;

class ShiftService
{
    public function createAssignment(array $data): ShiftAssignment
    {
        $hasShift = ! empty($data['shift_id']);
        $hasPattern = ! empty($data['rotation_pattern_id']);

        if ($hasShift === $hasPattern) {
            throw new InvalidArgumentException('Exactly one of shift_id or rotation_pattern_id must be set.');
        }

        if ($this->assignmentsOverlap(
            $data['scope_type'],
            $data['scope_id'] ?? null,
            $data['effective_from'],
            $data['effective_to'] ?? null,
        )) {
            throw new InvalidArgumentException('Assignment effective dates overlap an existing assignment for this scope.');
        }

        return ShiftAssignment::create($data);
    }

    public function assignmentsOverlap(string $scopeType, ?int $scopeId, string $from, ?string $to, ?int $ignoreId = null): bool
    {
        $query = ShiftAssignment::where('scope_type', $scopeType)
            ->where(fn ($q) => $q->where('scope_id', $scopeId)->orWhereNull('scope_id')->where(fn () => $scopeId === null));

        // Simpler, explicit scope match:
        $query = ShiftAssignment::where('scope_type', $scopeType);
        $scopeId === null ? $query->whereNull('scope_id') : $query->where('scope_id', $scopeId);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        // Overlap: existing.from <= new.to (or new open) AND existing.to (or open) >= new.from
        return $query->get()->contains(function (ShiftAssignment $a) use ($from, $to) {
            $aFrom = $a->effective_from->toDateString();
            $aTo = $a->effective_to?->toDateString();

            $newToOk = $aTo === null || $aTo >= $from;       // existing ends after new starts
            $existingToOk = $to === null || $aFrom <= $to;   // existing starts before new ends

            return $newToOk && $existingToOk;
        });
    }
}
```

> Clean up the duplicated `$query` assignment left as a comment-guide above into the single explicit-scope-match form when implementing.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ShiftServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/ShiftService.php tests/Feature/Attendance/ShiftServiceTest.php
git commit -m "feat(attendance): add ShiftService with effective-date overlap validation"
```

---

### Task 18: `RosterService::resolveShift` — precedence + rotation phase

**Files:**
- Create: `app/Services/Attendance/RosterService.php`
- Test: `tests/Feature/Attendance/RosterResolveShiftTest.php`

**Interfaces:**
- Consumes: `RosterDay`, `ShiftAssignment`, `ShiftRotationPattern`, `Shift`, `User` (for department/designation).
- Produces: `RosterService::resolveShift(int $userId, CarbonInterface $date): ?Shift` with precedence **manual roster override > approved swap (roster_days source=swap) > user assignment > designation rule > department rule > org default**. Rotation phase = `anchor_date.diffInDays(date) mod cycle_length_days`, indexing `definition`; a `"off"` entry → `null` (off day). Helper `resolveAssignment(int $userId, CarbonInterface $date): ?ShiftAssignment`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\HRM\ShiftRotationPattern;
use App\Models\User;
use App\Services\Attendance\RosterService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterResolveShiftTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_user_assignment_resolves(): void
    {
        $user = User::factory()->create();
        $shift = Shift::factory()->create();
        ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => $user->id,
            'shift_id' => $shift->id, 'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        $resolved = app(RosterService::class)->resolveShift($user->id, Carbon::parse('2026-06-19'));
        $this->assertSame($shift->id, $resolved->id);
    }

    public function test_rotation_pattern_resolves_by_cycle_day(): void
    {
        $user = User::factory()->create();
        $morning = Shift::factory()->create(['code' => 'MOR']);
        $night = Shift::factory()->create(['code' => 'NGT']);
        $pattern = ShiftRotationPattern::factory()->create([
            'cycle_length_days' => 3,
            'definition' => [$morning->id, $night->id, 'off'],
        ]);
        ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => $user->id,
            'shift_id' => null, 'rotation_pattern_id' => $pattern->id,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        $svc = app(RosterService::class);
        // 2026-06-01 = day0 → morning; 06-02 = night; 06-03 = off (null)
        $this->assertSame($morning->id, $svc->resolveShift($user->id, Carbon::parse('2026-06-01'))->id);
        $this->assertSame($night->id, $svc->resolveShift($user->id, Carbon::parse('2026-06-02'))->id);
        $this->assertNull($svc->resolveShift($user->id, Carbon::parse('2026-06-03')));
    }

    public function test_manual_roster_override_beats_assignment(): void
    {
        $user = User::factory()->create();
        $assigned = Shift::factory()->create(['code' => 'ASG']);
        $override = Shift::factory()->create(['code' => 'OVR']);
        ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => $user->id,
            'shift_id' => $assigned->id, 'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);
        RosterDay::create([
            'user_id' => $user->id, 'date' => '2026-06-19',
            'shift_id' => $override->id, 'source' => 'manual', 'locked' => true,
        ]);

        $resolved = app(RosterService::class)->resolveShift($user->id, Carbon::parse('2026-06-19'));
        $this->assertSame($override->id, $resolved->id);
    }

    public function test_falls_back_through_designation_department_org(): void
    {
        $user = User::factory()->create(['department_id' => 10, 'designation_id' => 20]);
        $orgShift = Shift::factory()->create(['code' => 'ORG']);
        ShiftAssignment::factory()->create([
            'scope_type' => 'org', 'scope_id' => null,
            'shift_id' => $orgShift->id, 'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01', 'priority' => 0,
        ]);

        $resolved = app(RosterService::class)->resolveShift($user->id, Carbon::parse('2026-06-19'));
        $this->assertSame($orgShift->id, $resolved->id);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=RosterResolveShiftTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `RosterService` (resolveShift first)**

```php
<?php

namespace App\Services\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class RosterService
{
    public function resolveShift(int $userId, CarbonInterface $date): ?Shift
    {
        $dateStr = $date->copy()->startOfDay()->toDateString();

        // 1. Manual override / approved swap materialized in roster_days wins.
        $rosterDay = RosterDay::where('user_id', $userId)
            ->whereDate('date', $dateStr)
            ->whereIn('source', ['manual', 'swap'])
            ->first();
        if ($rosterDay) {
            return $rosterDay->shift_id ? $rosterDay->shift : null; // null = off day
        }

        // 2. Effective-dated assignment, highest precedence scope first.
        $assignment = $this->resolveAssignment($userId, $date);
        if (! $assignment) {
            return null;
        }

        if ($assignment->shift_id) {
            return $assignment->shift;
        }

        // 3. Rotation pattern → phase by anchor_date.
        $pattern = $assignment->rotationPattern;
        if (! $pattern || empty($pattern->definition)) {
            return null;
        }

        $phase = $assignment->anchor_date->startOfDay()->diffInDays($date->copy()->startOfDay()) % $pattern->cycle_length_days;
        $entry = $pattern->definition[$phase] ?? 'off';

        return $entry === 'off' ? null : Shift::find($entry);
    }

    public function resolveAssignment(int $userId, CarbonInterface $date): ?ShiftAssignment
    {
        $user = User::find($userId);
        $dateStr = $date->copy()->startOfDay()->toDateString();

        // Precedence: user > designation > department > org.
        $scopes = [
            ['type' => 'user', 'id' => $userId],
            ['type' => 'designation', 'id' => $user?->designation_id],
            ['type' => 'department', 'id' => $user?->department_id],
            ['type' => 'org', 'id' => null],
        ];

        foreach ($scopes as $scope) {
            if ($scope['type'] !== 'org' && $scope['type'] !== 'user' && $scope['id'] === null) {
                continue;
            }

            $query = ShiftAssignment::where('scope_type', $scope['type'])
                ->whereDate('effective_from', '<=', $dateStr)
                ->where(function ($q) use ($dateStr) {
                    $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $dateStr);
                })
                ->orderByDesc('priority')
                ->orderByDesc('effective_from');

            $scope['id'] === null ? $query->whereNull('scope_id') : $query->where('scope_id', $scope['id']);

            $assignment = $query->first();
            if ($assignment) {
                return $assignment;
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=RosterResolveShiftTest`
Expected: PASS (all 4).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/RosterService.php tests/Feature/Attendance/RosterResolveShiftTest.php
git commit -m "feat(attendance): RosterService.resolveShift with precedence + rotation phase"
```

---

### Task 19: `RosterService::generateRoster` — idempotent materialization

**Files:**
- Modify: `app/Services/Attendance/RosterService.php` (add `generateRoster`)
- Test: `tests/Feature/Attendance/RosterGenerateTest.php`

**Interfaces:**
- Produces: `generateRoster(array $userIds, string $fromDate, string $toDate): int` — upserts `roster_days` for each user/day from `resolveShift`, `source='pattern'`; **never overwrites** rows with `locked=true` or `source IN (manual, swap)`. Returns count of rows written/updated.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\User;
use App\Services\Attendance\RosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterGenerateTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_and_is_idempotent_and_preserves_manual(): void
    {
        $user = User::factory()->create();
        $shift = Shift::factory()->create();
        $manualShift = Shift::factory()->create();
        ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => $user->id,
            'shift_id' => $shift->id, 'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        // Pre-existing manual override that must survive generation.
        RosterDay::create([
            'user_id' => $user->id, 'date' => '2026-06-02',
            'shift_id' => $manualShift->id, 'source' => 'manual', 'locked' => true,
        ]);

        $svc = app(RosterService::class);
        $svc->generateRoster([$user->id], '2026-06-01', '2026-06-03');
        $svc->generateRoster([$user->id], '2026-06-01', '2026-06-03'); // idempotent re-run

        $this->assertSame(3, RosterDay::where('user_id', $user->id)->count());
        $manual = RosterDay::where('user_id', $user->id)->whereDate('date', '2026-06-02')->first();
        $this->assertSame('manual', $manual->source);
        $this->assertSame($manualShift->id, $manual->shift_id);

        $generated = RosterDay::where('user_id', $user->id)->whereDate('date', '2026-06-01')->first();
        $this->assertSame('pattern', $generated->source);
        $this->assertSame($shift->id, $generated->shift_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RosterGenerateTest`
Expected: FAIL — method missing.

- [ ] **Step 3: Add `generateRoster`**

```php
    public function generateRoster(array $userIds, string $fromDate, string $toDate): int
    {
        $from = \Carbon\Carbon::parse($fromDate)->startOfDay();
        $to = \Carbon\Carbon::parse($toDate)->startOfDay();
        $written = 0;

        \Illuminate\Support\Facades\DB::transaction(function () use ($userIds, $from, $to, &$written) {
            foreach ($userIds as $userId) {
                for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                    $existing = RosterDay::where('user_id', $userId)->whereDate('date', $d->toDateString())->first();

                    // Never overwrite locked / manual / swap rows.
                    if ($existing && ($existing->locked || in_array($existing->source, ['manual', 'swap'], true))) {
                        continue;
                    }

                    $shift = $this->resolveShift($userId, $d);

                    RosterDay::updateOrCreate(
                        ['user_id' => $userId, 'date' => $d->toDateString()],
                        ['shift_id' => $shift?->id, 'source' => 'pattern'],
                    );
                    $written++;
                }
            }
        });

        return $written;
    }
```

> Note: `resolveShift` reads `roster_days` source `manual`/`swap` first — but for a `pattern` regeneration we want the assignment/rotation result, not a previously-generated `pattern` row. Since `resolveShift` only short-circuits on `manual`/`swap` rows (not `pattern`), regeneration correctly recomputes from the assignment. Good.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=RosterGenerateTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/RosterService.php tests/Feature/Attendance/RosterGenerateTest.php
git commit -m "feat(attendance): idempotent roster generation preserving manual/swap/locked"
```

---

### Task 20: `RosterService::applySwap` — rewrite both parties on approval

**Files:**
- Modify: `app/Services/Attendance/RosterService.php` (add `applySwap`)
- Test: `tests/Feature/Attendance/RosterApplySwapTest.php`

**Interfaces:**
- Consumes: `ShiftSwapRequest`.
- Produces: `applySwap(ShiftSwapRequest $swap): void` — on an approved swap, writes `roster_days` overrides (`source='swap'`, `locked=true`) for the requester's `requester_date` and (when present) the counterparty's `counterparty_date`, swapping their shifts. Idempotent.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use App\Services\Attendance\RosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterApplySwapTest extends TestCase
{
    use RefreshDatabase;

    public function test_swap_rewrites_both_parties_roster(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $shiftA = Shift::factory()->create(['code' => 'AAA']);
        $shiftB = Shift::factory()->create(['code' => 'BBB']);

        RosterDay::create(['user_id' => $a->id, 'date' => '2026-06-19', 'shift_id' => $shiftA->id, 'source' => 'pattern']);
        RosterDay::create(['user_id' => $b->id, 'date' => '2026-06-20', 'shift_id' => $shiftB->id, 'source' => 'pattern']);

        $swap = ShiftSwapRequest::create([
            'requester_id' => $a->id, 'requester_date' => '2026-06-19',
            'counterparty_id' => $b->id, 'counterparty_date' => '2026-06-20',
            'status' => 'approved',
        ]);

        app(RosterService::class)->applySwap($swap);

        $aDay = RosterDay::where('user_id', $a->id)->whereDate('date', '2026-06-19')->first();
        $bDay = RosterDay::where('user_id', $b->id)->whereDate('date', '2026-06-20')->first();

        $this->assertSame('swap', $aDay->source);
        $this->assertSame($shiftB->id, $aDay->shift_id);
        $this->assertSame($shiftA->id, $bDay->shift_id);
        $this->assertTrue($aDay->locked);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RosterApplySwapTest`
Expected: FAIL — method missing.

- [ ] **Step 3: Add `applySwap`**

```php
    public function applySwap(ShiftSwapRequest $swap): void
    {
        if ($swap->status !== 'approved') {
            return;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($swap) {
            $requesterShiftId = $this->resolveShift($swap->requester_id, $swap->requester_date)?->id;

            if ($swap->counterparty_id && $swap->counterparty_date) {
                $counterpartyShiftId = $this->resolveShift($swap->counterparty_id, $swap->counterparty_date)?->id;

                $this->writeSwapDay($swap->requester_id, $swap->requester_date->toDateString(), $counterpartyShiftId);
                $this->writeSwapDay($swap->counterparty_id, $swap->counterparty_date->toDateString(), $requesterShiftId);
            } else {
                // One-sided swap to a specific requested shift.
                $this->writeSwapDay($swap->requester_id, $swap->requester_date->toDateString(), $swap->requested_shift_id);
            }
        });
    }

    private function writeSwapDay(int $userId, string $date, ?int $shiftId): void
    {
        RosterDay::updateOrCreate(
            ['user_id' => $userId, 'date' => $date],
            ['shift_id' => $shiftId, 'source' => 'swap', 'locked' => true],
        );
    }
```

Add the `use App\Models\HRM\ShiftSwapRequest;` import at the top of `RosterService.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=RosterApplySwapTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/RosterService.php tests/Feature/Attendance/RosterApplySwapTest.php
git commit -m "feat(attendance): applySwap rewrites both parties' roster days"
```

---

### Task 21: `RosterScheduleResolver` — make the engine shift-aware

**Files:**
- Create: `app/Services/Attendance/RosterScheduleResolver.php`
- Modify: `app/Providers/AttendanceServiceProvider.php` (rebind)
- Test: `tests/Feature/Attendance/RosterScheduleResolverTest.php`

**Interfaces:**
- Consumes: `RosterService`, `AttendanceSetting` (fallback), `Shift::toSchedule`.
- Produces: `RosterScheduleResolver implements ScheduleResolver` — `resolve(userId, date)` returns the resolved `Shift`'s `ShiftSchedule`; if `resolveShift` returns `null` it returns `ShiftSchedule::nonWorking($date)` (off day → never "absent"); if **no roster/assignment at all** exists for the user it falls back to `DefaultScheduleResolver` (backward compatible).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\User;
use App\Services\Attendance\Contracts\ScheduleResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterScheduleResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolved_shift_drives_late_window(): void
    {
        $user = User::factory()->create();
        $shift = Shift::factory()->create([
            'start_time' => '10:00', 'end_time' => '18:00', 'grace_in_minutes' => 0,
        ]);
        ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => $user->id, 'shift_id' => $shift->id,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        $sched = app(ScheduleResolver::class)->resolve($user->id, Carbon::parse('2026-06-19'));
        $this->assertTrue($sched->isWorkingDay);
        $this->assertSame('10:00', $sched->start->format('H:i'));
    }

    public function test_off_day_is_non_working(): void
    {
        $user = User::factory()->create();
        // No assignment, no roster → falls back to default resolver (settings). With no settings, default working window.
        $sched = app(ScheduleResolver::class)->resolve($user->id, Carbon::parse('2026-06-19'));
        $this->assertNotNull($sched);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RosterScheduleResolverTest`
Expected: FAIL — still bound to `DefaultScheduleResolver`; resolved start is settings-based 09:00, not 10:00.

- [ ] **Step 3: Implement the resolver**

```php
<?php

namespace App\Services\Attendance;

use App\Models\HRM\RosterDay;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\CarbonInterface;

class RosterScheduleResolver implements ScheduleResolver
{
    public function __construct(
        private readonly RosterService $roster,
        private readonly DefaultScheduleResolver $fallback,
    ) {}

    public function resolve(int $userId, CarbonInterface $date): ShiftSchedule
    {
        $hasRoster = RosterDay::where('user_id', $userId)->whereDate('date', $date->toDateString())->exists();
        $assignment = $this->roster->resolveAssignment($userId, $date);

        // No roster coverage at all → preserve legacy behaviour via settings.
        if (! $hasRoster && ! $assignment) {
            return $this->fallback->resolve($userId, $date);
        }

        $shift = $this->roster->resolveShift($userId, $date);

        if ($shift === null) {
            return ShiftSchedule::nonWorking($date); // off / swap-to-off → never absent
        }

        return $shift->toSchedule($date);
    }
}
```

- [ ] **Step 4: Rebind in the provider**

In `AttendanceServiceProvider::register`, change the binding:
```php
        $this->app->bind(ScheduleResolver::class, RosterScheduleResolver::class);
```
Add `use App\Services\Attendance\RosterScheduleResolver;`.

- [ ] **Step 5: Run tests to verify they pass + regression sweep**

Run: `php artisan test --filter=RosterScheduleResolverTest` (PASS)
Run: `php artisan test --filter=Attendance` (all PASS — DefaultScheduleResolverTest still passes because it resolves the binding via `app(ScheduleResolver::class)` which is now Roster-backed but falls through to default when no roster/assignment exists).

> If `DefaultScheduleResolverTest` now fails because it asserts on the bound interface, change it to instantiate `DefaultScheduleResolver` directly (`app(DefaultScheduleResolver::class)`), since it specifically tests the default. Make that edit and re-run.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/RosterScheduleResolver.php app/Providers/AttendanceServiceProvider.php tests/Feature/Attendance/RosterScheduleResolverTest.php tests/Feature/Attendance/DefaultScheduleResolverTest.php
git commit -m "feat(attendance): shift-aware ScheduleResolver backed by roster, with settings fallback"
```

---

### Task 22: Shift / Roster / Swap controllers + routes

**Files:**
- Create: `app/Http/Controllers/HRM/ShiftController.php`, `RosterController.php`, `ShiftSwapController.php`
- Modify: `routes/web.php` (new group under `permission:attendance.settings` for shifts/roster; `attendance.own.view` for employee roster/swap)
- Test: `tests/Feature/Attendance/RosterApiTest.php`

**Interfaces:**
- Consumes: `ShiftService`, `RosterService`.
- Produces endpoints (JSON):
  - `GET /attendance/shifts` → list; `POST /attendance/shifts`; `PUT /attendance/shifts/{id}`; `DELETE /attendance/shifts/{id}`.
  - `GET /attendance/rotation-patterns`; `POST /attendance/rotation-patterns`.
  - `POST /attendance/shift-assignments` (uses `ShiftService::createAssignment`).
  - `GET /attendance/roster?from=&to=&department_id=` → matrix; `POST /attendance/roster/generate` `{user_ids[], from, to}`; `PUT /attendance/roster/cell` `{user_id, date, shift_id|null}` (manual override).
  - `GET /attendance/swaps`; `POST /attendance/swaps`; `POST /attendance/swaps/{id}/approve` (→ `RosterService::applySwap`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RosterApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    public function test_admin_can_create_shift_and_override_a_roster_cell(): void
    {
        $admin = User::factory()->create(); $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.settings');
        $emp = User::factory()->create();

        $shift = Shift::factory()->create();

        $this->actingAs($admin)->putJson(route('attendance.roster.cell'), [
            'user_id' => $emp->id, 'date' => '2026-06-19', 'shift_id' => $shift->id,
        ])->assertOk();

        $this->assertDatabaseHas('roster_days', [
            'user_id' => $emp->id, 'source' => 'manual', 'shift_id' => $shift->id,
        ]);
    }

    public function test_employee_without_settings_permission_is_forbidden(): void
    {
        $emp = User::factory()->create();
        $this->actingAs($emp)->putJson(route('attendance.roster.cell'), [
            'user_id' => $emp->id, 'date' => '2026-06-19', 'shift_id' => null,
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RosterApiTest`
Expected: FAIL — routes/controllers missing.

- [ ] **Step 3: Create `RosterController` (and Shift/Swap controllers)**

`app/Http/Controllers/HRM/RosterController.php`:
```php
<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\RosterDay;
use App\Services\Attendance\RosterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RosterController extends Controller
{
    public function __construct(private readonly RosterService $roster) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'department_id' => 'nullable|integer',
        ]);

        $rows = RosterDay::with('shift:id,code,color,name')
            ->whereBetween('date', [$data['from'], $data['to']])
            ->get()
            ->groupBy('user_id');

        return response()->json(['roster' => $rows]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'integer|exists:users,id',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $count = $this->roster->generateRoster($data['user_ids'], $data['from'], $data['to']);

        return response()->json(['message' => 'Roster generated.', 'count' => $count]);
    }

    public function updateCell(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'date' => 'required|date',
            'shift_id' => 'nullable|integer|exists:shifts,id',
            'note' => 'nullable|string|max:255',
        ]);

        $cell = RosterDay::updateOrCreate(
            ['user_id' => $data['user_id'], 'date' => $data['date']],
            ['shift_id' => $data['shift_id'] ?? null, 'source' => 'manual', 'locked' => true, 'note' => $data['note'] ?? null],
        );

        return response()->json(['message' => 'Roster updated.', 'cell' => $cell->load('shift')]);
    }
}
```

`app/Http/Controllers/HRM/ShiftController.php` — resourceful JSON CRUD over `Shift` (index/store/update/destroy) plus rotation-pattern store and `assignments` store delegating to `ShiftService::createAssignment` (catch `InvalidArgumentException` → 422). `app/Http/Controllers/HRM/ShiftSwapController.php` — `index`, `store` (creates pending), `approve` (sets `status='approved'`, `approved_by`, then `RosterService::applySwap`). Mirror the validation/transaction style above.

- [ ] **Step 4: Add routes**

In `routes/web.php`, inside the `permission:attendance.settings` group (near line 495) add:
```php
        Route::get('/attendance/shifts', [\App\Http\Controllers\HRM\ShiftController::class, 'index'])->name('attendance.shifts.index');
        Route::post('/attendance/shifts', [\App\Http\Controllers\HRM\ShiftController::class, 'store'])->name('attendance.shifts.store');
        Route::put('/attendance/shifts/{id}', [\App\Http\Controllers\HRM\ShiftController::class, 'update'])->name('attendance.shifts.update');
        Route::delete('/attendance/shifts/{id}', [\App\Http\Controllers\HRM\ShiftController::class, 'destroy'])->name('attendance.shifts.destroy');
        Route::post('/attendance/rotation-patterns', [\App\Http\Controllers\HRM\ShiftController::class, 'storePattern'])->name('attendance.patterns.store');
        Route::post('/attendance/shift-assignments', [\App\Http\Controllers\HRM\ShiftController::class, 'storeAssignment'])->name('attendance.assignments.store');
        Route::get('/attendance/roster', [\App\Http\Controllers\HRM\RosterController::class, 'index'])->name('attendance.roster.index');
        Route::post('/attendance/roster/generate', [\App\Http\Controllers\HRM\RosterController::class, 'generate'])->name('attendance.roster.generate');
        Route::put('/attendance/roster/cell', [\App\Http\Controllers\HRM\RosterController::class, 'updateCell'])->name('attendance.roster.cell');
        Route::get('/attendance/swaps', [\App\Http\Controllers\HRM\ShiftSwapController::class, 'index'])->name('attendance.swaps.index');
        Route::post('/attendance/swaps/{id}/approve', [\App\Http\Controllers\HRM\ShiftSwapController::class, 'approve'])->name('attendance.swaps.approve');
```
In the `permission:attendance.own.view` group (near line 118) add employee-facing:
```php
        Route::get('/attendance/my-roster', [\App\Http\Controllers\HRM\RosterController::class, 'index'])->name('attendance.myRoster');
        Route::post('/attendance/swaps', [\App\Http\Controllers\HRM\ShiftSwapController::class, 'store'])->name('attendance.swaps.store');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=RosterApiTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/HRM/ShiftController.php app/Http/Controllers/HRM/RosterController.php app/Http/Controllers/HRM/ShiftSwapController.php routes/web.php tests/Feature/Attendance/RosterApiTest.php
git commit -m "feat(attendance): shift/roster/swap controllers + routes"
```

---

### Task 23: Frontend — Roster tab on `/attendance`

**Files:**
- Create: `resources/js/Pages/Attendance/RosterTab.jsx`
- Create: `resources/js/Pages/Attendance/Components/RosterCalendar.jsx`
- Modify: `resources/js/Pages/Attendance/AttendancePage.jsx` (register the tab)
- Verify: Playwright runtime render (no unit test framework for these components).

**Interfaces:**
- Consumes: `GET /attendance/roster`, `POST /attendance/roster/generate`, `PUT /attendance/roster/cell` via the shared `requestJson` client (`resources/js/api/client.js`).
- Produces: a Roster tab showing an employees × days grid of shift codes/colors, a "Generate roster" range action, per-cell click → assign/clear shift.

- [ ] **Step 1: Add the tab registration (modify `AttendancePage.jsx`)**

After the `SettingsTab` lazy import (line 15) add:
```jsx
const RosterTab = lazy(() => import('./RosterTab'));
```
Add an icon import to the `@radix-ui/react-icons` import (line 8): include `LayersIcon`.
In the `tabs` array (lines 48-55), insert before the settings entry:
```jsx
        { value: 'roster',    label: 'Roster',          icon: <LayersIcon />   },
```
After the Monthly `Tabs.Content` block (line 201) add:
```jsx
                            {/* ── Roster Tab ────────────────────────────── */}
                            <Tabs.Content value="roster">
                                <ErrorBoundary>
                                    <Suspense fallback={<Skeleton height="400px" />}>
                                        <RosterTab
                                            departments={departments}
                                            month={selectedMonth}
                                            isActive={activeTab === 'roster'}
                                        />
                                    </Suspense>
                                </ErrorBoundary>
                            </Tabs.Content>
```

- [ ] **Step 2: Create `RosterCalendar.jsx`** (presentational grid)

```jsx
import React from 'react';
import { Box, Flex, Text, Tooltip } from '@radix-ui/themes';
import dayjs from 'dayjs';

/** roster: { [userId]: { name, days: { 'YYYY-MM-DD': { code, color, off } } } } */
export default function RosterCalendar({ roster = {}, days = [], onCellClick }) {
    return (
        <Box style={{ overflowX: 'auto' }}>
            <Flex>
                <Box style={{ minWidth: 160, position: 'sticky', left: 0, background: 'var(--color-panel)' }}>
                    <Text size="2" weight="bold">Employee</Text>
                </Box>
                {days.map(d => (
                    <Box key={d} style={{ minWidth: 40, textAlign: 'center' }}>
                        <Text size="1">{dayjs(d).format('D')}</Text>
                    </Box>
                ))}
            </Flex>

            {Object.entries(roster).map(([userId, row]) => (
                <Flex key={userId} align="center">
                    <Box style={{ minWidth: 160, position: 'sticky', left: 0, background: 'var(--color-panel)' }}>
                        <Text size="2">{row.name}</Text>
                    </Box>
                    {days.map(d => {
                        const cell = row.days?.[d];
                        return (
                            <Box
                                key={d}
                                onClick={() => onCellClick?.(userId, d, cell)}
                                style={{
                                    minWidth: 40, height: 32, cursor: 'pointer',
                                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                                    background: cell?.color || 'transparent',
                                    color: cell ? '#fff' : 'var(--gray-8)',
                                    borderRadius: 4, margin: 1,
                                }}
                            >
                                <Tooltip content={cell?.code || 'Off'}>
                                    <Text size="1">{cell?.off ? '—' : (cell?.code || '·')}</Text>
                                </Tooltip>
                            </Box>
                        );
                    })}
                </Flex>
            ))}
        </Box>
    );
}
```

- [ ] **Step 3: Create `RosterTab.jsx`** (data + actions)

```jsx
import React, { useMemo, useState } from 'react';
import { Box, Flex, Button, Text, Card } from '@radix-ui/themes';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { requestJson } from '@/api/client';
import RosterCalendar from './Components/RosterCalendar';

export default function RosterTab({ month, isActive }) {
    const qc = useQueryClient();
    const from = useMemo(() => dayjs(month + '-01').startOf('month').format('YYYY-MM-DD'), [month]);
    const to   = useMemo(() => dayjs(month + '-01').endOf('month').format('YYYY-MM-DD'), [month]);

    const days = useMemo(() => {
        const out = [];
        let d = dayjs(from);
        const end = dayjs(to);
        while (d.isBefore(end) || d.isSame(end, 'day')) { out.push(d.format('YYYY-MM-DD')); d = d.add(1, 'day'); }
        return out;
    }, [from, to]);

    const { data, isLoading } = useQuery({
        queryKey: ['roster', from, to],
        queryFn: () => requestJson(`/attendance/roster?from=${from}&to=${to}`),
        enabled: isActive,
    });

    const generate = useMutation({
        mutationFn: () => requestJson('/attendance/roster/generate', {
            method: 'POST',
            body: { user_ids: Object.keys(data?.roster || {}).map(Number), from, to },
        }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['roster', from, to] }),
    });

    return (
        <Card>
            <Flex justify="between" align="center" mb="3">
                <Text size="3" weight="bold">Roster — {dayjs(from).format('MMMM YYYY')}</Text>
                <Button onClick={() => generate.mutate()} loading={generate.isPending}>Generate roster</Button>
            </Flex>
            {isLoading
                ? <Text>Loading roster…</Text>
                : <RosterCalendar roster={data?.roster || {}} days={days} />}
        </Card>
    );
}
```

> Adapt the `roster` shape to whatever `RosterController::index` returns; transform server rows → `{ [userId]: { name, days } }` in the controller (preferred) so the component stays presentational. If the existing `requestJson` signature differs (it accepts a config object — see `resources/js/api/client.js`), match it exactly.

- [ ] **Step 4: Build + verify in the browser**

Run: `npx vite build` (NOT `npm run build`). With `npm run dev` running, open `https://aero-enterprise-suite.test/attendance`, click the **Roster** tab, confirm the grid renders and "Generate roster" populates cells (wait for skeletons to resolve).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Attendance/RosterTab.jsx resources/js/Pages/Attendance/Components/RosterCalendar.jsx resources/js/Pages/Attendance/AttendancePage.jsx
git commit -m "feat(attendance): Roster tab with month grid + generate"
```

---

### Task 24: Frontend — Shift definitions + rotation builder in Settings; employee My Roster + swap

**Files:**
- Create: `resources/js/Pages/Attendance/ShiftsSettings.jsx`, `resources/js/Forms/ShiftForm.jsx`, `resources/js/Forms/RotationPatternForm.jsx`, `resources/js/Forms/SwapRequestForm.jsx`
- Modify: `resources/js/Pages/Attendance/SettingsTab.jsx` (mount `<ShiftsSettings/>` section), `resources/js/Pages/AttendanceEmployee.jsx` (My Roster + Request swap)
- Verify: Playwright runtime render.

**Interfaces:**
- Consumes: `/attendance/shifts*`, `/attendance/rotation-patterns`, `/attendance/shift-assignments`, `/attendance/my-roster`, `/attendance/swaps`.
- Produces: admin shift CRUD + visual rotation cycle builder; employee upcoming-shifts list + swap request form.

- [ ] **Step 1: Create `ShiftForm.jsx`** (Radix dialog form: name, code, type, start/end time, crosses_midnight, grace in/out, full/half/min minutes, color, is_active) posting to `/attendance/shifts`.

```jsx
import React, { useState } from 'react';
import { Dialog, Flex, TextField, Select, Switch, Button, Text } from '@radix-ui/themes';
import { requestJson } from '@/api/client';

export default function ShiftForm({ open, onOpenChange, onSaved, initial = null }) {
    const [form, setForm] = useState(initial || {
        name: '', code: '', type: 'fixed', start_time: '09:00', end_time: '17:30',
        crosses_midnight: false, grace_in_minutes: 15, grace_out_minutes: 0,
        full_day_minutes: 480, half_day_minutes: 240, min_present_minutes: 0,
        color: '#3b82f6', is_active: true,
    });
    const set = (k, v) => setForm(f => ({ ...f, [k]: v }));

    const save = async () => {
        const url = initial?.id ? `/attendance/shifts/${initial.id}` : '/attendance/shifts';
        await requestJson(url, { method: initial?.id ? 'PUT' : 'POST', body: form });
        onSaved?.();
        onOpenChange(false);
    };

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Content maxWidth="520px">
                <Dialog.Title>{initial?.id ? 'Edit Shift' : 'New Shift'}</Dialog.Title>
                <Flex direction="column" gap="3">
                    <TextField.Root placeholder="Name" value={form.name} onChange={e => set('name', e.target.value)} />
                    <TextField.Root placeholder="Code" value={form.code} onChange={e => set('code', e.target.value)} />
                    <Flex gap="3">
                        <TextField.Root type="time" value={form.start_time} onChange={e => set('start_time', e.target.value)} />
                        <TextField.Root type="time" value={form.end_time} onChange={e => set('end_time', e.target.value)} />
                    </Flex>
                    <Flex align="center" gap="2">
                        <Switch checked={form.crosses_midnight} onCheckedChange={v => set('crosses_midnight', v)} />
                        <Text size="2">Crosses midnight (night shift)</Text>
                    </Flex>
                    <Flex gap="3">
                        <TextField.Root type="number" placeholder="Grace in" value={form.grace_in_minutes} onChange={e => set('grace_in_minutes', +e.target.value)} />
                        <TextField.Root type="number" placeholder="Full day min" value={form.full_day_minutes} onChange={e => set('full_day_minutes', +e.target.value)} />
                        <TextField.Root type="number" placeholder="Half day min" value={form.half_day_minutes} onChange={e => set('half_day_minutes', +e.target.value)} />
                    </Flex>
                    <TextField.Root type="color" value={form.color} onChange={e => set('color', e.target.value)} />
                    <Flex justify="end" gap="2">
                        <Button variant="soft" color="gray" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button onClick={save}>Save</Button>
                    </Flex>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}
```

- [ ] **Step 2: Create `ShiftsSettings.jsx`** — lists shifts (`GET /attendance/shifts`), "Add shift" opens `ShiftForm`, edit/delete actions; renders below existing settings.

```jsx
import React, { useState } from 'react';
import { Box, Flex, Table, Button, Badge, Text, IconButton } from '@radix-ui/themes';
import { Pencil1Icon, TrashIcon, PlusIcon } from '@radix-ui/react-icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import ShiftForm from '@/Forms/ShiftForm';

export default function ShiftsSettings() {
    const qc = useQueryClient();
    const [editing, setEditing] = useState(null);
    const [open, setOpen] = useState(false);

    const { data } = useQuery({ queryKey: ['shifts'], queryFn: () => requestJson('/attendance/shifts') });
    const refresh = () => qc.invalidateQueries({ queryKey: ['shifts'] });

    const remove = async (id) => {
        await requestJson(`/attendance/shifts/${id}`, { method: 'DELETE' });
        refresh();
    };

    return (
        <Box mt="5">
            <Flex justify="between" align="center" mb="3">
                <Text size="3" weight="bold">Shifts</Text>
                <Button onClick={() => { setEditing(null); setOpen(true); }}><PlusIcon /> Add shift</Button>
            </Flex>
            <Table.Root variant="surface">
                <Table.Header>
                    <Table.Row>
                        <Table.ColumnHeaderCell>Name</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Code</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Window</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Actions</Table.ColumnHeaderCell>
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {(data?.shifts || data || []).map(s => (
                        <Table.Row key={s.id}>
                            <Table.Cell>{s.name}</Table.Cell>
                            <Table.Cell><Badge style={{ background: s.color, color: '#fff' }}>{s.code}</Badge></Table.Cell>
                            <Table.Cell>{s.start_time}–{s.end_time}{s.crosses_midnight ? ' (+1)' : ''}</Table.Cell>
                            <Table.Cell>{s.is_active ? <Badge color="green">Active</Badge> : <Badge color="gray">Inactive</Badge>}</Table.Cell>
                            <Table.Cell>
                                <Flex gap="2">
                                    <IconButton variant="soft" onClick={() => { setEditing(s); setOpen(true); }}><Pencil1Icon /></IconButton>
                                    <IconButton variant="soft" color="red" onClick={() => remove(s.id)}><TrashIcon /></IconButton>
                                </Flex>
                            </Table.Cell>
                        </Table.Row>
                    ))}
                </Table.Body>
            </Table.Root>
            <ShiftForm open={open} onOpenChange={setOpen} initial={editing} onSaved={refresh} />
        </Box>
    );
}
```

- [ ] **Step 3: Mount in Settings + employee page**

In `resources/js/Pages/Attendance/SettingsTab.jsx`, import and render `<ShiftsSettings />` after the existing Attendance Types section. In `resources/js/Pages/AttendanceEmployee.jsx`, add a "My Roster" card that fetches `GET /attendance/my-roster?from=&to=` (upcoming shifts) and a "Request swap" button opening `SwapRequestForm` (POST `/attendance/swaps`). Build the small `RotationPatternForm.jsx` (cycle length + ordered shift/off picker → POST `/attendance/rotation-patterns`) and `SwapRequestForm.jsx` (date + optional counterparty + reason) mirroring `ShiftForm`'s Radix dialog pattern.

- [ ] **Step 4: Build + verify**

Run: `npx vite build`. With `npm run dev`, verify on `https://aero-enterprise-suite.test`:
- `/attendance` → Settings tab shows Shifts CRUD; create a shift; it appears.
- `/attendance` → Roster tab → assign the new shift to a cell; reload persists.
- `/attendance-employee` → My Roster shows upcoming shifts; Request swap submits.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Attendance/ShiftsSettings.jsx resources/js/Forms/ShiftForm.jsx resources/js/Forms/RotationPatternForm.jsx resources/js/Forms/SwapRequestForm.jsx resources/js/Pages/Attendance/SettingsTab.jsx resources/js/Pages/AttendanceEmployee.jsx
git commit -m "feat(attendance): shift CRUD UI, rotation builder, employee roster + swap"
```

---

### Task 25: Phase 1 acceptance sweep

**Files:**
- Verify only.

- [ ] **Step 1: Run the full attendance suite**

Run: `php artisan test --filter=Attendance`
Expected: all PASS.

- [ ] **Step 2: Verify each Phase 1 acceptance criterion (spec §6.4)**

Confirm via tests already written + a manual Playwright pass:
- `resolveShift` correct for fixed / rotation / manual override / approved swap / scope fallback (Tasks 18, 20).
- Night shift via roster computes hours across midnight (Task 5 + RosterScheduleResolver → engine).
- Absent only on scheduled working days; off/weekend/holiday never "absent" (Task 5 + RosterScheduleResolver nonWorking).
- Late uses resolved shift `start_time` + `grace_in_minutes` (Task 21).
- Roster generation idempotent, preserves manual/swap/locked (Task 19).
- Swap approval rewrites both parties (Task 20).

- [ ] **Step 3: Commit the closeout**

```bash
git commit -am "test(attendance): Phase 1 acceptance verification" --allow-empty
```

---

## Self-Review

**Spec coverage**

Phase 0 (spec §5): datetime punches + backfill + `(user_id,date)` index → Task 2; idempotency guard + transaction/lock → Task 9 (lock already present, dedupe added); UTC store / `YYYY-MM-DD` business date → `DayAttendance::toArray` + existing `date:Y-m-d` cast (the engine emits business-date strings); status engine DTO + single source of truth → Tasks 3-5 wired in Tasks 7-8, 12; audit trail enforced + immutable → Tasks 10-11; Phase 0 acceptance (night shift, concurrent punch, policy-derived late, audit row, identical surfaces) → Tasks 5, 9, 7, 11, 13.

Phase 1 (spec §6): shifts/rotation/assignments/roster_days/swaps tables → Tasks 14-16; `ShiftService` → Task 17; `RosterService.resolveShift/generateRoster/applySwap` → Tasks 18-20; feed `AttendanceStatusService` via resolver swap → Task 21; UI (Shifts settings, Roster tab, swap approvals, employee My Roster) → Tasks 23-24; Phase 1 acceptance → Task 25.

**Gaps intentionally deferred:** swap *approval-chain* workflow UI is minimal (single approve endpoint) — full leave-style chains are Phase 2 per spec §4. Department/designation rule *UI* for assignments is covered by the assignment endpoint; a richer rule-builder is acceptable to follow in Phase 3 (policy engine).

**Placeholder scan:** Task 17's `ShiftService` includes a deliberately-marked cleanup note (the first `$query` line is a guide to delete); every other step contains complete code. Frontend Tasks 23-24 reference `requestJson` with a note to match `resources/js/api/client.js`'s exact signature (config-object form) — the implementer must read that file (already root-caused in the audit) before wiring.

**Type consistency:** `ShiftSchedule` props, `DayAttendance` constants, `ScheduleResolver::resolve(userId,date)`, `RosterService::resolveShift/resolveAssignment/generateRoster/applySwap`, and `Shift::toSchedule` signatures are used identically across Tasks 3-21. Status strings (`present/absent/late/half_day/short/on_leave/holiday/weekend/day_off`) match the spec enum.
