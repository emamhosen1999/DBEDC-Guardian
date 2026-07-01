# Roster Phase 1 — Leave/Holiday Overlay Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the roster *planning* surfaces (admin grid, employee `myRoster`, mobile roster) show approved/pending leave and company holidays as a read-only overlay, without touching the attendance engine, schedule resolver, or leave day-counting.

**Architecture:** A new `RosterOverlayService` reads two already-authoritative sources — approved/pending `leaves` and `HolidayService::forRange` — and returns a per-user/date leave map plus an org-wide holiday map. The three roster read endpoints merge it into their JSON; the React grid renders it via a pure display-resolver (holiday > approved leave > shift > off). Leave/holiday visually supersede the scheduled shift; `roster_days` is never written.

**Tech Stack:** Laravel 11 (PHP 8.2), Eloquent, PHPUnit (sqlite `:memory:` + `RefreshDatabase`); React 18 + Radix Themes + `@tanstack/react-query` v5; Vitest (pure-function tests, jsdom available, **no** `@testing-library/react`).

## Global Constraints

- **No writes to `roster_days`** in this phase — overlay is read-only.
- **Do NOT modify** `AttendanceStatusService`, `RosterScheduleResolver`, `RosterService::resolveShift`, or `LeaveDayCalculator` (making the resolver leave-aware would make leave count itself as 0 days).
- **Leave semantics** must match the attendance module: only `status IN ('approved','pending')`; `is_half_day` ⇒ fraction `0.5` with `half_day_session`; else `1.0`. Approved out-ranks pending on the same date.
- **Holidays** come only from `HolidayService::forRange` (active-only + `annual_fixed` expansion already handled there). Holiday display field is `title`.
- **Display precedence** everywhere: `holiday > approved leave > pending-leave hint > scheduled shift > off`.
- **Cell interaction:** warn, don't block — a leave/holiday cell still opens the shift popover.
- Frontend tests follow the existing **pure-function + Vitest** pattern (see `resources/js/Pages/Attendance/rosterCellConflict.js`). No new test-only dependencies.
- Do **not** run `npm run build` (it auto-commits/pushes on this machine). Verify with `npm run dev` at `https://aero-enterprise-suite.test`; the production build is the owner's step.
- Run PHP tests on sqlite; new migrations are N/A this phase (no schema change).

---

### Task 1: `RosterOverlayService`

**Files:**
- Create: `app/Services/Attendance/RosterOverlayService.php`
- Test: `tests/Feature/Attendance/RosterOverlayServiceTest.php`

