# Roster, Upcoming-Shift & Avatar Refactor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make "upcoming shift" mean `now → now+12h` everywhere, order every shift list by start time, show real avatars wherever a user appears, port the web roster grid to the mobile app, and add a big employee hero card to the mobile dashboard.

**Architecture:** All shift/roster logic that currently exists twice (once in the web controller, once in the mobile API controller) is collapsed into shared Laravel services. The mobile app gets two new read endpoints (`/api/v1/attendance/roster`, `/api/v1/attendance/shifts`) that mirror the web payloads exactly, so the ported React Native roster components can consume the same data shape as the web React components with no reshaping.

**Tech Stack:** Laravel 11 + PHPUnit (Guardian), React 18 + Inertia + Radix Themes (Guardian web), Expo / React Native + Tamagui + expo-router (mobile).

## Repos & paths

| Repo | Absolute path |
|---|---|
| Guardian (backend + web) | `c:\laragon\www\DBEDC-Guardian` |
| Mobile app | `c:\laragon\www\dbedc-mobile-app` |

Spec: `docs/superpowers/specs/2026-07-14-roster-shift-avatar-refactor-design.md` (Guardian repo).

## Global Constraints

- **Guardian tests:** `php artisan test --filter=<TestName>` from the Guardian root. Every backend task ends with green tests.
- **No new dependencies** in either repo.
- **Mobile has no test harness** (known debt). Mobile tasks are verified by reading the rendered screen — a task is done when the screen renders with real data from a running Guardian.
- **Shift ordering key** is always: shift start clock time ascending (`00:00` → `23:59`), then employee name. A midnight-crossing shift sorts by its **start**, never its end. Users with no shift sort last.
- **Upcoming window** is always `[now, now + 12h]` on today; the whole day on a future date; nothing on a past date.
- **Commits:** author is Emam Hosen. Never add a `Co-Authored-By: Claude` trailer.
- Existing behaviour not named in this plan must not change. `RosterApiTest`, `MyRosterScopingTest`, `RosterPayloadTest`, `RosterResolveShiftTest` must stay green throughout.

## File structure

**Guardian — create:**
- `app/Http/Controllers/Api/V1/Concerns/ResolvesTeamMembers.php` — the team-tree walk, currently triplicated.
- `app/Services/Attendance/UpcomingShiftService.php` — the one definition of "upcoming", plus the shift-start sort and the shift-decoration helpers.
- `app/Http/Controllers/Api/V1/RosterController.php` — the mobile roster + shifts read endpoints.
- `tests/Feature/Attendance/UpcomingShiftServiceTest.php`
- `tests/Feature/Attendance/MobileRosterApiTest.php`

**Guardian — modify:**
- `app/Http/Controllers/AttendanceController.php` (web absent/present endpoints)
- `app/Http/Controllers/Api/V1/AttendanceController.php` (mobile absent/present/today endpoints; drop private helpers → trait)
- `app/Http/Controllers/Api/V1/AttendanceRequestController.php` (drop private helpers → trait)
- `app/Http/Controllers/Api/V1/ManagerDashboardController.php` (drop private helpers → trait; add avatar to team-members)
- `app/Http/Controllers/HRM/RosterController.php` (avatar in roster payload)
- `app/Http/Controllers/HRM/ShiftController.php` (catalog ordering)
- `app/Services/Attendance/AttendanceQueryService.php` (present-users: avatar + shift)
- `routes/api.php`
- `resources/js/Pages/Attendance/AbsentSidebar.jsx`
- `resources/js/Pages/Attendance/Components/RosterCalendar.jsx`

**Mobile — create:**
- `components/ui/Avatar.js` — the one avatar implementation.
- `components/roster/RosterLegend.js`
- `components/roster/RosterGrid.js` — the 24-hour-segment grid (port of web `RosterCalendar.jsx`).
- `components/roster/RosterMonthCalendar.js` — per-employee month view (port of web `RosterEmployeeView.jsx`).
- `components/dashboard/EmployeeHeroCard.js`

**Mobile — modify:**
- `components/ui/index.js`, `src/api/attendance.js`, `src/api/managerApi.js`
- `app/(tabs)/team-roster.js` (rebuild), `app/(tabs)/my-roster.js`, `app/(tabs)/team-attendance.js`
- `app/(tabs)/leave-approvals.js`, `overtime-approvals.js`, `regularization-approvals.js`, `swap-approvals.js`, `swap-request.js`
- `components/attendance/PunchStatusCardMobile.js`, `components/dashboard/DashboardView.js`, `app/(tabs)/index.js`

---

## Task 1: Extract the team-member resolution trait

`isManagerUser()`, `isAdminLikeUser()`, `resolveTeamMemberIds()` and `collectDescendantIds()` are byte-identical private methods in three Api/V1 controllers. Task 5 adds a fourth consumer, so extract them first.

**Files:**
- Create: `app/Http/Controllers/Api/V1/Concerns/ResolvesTeamMembers.php`
- Modify: `app/Http/Controllers/Api/V1/AttendanceController.php:1286-1350`
- Modify: `app/Http/Controllers/Api/V1/AttendanceRequestController.php:544-600`
- Modify: `app/Http/Controllers/Api/V1/ManagerDashboardController.php:430-470,597-618`

**Interfaces:**
- Produces: trait `App\Http\Controllers\Api\V1\Concerns\ResolvesTeamMembers` with `isManagerUser(User $user): bool`, `isAdminLikeUser(User $user): bool`, `resolveTeamMemberIds(User $user): array` (returns descendant user IDs, **not** including the manager themselves), `collectDescendantIds(int $rootId, int $maxDepth = 10): array`.

- [ ] **Step 1: Create the trait**

```php
<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\User;

/**
 * Resolves a manager's team from the `report_to` reporting tree.
 * Extracted from three Api/V1 controllers that each held an identical copy.
 */
trait ResolvesTeamMembers
{
    protected function isManagerUser(User $user): bool
    {
        return $user->hasRole([
            'Super Admin',
            'Admin',
            'HR Manager',
            'Project Manager',
            'Consultant',
            'Super Administrator',
            'Administrator',
        ]);
    }

    protected function isAdminLikeUser(User $user): bool
    {
        return $user->hasRole([
            'Super Admin',
            'Admin',
            'HR Manager',
            'Super Administrator',
            'Administrator',
        ]);
    }

    /**
     * A manager sees their direct reports AND everyone below them.
     * The manager's own id is NOT included.
     *
     * @return array<int, int>
     */
    protected function resolveTeamMemberIds(User $user): array
    {
        return $this->collectDescendantIds($user->id);
    }

    /**
     * Walk the report_to hierarchy and collect all descendant user IDs.
     * Depth-capped at 10 levels and 500 users to guard against circular
     * references and runaway queries in very large orgs.
     *
     * @return array<int, int>
     */
    protected function collectDescendantIds(int $rootId, int $maxDepth = 10): array
    {
        $collected = [];
        $currentLevelIds = [$rootId];
        $visited = [$rootId => true];

        for ($depth = 0; $depth < $maxDepth; $depth++) {
            $children = User::query()
                ->whereNull('deleted_at')
                ->whereIn('report_to', $currentLevelIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => ! isset($visited[$id]))
                ->values()
                ->all();

            if ($children === []) {
                break;
            }

            foreach ($children as $childId) {
                $visited[$childId] = true;
                $collected[] = $childId;
            }

            $currentLevelIds = $children;

            if (count($collected) >= 500) {
                break;
            }
        }

        return $collected;
    }
}
```

- [ ] **Step 2: Use the trait in the three controllers**

In each of `Api/V1/AttendanceController.php`, `Api/V1/AttendanceRequestController.php`, `Api/V1/ManagerDashboardController.php`:

1. Add the import below the existing `use` block:

```php
use App\Http\Controllers\Api\V1\Concerns\ResolvesTeamMembers;
```

2. Add the trait inside the class body, as the first line of the class:

```php
class AttendanceController extends Controller
{
    use ResolvesTeamMembers;
```

3. **Delete** the now-duplicated private methods from that class: `isManagerUser`, `isAdminLikeUser`, `resolveTeamMemberIds`, `collectDescendantIds`. Leave every other private method untouched.

- [ ] **Step 3: Run the mobile API test suite to prove nothing broke**