**Interfaces:**
- Consumes: `App\Services\Attendance\HolidayService::forRange(CarbonInterface $from, CarbonInterface $to): Collection` (items have `from_date`, `to_date`, `title`); `App\Models\HRM\Leave` (`user_id`, `from_date`, `to_date`, `status`, `is_half_day`, `half_day_session`, `leaveSetting` relation → `LeaveSetting` with `symbol`,`type`).
- Produces:
  ```
  forRange(array $userIds, string $from, string $to): array
  // returns:
  // [
  //   'leave'    => [ (int)userId => [ 'Y-m-d' => [
  //                     'type'=>string,'fraction'=>float(1.0|0.5),
  //                     'session'=>?string,'status'=>'approved'|'pending' ] ] ],
  //   'holidays' => [ 'Y-m-d' => string(title) ],
  // ]
  ```

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Holiday;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\RosterOverlayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RosterOverlayServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeLeaveType(string $symbol = 'AL'): LeaveSetting
    {
        return LeaveSetting::create(['type' => 'Annual Leave', 'symbol' => $symbol, 'days' => 20]);
    }

    public function test_returns_approved_full_and_half_day_leave_and_holidays(): void
    {
        $svc = app(RosterOverlayService::class);
        $user = User::factory()->create();
        $type = $this->makeLeaveType('AL');

        // Full-day approved leave across two in-range days.
        Leave::create([
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-06', 'to_date' => '2026-07-07',
            'status' => 'approved', 'is_half_day' => false,
        ]);
        // Half-day approved leave (single date, PM).
        Leave::create([
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-09', 'to_date' => '2026-07-09',
            'status' => 'approved', 'is_half_day' => true, 'half_day_session' => 'second_half',
        ]);
        Holiday::create([
            'title' => 'Independence Day', 'from_date' => '2026-07-10',
            'to_date' => '2026-07-10', 'type' => 'national', 'is_active' => true,
        ]);

        $out = $svc->forRange([$user->id], '2026-07-01', '2026-07-31');

        $this->assertSame('AL', $out['leave'][$user->id]['2026-07-06']['type']);
        $this->assertSame(1.0, $out['leave'][$user->id]['2026-07-06']['fraction']);
        $this->assertSame('approved', $out['leave'][$user->id]['2026-07-07']['status']);
        $this->assertSame(0.5, $out['leave'][$user->id]['2026-07-09']['fraction']);
        $this->assertSame('second_half', $out['leave'][$user->id]['2026-07-09']['session']);
        $this->assertSame('Independence Day', $out['holidays']['2026-07-10']);
    }

    public function test_excludes_rejected_and_marks_pending_separately_with_approved_priority(): void
    {
        $svc = app(RosterOverlayService::class);
        $user = User::factory()->create();
        $type = $this->makeLeaveType('SL');

        Leave::create([ // rejected — never shown
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-06', 'to_date' => '2026-07-06',
            'status' => 'rejected', 'is_half_day' => false,
        ]);
        Leave::create([ // pending — shown as pending
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-08', 'to_date' => '2026-07-08',
            'status' => 'pending', 'is_half_day' => false,
        ]);
        // approved on the same date as a pending → approved wins.
        Leave::create([
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-12', 'to_date' => '2026-07-12',
            'status' => 'pending', 'is_half_day' => false,
        ]);
        Leave::create([
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-12', 'to_date' => '2026-07-12',
            'status' => 'approved', 'is_half_day' => false,
        ]);

        $out = $svc->forRange([$user->id], '2026-07-01', '2026-07-31');

        $this->assertArrayNotHasKey('2026-07-06', $out['leave'][$user->id] ?? []);
        $this->assertSame('pending', $out['leave'][$user->id]['2026-07-08']['status']);
        $this->assertSame('approved', $out['leave'][$user->id]['2026-07-12']['status']);
    }

    public function test_query_count_is_bounded_regardless_of_users(): void
    {
        $svc = app(RosterOverlayService::class);
        $users = User::factory()->count(5)->create();

        DB::enableQueryLog();
        $svc->forRange($users->pluck('id')->all(), '2026-07-01', '2026-07-31');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 1 leaves query + HolidayService's 1 holidays query = constant (allow small slack).
        $this->assertLessThanOrEqual(3, $count);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=RosterOverlayServiceTest`
Expected: FAIL — `Class "App\Services\Attendance\RosterOverlayService" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services\Attendance;

use App\Models\HRM\Leave;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Read-only overlay for the roster PLANNING surfaces.
 *
 * Merges two already-authoritative sources onto roster cells at read time:
 *  - approved/pending leave (leaves table), and
 *  - active holidays (HolidayService::forRange).
 *
 * It never writes roster_days and never touches the schedule resolver /
 * attendance engine. Approved leave out-ranks a same-date pending row.
 */
class RosterOverlayService
{
    public function __construct(private readonly HolidayService $holidays) {}

    /**
     * @param  int[]  $userIds
     * @return array{leave: array<int, array<string, array{type:string,fraction:float,session:?string,status:string}>>, holidays: array<string,string>}
     */
    public function forRange(array $userIds, string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        return [
            'leave' => $this->buildLeaveOverlay($userIds, $start, $end),
            'holidays' => $this->buildHolidayOverlay($start, $end),
        ];
    }

    private function buildLeaveOverlay(array $userIds, CarbonInterface $start, CarbonInterface $end): array
    {
        if (empty($userIds)) {
            return [];
        }

        $leaves = Leave::with('leaveSetting:id,type,symbol')
            ->whereIn('user_id', $userIds)
            ->whereIn('status', ['approved', 'pending'])
            ->whereDate('from_date', '<=', $end->toDateString())
            ->whereDate('to_date', '>=', $start->toDateString())
            ->get();

        $overlay = [];
        foreach ($leaves as $leave) {
            $type = $leave->leaveSetting?->symbol ?? $leave->leaveSetting?->type ?? 'Leave';
            $fraction = $leave->is_half_day ? 0.5 : 1.0;
            $session = $leave->is_half_day ? $leave->half_day_session : null;

            foreach ($this->intersectDates($leave->from_date, $leave->to_date, $start, $end) as $key) {
                // Never let a pending row overwrite an already-recorded approved one.
                if (($overlay[$leave->user_id][$key]['status'] ?? null) === 'approved') {
                    continue;
                }
                $overlay[$leave->user_id][$key] = [
                    'type' => $type,
                    'fraction' => $fraction,
                    'session' => $session,
                    'status' => $leave->status,
                ];
            }
        }

        return $overlay;
    }

    private function buildHolidayOverlay(CarbonInterface $start, CarbonInterface $end): array
    {
        $overlay = [];
        foreach ($this->holidays->forRange($start, $end->copy()->endOfDay()) as $holiday) {
            foreach ($this->intersectDates($holiday->from_date, $holiday->to_date, $start, $end) as $key) {
                $overlay[$key] = $holiday->title;
            }
        }

        return $overlay;
    }

    /**
     * Dates (Y-m-d) shared by [rangeFrom,rangeTo] and the query window [start,end].
     *
     * @return string[]
     */
    private function intersectDates($rangeFrom, $rangeTo, CarbonInterface $start, CarbonInterface $end): array
    {
        $rf = Carbon::parse($rangeFrom)->startOfDay();
        $rt = Carbon::parse($rangeTo)->startOfDay();
        $cursor = $rf->greaterThan($start) ? $rf->copy() : $start->copy();
        $limit = $rt->lessThan($end) ? $rt->copy() : $end->copy();

        $dates = [];
        for ($d = $cursor; $d->lte($limit); $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return $dates;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=RosterOverlayServiceTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/RosterOverlayService.php tests/Feature/Attendance/RosterOverlayServiceTest.php
git commit -m "feat(roster): add read-only leave/holiday overlay service"
```

---

### Task 2: Merge overlay into web `RosterController` (`index` + `myRoster`)

**Files:**
- Modify: `app/Http/Controllers/HRM/RosterController.php`
- Test: `tests/Feature/Attendance/RosterOverlayEndpointTest.php`

**Interfaces:**
- Consumes: `RosterOverlayService::forRange(...)` (Task 1).
- Produces: `GET attendance.roster.index` and `attendance.myRoster` JSON now shaped `{ roster: { <uid>: { name, days: { <date>: { code,color,off,updated_at, leave? } } } }, holidays: { <date>: title } }`. The `leave` cell key is present only on leave dates.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Holiday;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RosterOverlayEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
        Permission::firstOrCreate(['name' => 'attendance.own.view']);
    }

    public function test_index_merges_leave_onto_cell_and_holidays_top_level(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.settings');

        $emp = User::factory()->create();
        $shift = Shift::factory()->create(['code' => 'D']);
        $type = LeaveSetting::create(['type' => 'Annual Leave', 'symbol' => 'AL', 'days' => 20]);

        RosterDay::create(['user_id' => $emp->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern']);
        Leave::create([
            'user_id' => $emp->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-06', 'to_date' => '2026-07-06',
            'status' => 'approved', 'is_half_day' => false,
        ]);
        Holiday::create([
            'title' => 'Founders Day', 'from_date' => '2026-07-07', 'to_date' => '2026-07-07',
            'type' => 'company', 'is_active' => true,
        ]);

        $res = $this->actingAs($admin)->getJson(route('attendance.roster.index', [
            'from' => '2026-07-01', 'to' => '2026-07-31',
        ]))->assertOk();

        $this->assertSame('AL', $res->json("roster.{$emp->id}.days.2026-07-06.leave.type"));
        $this->assertSame('approved', $res->json("roster.{$emp->id}.days.2026-07-06.leave.status"));
        $this->assertSame('Founders Day', $res->json('holidays.2026-07-07'));
    }

    public function test_my_roster_overlay_is_scoped_to_requester(): void
    {
        $a = User::factory()->create();
        $a->assignRole('Admin');
        $a->givePermissionTo('attendance.own.view');
        $shift = Shift::factory()->create(['code' => 'D']);
        $type = LeaveSetting::create(['type' => 'Sick', 'symbol' => 'SL', 'days' => 10]);

        RosterDay::create(['user_id' => $a->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern']);
        Leave::create([
            'user_id' => $a->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-06', 'to_date' => '2026-07-06',
            'status' => 'approved', 'is_half_day' => true, 'half_day_session' => 'first_half',
        ]);

        $res = $this->actingAs($a)->getJson(route('attendance.myRoster', [
            'from' => '2026-07-01', 'to' => '2026-07-31',
        ]))->assertOk();

        $this->assertSame(0.5, $res->json("roster.{$a->id}.days.2026-07-06.leave.fraction"));
        $this->assertSame('first_half', $res->json("roster.{$a->id}.days.2026-07-06.leave.session"));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=RosterOverlayEndpointTest`
Expected: FAIL — `holidays` key absent / `leave` key null.

- [ ] **Step 3: Implement — inject the service and merge**

In `app/Http/Controllers/HRM/RosterController.php`, add the import and constructor dependency:

```php
use App\Services\Attendance\RosterOverlayService;
```

```php
    public function __construct(
        private readonly RosterService $roster,
        private readonly RealtimeSignal $signals,
        private readonly RosterOverlayService $overlay,
    ) {}
```

Add a private merge helper at the end of the class:

```php
    /**
     * Merge the leave/holiday overlay into a formatted roster payload.
     * Leave is attached per user/date cell; holidays are org-wide (top level).
     *
     * @return array{roster: array, holidays: array<string,string>}
     */
    private function withOverlay($rosterCollection, array $userIds, string $from, string $to): array
    {
        $roster = $rosterCollection->toArray();
        $overlay = $this->overlay->forRange($userIds, $from, $to);

        foreach ($overlay['leave'] as $userId => $days) {
            if (! isset($roster[$userId])) {
                continue; // only annotate users already present in the grid
            }
            foreach ($days as $date => $info) {
                if (! isset($roster[$userId]['days'][$date])) {
                    $roster[$userId]['days'][$date] = [
                        'code' => null, 'color' => null, 'off' => true, 'updated_at' => null,
                    ];
                }
                $roster[$userId]['days'][$date]['leave'] = $info;
            }
        }

        return ['roster' => $roster, 'holidays' => $overlay['holidays']];
    }
```

Change `index()`'s return (replace the final `return response()->json(['roster' => $this->formatRoster($rows)]);`):

```php
        return response()->json($this->withOverlay(
            $this->formatRoster($rows),
            $rows->pluck('user_id')->unique()->values()->all(),
            $data['from'],
            $data['to'],
        ));
```

Change `myRoster()`'s return the same way:

```php
        return response()->json($this->withOverlay(
            $this->formatRoster($rows),
            [$request->user()->id],
            $data['from'],
            $data['to'],
        ));
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=RosterOverlayEndpointTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the existing roster/scoping tests (no regression)**

Run: `php artisan test --filter="RosterApiTest|MyRosterScopingTest|RosterPayloadTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/HRM/RosterController.php tests/Feature/Attendance/RosterOverlayEndpointTest.php
git commit -m "feat(roster): merge leave/holiday overlay into web roster endpoints"
```

---

### Task 3: Merge overlay into mobile `AttendanceRequestController::myRoster`

**Files:**
- Modify: `app/Http/Controllers/Api/V1/AttendanceRequestController.php`
- Test: `tests/Feature/Api/MobileRosterOverlayTest.php`

**Interfaces:**
- Consumes: `RosterOverlayService::forRange(...)` (Task 1).
- Produces: `GET api.v1.attendance.my-roster` `{success,data}` envelope where `data` gains `holidays` and each leave date's `days.<date>` gains a `leave` key.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Api;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileRosterOverlayTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_my_roster_includes_leave_and_holidays(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $shift = Shift::factory()->create(['code' => 'N']);
        $type = LeaveSetting::create(['type' => 'Annual Leave', 'symbol' => 'AL', 'days' => 20]);

        RosterDay::create(['user_id' => $user->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern']);
        Leave::create([
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-06', 'to_date' => '2026-07-06',
            'status' => 'approved', 'is_half_day' => false,
        ]);

        $res = $this->getJson(route('api.v1.attendance.my-roster', [
            'from' => '2026-07-01', 'to' => '2026-07-31',
        ]))->assertOk();

        $this->assertTrue($res->json('success'));
        $this->assertSame('AL', $res->json('data.days.2026-07-06.leave.type'));
        $this->assertArrayHasKey('holidays', $res->json('data'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=MobileRosterOverlayTest`
Expected: FAIL — `data.days.2026-07-06.leave` is null.

- [ ] **Step 3: Implement — inject the service and merge in `myRoster`**

Add the import in `app/Http/Controllers/Api/V1/AttendanceRequestController.php`:

```php
use App\Services\Attendance\RosterOverlayService;
```

Add the dependency to the constructor (append the parameter):

```php
    public function __construct(
        private readonly RegularizationService $regularization,
        private readonly OvertimeService $overtime,
        private readonly CompOffService $compOff,
        private readonly RosterService $roster,
        private readonly RosterOverlayService $overlay,
    ) {}
```

Replace the `return $this->successResponse([... 'days' => $days ...]);` at the end of `myRoster()` with an overlay-merged version:

```php
        $overlay = $this->overlay->forRange([$request->user()->id], $data['from'], $data['to']);
        $daysArr = $days->toArray();

        foreach (($overlay['leave'][$request->user()->id] ?? []) as $date => $info) {
            if (! isset($daysArr[$date])) {
                $daysArr[$date] = [
                    'code' => null, 'name' => null, 'color' => null, 'type' => null,
                    'start' => null, 'end' => null, 'crosses_midnight' => false, 'off' => true,
                ];
            }
            $daysArr[$date]['leave'] = $info;
        }

        return $this->successResponse([
            'name' => $request->user()->name,
            'days' => $daysArr,
            'holidays' => $overlay['holidays'],
        ]);
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=MobileRosterOverlayTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/V1/AttendanceRequestController.php tests/Feature/Api/MobileRosterOverlayTest.php
git commit -m "feat(roster): include leave/holiday overlay in mobile my-roster"
```

---

### Task 4: Pure display resolver for roster cells

**Files:**
- Create: `resources/js/Pages/Attendance/rosterCellDisplay.js`
- Test: `resources/js/Pages/Attendance/__tests__/rosterCellDisplay.test.js`

**Interfaces:**
- Produces:
  ```js
  // resolveRosterCellDisplay(cell, holidayTitle) -> {
  //   kind: 'holiday'|'leave'|'leave-half'|'pending'|'shift'|'off',
  //   label: string,        // text to render in the cell
  //   color: string|null,   // background for shift/leave-half worked portion
  //   tooltip: string,
  //   leaveType: string|null,
  //   session: string|null, // 'first_half'|'second_half'|null
  // }
  // cell shape: { code, color, off, leave?: {type,fraction,session,status} }
  ```

- [ ] **Step 1: Write the failing test**

```js
import { describe, it, expect } from 'vitest';
import { resolveRosterCellDisplay } from '../rosterCellDisplay';

describe('resolveRosterCellDisplay', () => {
  it('holiday out-ranks everything', () => {
    const cell = { code: 'D', color: '#123', off: false, leave: { type: 'AL', fraction: 1, session: null, status: 'approved' } };
    const out = resolveRosterCellDisplay(cell, 'Eid');
    expect(out.kind).toBe('holiday');
    expect(out.tooltip).toContain('Eid');
  });

  it('approved full-day leave supersedes the shift', () => {
    const cell = { code: 'D', color: '#123', off: false, leave: { type: 'AL', fraction: 1, session: null, status: 'approved' } };
    const out = resolveRosterCellDisplay(cell, null);
    expect(out.kind).toBe('leave');
    expect(out.label).toBe('AL');
    expect(out.tooltip).toContain('leave');
  });

  it('approved half-day leave keeps the worked shift color', () => {
    const cell = { code: 'D', color: '#123', off: false, leave: { type: 'AL', fraction: 0.5, session: 'second_half', status: 'approved' } };
    const out = resolveRosterCellDisplay(cell, null);
    expect(out.kind).toBe('leave-half');
    expect(out.color).toBe('#123');
    expect(out.session).toBe('second_half');
  });

  it('pending leave is a hint over the shift, not a takeover', () => {
    const cell = { code: 'D', color: '#123', off: false, leave: { type: 'AL', fraction: 1, session: null, status: 'pending' } };
    const out = resolveRosterCellDisplay(cell, null);
    expect(out.kind).toBe('pending');
    expect(out.label).toBe('D');
  });

  it('plain shift and off render normally', () => {
    expect(resolveRosterCellDisplay({ code: 'N', color: '#0af', off: false }, null).kind).toBe('shift');
    expect(resolveRosterCellDisplay({ off: true }, null).kind).toBe('off');
    expect(resolveRosterCellDisplay(undefined, null).kind).toBe('off');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run resources/js/Pages/Attendance/__tests__/rosterCellDisplay.test.js`
Expected: FAIL — cannot resolve `../rosterCellDisplay`.

- [ ] **Step 3: Write the implementation**

```js
/**
 * Pure decision for how a roster cell renders, applying the overlay precedence:
 *   holiday > approved leave > pending-leave hint > scheduled shift > off.
 * Presentational only — no React, no side effects.
 *
 * cell: { code, color, off, leave?: { type, fraction, session, status } }
 */
export function resolveRosterCellDisplay(cell, holidayTitle) {
  if (holidayTitle) {
    return { kind: 'holiday', label: '', color: null, tooltip: `Holiday — ${holidayTitle}`, leaveType: null, session: null };
  }

  const leave = cell?.leave;
  if (leave && leave.status === 'approved') {
    if (leave.fraction === 0.5) {
      return {
        kind: 'leave-half',
        label: cell.code || leave.type,
        color: cell.color || null,
        tooltip: `Half-day leave (${leave.session === 'first_half' ? 'AM' : 'PM'}) — ${leave.type}`,
        leaveType: leave.type,
        session: leave.session,
      };
    }
    return { kind: 'leave', label: leave.type, color: null, tooltip: `On leave — ${leave.type}`, leaveType: leave.type, session: null };
  }

  const assigned = cell && !cell.off;
  if (leave && leave.status === 'pending' && assigned) {
    return { kind: 'pending', label: cell.code || '·', color: cell.color || null, tooltip: `${cell.code || 'Assigned'} — pending leave`, leaveType: leave.type, session: null };
  }
  if (leave && leave.status === 'pending') {
    return { kind: 'pending', label: '', color: null, tooltip: `Pending leave — ${leave.type}`, leaveType: leave.type, session: null };
  }

  if (assigned) {
    return { kind: 'shift', label: cell.code || '·', color: cell.color || null, tooltip: cell.code || 'Assigned', leaveType: null, session: null };
  }
  if (cell?.off) {
    return { kind: 'off', label: '', color: null, tooltip: 'Off', leaveType: null, session: null };
  }
  return { kind: 'off', label: '', color: null, tooltip: 'No assignment', leaveType: null, session: null };
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx vitest run resources/js/Pages/Attendance/__tests__/rosterCellDisplay.test.js`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Attendance/rosterCellDisplay.js resources/js/Pages/Attendance/__tests__/rosterCellDisplay.test.js
git commit -m "feat(roster): pure display resolver for leave/holiday cell precedence"
```

---

### Task 5: Render overlay in `RosterCalendar` + pass `holidays` through `RosterTab`

**Files:**
- Modify: `resources/js/Pages/Attendance/Components/RosterCalendar.jsx`
- Modify: `resources/js/Pages/Attendance/RosterTab.jsx`

**Interfaces:**
- Consumes: `resolveRosterCellDisplay(cell, holidayTitle)` (Task 4); `data.holidays` from the index endpoint (Task 2).
- Produces: `RosterCalendar` accepts a new `holidays = {}` prop (`{ 'Y-m-d': title }`) and renders holiday/leave/pending states.

- [ ] **Step 1: Pass `holidays` from `RosterTab` into `RosterCalendar`**

In `resources/js/Pages/Attendance/RosterTab.jsx`, add near the other derived values (after `const roster = data?.roster || {};`):

```jsx
    const holidays = data?.holidays || {};
```

Update the `<RosterCalendar ... />` usage to pass it:

```jsx
                        <RosterCalendar
                            roster={Object.fromEntries(rows)}
                            days={days}
                            holidays={holidays}
                            onCellClick={handleCellClick}
                        />
```

- [ ] **Step 2: Render the overlay in `RosterCalendar`**

In `resources/js/Pages/Attendance/Components/RosterCalendar.jsx`, add the import at the top:

```jsx
import { resolveRosterCellDisplay } from '../rosterCellDisplay';
```

Change the signature to accept `holidays`:

```jsx
export default function RosterCalendar({ roster = {}, days = [], holidays = {}, onCellClick }) {
```

In the **day header** map, tint holiday columns — replace the header `background` line so it also reacts to holidays:

```jsx
                                    background: holidays[d] ? 'var(--amber-a3)' : (weekend ? 'var(--gray-a3)' : 'transparent'),
```

Replace the **cell body** block (the inner `Tooltip`/`Box` that currently renders `assigned ? ...`) with a display-driven version:

```jsx
                        {days.map((d) => {
                            const cell = row.days?.[d];
                            const disp = resolveRosterCellDisplay(cell, holidays[d]);
                            return (
                                <Box
                                    key={d}
                                    onClick={() => onCellClick?.(userId, d, cell)}
                                    style={{
                                        width: CELL_W, minWidth: CELL_W, height: ROW_H,
                                        borderRight: LINE, borderBottom: LINE, padding: 4,
                                        boxSizing: 'border-box',
                                        background: disp.kind === 'holiday' ? 'var(--amber-a2)' : 'transparent',
                                        cursor: onCellClick ? 'pointer' : 'default',
                                    }}
                                >
                                    <Tooltip content={disp.tooltip}>
                                        <Box
                                            style={{
                                                width: '100%', height: '100%', borderRadius: 4,
                                                display: 'flex', alignItems: 'center', justifyContent: 'center',
                                                background:
                                                    disp.kind === 'leave' ? 'var(--amber-9)'
                                                    : disp.kind === 'leave-half' ? `linear-gradient(135deg, ${disp.color || 'var(--accent-9)'} 50%, var(--amber-9) 50%)`
                                                    : (disp.kind === 'shift' || disp.kind === 'pending') ? (disp.color || 'var(--accent-9)')
                                                    : 'transparent',
                                                border:
                                                    disp.kind === 'off' ? '1px dashed var(--gray-a6)'
                                                    : disp.kind === 'pending' ? '1px dashed var(--amber-8)'
                                                    : 'none',
                                                color: (disp.kind === 'shift' || disp.kind === 'pending' || disp.kind === 'leave' || disp.kind === 'leave-half') ? '#fff' : 'var(--gray-8)',
                                                fontSize: 9, fontWeight: 700, letterSpacing: 0.2,
                                            }}
                                        >
                                            {disp.label}
                                        </Box>
                                    </Tooltip>
                                </Box>
                            );
                        })}
```

- [ ] **Step 3: Re-run the frontend suite (no regression)**

Run: `npx vitest run resources/js/Pages/Attendance`
Expected: PASS (existing `RosterTab.conflict`, `timesheetPatch`, `logRange` + new `rosterCellDisplay`).

- [ ] **Step 4: Verify visually with the dev server**

Run: `npm run dev` (leave running), open `https://aero-enterprise-suite.test`, go to Attendance → Roster for a month where a test employee has approved leave + a holiday exists. Confirm: holiday column tinted amber, full-leave cell solid amber with leave code, half-day split cell, pending leave dashed-amber over the shift.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Attendance/Components/RosterCalendar.jsx resources/js/Pages/Attendance/RosterTab.jsx
git commit -m "feat(roster): render leave/holiday overlay in the roster grid"
```

---

### Task 6: Warn-not-block banner when editing a leave/holiday cell

**Files:**
- Modify: `resources/js/Pages/Attendance/RosterTab.jsx`
- Modify: `resources/js/Pages/Attendance/Components/RosterCellPopover.jsx`

**Interfaces:**
- Consumes: the clicked cell + `holidays` map already in `RosterTab`.
- Produces: `RosterCellPopover` accepts an optional `notice = null` (string) and renders it as a callout banner above the shift options. No behavioral block — picking a shift still works.

- [ ] **Step 1: Compute the notice in `RosterTab` and pass it to the popover**

In `resources/js/Pages/Attendance/RosterTab.jsx`, derive a notice for the selected cell (add after `const holidays = data?.holidays || {};`):

```jsx
    const selectedNotice = useMemo(() => {
        if (!selectedCell) return null;
        const { userId, date } = selectedCell;
        if (holidays[date]) return `Company holiday — ${holidays[date]}`;
        const lv = roster?.[userId]?.days?.[date]?.leave;
        if (lv?.status === 'approved') return `On approved leave — ${lv.type}`;
        if (lv?.status === 'pending') return `Has a pending leave request — ${lv.type}`;
        return null;
    }, [selectedCell, holidays, roster]);
```

Pass it into the popover:

```jsx
                        <RosterCellPopover
                            open={popoverOpen}
                            onOpenChange={(o) => { if (!o) setPopoverOpen(false); }}
                            anchor={<span />}
                            shifts={shifts}
                            notice={selectedNotice}
                            onPick={handlePick}
                        />
```

- [ ] **Step 2: Render the banner in `RosterCellPopover`**

Open `resources/js/Pages/Attendance/Components/RosterCellPopover.jsx`. Add `notice = null` to the component's props, import `Callout` from `@radix-ui/themes` (extend the existing import), and render the callout at the top of the popover content when `notice` is set:

```jsx
{notice && (
    <Callout.Root color="amber" size="1" mb="2">
        <Callout.Text>{notice}</Callout.Text>
    </Callout.Root>
)}
```

(If `Callout` is not already imported, change the existing `import { ... } from '@radix-ui/themes';` line to include `Callout`.)

- [ ] **Step 3: Verify visually with the dev server**

With `npm run dev` running at `https://aero-enterprise-suite.test`, click a cell that is on approved leave and one on a holiday. Confirm the amber banner shows in the popover and that picking a shift still updates the cell (no block).

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Attendance/RosterTab.jsx resources/js/Pages/Attendance/Components/RosterCellPopover.jsx
git commit -m "feat(roster): warn (not block) when scheduling over leave/holiday"
```

---

### Task 7: Full-suite verification

**Files:** none (verification only).

- [ ] **Step 1: Run the roster/attendance backend suites**

Run: `php artisan test --filter="Roster|Attendance"`
Expected: PASS, no new failures. (Pre-existing allowed failures per the leaves-holidays spec: `MobileSyncApiTest > sync push applies leave apply mutation`, `NavigationRoutesTest > any authenticated user can access organization directory` — only these two may remain red.)

- [ ] **Step 2: Run the full frontend unit suite**

Run: `npx vitest run`
Expected: PASS.

- [ ] **Step 3: Confirm the engine/resolver were untouched**

Run: `git diff --name-only main...HEAD`
Expected: the list contains only the overlay service, the two spec/plan docs, the three controllers, and the four frontend files — **not** `AttendanceStatusService.php`, `RosterScheduleResolver.php`, `RosterService.php`, or `LeaveDayCalculator.php`.

- [ ] **Step 4: Final notes**

Do NOT run `npm run build` here (it auto-commits/pushes on this machine). The production asset build + deploy is the owner's step. No DB migration is required for this phase (pure read overlay), so nothing to run on prod MySQL `dbedc_guardian`.

---

## Self-Review (completed during authoring)

- **Spec coverage:** overlay service (Task 1) ✓; web index+myRoster merge (Task 2) ✓; mobile parity (Task 3) ✓; grid rendering with precedence holiday>leave>pending>shift>off (Tasks 4–5) ✓; warn-not-block (Task 6) ✓; holiday inclusion via `HolidayService::forRange` ✓; pending-as-hint ✓; half-day split ✓; no engine/resolver/day-count changes (Global Constraints + Task 7 Step 3) ✓; no `roster_days` writes ✓; N+1 guard (Task 1 test) ✓.
- **Placeholder scan:** none — every code step is complete.
- **Type consistency:** `forRange(array,string,string): {leave,holidays}` used identically in Tasks 1–3; cell `leave` shape `{type,fraction,session,status}` consistent across backend payload and `resolveRosterCellDisplay`; `holidays` map `{date:title}` consistent from Task 2 → Task 5.