Run: `php artisan test --filter=Attendance`
Expected: PASS — the same set of tests that passed before the refactor.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Api/V1/Concerns/ResolvesTeamMembers.php app/Http/Controllers/Api/V1/AttendanceController.php app/Http/Controllers/Api/V1/AttendanceRequestController.php app/Http/Controllers/Api/V1/ManagerDashboardController.php
git commit -m "refactor(api): extract triplicated team-member resolution into ResolvesTeamMembers trait"
```

---

## Task 2: UpcomingShiftService — the one definition of "upcoming"

**Files:**
- Create: `app/Services/Attendance/UpcomingShiftService.php`
- Test: `tests/Feature/Attendance/UpcomingShiftServiceTest.php`

**Interfaces:**
- Consumes: `App\Services\Attendance\Contracts\ScheduleResolver` (`resolve(int $userId, CarbonInterface $date): ShiftSchedule` — the DTO exposes `->start` (Carbon), `->end` (Carbon), `->isWorkingDay` (bool)); `App\Services\Attendance\RosterService` (`resolveShift(int $userId, CarbonInterface $date): ?Shift`).
- Produces:
  - `isVisibleFor(CarbonInterface $date): bool` — false when `$date` is in the past.
  - `forDate(CarbonInterface $date, Collection $users): Collection` — the decorated, sorted upcoming users.
  - `partition(CarbonInterface $date, Collection $allUsers, Collection $absentUsers): array` — returns `['upcoming' => Collection, 'absent' => Collection, 'off' => Collection]`, each decorated and sorted. **This is the method both controllers call** — the whole upcoming/absent/off split lives here exactly once.
  - `decorate(User $user, CarbonInterface $shiftDate, ShiftSchedule $schedule): User` — clones the user and attaches `shift_code`, `shift_name`, `shift_color`, `shift_start`, `shift_end`, `shift_start_time`, `shift_start_minutes`.
  - `sortByShiftStart(Collection $users): Collection` — sorts by `shift_start_minutes` then `name`; users with a null `shift_start_minutes` sort last.

The decorated attribute names must match exactly — the existing serializers in both `AttendanceController`s already read `shift_code` / `shift_name` / `shift_color` / `shift_start` / `shift_end` / `shift_start_time`, and Task 3 and Task 4 keep those serializers.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Attendance/UpcomingShiftServiceTest.php`:

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Services\Attendance\UpcomingShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UpcomingShiftServiceTest extends TestCase
{
    use RefreshDatabase;

    private function shift(string $code, string $start, string $end, bool $crosses = false): Shift
    {
        return Shift::factory()->create([
            'code' => $code,
            'name' => $code.' shift',
            'start_time' => $start,
            'end_time' => $end,
            'crosses_midnight' => $crosses,
            'color' => '#123456',
        ]);
    }

    private function roster(User $user, Shift $shift, string $date): void
    {
        RosterDay::create([
            'user_id' => $user->id,
            'date' => $date,
            'shift_id' => $shift->id,
            'source' => 'manual',
        ]);
    }

    public function test_today_returns_only_shifts_starting_within_twelve_hours(): void
    {
        Carbon::setTestNow('2026-07-14 09:00:00');

        $inWindow = User::factory()->create(['name' => 'In Window']);
        $outOfWindow = User::factory()->create(['name' => 'Out Of Window']);

        // 16:00 start is 7h away -> in the 12h window.
        $this->roster($inWindow, $this->shift('E', '16:00', '00:00', true), '2026-07-14');
        // 08:00 start already began -> not upcoming.
        $this->roster($outOfWindow, $this->shift('M', '08:00', '16:00'), '2026-07-14');

        $result = app(UpcomingShiftService::class)
            ->forDate(Carbon::now(), User::query()->get());

        $this->assertSame(['In Window'], $result->pluck('name')->all());
    }

    public function test_today_window_crosses_midnight_into_tomorrow(): void
    {
        Carbon::setTestNow('2026-07-14 22:00:00');

        $tomorrowNight = User::factory()->create(['name' => 'Night Owl']);
        // 00:00 start tomorrow is 2h away -> in the 12h window even though it is a different date.
        $this->roster($tomorrowNight, $this->shift('N', '00:00', '08:00'), '2026-07-15');

        $result = app(UpcomingShiftService::class)
            ->forDate(Carbon::now(), User::query()->get());

        $this->assertSame(['Night Owl'], $result->pluck('name')->all());
        $this->assertSame('N', $result->first()->shift_code);
    }

    public function test_results_are_sorted_by_shift_start_ascending(): void
    {
        Carbon::setTestNow('2026-07-14 23:30:00');

        $night = User::factory()->create(['name' => 'Night']);
        $morning = User::factory()->create(['name' => 'Morning']);

        // Both start tomorrow, inside the 12h window: 00:00 and 08:00.
        $this->roster($night, $this->shift('N', '00:00', '08:00'), '2026-07-15');
        $this->roster($morning, $this->shift('M', '08:00', '16:00'), '2026-07-15');

        $result = app(UpcomingShiftService::class)
            ->forDate(Carbon::now(), User::query()->get());

        $this->assertSame(['N', 'M'], $result->pluck('shift_code')->all());
    }

    public function test_future_date_returns_every_working_shift_that_day(): void
    {
        Carbon::setTestNow('2026-07-14 09:00:00');

        $user = User::factory()->create(['name' => 'Future Worker']);
        // A shift far outside any 12h window from now, but the whole day is ahead.
        $this->roster($user, $this->shift('E', '16:00', '00:00', true), '2026-07-20');

        $result = app(UpcomingShiftService::class)
            ->forDate(Carbon::parse('2026-07-20'), User::query()->get());

        $this->assertSame(['Future Worker'], $result->pluck('name')->all());
    }

    public function test_past_date_is_not_visible_and_returns_nothing(): void
    {
        Carbon::setTestNow('2026-07-14 09:00:00');

        $user = User::factory()->create(['name' => 'Yesterday']);
        $this->roster($user, $this->shift('M', '08:00', '16:00'), '2026-07-13');

        $service = app(UpcomingShiftService::class);
        $past = Carbon::parse('2026-07-13');

        $this->assertFalse($service->isVisibleFor($past));
        $this->assertTrue($service->forDate($past, User::query()->get())->isEmpty());
    }

    public function test_partition_splits_upcoming_absent_and_off(): void
    {
        Carbon::setTestNow('2026-07-14 09:00:00');

        // Shift starts at 16:00 -> inside the 12h window -> upcoming, not absent.
        $upcoming = User::factory()->create(['name' => 'Upcoming Person']);
        $this->roster($upcoming, $this->shift('E', '16:00', '23:59'), '2026-07-14');

        // Shift started at 08:00 and they never punched in -> absent.
        $absent = User::factory()->create(['name' => 'Absent Person']);
        $this->roster($absent, $this->shift('M', '08:00', '16:00'), '2026-07-14');

        // No shift rostered today -> off.
        $off = User::factory()->create(['name' => 'Off Person']);

        $all = collect([$upcoming, $absent, $off]);

        // Nobody punched in, so every user is in the not-present set.
        $result = app(UpcomingShiftService::class)->partition(Carbon::now(), $all, $all);

        $this->assertSame(['Upcoming Person'], $result['upcoming']->pluck('name')->all());
        $this->assertSame(['Absent Person'], $result['absent']->pluck('name')->all());
        $this->assertSame(['Off Person'], $result['off']->pluck('name')->all());
        $this->assertSame('M', $result['absent']->first()->shift_code);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=UpcomingShiftServiceTest`
Expected: FAIL — `Target class [App\Services\Attendance\UpcomingShiftService] does not exist.`

- [ ] **Step 3: Write the service**

Create `app/Services/Attendance/UpcomingShiftService.php`:

```php
<?php

namespace App\Services\Attendance;

use App\Models\User;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The single definition of "upcoming shift", shared by the web attendance page
 * and the mobile team-attendance screen.
 *
 *  - today   → shifts starting in [now, now + 12h]; the window crosses midnight,
 *              so at 22:00 tomorrow's 00-08 shift is upcoming.
 *  - future  → every working shift on that date (the whole day is ahead).
 *  - past    → nothing at all; the section is hidden, not rendered empty.
 */
class UpcomingShiftService
{
    public const WINDOW_HOURS = 12;

    public function __construct(
        private readonly ScheduleResolver $schedules,
        private readonly RosterService $roster,
    ) {}

    /**
     * Is an Upcoming section meaningful for this date? False for past dates.
     */
    public function isVisibleFor(CarbonInterface $date): bool
    {
        return ! $date->copy()->startOfDay()->lt(Carbon::now()->startOfDay());
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>  decorated + sorted by shift start
     */
    public function forDate(CarbonInterface $date, Collection $users): Collection
    {
        if (! $this->isVisibleFor($date)) {
            return collect();
        }

        return $date->copy()->startOfDay()->isToday()
            ? $this->forToday($users)
            : $this->forFutureDate($date, $users);
    }

    /**
     * The whole upcoming / absent / off split, in one place. Both the web
     * attendance page and the mobile team-attendance screen call this — the
     * logic used to live twice and drifted.
     *
     * @param  Collection<int, User>  $allUsers      every employee in scope
     * @param  Collection<int, User>  $absentUsers   those in scope with no punch-in for the date
     * @return array{upcoming: Collection<int, User>, absent: Collection<int, User>, off: Collection<int, User>}
     */
    public function partition(CarbonInterface $date, Collection $allUsers, Collection $absentUsers): array
    {
        $upcoming = $this->forDate($date, $allUsers);
        $upcomingIds = $upcoming->pluck('id')->all();

        $absent = collect();
        $off = collect();

        foreach ($absentUsers as $user) {
            // A user whose shift has not started yet belongs to Upcoming, not Absent.
            if (in_array($user->id, $upcomingIds, true)) {
                continue;
            }

            $schedule = $this->schedules->resolve($user->id, $date);

            if (! $schedule->isWorkingDay) {
                $off->push($user);

                continue;
            }

            // Working day, not upcoming → the shift already started (or the date is
            // in the past) and the user never punched in.
            $absent->push($this->decorate($user, $date, $schedule));
        }

        return [
            'upcoming' => $upcoming,
            'absent' => $this->sortByShiftStart($absent),
            'off' => $off->sortBy(fn (User $user) => (string) $user->name)->values(),
        ];
    }

    /**
     * Shifts starting inside [now, now + 12h]. Today's shift is checked first,
     * then tomorrow's — that second check is what lets a late-evening window
     * reach across midnight.
     */
    private function forToday(Collection $users): Collection
    {
        $now = Carbon::now();
        $windowEnd = $now->copy()->addHours(self::WINDOW_HOURS);
        $tomorrow = $now->copy()->addDay();

        $upcoming = $users->map(function (User $user) use ($now, $windowEnd, $tomorrow): ?User {
            foreach ([$now, $tomorrow] as $candidateDate) {
                $schedule = $this->schedules->resolve($user->id, $candidateDate);

                if ($this->startsInWindow($schedule, $now, $windowEnd)) {
                    return $this->decorate($user, $candidateDate, $schedule);
                }
            }

            return null;
        })->filter()->values();

        return $this->sortByShiftStart($upcoming);
    }

    private function forFutureDate(CarbonInterface $date, Collection $users): Collection
    {
        $upcoming = $users->map(function (User $user) use ($date): ?User {
            $schedule = $this->schedules->resolve($user->id, $date);

            return $schedule->isWorkingDay ? $this->decorate($user, $date, $schedule) : null;
        })->filter()->values();

        return $this->sortByShiftStart($upcoming);
    }

    private function startsInWindow(ShiftSchedule $schedule, Carbon $now, Carbon $windowEnd): bool
    {
        return $schedule->isWorkingDay
            && $schedule->start->gte($now)
            && $schedule->start->lte($windowEnd);
    }

    /**
     * Clone the user and attach the shift attributes the serializers read.
     * The roster shift (code/name/colour) is preferred; the resolved schedule
     * is the fallback when no Shift row backs the day.
     */
    public function decorate(User $user, CarbonInterface $shiftDate, ShiftSchedule $schedule): User
    {
        $decorated = clone $user;
        $shift = $this->roster->resolveShift($user->id, $shiftDate);

        if ($shift) {
            $start = Carbon::parse($shift->start_time);
            $decorated->shift_code = $shift->code;
            $decorated->shift_name = $shift->name;
            $decorated->shift_color = $shift->color;
            $decorated->shift_start = $start->format('g:i A');
            $decorated->shift_end = Carbon::parse($shift->end_time)->format('g:i A');
            $decorated->shift_start_minutes = $start->hour * 60 + $start->minute;
        } else {
            $decorated->shift_code = null;
            $decorated->shift_name = null;
            $decorated->shift_color = null;
            $decorated->shift_start = $schedule->start->format('g:i A');
            $decorated->shift_end = $schedule->end->format('g:i A');
            $decorated->shift_start_minutes = $schedule->start->hour * 60 + $schedule->start->minute;
        }

        $decorated->shift_start_time = $decorated->shift_start;

        return $decorated;
    }

    /**
     * Shift start clock time ascending (00-08 → 08-16 → 16-24), then name.
     * A midnight-crossing shift sorts by its START. Users with no resolvable
     * shift sort last.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    public function sortByShiftStart(Collection $users): Collection
    {
        return $users
            ->sortBy([
                fn (User $user) => $user->shift_start_minutes ?? PHP_INT_MAX,
                fn (User $user) => (string) $user->name,
            ])
            ->values();
    }
}
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `php artisan test --filter=UpcomingShiftServiceTest`
Expected: PASS — 5 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/UpcomingShiftService.php tests/Feature/Attendance/UpcomingShiftServiceTest.php
git commit -m "feat(attendance): add UpcomingShiftService — 12h window, date-aware visibility, shift-start ordering"
```

---

## Task 3: Web attendance page consumes the service

**Files:**
- Modify: `app/Http/Controllers/AttendanceController.php:541-819` (`getAbsentUsersForDate`)
- Modify: `resources/js/Pages/Attendance/AbsentSidebar.jsx:37,195,282`

**Interfaces:**
- Consumes: `UpcomingShiftService::forDate()`, `::isVisibleFor()`, `::decorate()`, `::sortByShiftStart()` from Task 2.
- Produces: the absent-users JSON gains `upcoming_visible` (bool). All existing keys (`absent_users`, `off_users`, `upcoming_users`, `leaves`, `total_absent`, `total_off`, `total_upcoming`) keep their shape.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Attendance/AbsentUsersUpcomingTest.php`:

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AbsentUsersUpcomingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
    }

    private function employee(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole('Employee');

        return $user;
    }

    private function roster(User $user, string $code, string $start, string $end, string $date): void
    {
        $shift = Shift::factory()->create([
            'code' => $code, 'name' => $code, 'start_time' => $start, 'end_time' => $end,
        ]);

        RosterDay::create([
            'user_id' => $user->id, 'date' => $date, 'shift_id' => $shift->id, 'source' => 'manual',
        ]);
    }

    public function test_upcoming_is_sorted_by_shift_start_and_visible_today(): void
    {
        Carbon::setTestNow('2026-07-14 07:00:00');

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        $evening = $this->employee('Evening Person');
        $morning = $this->employee('Morning Person');

        $this->roster($evening, 'E', '16:00', '23:59', '2026-07-14');
        $this->roster($morning, 'M', '08:00', '16:00', '2026-07-14');

        $response = $this->actingAs($admin)
            ->getJson(route('attendance.absentUsers', ['date' => '2026-07-14']))
            ->assertOk();

        $response->assertJsonPath('upcoming_visible', true);
        $this->assertSame(
            ['M', 'E'],
            collect($response->json('upcoming_users'))->pluck('shift_code')->all()
        );
    }

    public function test_upcoming_is_hidden_and_empty_on_a_past_date(): void
    {
        Carbon::setTestNow('2026-07-14 07:00:00');

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        $this->roster($this->employee('Yesterday Person'), 'M', '08:00', '16:00', '2026-07-13');

        $this->actingAs($admin)
            ->getJson(route('attendance.absentUsers', ['date' => '2026-07-13']))
            ->assertOk()
            ->assertJsonPath('upcoming_visible', false)
            ->assertJsonPath('upcoming_users', []);
    }
}
```

Before running, confirm the route name: `php artisan route:list --path=absent`. If the name is not `attendance.absentUsers`, use the printed name in the test.

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=AbsentUsersUpcomingTest`
Expected: FAIL — `upcoming_visible` is missing from the payload, and the upcoming order is database order, not `['M', 'E']`.

- [ ] **Step 3: Rewrite the controller block**

In `app/Http/Controllers/AttendanceController.php`, inject the service into the constructor (add the parameter to the existing constructor's promoted properties):

```php
use App\Services\Attendance\UpcomingShiftService;
```

Then replace **everything** from `$scheduleResolver = app(...)` (the line after `$absentUsers = $allUsers->filter(...)->values();`) through the end of the `foreach ($absentUsers as $user)` loop that builds `$absentCollection` / `$offCollection` — i.e. the whole hand-rolled block, lines ~576-703 — with a call to the service:

```php
            $upcomingService = app(UpcomingShiftService::class);
            $parsedDate = Carbon::parse($date);

            // The upcoming / absent / off split lives in the service so the web and
            // the mobile API cannot drift apart again.
            $partition = $upcomingService->partition($parsedDate, $allUsers, $absentUsers);

            $upcomingUsers = $partition['upcoming'];
            $absentUsers = $partition['absent'];
            $offUsers = $partition['off'];
```

Delete the now-unused `$rosterService = app(...RosterService::class);` line, the `$scheduleResolver` local, and the three `$absentUsers = $absentCollection; $offUsers = $offCollection; $upcomingUsers = $upcomingCollection;` assignment lines further down. Check whether `$now` is still used elsewhere in the method before removing it.

- [ ] **Step 4: Add `upcoming_visible` to the response**

In the same method, change the `return response()->json([...])` block (~line 805) to:

```php
            return response()->json([
                'absent_users' => $serializedAbsentUsers,
                'off_users' => $serializedOffUsers,
                'upcoming_users' => $serializedUpcomingUsers,
                'upcoming_visible' => $upcomingService->isVisibleFor($parsedDate),
                'leaves' => $leaves,
                'total_absent' => $serializedAbsentUsers->count(),
                'total_off' => $serializedOffUsers->count(),
                'total_upcoming' => $serializedUpcomingUsers->count(),
            ]);
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=AbsentUsersUpcomingTest`
Expected: PASS — 2 tests.

Run: `php artisan test --filter=Attendance`
Expected: PASS — no regressions.

- [ ] **Step 6: Hide the sidebar section when not visible**

In `resources/js/Pages/Attendance/AbsentSidebar.jsx`, add the prop (line ~37, next to `upcomingUsers = []`):

```jsx
    upcomingVisible = true,
```

and change the section guard (line ~282) from `{upcomingUsers.length > 0 && (` to:

```jsx
                            {upcomingVisible && upcomingUsers.length > 0 && (
```

Then, in the parent that renders `AbsentSidebar`, pass `upcomingVisible={data?.upcoming_visible ?? true}` alongside the existing `upcomingUsers={...}` prop. Find it with: `grep -rn "AbsentSidebar" resources/js/Pages/Attendance/`.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/AttendanceController.php resources/js/Pages/Attendance/AbsentSidebar.jsx tests/Feature/Attendance/AbsentUsersUpcomingTest.php
git commit -m "fix(attendance): web upcoming list is a live 12h window, sorted by shift start, hidden on past dates"
```

---

## Task 4: Mobile API attendance endpoints consume the service

**Files:**
- Modify: `app/Http/Controllers/Api/V1/AttendanceController.php:480-700` (`absentUsersForDate`)

**Interfaces:**
- Consumes: `UpcomingShiftService` (Task 2), `ResolvesTeamMembers` (Task 1).
- Produces: `GET /api/v1/attendance/absent-users` response gains `upcoming_visible` (bool) inside the existing `data` envelope.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Attendance/MobileUpcomingUsersTest.php`:

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MobileUpcomingUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Employee']);
    }

    private function report(User $manager, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'report_to' => $manager->id]);
        $user->assignRole('Employee');

        return $user;
    }

    private function roster(User $user, string $code, string $start, string $end, string $date): void
    {
        $shift = Shift::factory()->create([
            'code' => $code, 'name' => $code, 'start_time' => $start, 'end_time' => $end,
        ]);

        RosterDay::create([
            'user_id' => $user->id, 'date' => $date, 'shift_id' => $shift->id, 'source' => 'manual',
        ]);
    }

    public function test_upcoming_users_are_sorted_by_shift_start(): void
    {
        Carbon::setTestNow('2026-07-14 07:00:00');

        $manager = User::factory()->create();
        $manager->assignRole('Admin');

        $evening = $this->report($manager, 'Evening Person');
        $morning = $this->report($manager, 'Morning Person');

        $this->roster($evening, 'E', '16:00', '23:59', '2026-07-14');
        $this->roster($morning, 'M', '08:00', '16:00', '2026-07-14');

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/attendance/absent-users?date=2026-07-14')->assertOk();

        $this->assertSame(
            ['M', 'E'],
            collect($response->json('data.upcoming_users'))->pluck('shift_code')->all()
        );
        $response->assertJsonPath('data.upcoming_visible', true);
    }

    public function test_upcoming_is_hidden_on_a_past_date(): void
    {
        Carbon::setTestNow('2026-07-14 07:00:00');

        $manager = User::factory()->create();
        $manager->assignRole('Admin');
        $this->roster($this->report($manager, 'Yesterday Person'), 'M', '08:00', '16:00', '2026-07-13');

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/attendance/absent-users?date=2026-07-13')
            ->assertOk()
            ->assertJsonPath('data.upcoming_visible', false)
            ->assertJsonPath('data.upcoming_users', []);
    }
}
```

If `data.upcoming_users` is not the actual JSON path, print the payload once with `dump($response->json())` and correct the paths — the response envelope is whatever `successResponse()` produces.

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=MobileUpcomingUsersTest`
Expected: FAIL — no `upcoming_visible` key; upcoming order is database order.

- [ ] **Step 3: Rewrite the controller block**

In `app/Http/Controllers/Api/V1/AttendanceController.php`, add the import:

```php
use App\Services\Attendance\UpcomingShiftService;
```

Replace the whole hand-rolled block in `absentUsersForDate()` — from `$scheduleResolver = app(...)` through the end of the `foreach ($absentUsers as $user)` loop and the three `$absentUsers = $absentCollection; ...` reassignments (lines ~513-643) — with the same service call the web controller now makes, using this method's local name for the date string (`$selectedDate`, not `$date`):

```php
            $upcomingService = app(UpcomingShiftService::class);
            $parsedDate = Carbon::parse($selectedDate);

            $partition = $upcomingService->partition($parsedDate, $allUsers, $absentUsers);

            $upcomingUsers = $partition['upcoming'];
            $absentUsers = $partition['absent'];
            $offUsers = $partition['off'];
```

The split itself lives in `UpcomingShiftService::partition()` (Task 2) — do **not** re-implement the loop here. Delete the now-unused `$scheduleResolver`, `$rosterService` and `$now` locals **only if nothing else in the method uses them** (search inside the method before deleting).

- [ ] **Step 4: Add `upcoming_visible` to the response**

Find the `return $this->successResponse([...])` at the end of `absentUsersForDate()` and add the key next to `upcoming_users`:

```php
                'upcoming_visible' => $upcomingService->isVisibleFor($parsedDate),
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=MobileUpcomingUsersTest`
Expected: PASS — 2 tests.

Run: `php artisan test --filter=Attendance`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/AttendanceController.php tests/Feature/Attendance/MobileUpcomingUsersTest.php
git commit -m "fix(api): mobile upcoming list uses the shared 12h-window service, sorted and date-aware"
```

---

## Task 5: Shift catalog ordering + avatars + shift on the present list

Three small backend payload fixes that every later UI task depends on.

**Files:**
- Modify: `app/Http/Controllers/HRM/ShiftController.php:19-21`
- Modify: `app/Http/Controllers/HRM/RosterController.php:76-92` (`formatRoster`)
- Modify: `app/Http/Controllers/Api/V1/ManagerDashboardController.php:634-638` (`teamMembers`)
- Modify: `app/Services/Attendance/AttendanceQueryService.php:286-304` (`getPresentUsersForDate`)

**Interfaces:**
- Produces:
  - `GET /attendance/shifts` → `shifts` ordered by `start_time` then `name`.
  - Roster payload rows gain `profile_image_url` (string|null) beside `name`.
  - `GET /api/v1/manager/team-members` items gain `profile_image_url`.
  - Present-user items gain `user.profile_image_url` and `shift_code` / `shift_name` / `shift_color` / `shift_start` / `shift_end`, ordered by shift start.

- [ ] **Step 1: Order the shift catalog**

`app/Http/Controllers/HRM/ShiftController.php` — replace `index()`:

```php
    public function index(): JsonResponse
    {
        // Start-time order (00-08 → 08-16 → 16-24) is the reading order everywhere:
        // the roster legend, the roster cell popover and the swap forms all read this.
        return response()->json([
            'shifts' => Shift::orderBy('start_time')->orderBy('name')->get(),
        ]);
    }
```

- [ ] **Step 2: Put the avatar in the roster payload**

`app/Http/Controllers/HRM/RosterController.php` — in `formatRoster()`, add the field:

```php
            return [
                'name' => $first->user?->name,
                'profile_image_url' => $first->user?->profile_image_url,
                'days' => $userRows->keyBy(fn ($row) => $row->date->format('Y-m-d'))
```

and widen the eager-load in **both** `index()` and `myRoster()` so the accessor has its media relation — change `'user:id,name'` to `'user:id,name,profile_image'` in the two `RosterDay::with([...])` calls.

Then carry the field through `withOverlay()`, whose `map()` currently rebuilds each user array and would drop it:

```php
        $roster = $rosterCollection->map(fn ($user) => [
            'name' => $user['name'],
            'profile_image_url' => $user['profile_image_url'],
            'days' => $user['days'] instanceof \Illuminate\Support\Collection
                ? $user['days']->toArray()
                : $user['days'],
        ])->toArray();
```

- [ ] **Step 3: Put the avatar on team members**

`app/Http/Controllers/Api/V1/ManagerDashboardController.php` — in `teamMembers()`, the `->get(['id', 'name', 'employee_id'])` column list strips the appended `profile_image_url` accessor. Replace the query + response:

```php
        $members = User::query()
            ->whereIn('id', $teamMemberIds)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'employee_id', 'profile_image'])
            ->map(fn (User $member) => [
                'id' => (int) $member->id,
                'name' => $member->name,
                'employee_id' => $member->employee_id,
                'profile_image_url' => $member->profile_image_url,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => $members,
        ]);
```

- [ ] **Step 4: Put the avatar and the shift on the present list**

`app/Services/Attendance/AttendanceQueryService.php` — replace `getPresentUsersForDate()`:

```php
    public function getPresentUsersForDate(string $date, array $filters = []): array
    {
        $attendances = $this->attendanceRepository->getPresentUsersForDate($date, $filters);
        $roster = app(\App\Services\Attendance\RosterService::class);
        $parsedDate = Carbon::parse($date);

        return collect($attendances)->map(function ($attendance) use ($roster, $parsedDate) {
            $shift = $roster->resolveShift($attendance->user_id, $parsedDate);
            $start = $shift ? Carbon::parse($shift->start_time) : null;

            return [
                'id' => $attendance->id,
                'user_id' => $attendance->user_id,
                'user' => [
                    'id' => $attendance->user->id,
                    'name' => $attendance->user->name,
                    'employee_id' => $attendance->user->employee_id,
                    'profile_image_url' => $attendance->user->profile_image_url,
                ],
                'shift_code' => $shift?->code,
                'shift_name' => $shift?->name,
                'shift_color' => $shift?->color,
                'shift_start' => $start?->format('g:i A'),
                'shift_end' => $shift ? Carbon::parse($shift->end_time)->format('g:i A') : null,
                'shift_start_minutes' => $start ? $start->hour * 60 + $start->minute : PHP_INT_MAX,
                'punchin_time' => $attendance->punchin->format('H:i:s'),
                'punchin_location' => $attendance->punchin_location_array,
                'punchout_time' => $attendance->punchout?->format('H:i:s'),
            ];
        })
            ->sortBy(['shift_start_minutes', 'user.name'])
            ->values()
            ->toArray();
    }
```

- [ ] **Step 5: Put the avatar on the swap-eligible teammate list**

`app/Http/Controllers/Api/V1/AttendanceRequestController.php` — in `swapEligible()` (~line 468), the query selects only `users.id, users.name`, so the swap-request coworker picker has nothing to show. Widen both `select()` calls and the final `map()`:

```php
        if ($requesterLevel !== null) {
            $query->leftJoin('designations', 'users.designation_id', '=', 'designations.id')
                ->where(function ($q) use ($requesterLevel) {
                    $q->where('designations.hierarchy_level', '>=', $requesterLevel)
                      ->orWhereNull('users.designation_id');
                })
                ->select('users.id', 'users.name', 'users.profile_image');
        }

        $employees = $query
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.profile_image'])
            ->filter(fn ($u) => $this->roster->effectiveShiftId($u->id, $data['date']) === null)
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'profile_image_url' => $c->profile_image_url,
            ])
```

Leave the rest of the method (the `->values()` / response tail) untouched.

- [ ] **Step 6: Run the suite**

Run: `php artisan test --filter=Attendance`
Expected: PASS. If `RosterPayloadTest` asserts an exact roster-row shape, update its expectation to include `profile_image_url` — that is the intended change.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/HRM/ShiftController.php app/Http/Controllers/HRM/RosterController.php app/Http/Controllers/Api/V1/ManagerDashboardController.php app/Http/Controllers/Api/V1/AttendanceRequestController.php app/Services/Attendance/AttendanceQueryService.php tests/
git commit -m "feat(attendance): shift-start ordering for the catalog, avatars on roster/team/present/swap payloads"
```

---

## Task 6: Mobile roster + shifts endpoints

The mobile app has no multi-employee roster, no holidays and no shift catalog. Without these the mobile grid cannot position a chip.

**Files:**
- Create: `app/Http/Controllers/Api/V1/RosterController.php`
- Modify: `routes/api.php:209` (add two routes in the `v1` auth group)
- Test: `tests/Feature/Attendance/MobileRosterApiTest.php`

**Interfaces:**
- Consumes: `ResolvesTeamMembers` (Task 1), `RosterOverlayService::forRange(array $userIds, string $from, string $to): array{leave: array, holidays: array<string,string>}`, `Shift` model.
- Produces:
  - `GET /api/v1/attendance/roster?from=YYYY-MM-DD&to=YYYY-MM-DD` → `{ success, data: { roster: { [userId]: { name, profile_image_url, days: { 'YYYY-MM-DD': { code, color, off, leave? } } } }, holidays: { 'YYYY-MM-DD': 'name' } } }`. A manager sees their team; anyone else sees only themselves.
  - `GET /api/v1/attendance/shifts` → `{ success, data: { shifts: [{ id, code, name, color, type, start_time, end_time, crosses_midnight }] } }`, ordered by start time.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Attendance/MobileRosterApiTest.php`:

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MobileRosterApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Employee']);
    }

    public function test_manager_sees_the_whole_team_roster(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Admin');

        $member = User::factory()->create(['name' => 'Team Member', 'report_to' => $manager->id]);
        $shift = Shift::factory()->create(['code' => 'M', 'start_time' => '08:00', 'end_time' => '16:00']);

        RosterDay::create([
            'user_id' => $member->id, 'date' => '2026-07-15', 'shift_id' => $shift->id, 'source' => 'manual',
        ]);

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/attendance/roster?from=2026-07-01&to=2026-07-31')
            ->assertOk()
            ->assertJsonPath("data.roster.{$member->id}.name", 'Team Member')
            ->assertJsonPath("data.roster.{$member->id}.days.2026-07-15.code", 'M')
            ->assertJsonPath("data.roster.{$member->id}.days.2026-07-15.off", false);
    }

    public function test_non_manager_sees_only_their_own_roster(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Admin');

        $employee = User::factory()->create(['report_to' => $manager->id]);
        $employee->assignRole('Employee');
        $colleague = User::factory()->create(['report_to' => $manager->id]);

        $shift = Shift::factory()->create(['code' => 'N', 'start_time' => '00:00', 'end_time' => '08:00']);
        RosterDay::create(['user_id' => $employee->id, 'date' => '2026-07-15', 'shift_id' => $shift->id, 'source' => 'manual']);
        RosterDay::create(['user_id' => $colleague->id, 'date' => '2026-07-15', 'shift_id' => $shift->id, 'source' => 'manual']);

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/v1/attendance/roster?from=2026-07-01&to=2026-07-31')->assertOk();

        $this->assertSame([(string) $employee->id], array_keys($response->json('data.roster')));
    }

    public function test_shifts_catalog_is_ordered_by_start_time(): void
    {
        $user = User::factory()->create();

        Shift::factory()->create(['code' => 'E', 'start_time' => '16:00', 'end_time' => '23:59']);
        Shift::factory()->create(['code' => 'N', 'start_time' => '00:00', 'end_time' => '08:00']);
        Shift::factory()->create(['code' => 'M', 'start_time' => '08:00', 'end_time' => '16:00']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/attendance/shifts')->assertOk();

        $this->assertSame(['N', 'M', 'E'], collect($response->json('data.shifts'))->pluck('code')->all());
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=MobileRosterApiTest`
Expected: FAIL — 404, the routes do not exist.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Api/V1/RosterController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTeamMembers;
use App\Http\Controllers\Controller;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Services\Attendance\RosterOverlayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Read-only roster feed for the mobile app. The payload deliberately mirrors the
 * web `/attendance/roster` shape so the ported grid components need no reshaping.
 * Cell editing stays web-only.
 */
class RosterController extends Controller
{
    use ResolvesTeamMembers;

    public function __construct(private readonly RosterOverlayService $overlay) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'department_id' => 'nullable|integer',
        ]);

        $currentUser = $request->user();

        // A manager sees their reporting tree; anyone else sees only themselves.
        $userIds = $this->isManagerUser($currentUser)
            ? $this->resolveTeamMemberIds($currentUser)
            : [$currentUser->id];

        if ($userIds === []) {
            $userIds = [$currentUser->id];
        }

        $rows = RosterDay::with(['shift:id,code,color,name', 'user:id,name,profile_image'])
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$data['from'], $data['to']])
            ->when($data['department_id'] ?? null, fn ($q, $departmentId) => $q->whereHas(
                'user',
                fn ($uq) => $uq->where('department_id', $departmentId)
            ))
            ->get();

        return response()->json([
            'success' => true,
            'data' => $this->withOverlay(
                $this->formatRoster($rows),
                $rows->pluck('user_id')->unique()->values()->all(),
                $data['from'],
                $data['to'],
            ),
        ]);
    }

    public function shifts(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'shifts' => Shift::query()
                    ->orderBy('start_time')
                    ->orderBy('name')
                    ->get(['id', 'code', 'name', 'color', 'type', 'start_time', 'end_time', 'crosses_midnight']),
            ],
        ]);
    }

    private function formatRoster(Collection $rows): Collection
    {
        return $rows->groupBy('user_id')->map(function (Collection $userRows) {
            $first = $userRows->first();

            return [
                'name' => $first->user?->name,
                'profile_image_url' => $first->user?->profile_image_url,
                'days' => $userRows->keyBy(fn ($row) => $row->date->format('Y-m-d'))
                    ->map(fn ($row) => [
                        'code' => $row->shift?->code,
                        'color' => $row->shift?->color,
                        'off' => $row->shift_id === null,
                    ]),
            ];
        });
    }

    /**
     * @return array{roster: array, holidays: array<string, string>}
     */
    private function withOverlay(Collection $rosterCollection, array $userIds, string $from, string $to): array
    {
        $roster = $rosterCollection->map(fn ($user) => [
            'name' => $user['name'],
            'profile_image_url' => $user['profile_image_url'],
            'days' => $user['days'] instanceof Collection ? $user['days']->toArray() : $user['days'],
        ])->toArray();

        $overlay = $this->overlay->forRange($userIds, $from, $to);

        foreach ($overlay['leave'] as $userId => $days) {
            if (! isset($roster[$userId])) {
                continue;
            }

            foreach ($days as $date => $info) {
                if (! isset($roster[$userId]['days'][$date])) {
                    $roster[$userId]['days'][$date] = [
                        'code' => null, 'color' => null, 'off' => true,
                    ];
                }

                $roster[$userId]['days'][$date]['leave'] = $info;
            }
        }

        return ['roster' => $roster, 'holidays' => $overlay['holidays']];
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/api.php`, inside the `Route::prefix('v1')->middleware(['auth:sanctum', ...])` group, next to the existing `my-roster` line (~209), add:

```php
    Route::get('/attendance/roster', [\App\Http\Controllers\Api\V1\RosterController::class, 'index'])->name('api.v1.attendance.roster');
    Route::get('/attendance/shifts', [\App\Http\Controllers\Api\V1\RosterController::class, 'shifts'])->name('api.v1.attendance.shifts');
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=MobileRosterApiTest`
Expected: PASS — 3 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/RosterController.php routes/api.php tests/Feature/Attendance/MobileRosterApiTest.php
git commit -m "feat(api): mobile roster + shifts read endpoints mirroring the web roster payload"
```

---

## Task 7: `today_shift` on the mobile today endpoint

The dashboard hero (Task 12) shows today's shift; no mobile payload carries it.

**Files:**
- Modify: `app/Services/Attendance/AttendanceQueryService.php:27-71` (`getTodayAttendance`)
- Test: `tests/Feature/Attendance/TodayShiftPayloadTest.php`

**Interfaces:**
- Produces: `GET /api/v1/attendance/today` `data` gains `today_shift`: `{ code, name, color, start, end, crosses_midnight, off }`. `off` is `true` and every other field `null` when no shift resolves for today.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Attendance/TodayShiftPayloadTest.php`:

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TodayShiftPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_payload_carries_the_rostered_shift(): void
    {
        Carbon::setTestNow('2026-07-14 09:00:00');

        $user = User::factory()->create();
        $shift = Shift::factory()->create([
            'code' => 'M', 'name' => 'Morning', 'color' => '#22c55e',
            'start_time' => '08:00', 'end_time' => '16:00', 'crosses_midnight' => false,
        ]);

        RosterDay::create([
            'user_id' => $user->id, 'date' => '2026-07-14', 'shift_id' => $shift->id, 'source' => 'manual',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('data.today_shift.code', 'M')
            ->assertJsonPath('data.today_shift.start', '08:00')
            ->assertJsonPath('data.today_shift.end', '16:00')
            ->assertJsonPath('data.today_shift.off', false);
    }

    public function test_today_payload_reports_an_off_day(): void
    {
        Carbon::setTestNow('2026-07-14 09:00:00');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('data.today_shift.off', true)
            ->assertJsonPath('data.today_shift.code', null);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=TodayShiftPayloadTest`
Expected: FAIL — `today_shift` is not in the payload.

- [ ] **Step 3: Add the field**

`app/Services/Attendance/AttendanceQueryService.php` — in `getTodayAttendance()`, before the `return`, resolve the shift, and add it to the returned array:

```php
        $shift = app(\App\Services\Attendance\RosterService::class)
            ->resolveShift($userId, Carbon::today());

        return [
            'punches' => $punches,
            'total_production_time' => gmdate('H:i:s', $totalProductionTime),
            'isUserOnLeave' => $isUserOnLeave,
            'is_user_on_leave' => $isUserOnLeave,
            'today_shift' => [
                'code' => $shift?->code,
                'name' => $shift?->name,
                'color' => $shift?->color,
                'start' => $shift ? Carbon::parse($shift->start_time)->format('H:i') : null,
                'end' => $shift ? Carbon::parse($shift->end_time)->format('H:i') : null,
                'crosses_midnight' => (bool) ($shift?->crosses_midnight ?? false),
                'off' => $shift === null,
            ],
        ];
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter=TodayShiftPayloadTest`
Expected: PASS — 2 tests.

Run: `php artisan test --filter=Attendance`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/AttendanceQueryService.php tests/Feature/Attendance/TodayShiftPayloadTest.php
git commit -m "feat(api): expose today_shift on the mobile today-attendance payload"
```

---

## Task 8: Web roster grid shows real avatars

**Files:**
- Modify: `resources/js/Pages/Attendance/Components/RosterCalendar.jsx:19-22,126-141`

**Interfaces:**
- Consumes: `roster[userId].profile_image_url` from Task 5.

- [ ] **Step 1: Render the image with initials as the fallback**

In `RosterCalendar.jsx`, replace the initials `<Box>` inside the row's `nameCell(...)` (lines ~128-135) with:

```jsx
                                    <Box style={{
                                        width: 24, height: 24, borderRadius: '50%',
                                        background: 'var(--accent-3)', color: 'var(--accent-11)',
                                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                                        fontSize: 10, fontWeight: 700, flexShrink: 0,
                                        overflow: 'hidden',
                                    }}>
                                        {row.profile_image_url ? (
                                            <img
                                                src={row.profile_image_url}
                                                alt=""
                                                style={{ width: '100%', height: '100%', objectFit: 'cover' }}
                                                onError={(e) => { e.currentTarget.style.display = 'none'; }}
                                            />
                                        ) : (
                                            getInitials(row.name)
                                        )}
                                    </Box>
```

- [ ] **Step 2: Build and eyeball it**

Run: `npm run build`
Expected: build succeeds. Load the Attendance → Roster tab and confirm faces appear in the sticky name column, with initials still shown for users who have no photo.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Attendance/Components/RosterCalendar.jsx
git commit -m "feat(roster): show employee avatars in the web roster name column"
```

---

## Task 9: Mobile `<Avatar>` primitive replaces five copies

**Files (mobile repo):**
- Create: `components/ui/Avatar.js`
- Modify: `components/ui/index.js`
- Modify: `app/(tabs)/leave-approvals.js:130-160,214`
- Modify: `app/(tabs)/overtime-approvals.js:97-127,164`
- Modify: `app/(tabs)/regularization-approvals.js:114-144,174`
- Modify: `app/(tabs)/swap-approvals.js:60-90,238`
- Modify: `app/(tabs)/team-attendance.js:132-185`
- Modify: `components/attendance/PunchStatusCardMobile.js:505-520`

**Interfaces:**
- Produces: `<Avatar user={...} baseUrl={...} size={40} />` exported from `components/ui`. `user` may carry `profile_image_url` or `profile_image`; both are resolved by `resolveUserProfileImageUri`. Falls back to initials, then to a person icon when there is no name.

- [ ] **Step 1: Write the primitive**

Create `components/ui/Avatar.js`:

```jsx
import { Ionicons } from '@expo/vector-icons';
import { Image } from 'react-native';
import { Text, YStack, useTheme } from 'tamagui';
import { getNameInitials, resolveUserProfileImageUri } from '../../src/auth/profileImage';

/**
 * The one avatar in the app: photo → initials → person icon.
 * `user` accepts any shape carrying profile_image_url / profile_image + name.
 */
export function Avatar({ user, baseUrl, size = 40, borderColor }) {
  const theme = useTheme();
  const name = user?.name || user?.user?.name || '';
  const imageUri = resolveUserProfileImageUri(baseUrl, user?.user || user);
  const initials = name ? getNameInitials(name) : null;

  return (
    <YStack
      width={size}
      height={size}
      borderRadius={size / 2}
      overflow="hidden"
      backgroundColor="$surface"
      alignItems="center"
      justifyContent="center"
      borderWidth={borderColor ? 2 : 0}
      borderColor={borderColor || 'transparent'}
    >
      {imageUri ? (
        <Image
          source={{ uri: imageUri }}
          style={{ width: size, height: size, resizeMode: 'cover' }}
        />
      ) : initials ? (
        <Text
          fontFamily="$heading"
          fontWeight="700"
          fontSize={Math.max(11, Math.round(size * 0.38))}
          color="$colorSubtle"
        >
          {initials}
        </Text>
      ) : (
        <Ionicons name="person" size={Math.round(size * 0.5)} color={theme.colorFaint?.val} />
      )}
    </YStack>
  );
}
```

- [ ] **Step 2: Export it**

`components/ui/index.js` — add the line (keep the file's alphabetical-ish grouping, put it first):

```js
export { Avatar } from './Avatar';
```

- [ ] **Step 3: Replace the four approval-screen copies**

In each of `app/(tabs)/leave-approvals.js`, `overtime-approvals.js`, `regularization-approvals.js`, `swap-approvals.js`:

1. **Delete** the local `function EmployeeAvatar({ employee, baseUrl, size = N }) { ... }` declaration in full.
2. Add `Avatar` to the existing `components/ui` import, e.g.:

```js
import { AppButton, Avatar, Divider, EmptyState, Screen, Section, Skeleton } from '../../components/ui';
```

3. Replace each call site `<EmployeeAvatar employee={X} baseUrl={baseUrl} size={N} />` with `<Avatar user={X} baseUrl={baseUrl} size={N} />` (keep whatever `size` that call site used; `swap-approvals.js` used 32, the others 40).
4. Remove any now-unused imports (`Image` from react-native, `useMemo`, `getNameInitials`) **only if** nothing else in the file uses them.

- [ ] **Step 4: Replace the inline avatar in team-attendance**

`app/(tabs)/team-attendance.js` — inside `EmployeeRow`, delete the `profileImage` const, the `imageUri` `useMemo`, the `initials` const, and the whole 40×40 `<YStack>…</YStack>` avatar block (lines ~136-185), and render instead:

```jsx
      <Avatar user={employee?.user || employee} baseUrl={baseUrl} size={40} />
```

Add `Avatar` to the `components/ui` import.

- [ ] **Step 5: Replace the punch-card avatar**

`components/attendance/PunchStatusCardMobile.js` — inside the `avatarWrap` view (lines ~512-517), replace the `profileImageUri ? <Image .../> : <Text>{getNameInitials(...)}</Text>` conditional with:

```jsx
                <Avatar user={user} baseUrl={baseUrl} size={AVATAR_SIZE} />
```

where `AVATAR_SIZE` is the existing width used by `styles.avatarImage`. Keep the surrounding `Animated.View` pulse ring untouched. Add the import: `import { Avatar } from '../ui';`. Delete `styles.avatarImage` / `styles.avatarText` if nothing else uses them.

- [ ] **Step 6: Put avatars in the swap-request coworker picker**

`app/(tabs)/swap-request.js` — the `coworkers` list (`[{ id, name, profile_image_url }]` after Task 5 Step 5) renders through `OptionItem`. Give that component a face: add `Avatar` to the `components/ui` import, add a `user` prop to `OptionItem`, and render it left of the label:

```jsx
function OptionItem({ label, sublabel, selected, onSelect, accent, user, baseUrl }) {
  return (
    <Pressable onPress={onSelect} accessibilityRole="radio" accessibilityState={{ checked: selected }}>
      <XStack
        alignItems="center"
        gap="$2.5"
        height={54}
        paddingHorizontal="$3.5"
        borderRadius={12}
        borderWidth={1.5}
        borderColor={selected ? '$accent' : '$surfaceBorder'}
        backgroundColor={selected ? '$surface' : 'transparent'}
        animation="fast"
      >
        {user ? <Avatar user={user} baseUrl={baseUrl} size={32} /> : null}
        <YStack flex={1}>
          <Text color="$color" fontSize={15} fontWeight={selected ? '700' : '500'}>
            {label}
          </Text>
          {sublabel ? <Text color="$colorFaint" fontSize={12}>{sublabel}</Text> : null}
        </YStack>
        {selected ? <Ionicons name="checkmark-circle" size={20} color={accent} /> : null}
      </XStack>
    </Pressable>
  );
}
```

Then at the coworker call site (~line 349) pass the person through:

```jsx
              {coworkers.map((c) => (
                <OptionItem
                  key={c.id}
                  label={c.name}
                  user={c}
                  baseUrl={baseUrl}
                  selected={counterpartyId === c.id}
                  onSelect={() => setCounterpartyId(c.id)}
                  accent={theme.accent?.val}
                />
              ))}
```

Keep the existing `selected` / `onSelect` expressions from the file — only `user` and `baseUrl` are new. The shift-picker `OptionItem` call sites pass no `user`, so they render unchanged.

- [ ] **Step 7: Run the app and look at every changed screen**

Run: `npm start` (mobile repo), open the app against a running Guardian, and visit: Team Attendance (all tabs), Leave / Overtime / Regularization / Swap approvals, Swap request (coworker picker), and the Punch screen. Every listed person shows a photo, or their initials if they have none. No screen crashes with "EmployeeAvatar is not defined".

- [ ] **Step 8: Commit**

```bash
git add components/ui/Avatar.js components/ui/index.js "app/(tabs)/leave-approvals.js" "app/(tabs)/overtime-approvals.js" "app/(tabs)/regularization-approvals.js" "app/(tabs)/swap-approvals.js" "app/(tabs)/swap-request.js" "app/(tabs)/team-attendance.js" components/attendance/PunchStatusCardMobile.js
git commit -m "refactor(ui): one Avatar primitive replaces five copy-pasted implementations"
```

---

## Task 10: Mobile roster components — legend, 24h grid, month calendar

Ports of the web components. Written as standalone presentational components so Task 11 only has to wire them.

**Files (mobile repo):**
- Create: `components/roster/RosterLegend.js`
- Create: `components/roster/RosterGrid.js`
- Create: `components/roster/RosterMonthCalendar.js`
- Modify: `src/api/managerApi.js` (add `fetchRoster`, `fetchShifts`)

**Interfaces:**
- Consumes: the Task 6 endpoints.
- Produces:
  - `fetchRoster(config, { from, to }): Promise<{ roster, holidays }>`
  - `fetchShifts(config): Promise<Array<{ id, code, name, color, start_time, end_time, crosses_midnight }>>`
  - `<RosterLegend shifts={[]} showHoliday={false} />`
  - `<RosterGrid roster={{}} days={[]} holidays={{}} shifts={[]} baseUrl={''} />`
  - `<RosterMonthCalendar row={{ name, days }} days={[]} holidays={{}} shifts={[]} />`

`roster` is `{ [userId]: { name, profile_image_url, days: { 'YYYY-MM-DD': { code, color, off, leave? } } } }`. `days` is an ordered array of `'YYYY-MM-DD'` strings. `holidays` is `{ 'YYYY-MM-DD': 'Holiday name' }`.

- [ ] **Step 1: Add the API functions**

`src/api/managerApi.js` — append:

```js
/**
 * GET /api/v1/attendance/roster?from&to
 * Manager → whole team; anyone else → self only.
 */
export const fetchRoster = async (config, { from, to }) => {
  const response = await requestJson(
    withSearchParams('/api/v1/attendance/roster', { from, to }),
    config
  );
  const payload = ensureSuccessPayload(response, 'Failed to load roster.');

  return {
    roster: payload.data?.roster || {},
    holidays: payload.data?.holidays || {},
  };
};

/**
 * GET /api/v1/attendance/shifts — the shift catalog, ordered by start time.
 * Chip geometry (start/end hour, midnight crossing) comes from here.
 */
export const fetchShifts = async (config) => {
  const response = await requestJson('/api/v1/attendance/shifts', config);
  const payload = ensureSuccessPayload(response, 'Failed to load shifts.');

  return payload.data?.shifts || [];
};
```

Use the file's existing `requestJson`, `withSearchParams` and `ensureSuccessPayload` helpers — do not import new ones.

- [ ] **Step 2: Write the legend**

Create `components/roster/RosterLegend.js`:

```jsx
import { Text, XStack, YStack } from 'tamagui';

/**
 * Shift colour → code + name, plus Off and Holiday markers.
 * Mirrors the web RosterLegend so both shells read the same.
 */
export function RosterLegend({ shifts = [], showHoliday = false }) {
  if (!shifts.length) return null;

  return (
    <XStack flexWrap="wrap" alignItems="center" gap="$3" paddingVertical="$2">
      <Text color="$colorFaint" fontSize={11} fontWeight="600">Legend</Text>

      {shifts.map((shift) => (
        <XStack key={shift.id ?? shift.code} alignItems="center" gap="$1.5">
          <YStack width={12} height={12} borderRadius={3} backgroundColor={shift.color || '$accent'} />
          <Text color="$color" fontSize={11} fontWeight="700">{shift.code}</Text>
          <Text color="$colorFaint" fontSize={11}>{shift.name}</Text>
        </XStack>
      ))}

      <XStack alignItems="center" gap="$1.5">
        <YStack width={12} height={12} borderRadius={3} borderWidth={1} borderStyle="dashed" borderColor="$colorFaint" />
        <Text color="$colorFaint" fontSize={11}>Off</Text>
      </XStack>

      {showHoliday ? (
        <XStack alignItems="center" gap="$1.5">
          <YStack width={12} height={12} borderRadius={3} backgroundColor="#f59e0b" />
          <Text color="$colorFaint" fontSize={11}>Holiday</Text>
        </XStack>
      ) : null}
    </XStack>
  );
}
```

- [ ] **Step 3: Write the 24-hour grid**

Create `components/roster/RosterGrid.js`. This is the port of `RosterCalendar.jsx`: a horizontally scrolling table, sticky-left employee column (rendered as a separate non-scrolling column beside the scroll view, since React Native has no `position: sticky`), one 144px column per day, each day cell an absolutely-positioned 24-hour track.

```jsx
import { ScrollView } from 'react-native';
import { Text, XStack, YStack, useTheme } from 'tamagui';
import { Avatar } from '../ui';

const NAME_W = 132;
const CELL_W = 144;
const HEADER_H = 56;
const ROW_H = 44;
const CHIP_H = 24;

const HOLIDAY_COLOR = '#f59e0b';

const WEEKDAY = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTH = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const parseDay = (key) => {
  const [y, m, d] = key.split('-').map(Number);
  return new Date(y, m - 1, d);
};

const dayLabel = (key) => {
  const d = parseDay(key);
  return `${WEEKDAY[d.getDay()]} ${d.getDate()} ${MONTH[d.getMonth()]}`;
};

const isWeekend = (key) => {
  const wd = parseDay(key).getDay();
  return wd === 5 || wd === 6; // Fri / Sat — the local working week.
};

/** '16:00' | '16:00:00' → 16 (rounded to the nearest hour, like the web grid). */
const toHour = (timeStr) => {
  if (!timeStr) return 0;
  const [h, m] = String(timeStr).split(':').map(Number);
  return Math.round((h || 0) + (m || 0) / 60);
};

/** Chip geometry as a percentage of the 24-hour track. */
const chipBox = (startHour, endHour) => ({
  left: `${(startHour / 24) * 100}%`,
  width: `${((endHour - startHour) / 24) * 100}%`,
});

function ShiftChips({ cell, shift, holidayName }) {
  const theme = useTheme();

  if (holidayName) {
    return (
      <YStack
        position="absolute"
        left={2}
        right={2}
        height={CHIP_H}
        top={(ROW_H - CHIP_H) / 2}
        borderRadius={4}
        backgroundColor={HOLIDAY_COLOR}
        alignItems="center"
        justifyContent="center"
      >
        <Text color="#fff" fontSize={9} fontWeight="700">HOLIDAY</Text>
      </YStack>
    );
  }

  const leave = cell?.leave;
  if (leave && leave.status === 'approved' && !leave.session) {
    return (
      <YStack
        position="absolute"
        left={2}
        right={2}
        height={CHIP_H}
        top={(ROW_H - CHIP_H) / 2}
        borderRadius={4}
        backgroundColor={HOLIDAY_COLOR}
        alignItems="center"
        justifyContent="center"
      >
        <Text color="#fff" fontSize={9} fontWeight="700" numberOfLines={1}>
          {String(leave.type || 'LEAVE').toUpperCase()}
        </Text>
      </YStack>
    );
  }

  if (!cell || cell.off || !cell.code) return null;

  const color = cell.color || shift?.color || theme.accent?.val;

  // No catalog entry → we cannot place the chip on the hour track; show it full-width.
  if (!shift) {
    return (
      <YStack
        position="absolute"
        left={4}
        right={4}
        height={CHIP_H}
        top={(ROW_H - CHIP_H) / 2}
        borderRadius={4}
        backgroundColor={color}
        alignItems="center"
        justifyContent="center"
      >
        <Text color="#fff" fontSize={9} fontWeight="700">{cell.code}</Text>
      </YStack>
    );
  }

  const startHour = toHour(shift.start_time);
  const endHour = toHour(shift.end_time);
  const crosses = shift.crosses_midnight || endHour <= startHour;

  // A midnight-crossing shift renders as two chips: start→24 and 0→end.
  const segments = crosses
    ? [
        ...(startHour < 24 ? [[startHour, 24, 'a']] : []),
        ...(endHour > 0 ? [[0, endHour, 'b']] : []),
      ]
    : [[startHour, endHour, 'full']];

  return segments.map(([from, to, key]) => (
    <YStack
      key={key}
      position="absolute"
      top={(ROW_H - CHIP_H) / 2}
      height={CHIP_H}
      borderRadius={4}
      backgroundColor={color}
      alignItems="center"
      justifyContent="center"
      overflow="hidden"
      {...chipBox(from, to)}
    >
      <Text color="#fff" fontSize={9} fontWeight="700" numberOfLines={1}>{cell.code}</Text>
    </YStack>
  ));
}

function HourTicks() {
  return Array.from({ length: 23 }).map((_, i) => (
    <YStack
      key={i + 1}
      position="absolute"
      top={0}
      bottom={0}
      width={1}
      left={`${((i + 1) / 24) * 100}%`}
      backgroundColor="$surfaceBorder"
      opacity={0.5}
    />
  ));
}

export function RosterGrid({ roster = {}, days = [], holidays = {}, shifts = [], baseUrl }) {
  const rows = Object.entries(roster);
  const shiftByCode = Object.fromEntries(shifts.map((s) => [s.code, s]));

  if (rows.length === 0) return null;

  return (
    <XStack borderWidth={1} borderColor="$surfaceBorder" borderRadius={8} overflow="hidden">
      {/* Fixed employee column — React Native has no position:sticky. */}
      <YStack width={NAME_W} borderRightWidth={1} borderRightColor="$surfaceBorder">
        <YStack height={HEADER_H} justifyContent="center" paddingHorizontal="$2" backgroundColor="$surface">
          <Text fontSize={12} fontWeight="700" color="$color">Employee</Text>
        </YStack>

        {rows.map(([userId, row]) => (
          <XStack
            key={userId}
            height={ROW_H}
            alignItems="center"
            gap="$2"
            paddingHorizontal="$2"
            borderTopWidth={1}
            borderTopColor="$surfaceBorder"
          >
            <Avatar user={row} baseUrl={baseUrl} size={24} />
            <Text fontSize={11} color="$color" numberOfLines={1} flex={1}>
              {row.name || 'Unknown'}
            </Text>
          </XStack>
        ))}
      </YStack>

      {/* Day columns — the month is ~30 columns wide, so this side-scrolls. */}
      <ScrollView horizontal showsHorizontalScrollIndicator>
        <YStack>
          <XStack height={HEADER_H} backgroundColor="$surface">
            {days.map((day) => (
              <YStack
                key={day}
                width={CELL_W}
                height={HEADER_H}
                borderLeftWidth={1}
                borderLeftColor="$surfaceBorder"
                backgroundColor={holidays[day] ? 'rgba(245,158,11,0.15)' : isWeekend(day) ? '$surfaceHover' : 'transparent'}
                alignItems="center"
                justifyContent="space-between"
                paddingVertical={4}
              >
                <Text fontSize={10} fontWeight="700" color="$color">{dayLabel(day)}</Text>
                <XStack width="100%" height={10}>
                  {[0, 4, 8, 12, 16, 20].map((h) => (
                    <Text
                      key={h}
                      position="absolute"
                      left={`${(h / 24) * 100}%`}
                      fontSize={7}
                      fontWeight="600"
                      color="$colorFaint"
                    >
                      {String(h).padStart(2, '0')}
                    </Text>
                  ))}
                </XStack>
              </YStack>
            ))}
          </XStack>

          {rows.map(([userId, row]) => (
            <XStack key={userId} height={ROW_H} borderTopWidth={1} borderTopColor="$surfaceBorder">
              {days.map((day) => {
                const cell = row.days?.[day];

                return (
                  <YStack
                    key={day}
                    width={CELL_W}
                    height={ROW_H}
                    borderLeftWidth={1}
                    borderLeftColor="$surfaceBorder"
                    position="relative"
                  >
                    <HourTicks />
                    <ShiftChips
                      cell={cell}
                      shift={cell?.code ? shiftByCode[cell.code] : null}
                      holidayName={holidays[day]}
                    />
                  </YStack>
                );
              })}
            </XStack>
          ))}
        </YStack>
      </ScrollView>
    </XStack>
  );
}
```

- [ ] **Step 4: Write the per-employee month calendar**

Create `components/roster/RosterMonthCalendar.js`:

```jsx
import { Text, XStack, YStack, useTheme } from 'tamagui';

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const HOLIDAY_COLOR = '#f59e0b';

const parseDay = (key) => {
  const [y, m, d] = key.split('-').map(Number);
  return new Date(y, m - 1, d);
};

/** Pad the month's days into whole Sunday-start weeks. */
const toWeeks = (days) => {
  if (!days.length) return [];
  const lead = parseDay(days[0]).getDay();
  const cells = [...Array(lead).fill(null), ...days];
  while (cells.length % 7 !== 0) cells.push(null);

  const weeks = [];
  for (let i = 0; i < cells.length; i += 7) weeks.push(cells.slice(i, i + 7));
  return weeks;
};

/**
 * One employee, one month. Same chip vocabulary as the grid:
 * colour = shift colour, dashed = off, amber = holiday / leave.
 */
export function RosterMonthCalendar({ row, days = [], holidays = {}, shifts = [] }) {
  const theme = useTheme();
  const shiftByCode = Object.fromEntries(shifts.map((s) => [s.code, s]));
  const weeks = toWeeks(days);
  const todayKey = new Date().toISOString().slice(0, 10);

  return (
    <YStack borderWidth={1} borderColor="$surfaceBorder" borderRadius={8} overflow="hidden">
      <XStack backgroundColor="$surface">
        {WEEKDAYS.map((w) => (
          <YStack key={w} flex={1} paddingVertical="$1.5" alignItems="center">
            <Text fontSize={11} fontWeight="700" color="$colorSubtle">{w}</Text>
          </YStack>
        ))}
      </XStack>

      {weeks.map((week, wi) => (
        <XStack key={wi} borderTopWidth={1} borderTopColor="$surfaceBorder">
          {week.map((day, di) => {
            if (!day) {
              return <YStack key={`empty-${di}`} flex={1} height={62} backgroundColor="$surface" opacity={0.4} />;
            }

            const cell = row?.days?.[day];
            const holiday = holidays[day];
            const shift = cell?.code ? shiftByCode[cell.code] : null;
            const isOff = !cell || cell.off || !cell.code;
            const isToday = day === todayKey;

            return (
              <YStack
                key={day}
                flex={1}
                height={62}
                padding={4}
                gap={3}
                borderLeftWidth={di === 0 ? 0 : 1}
                borderLeftColor="$surfaceBorder"
                backgroundColor={holiday ? 'rgba(245,158,11,0.12)' : 'transparent'}
              >
                <Text
                  fontSize={10}
                  fontWeight={isToday ? '800' : '500'}
                  color={isToday ? '$accent' : '$colorFaint'}
                >
                  {parseDay(day).getDate()}
                </Text>

                {holiday ? (
                  <YStack flex={1} alignItems="center" justifyContent="center">
                    <Text fontSize={9} fontWeight="700" color={HOLIDAY_COLOR}>HOLIDAY</Text>
                  </YStack>
                ) : isOff ? (
                  <YStack
                    flex={1}
                    borderRadius={6}
                    borderWidth={1}
                    borderStyle="dashed"
                    borderColor="$surfaceBorder"
                    alignItems="center"
                    justifyContent="center"
                  >
                    <Text fontSize={9} fontWeight="700" color="$colorFaint">OFF</Text>
                  </YStack>
                ) : (
                  <YStack
                    flex={1}
                    borderRadius={6}
                    backgroundColor={cell.color || shift?.color || theme.accent?.val}
                    alignItems="center"
                    justifyContent="center"
                  >
                    <Text fontSize={10} fontWeight="700" color="#fff">{cell.code}</Text>
                  </YStack>
                )}
              </YStack>
            );
          })}
        </XStack>
      ))}
    </YStack>
  );
}
```

- [ ] **Step 5: Commit**

```bash
git add components/roster src/api/managerApi.js
git commit -m "feat(roster): mobile roster primitives — legend, 24h-segment grid, per-employee month calendar"
```

---

## Task 11: Rebuild the mobile team-roster screen

**Files (mobile repo):**
- Modify: `app/(tabs)/team-roster.js` (full rewrite — 569 lines → the composition below)
- Modify: `app/(tabs)/my-roster.js` (adopt the legend + chip vocabulary)

**Interfaces:**
- Consumes: `fetchRoster`, `fetchShifts` (Task 10), `RosterLegend`, `RosterGrid`, `RosterMonthCalendar` (Task 10), `Avatar` (Task 9).

- [ ] **Step 1: Rewrite team-roster.js**

Replace the entire file with:

```jsx
import { Ionicons } from '@expo/vector-icons';
import { useCallback, useMemo, useState } from 'react';
import { Pressable, ScrollView } from 'react-native';
import { Text, XStack, YStack, useTheme } from 'tamagui';
import { RosterGrid, RosterLegend, RosterMonthCalendar } from '../../components/roster';
import {
  AppButton,
  Avatar,
  Divider,
  EmptyState,
  Screen,
  Section,
  SegmentedTabs,
  Skeleton,
} from '../../components/ui';
import { fetchRoster, fetchShifts } from '../../src/api/managerApi';
import { useAuth } from '../../src/auth/AuthContext';
import useAutoRefreshOnFocus from '../../src/hooks/useAutoRefreshOnFocus';

const pad2 = (n) => String(n).padStart(2, '0');
const toKey = (d) => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;

const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

const monthDays = (monthDate) => {
  const year = monthDate.getFullYear();
  const month = monthDate.getMonth();
  const last = new Date(year, month + 1, 0).getDate();
  const out = [];

  for (let d = 1; d <= last; d++) {
    out.push(toKey(new Date(year, month, d)));
  }

  return out;
};

const VIEW_TABS = [
  { key: 'grid', label: 'Grid' },
  { key: 'employee', label: 'Per employee' },
];

export default function TeamRosterScreen() {
  const { baseUrl, token } = useAuth();
  const theme = useTheme();

  const [currentMonth, setCurrentMonth] = useState(() => new Date());
  const [viewMode, setViewMode] = useState('grid');
  const [query, setQuery] = useState('');
  const [selectedUserId, setSelectedUserId] = useState(null);

  const [roster, setRoster] = useState({});
  const [holidays, setHolidays] = useState({});
  const [shifts, setShifts] = useState([]);
  const [loading, setLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState('');

  const apiConfig = useMemo(() => ({ baseUrl, token }), [baseUrl, token]);
  const days = useMemo(() => monthDays(currentMonth), [currentMonth]);
  const from = days[0];
  const to = days[days.length - 1];

  const reload = useCallback(
    async ({ withSpinner = true } = {}) => {
      if (!baseUrl || !token) return;
      setErrorMessage('');
      if (withSpinner) setLoading(true);

      try {
        const [rosterData, shiftData] = await Promise.all([
          fetchRoster(apiConfig, { from, to }),
          fetchShifts(apiConfig),
        ]);

        setRoster(rosterData.roster);
        setHolidays(rosterData.holidays);
        setShifts(shiftData);
      } catch (err) {
        setErrorMessage(err instanceof Error ? err.message : 'Failed to load roster.');
      } finally {
        if (withSpinner) setLoading(false);
      }
    },
    [apiConfig, baseUrl, token, from, to]
  );

  useAutoRefreshOnFocus(
    useCallback(async () => {
      await reload({ withSpinner: Object.keys(roster).length === 0 });
    }, [reload, roster]),
    { enabled: Boolean(baseUrl && token), minIntervalMs: 5000 }
  );

  const rows = useMemo(() => {
    const entries = Object.entries(roster);
    if (!query.trim()) return entries;

    const needle = query.trim().toLowerCase();
    return entries.filter(([, row]) => String(row.name || '').toLowerCase().includes(needle));
  }, [roster, query]);

  const filteredRoster = useMemo(() => Object.fromEntries(rows), [rows]);

  // Only the shifts actually rostered this month appear in the legend.
  const legendShifts = useMemo(() => {
    const present = new Set();
    rows.forEach(([, row]) => Object.values(row.days || {}).forEach((cell) => {
      if (cell && !cell.off && cell.code) present.add(cell.code);
    }));

    return shifts.filter((s) => present.has(s.code));
  }, [rows, shifts]);

  const employeeOptions = useMemo(
    () => rows.map(([id, row]) => ({ id, name: row.name || 'Unknown', profile_image_url: row.profile_image_url })),
    [rows]
  );

  const effectiveUserId = employeeOptions.some((e) => e.id === selectedUserId)
    ? selectedUserId
    : employeeOptions[0]?.id ?? null;

  const selectedRow = effectiveUserId ? filteredRoster[effectiveUserId] : null;
  const goMonth = (delta) => setCurrentMonth((m) => new Date(m.getFullYear(), m.getMonth() + delta, 1));

  return (
    <Screen onRefresh={() => reload({ withSpinner: true })} refreshing={loading}>
      {/* Month navigator */}
      <XStack justifyContent="space-between" alignItems="center">
        <Pressable onPress={() => goMonth(-1)} accessibilityRole="button" accessibilityLabel="Previous month">
          <Ionicons name="chevron-back" size={24} color={theme.color?.val} />
        </Pressable>

        <Text fontFamily="$heading" fontWeight="700" fontSize={18} color="$color">
          {MONTHS[currentMonth.getMonth()]} {currentMonth.getFullYear()}
        </Text>

        <Pressable onPress={() => goMonth(1)} accessibilityRole="button" accessibilityLabel="Next month">
          <Ionicons name="chevron-forward" size={24} color={theme.color?.val} />
        </Pressable>
      </XStack>

      <SegmentedTabs options={VIEW_TABS} value={viewMode} onChange={setViewMode} />

      {loading && Object.keys(roster).length === 0 ? (
        <YStack gap="$2" paddingVertical="$3">
          <Skeleton height={56} />
          <Skeleton height={44} />
          <Skeleton height={44} />
          <Skeleton height={44} />
        </YStack>
      ) : errorMessage ? (
        <EmptyState
          title="Couldn't load roster"
          message={errorMessage}
          action={<AppButton variant="ghost" onPress={() => reload({ withSpinner: true })}>Retry</AppButton>}
        />
      ) : rows.length === 0 ? (
        <EmptyState
          title="No roster data"
          message="No shifts have been rostered for this month yet."
          icon="calendar-outline"
        />
      ) : (
        <>
          <RosterLegend shifts={legendShifts} showHoliday={Object.keys(holidays).length > 0} />

          {viewMode === 'grid' ? (
            <Section title="Grid">
              <RosterGrid
                roster={filteredRoster}
                days={days}
                holidays={holidays}
                shifts={shifts}
                baseUrl={baseUrl}
              />
              <Text color="$colorFaint" fontSize={11} paddingTop="$2">
                Scroll sideways to move through the month.
              </Text>
            </Section>
          ) : (
            <Section title="Per employee">
              <ScrollView horizontal showsHorizontalScrollIndicator={false}>
                <XStack gap="$2" paddingBottom="$2">
                  {employeeOptions.map((employee) => {
                    const active = employee.id === effectiveUserId;

                    return (
                      <Pressable key={employee.id} onPress={() => setSelectedUserId(employee.id)}>
                        <XStack
                          alignItems="center"
                          gap="$2"
                          paddingHorizontal="$2.5"
                          paddingVertical="$1.5"
                          borderRadius={999}
                          borderWidth={1.5}
                          borderColor={active ? '$accent' : '$surfaceBorder'}
                          backgroundColor={active ? '$surface' : 'transparent'}
                        >
                          <Avatar user={employee} baseUrl={baseUrl} size={22} />
                          <Text fontSize={12} fontWeight={active ? '700' : '500'} color="$color">
                            {employee.name}
                          </Text>
                        </XStack>
                      </Pressable>
                    );
                  })}
                </XStack>
              </ScrollView>

              <YStack paddingTop="$2">
                <RosterMonthCalendar
                  row={selectedRow}
                  days={days}
                  holidays={holidays}
                  shifts={shifts}
                />
              </YStack>
            </Section>
          )}
        </>
      )}

      <Divider />

      <AppButton variant="ghost" loading={loading} onPress={() => reload({ withSpinner: true })}>
        Refresh
      </AppButton>
    </Screen>
  );
}
```

- [ ] **Step 2: Add the roster barrel**

Create `components/roster/index.js`:

```js
export { RosterGrid } from './RosterGrid';
export { RosterLegend } from './RosterLegend';
export { RosterMonthCalendar } from './RosterMonthCalendar';
```

- [ ] **Step 3: Give my-roster the same vocabulary**

`app/(tabs)/my-roster.js` — the week list stays, but it must speak the grid's language:

1. Import the legend and the shifts API:

```js
import { RosterLegend } from '../../components/roster';
import { fetchShifts } from '../../src/api/managerApi';
```

2. Add `const [shifts, setShifts] = useState([]);` and load them alongside the roster inside `reload()`:

```js
        const [data, shiftData] = await Promise.all([
          fetchMyRoster(
            { baseUrl, token, options: { suppressGlobalFeedback: !withSpinner } },
            { from, to }
          ),
          fetchShifts({ baseUrl, token }),
        ]);
        setRoster(data);
        setShifts(shiftData);
```

3. Render the legend above the `Schedule` section, scoped to the shifts actually in this week:

```jsx
      <RosterLegend
        shifts={shifts.filter((s) => weekDays.some((d) => d.entry && !d.entry.off && d.entry.code === s.code))}
      />
```

- [ ] **Step 4: Run the app and compare against the web**

Run: `npm start`, open Team Roster.

Check, side by side with the web Attendance → Roster tab on the same month:
- The legend lists the same shift codes in the same colours, in start-time order.
- The grid shows one row per employee with an avatar, one column per day.
- A 08-16 shift's chip sits in the left-middle of its day cell; a 16-24 chip sits on the right; a 00-08 chip sits on the left edge.
- A midnight-crossing shift shows two chips in its day cell — one at the right edge, one at the left.
- Holidays render amber, off days render nothing, approved full-day leave renders an amber chip.
- The Per-employee tab shows the selected person's month with one chip per day.

- [ ] **Step 5: Commit**

```bash
git add "app/(tabs)/team-roster.js" "app/(tabs)/my-roster.js" components/roster/index.js
git commit -m "feat(roster): mobile team roster is now the web grid — 24h segments, chips, legend, per-employee calendar"
```

---

## Task 12: Mobile dashboard employee hero card

**Files (mobile repo):**
- Create: `components/dashboard/EmployeeHeroCard.js`
- Modify: `components/dashboard/DashboardView.js:600-672`
- Modify: `app/(tabs)/index.js:632-657`

**Interfaces:**
- Consumes: `todayAttendance.today_shift` (Task 7), the auth `user` object (`name`, `employee_id`, `designation.title`, `department.name`, `profile_image_url`), `<Avatar>` (Task 9).
- Produces: `<EmployeeHeroCard user={} todayShift={} punchStatus={} welcomeDateTime={} lastUpdated={} isCheckedIn={} />`.

- [ ] **Step 1: Write the hero card**

Create `components/dashboard/EmployeeHeroCard.js`:

```jsx
import { Dimensions } from 'react-native';
import { Text, XStack, YStack, useTheme } from 'tamagui';
import { Avatar } from '../ui';

/**
 * The dashboard's identity block: a big face, everything else underneath.
 * The avatar is deliberately half the screen wide — this is the first thing
 * the employee sees when the app opens.
 */
export function EmployeeHeroCard({ user, todayShift, punchStatus, welcomeDateTime, lastUpdated, isCheckedIn }) {
  const theme = useTheme();
  const avatarSize = Math.round(Dimensions.get('window').width * 0.5);

  const designation = user?.designation?.title || null;
  const department = user?.department?.name || null;
  const role = [designation, department].filter(Boolean).join(' · ');

  const shiftLabel = !todayShift || todayShift.off
    ? null
    : `${todayShift.start || '--'} – ${todayShift.end || '--'}${todayShift.crosses_midnight ? ' +1d' : ''}`;

  return (
    <YStack alignItems="center" gap="$2.5" paddingTop="$2">
      <Avatar
        user={user}
        baseUrl={user?.baseUrl}
        size={avatarSize}
        borderColor={isCheckedIn ? theme.accent?.val : undefined}
      />

      <YStack alignItems="center" gap="$1">
        <Text
          fontFamily="$heading"
          fontWeight="900"
          fontSize={26}
          lineHeight={32}
          letterSpacing={-0.5}
          color="$color"
          textAlign="center"
        >
          {user?.name || 'Employee'}
        </Text>

        {user?.employee_id ? (
          <Text color="$colorSubtle" fontSize={13} fontVariant={['tabular-nums']}>
            {user.employee_id}
          </Text>
        ) : null}

        {role ? (
          <Text color="$colorFaint" fontSize={13} textAlign="center">
            {role}
          </Text>
        ) : null}
      </YStack>

      {/* Today's shift */}
      <XStack
        alignItems="center"
        gap="$2"
        paddingHorizontal="$3"
        paddingVertical="$1.5"
        borderRadius={999}
        borderWidth={1}
        borderColor="$surfaceBorder"
        backgroundColor="$surface"
      >
        {shiftLabel ? (
          <>
            <YStack
              width={10}
              height={10}
              borderRadius={5}
              backgroundColor={todayShift.color || theme.accent?.val}
            />
            <Text color="$color" fontSize={13} fontWeight="700">
              {todayShift.code || 'Shift'}
            </Text>
            <Text color="$colorSubtle" fontSize={13} fontVariant={['tabular-nums']}>
              {shiftLabel}
            </Text>
          </>
        ) : (
          <Text color="$colorFaint" fontSize={13}>No shift today</Text>
        )}
      </XStack>

      <YStack alignItems="center" gap="$0.5" paddingTop="$1">
        <Text color="$colorSubtle" fontSize={13}>
          {punchStatus?.text || 'Ready'}
        </Text>
        <Text color="$colorFaint" fontSize={12}>{welcomeDateTime}</Text>
        <Text color="$colorFaint" fontSize={11}>
          {lastUpdated ? `Last sync ${lastUpdated}` : 'Waiting for first sync'}
        </Text>
      </YStack>
    </YStack>
  );
}
```

- [ ] **Step 2: Swap the welcome block for the hero**

`components/dashboard/DashboardView.js` — add the import:

```js
import { EmployeeHeroCard } from './EmployeeHeroCard';
```

Add `user`, `baseUrl` and `todayShift` to the `DashboardView({ ... })` prop list, and **replace** the welcome `<YStack gap="$1">…</YStack>` block (the one holding `welcomeTitle` / `welcomeDateTime` / `lastUpdated`, lines ~626-636) with:

```jsx
      <EmployeeHeroCard
        user={user ? { ...user, baseUrl } : null}
        todayShift={todayShift}
        punchStatus={punchStatus}
        welcomeDateTime={welcomeDateTime}
        lastUpdated={lastUpdated}
        isCheckedIn={Boolean(
          todayAttendance?.punches?.length
            && todayAttendance.punches[todayAttendance.punches.length - 1]?.punchin_time
            && !todayAttendance.punches[todayAttendance.punches.length - 1]?.punchout_time
        )}
      />
```

`welcomeTitle` is now unused — remove it from the prop list. `PunchSection` below stays exactly as it is.

- [ ] **Step 3: Pass the data in**

`app/(tabs)/index.js` — the screen already calls `useAuth()`; pull `user` and `baseUrl` from it (`const { baseUrl, token, user } = useAuth();` — check the existing destructure and extend it rather than adding a second call). Then, in the `<DashboardView ... />` props, drop `welcomeTitle={welcomeTitle}` and add:

```jsx
      user={user}
      baseUrl={baseUrl}
      todayShift={todayAttendance?.today_shift}
```

If `welcomeTitle` is now an unused local, delete its `useMemo`.

- [ ] **Step 4: Run the app**

Run: `npm start`, open the Dashboard.

Check: the avatar fills half the screen width; name, employee ID, designation · department, today's shift chip and the punch status read down the centre under it; the punch card (PulseDot + clock + button) still works below; punching in rings the hero avatar with the accent colour.

- [ ] **Step 5: Commit**

```bash
git add components/dashboard/EmployeeHeroCard.js components/dashboard/DashboardView.js "app/(tabs)/index.js"
git commit -m "feat(dashboard): big employee hero card — half-width avatar, identity + today's shift beneath it"
```

---

## Final verification

- [ ] **Guardian:** `php artisan test` — the whole suite is green.
- [ ] **Web:** Attendance page → the Upcoming section lists people in 00-08 → 08-16 → 16-24 order, is present on today and any future date, and is gone on a past date. Roster tab → faces in the name column.
- [ ] **Mobile:** Team Attendance (avatars + upcoming order + no Upcoming tab on a past date), Team Roster (grid matches the web, per-employee tab works), My Roster (legend present), Dashboard (hero card), all four approval screens (avatars), Punch screen (avatar intact).
- [ ] The four `git log --oneline` heads in each repo show the task commits, authored by Emam Hosen, with no `Co-Authored-By` trailer.
