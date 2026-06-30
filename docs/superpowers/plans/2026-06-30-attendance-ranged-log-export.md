# Date-ranged, Filterable Attendance Log + Export — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the Daily Timesheet tab select a date range with filters (status / department / designation / employee search), show a read-only ranged log when the range spans 2+ days, and export XLSX/PDF that mirrors exactly what is filtered.

**Architecture:** A single new service method, `AttendanceReportService::getRangedAttendanceLog($from, $to, $filters)`, materializes one-row-per-employee-per-day records by reusing the existing month-keyed status engine (`getUserAttendanceData`) per month and trimming to the range. Both a new JSON endpoint (`/attendance/log`) and the existing queued export job consume that one method, so the export always matches the screen. The frontend `DailyTimesheetTab` auto-switches between the current single-day experience and a read-only log based on range width.

**Tech Stack:** Laravel 11 (PHP), `maatwebsite/excel` + `phpoffice/phpspreadsheet`, `barryvdh/laravel-dompdf`, React + Inertia, `@radix-ui/themes`, `@tanstack/react-query`, dayjs, vitest, PHPUnit.

Reference spec: `docs/superpowers/specs/2026-06-30-attendance-ranged-log-export-design.md`

## Global Constraints

- **No schema changes** — this feature adds no migrations; it reuses existing tables and relations.
- **PHP tests run on sqlite** via `RefreshDatabase`; run with `php artisan test --filter=<name>`.
- **Frontend dev only** — verify UI with `npm run dev` at `https://aero-enterprise-suite.test`. NEVER run `npm run build` (it auto-commits/pushes via `postbuild`).
- **Reuse the existing export contract** — queued `ExportAttendanceReport` job + JSON `{ success, queued, filename, download_url }` + polling at `/attendance/export/status/{filename}`. Do not invent a new download mechanism.
- **Derived status is the single source of truth** — status filtering uses the engine's effective status code, never raw `punchin` null-checks, so Absent/Leave/Holiday/Weekend rows are filterable.
- **Leave existing exports untouched** — `AttendanceExport` (daily) and `AttendanceAdminExport` (monthly calendar) must keep working unchanged.
- **PDF day cap** — the ranged PDF export refuses ranges longer than 62 days with a clear message (DomPDF memory).

---

## File Structure

**Backend (create):**
- `app/Exports/AttendanceRangeExport.php` — styled XLSX builder for the ranged log.
- `resources/views/attendance_range_pdf.blade.php` — PDF view for the ranged log.
- `tests/Feature/Attendance/RangedAttendanceLogTest.php` — service + endpoint + export tests.

**Backend (modify):**
- `app/Services/Attendance/AttendanceReportService.php` — add `status_code`/`is_complete` to `getUserAttendanceData`; extend `getEmployeeUsersWithAttendanceAndLeaves`; add `getRangedAttendanceLog` + `matchesStatusFilter`.
- `app/Http/Controllers/AttendanceController.php` — add `getAttendanceLog` + `exportAttendanceLog`.
- `app/Jobs/ExportAttendanceReport.php` — accept a filter payload + handle `range_excel` / `range_pdf`.
- `routes/web.php` — add `/attendance/log` and `/attendance/log/export` routes.

**Frontend (create):**
- `resources/js/Pages/Attendance/logRange.js` — pure helpers (date presets + mode derivation).
- `resources/js/Pages/Attendance/__tests__/logRange.test.js` — vitest unit tests for the helpers.

**Frontend (modify):**
- `resources/js/api/queries/useAttendanceQuery.js` — add `useAttendanceLog` + `useExportAttendanceLog`.
- `resources/js/Pages/Attendance/AttendancePage.jsx` — pass `designations` to `DailyTimesheetTab`.
- `resources/js/Pages/Attendance/DailyTimesheetTab.jsx` — range UI, filters, mode switch, log table, export wiring.

---

## Task 1: Expose `status_code` + `is_complete` from the day engine

Robust status filtering needs the engine's effective status, not the display symbol. This task adds two additive keys to each per-day record. Purely additive — existing consumers ignore the new keys.

**Files:**
- Modify: `app/Services/Attendance/AttendanceReportService.php` (`getUserAttendanceData`, ~lines 181-193)
- Test: `tests/Feature/Attendance/RangedAttendanceLogTest.php`

**Interfaces:**
- Produces: each date entry returned by `getUserAttendanceData(...)` gains `'status_code' => string` (one of `DayAttendance` constants) and `'is_complete' => bool`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Attendance/RangedAttendanceLogTest.php`:

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use App\Services\Attendance\DTO\DayAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RangedAttendanceLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Admin']);
        AttendanceSetting::create([
            'office_start_time' => '09:00:00',
            'late_mark_after' => 15,
            'weekend_days' => ['friday'],
        ]);
    }

    private function employee(string $name = 'Worker'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole('Employee');

        return $user;
    }

    public function test_user_attendance_data_exposes_status_code_and_is_complete(): void
    {
        $user = $this->employee();
        Attendance::create([
            'user_id' => $user->id,
            'date' => '2026-06-02',
            'punchin' => '2026-06-02 09:00:00',
            'punchout' => '2026-06-02 18:00:00',
        ]);

        $service = app(AttendanceReportService::class);
        $holidays = $service->getHolidaysForMonth(2026, 6);
        $data = $service->getUserAttendanceData($user, 2026, 6, $holidays, LeaveSetting::all());

        $this->assertArrayHasKey('status_code', $data['2026-06-02']);
        $this->assertArrayHasKey('is_complete', $data['2026-06-02']);
        $this->assertSame(DayAttendance::PRESENT, $data['2026-06-02']['status_code']);
        $this->assertTrue($data['2026-06-02']['is_complete']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_user_attendance_data_exposes_status_code_and_is_complete`
Expected: FAIL — `Failed asserting that array has the key 'status_code'`.

- [ ] **Step 3: Add the two keys to the per-day record**

In `app/Services/Attendance/AttendanceReportService.php`, inside `getUserAttendanceData`, the loop already computes `$effective = $this->classifyDay($ctx);` and `$result`. Add the two keys to the `$attendanceData[$dateString]` array (the array currently ending at `'policy_events' => $result->policy_events,`):

```php
            $attendanceData[$dateString] = [
                'status' => $symbol,
                'status_code' => $effective,
                'is_complete' => $result->is_complete,
                'punch_in' => $punchIn,
                'punch_out' => $punchOut,
                'total_work_hours' => $totalWorkHours,
                'remarks' => $remarks,
                'ot_minutes' => $result->ot_minutes,
                'worked_minutes' => $result->worked_minutes,
                'double_time_minutes' => $result->double_time_minutes,
                'regular_minutes' => $result->regular_minutes,
                'break_deducted_minutes' => $result->break_deducted_minutes,
                'policy_events' => $result->policy_events,
            ];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_user_attendance_data_exposes_status_code_and_is_complete`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/AttendanceReportService.php tests/Feature/Attendance/RangedAttendanceLogTest.php
git commit -m "feat(attendance): expose status_code + is_complete from day engine"
```

---

## Task 2: Extend employee selection with designation + employee filters

`getEmployeeUsersWithAttendanceAndLeaves` already supports `departmentId` and eager-loads `department`. Add `designationId` + `employee` keyword params, and eager-load `designation` so the range method can read `->designation->title` without a lazy-load 500 under `preventLazyLoading`.

**Files:**
- Modify: `app/Services/Attendance/AttendanceReportService.php` (`getEmployeeUsersWithAttendanceAndLeaves`, ~lines 29-70)
- Test: `tests/Feature/Attendance/RangedAttendanceLogTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `getEmployeeUsersWithAttendanceAndLeaves(int $year, int $month, ?int $departmentId = null, ?int $userId = null, ?int $designationId = null, ?string $employee = null): Collection` — backward compatible (new params optional); each user has `designation` eager-loaded.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Attendance/RangedAttendanceLogTest.php`:

```php
    public function test_employee_selection_filters_by_employee_keyword(): void
    {
        $alice = $this->employee('Alice Example');
        $bob = $this->employee('Bob Sample');

        $service = app(AttendanceReportService::class);
        $users = $service->getEmployeeUsersWithAttendanceAndLeaves(2026, 6, null, null, null, 'Alice');

        $this->assertCount(1, $users);
        $this->assertSame($alice->id, $users->first()->id);
        $this->assertTrue($users->first()->relationLoaded('designation'));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_employee_selection_filters_by_employee_keyword`
Expected: FAIL — too many args / count mismatch (method signature has 4 params).

- [ ] **Step 3: Extend the method signature and query**

Replace the `getEmployeeUsersWithAttendanceAndLeaves` signature and the filtering block. New signature line:

```php
    public function getEmployeeUsersWithAttendanceAndLeaves(int $year, int $month, ?int $departmentId = null, ?int $userId = null, ?int $designationId = null, ?string $employee = null): Collection
    {
```

Inside the `else` branch (the `$userId === null` path that calls `$query->role('Employee')`), after the existing `if ($departmentId) { ... }`, add:

```php
            if ($designationId) {
                $query->where('users.designation_id', $designationId);
            }

            if (! empty($employee)) {
                $query->where(function ($q) use ($employee) {
                    $q->where('users.name', 'like', '%'.$employee.'%')
                        ->orWhere('users.employee_id', 'like', '%'.$employee.'%');
                });
            }
```

Then add `'designation'` to the `->with([...])` array (alongside the existing `'department'`):

```php
            ->with([
                'offboarding',
                'department',
                'designation',
                'attendances' => function ($query) use ($year, $month) {
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_employee_selection_filters_by_employee_keyword`
Expected: PASS.

- [ ] **Step 5: Run the full file to confirm no regression**

Run: `php artisan test --filter=RangedAttendanceLogTest`
Expected: PASS (both tests so far).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/AttendanceReportService.php tests/Feature/Attendance/RangedAttendanceLogTest.php
git commit -m "feat(attendance): designation + employee filters in employee selection"
```

---

## Task 3: `getRangedAttendanceLog` service method

The core. Materializes one-row-per-employee-per-day across an arbitrary range by iterating the months the range covers, reusing `getUserAttendanceData`, trimming to `[from, to]`, sorting, and applying the derived-status filter.

**Files:**
- Modify: `app/Services/Attendance/AttendanceReportService.php` (add two methods)
- Test: `tests/Feature/Attendance/RangedAttendanceLogTest.php`

**Interfaces:**
- Consumes: `getEmployeeUsersWithAttendanceAndLeaves` (Task 2), `getUserAttendanceData` (Task 1).
- Produces: `getRangedAttendanceLog(Carbon $from, Carbon $to, array $filters = []): array` — returns a flat, date-then-name-sorted array of rows. Each row:
  `['date' => 'Y-m-d', 'user_id' => int, 'employee_id' => ?string, 'employee_name' => string, 'department' => ?string, 'designation' => ?string, 'clock_in' => ?string, 'clock_out' => ?string, 'work_hours' => string, 'worked_minutes' => int, 'status' => string, 'status_code' => string, 'is_complete' => bool, 'remarks' => string]`.
  Recognized `$filters` keys: `department_id`, `designation_id`, `employee`, `status` (one of `present|absent|on_leave|holiday|day_off|incomplete`), `user_id` (single-user scoping).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Attendance/RangedAttendanceLogTest.php`:

```php
    public function test_ranged_log_spans_month_boundary_and_trims_to_range(): void
    {
        $user = $this->employee('Range Worker');
        Attendance::create([
            'user_id' => $user->id, 'date' => '2026-06-29',
            'punchin' => '2026-06-29 09:00:00', 'punchout' => '2026-06-29 18:00:00',
        ]);
        Attendance::create([
            'user_id' => $user->id, 'date' => '2026-07-01',
            'punchin' => '2026-07-01 09:00:00', 'punchout' => '2026-07-01 18:00:00',
        ]);

        $service = app(AttendanceReportService::class);
        $rows = $service->getRangedAttendanceLog(
            \Carbon\Carbon::parse('2026-06-29'),
            \Carbon\Carbon::parse('2026-07-01')
        );

        $dates = collect($rows)->where('user_id', $user->id)->pluck('date')->all();
        $this->assertSame(['2026-06-29', '2026-06-30', '2026-07-01'], $dates);
        $this->assertSame('Range Worker', $rows[0]['employee_name']);
    }

    public function test_ranged_log_status_filter_returns_only_absent(): void
    {
        $present = $this->employee('Present Person');
        $absent = $this->employee('Absent Person');
        Attendance::create([
            'user_id' => $present->id, 'date' => '2026-06-02',
            'punchin' => '2026-06-02 09:00:00', 'punchout' => '2026-06-02 18:00:00',
        ]);

        $service = app(AttendanceReportService::class);
        $rows = $service->getRangedAttendanceLog(
            \Carbon\Carbon::parse('2026-06-02'),
            \Carbon\Carbon::parse('2026-06-02'),
            ['status' => 'absent']
        );

        $names = collect($rows)->pluck('employee_name')->unique()->values()->all();
        $this->assertContains('Absent Person', $names);
        $this->assertNotContains('Present Person', $names);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_ranged_log`
Expected: FAIL — `Call to undefined method ...::getRangedAttendanceLog()`.

- [ ] **Step 3: Implement `getRangedAttendanceLog` + `matchesStatusFilter`**

Add these two methods to `AttendanceReportService` (place `getRangedAttendanceLog` as a public method near `getUserAttendanceData`; `matchesStatusFilter` as a private method). The `DayAttendance` class is already imported at the top of the file.

```php
    /**
     * Build a flat one-row-per-employee-per-day attendance log across an arbitrary
     * date range. Reuses the month-keyed engine per month and trims to [from, to].
     * Both the on-screen log endpoint and the range export consume this method.
     *
     * @param  array<string,mixed>  $filters  department_id, designation_id, employee, status, user_id
     * @return array<int,array<string,mixed>>
     */
    public function getRangedAttendanceLog(Carbon $from, Carbon $to, array $filters = []): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        $departmentId = $filters['department_id'] ?? null;
        $designationId = $filters['designation_id'] ?? null;
        $employee = $filters['employee'] ?? null;
        $statusFilter = $filters['status'] ?? null;
        $userId = $filters['user_id'] ?? null;

        $leaveTypes = LeaveSetting::all();

        $rows = [];
        $cursor = $from->copy()->startOfMonth();
        while ($cursor->lte($to)) {
            $year = (int) $cursor->year;
            $month = (int) $cursor->month;

            $users = $this->getEmployeeUsersWithAttendanceAndLeaves(
                $year, $month, $departmentId, $userId, $designationId, $employee
            );
            $holidays = $this->getHolidaysForMonth($year, $month);

            foreach ($users as $user) {
                $data = $this->getUserAttendanceData($user, $year, $month, $holidays, $leaveTypes);

                foreach ($data as $dateString => $day) {
                    if (! is_array($day) || ! isset($day['status_code'])) {
                        continue; // skip meta keys (user_id, employee_id, name, profile_image_url)
                    }

                    $date = Carbon::parse($dateString)->startOfDay();
                    if ($date->lt($from) || $date->gt($to)) {
                        continue;
                    }

                    $rows[] = [
                        'date' => $dateString,
                        'user_id' => $user->id,
                        'employee_id' => $user->employee_id,
                        'employee_name' => $user->name,
                        'department' => $user->department->name ?? null,
                        'designation' => $user->designation->title ?? null,
                        'clock_in' => $day['punch_in'],
                        'clock_out' => $day['punch_out'],
                        'work_hours' => $day['total_work_hours'],
                        'worked_minutes' => $day['worked_minutes'] ?? 0,
                        'status' => $day['status'],
                        'status_code' => $day['status_code'],
                        'is_complete' => $day['is_complete'],
                        'remarks' => $day['remarks'],
                    ];
                }
            }

            $cursor->addMonth();
        }

        usort($rows, fn ($a, $b) => [$a['date'], $a['employee_name']] <=> [$b['date'], $b['employee_name']]);

        if (! empty($statusFilter)) {
            $rows = array_values(array_filter($rows, fn ($r) => $this->matchesStatusFilter($r, $statusFilter)));
        }

        return $rows;
    }

    /**
     * Match a materialized log row against a derived-status filter keyword.
     */
    private function matchesStatusFilter(array $row, string $filter): bool
    {
        $worked = [DayAttendance::PRESENT, DayAttendance::LATE, DayAttendance::HALF_DAY, DayAttendance::SHORT];

        return match ($filter) {
            'present' => in_array($row['status_code'], $worked, true),
            'absent' => $row['status_code'] === DayAttendance::ABSENT,
            'on_leave' => $row['status_code'] === DayAttendance::ON_LEAVE,
            'holiday' => $row['status_code'] === DayAttendance::HOLIDAY,
            'day_off' => in_array($row['status_code'], [DayAttendance::WEEKEND, DayAttendance::DAY_OFF], true),
            'incomplete' => in_array($row['status_code'], $worked, true) && $row['is_complete'] === false,
            default => true,
        };
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_ranged_log`
Expected: PASS (both).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/AttendanceReportService.php tests/Feature/Attendance/RangedAttendanceLogTest.php
git commit -m "feat(attendance): getRangedAttendanceLog service method"
```

---

## Task 4: `/attendance/log` JSON endpoint (paginated)

Expose the ranged log to the frontend, paginated, with an echo of applied filters.

**Files:**
- Modify: `app/Http/Controllers/AttendanceController.php` (add `getAttendanceLog`)
- Modify: `routes/web.php` (add route inside the same authenticated group as `/admin/daily-timesheet`, near line 483)
- Test: `tests/Feature/Attendance/RangedAttendanceLogTest.php`

**Interfaces:**
- Consumes: `AttendanceReportService::getRangedAttendanceLog` (Task 3). The controller already has `$this->attendanceReportService` injected.
- Produces: `GET /attendance/log` (route name `attendance.log`) → JSON `{ rows: [...page slice...], total: int, page: int, per_page: int, last_page: int, applied_filters: {...} }`. Query params: `from`, `to`, `page` (default 1), `perPage` (default 25), `employee`, `department_id`, `designation_id`, `status`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Attendance/RangedAttendanceLogTest.php`:

```php
    public function test_attendance_log_endpoint_returns_paginated_rows(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $user = $this->employee('Log Worker');
        Attendance::create([
            'user_id' => $user->id, 'date' => '2026-06-02',
            'punchin' => '2026-06-02 09:00:00', 'punchout' => '2026-06-02 18:00:00',
        ]);

        $response = $this->actingAs($admin)->getJson(route('attendance.log', [
            'from' => '2026-06-01', 'to' => '2026-06-03', 'perPage' => 25,
        ]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'rows' => [['date', 'employee_name', 'clock_in', 'clock_out', 'work_hours', 'status']],
            'total', 'page', 'per_page', 'last_page', 'applied_filters',
        ]);
        $response->assertJsonPath('applied_filters.from', '2026-06-01');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_attendance_log_endpoint_returns_paginated_rows`
Expected: FAIL — route `attendance.log` not defined (`RouteNotFoundException`).

- [ ] **Step 3: Add the route**

In `routes/web.php`, immediately after the `/admin/daily-timesheet` route (line ~483), add:

```php
        Route::get('/attendance/log', [AttendanceController::class, 'getAttendanceLog'])->name('attendance.log');
```

- [ ] **Step 4: Add the controller method**

In `app/Http/Controllers/AttendanceController.php`, add (near `getAllUsersAttendanceForDate`):

```php
    public function getAttendanceLog(Request $request): JsonResponse
    {
        try {
            $from = Carbon::parse($request->query('from', now()->toDateString()))->startOfDay();
            $to = Carbon::parse($request->query('to', now()->toDateString()))->startOfDay();
            if ($to->lt($from)) {
                [$from, $to] = [$to, $from];
            }

            $page = max(1, (int) $request->query('page', 1));
            $perPage = max(1, (int) $request->query('perPage', 25));

            $filters = array_filter([
                'employee' => $request->query('employee'),
                'department_id' => $request->query('department_id') ? (int) $request->query('department_id') : null,
                'designation_id' => $request->query('designation_id') ? (int) $request->query('designation_id') : null,
                'status' => $request->query('status'),
            ], fn ($v) => $v !== null && $v !== '');

            $rows = $this->attendanceReportService->getRangedAttendanceLog($from, $to, $filters);

            $total = count($rows);
            $lastPage = max(1, (int) ceil($total / $perPage));
            $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

            return response()->json([
                'rows' => array_values($slice),
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
                'applied_filters' => array_merge($filters, [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ]),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get attendance log: '.$e->getMessage());

            return response()->json(['error' => 'Failed to retrieve attendance log.'], 500);
        }
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=test_attendance_log_endpoint_returns_paginated_rows`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AttendanceController.php routes/web.php tests/Feature/Attendance/RangedAttendanceLogTest.php
git commit -m "feat(attendance): /attendance/log paginated JSON endpoint"
```

---

## Task 5: Ranged XLSX export builder

A styled XLSX with a metadata header (range + applied filters + generated-by/at), a summary line, and the one-row-per-employee-per-day grid. Consumes the same service method as the endpoint (WYSIWYG).

**Files:**
- Create: `app/Exports/AttendanceRangeExport.php`
- Test: `tests/Feature/Attendance/RangedAttendanceLogTest.php`

**Interfaces:**
- Consumes: `AttendanceReportService::getRangedAttendanceLog` (Task 3).
- Produces: `AttendanceRangeExport::saveToDisk(string $from, string $to, array $filters, string $filePath, string $disk = 'public'): void` — writes an `.xlsx` to the given disk path. Header columns: `Date, Employee, Employee ID, Department, Designation, Clock In, Clock Out, Work Hours, Status, Remarks`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Attendance/RangedAttendanceLogTest.php` (add `use Illuminate\Support\Facades\Storage;` to the imports at the top of the file):

```php
    public function test_range_export_saves_xlsx_with_rows(): void
    {
        Storage::fake('public');
        $user = $this->employee('Export Worker');
        Attendance::create([
            'user_id' => $user->id, 'date' => '2026-06-02',
            'punchin' => '2026-06-02 09:00:00', 'punchout' => '2026-06-02 18:00:00',
        ]);

        $path = 'exports/test_range.xlsx';
        (new \App\Exports\AttendanceRangeExport)->saveToDisk('2026-06-01', '2026-06-03', [], $path, 'public');

        Storage::disk('public')->assertExists($path);
        $loaded = \PhpOffice\PhpSpreadsheet\IOFactory::load(Storage::disk('public')->path($path));
        $cells = $loaded->getActiveSheet()->toArray();
        $flat = json_encode($cells);
        $this->assertStringContainsString('Export Worker', $flat);
        $this->assertStringContainsString('Clock In', $flat);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_range_export_saves_xlsx_with_rows`
Expected: FAIL — class `App\Exports\AttendanceRangeExport` not found.

- [ ] **Step 3: Create the export builder**

Create `app/Exports/AttendanceRangeExport.php`:

```php
<?php

namespace App\Exports;

use App\Services\Attendance\AttendanceReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AttendanceRangeExport
{
    private array $headings = [
        'Date', 'Employee', 'Employee ID', 'Department', 'Designation',
        'Clock In', 'Clock Out', 'Work Hours', 'Status', 'Remarks',
    ];

    public function saveToDisk(string $from, string $to, array $filters, string $filePath, string $disk = 'public'): void
    {
        $spreadsheet = $this->build($from, $to, $filters);
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'attlog');
        $writer->save($tempFile);

        if (! Storage::disk($disk)->exists('exports')) {
            Storage::disk($disk)->makeDirectory('exports');
        }
        Storage::disk($disk)->put($filePath, file_get_contents($tempFile));
        @unlink($tempFile);
    }

    private function build(string $from, string $to, array $filters): Spreadsheet
    {
        $fromC = Carbon::parse($from);
        $toC = Carbon::parse($to);

        $rows = app(AttendanceReportService::class)->getRangedAttendanceLog($fromC, $toC, $filters);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Attendance Log');

        // Metadata header
        $sheet->setCellValue('A1', 'Attendance Log');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->mergeCells('A1:J1');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', 'Range: '.$fromC->format('M d, Y').' to '.$toC->format('M d, Y'));
        $sheet->mergeCells('A2:J2');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $filterParts = [];
        foreach (['status' => 'Status', 'department_id' => 'Dept', 'designation_id' => 'Designation', 'employee' => 'Search'] as $key => $label) {
            if (! empty($filters[$key])) {
                $filterParts[] = $label.': '.$filters[$key];
            }
        }
        $sheet->setCellValue('A3', 'Filters: '.($filterParts ? implode(', ', $filterParts) : 'None').'  |  Generated: '.now()->format('M d, Y h:i A'));
        $sheet->mergeCells('A3:J3');
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Column headings on row 5
        $headerRow = 5;
        $col = 'A';
        foreach ($this->headings as $heading) {
            $sheet->setCellValue($col.$headerRow, $heading);
            $col++;
        }
        $sheet->getStyle("A{$headerRow}:J{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:J{$headerRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE3F2FD');

        // Data rows
        $r = $headerRow + 1;
        foreach ($rows as $row) {
            $sheet->setCellValue('A'.$r, Carbon::parse($row['date'])->format('M d, Y'));
            $sheet->setCellValue('B'.$r, $row['employee_name']);
            $sheet->setCellValue('C'.$r, $row['employee_id'] ?? '');
            $sheet->setCellValue('D'.$r, $row['department'] ?? '');
            $sheet->setCellValue('E'.$r, $row['designation'] ?? '');
            $sheet->setCellValue('F'.$r, $row['clock_in'] ? Carbon::parse($row['clock_in'])->format('h:i A') : '—');
            $sheet->setCellValue('G'.$r, $row['clock_out'] ? Carbon::parse($row['clock_out'])->format('h:i A') : '—');
            $sheet->setCellValue('H'.$r, $row['work_hours']);
            $sheet->setCellValue('I'.$r, $row['remarks']);
            $sheet->setCellValue('J'.$r, $row['remarks']);
            $r++;
        }

        $lastRow = max($headerRow, $r - 1);
        $sheet->getStyle("A{$headerRow}:J{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        foreach (range('A', 'J') as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }

        return $spreadsheet;
    }
}
```

Note: column I uses the human `remarks` (e.g. "Present"/"Absent"/"On Leave") as the readable Status, and column J repeats remarks for clarity; if you prefer the raw symbol in Status, set `'I'` to `$row['status']`. Keep as written unless the user asks otherwise.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_range_export_saves_xlsx_with_rows`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Exports/AttendanceRangeExport.php tests/Feature/Attendance/RangedAttendanceLogTest.php
git commit -m "feat(attendance): ranged XLSX export builder"
```

---

## Task 6: Wire the range export into the queued job + endpoint (XLSX + PDF)

Generalize `ExportAttendanceReport` to carry a filter payload and handle `range_excel` / `range_pdf`, add the controller `exportAttendanceLog`, route, and the PDF Blade view with the 62-day cap.

**Files:**
- Modify: `app/Jobs/ExportAttendanceReport.php`
- Modify: `app/Http/Controllers/AttendanceController.php` (add `exportAttendanceLog`)
- Modify: `routes/web.php` (add route near line 497)
- Create: `resources/views/attendance_range_pdf.blade.php`
- Test: `tests/Feature/Attendance/RangedAttendanceLogTest.php`

**Interfaces:**
- Consumes: `AttendanceRangeExport` (Task 5), `getRangedAttendanceLog` (Task 3).
- Produces:
  - `ExportAttendanceReport` gains an optional constructor param `array $filters = []` (appended last, so existing 5-arg dispatches keep working) and handles `$this->type === 'range_excel'` / `'range_pdf'`.
  - `GET /attendance/log/export` (route name `attendance.log.export`) → JSON `{ success, queued, filename, download_url, message }`, same contract as existing exports. Query params: `from`, `to`, `type` (`excel|pdf`), `employee`, `department_id`, `designation_id`, `status`. Returns 422 `{ error }` if `type=pdf` and the range exceeds 62 days.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Attendance/RangedAttendanceLogTest.php` (add `use App\Jobs\ExportAttendanceReport;` and `use Illuminate\Support\Facades\Queue;` to imports):

```php
    public function test_log_export_dispatches_range_excel_job(): void
    {
        Queue::fake();
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin)->getJson(route('attendance.log.export', [
            'from' => '2026-06-01', 'to' => '2026-06-10', 'type' => 'excel',
        ]));

        $response->assertStatus(200)->assertJson(['success' => true, 'queued' => true]);
        $response->assertJsonStructure(['download_url', 'filename']);
        Queue::assertPushed(ExportAttendanceReport::class, fn ($job) => $job->getType() === 'range_excel');
    }

    public function test_log_export_pdf_rejects_range_over_cap(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin)->getJson(route('attendance.log.export', [
            'from' => '2026-01-01', 'to' => '2026-06-30', 'type' => 'pdf',
        ]));

        $response->assertStatus(422);
        $response->assertJsonStructure(['error']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="test_log_export"`
Expected: FAIL — route `attendance.log.export` not defined.

- [ ] **Step 3: Generalize the job**

In `app/Jobs/ExportAttendanceReport.php`:

Add the property and constructor param (append `array $filters = []` last):

```php
    protected array $filters;

    public function __construct(string $type, ?string $date, ?string $month, int $userId, string $filename, array $filters = [])
    {
        $this->type = $type;
        $this->date = $date;
        $this->month = $month;
        $this->userId = $userId;
        $this->filename = $filename;
        $this->filters = $filters;
    }
```

In `handle()`, add two new branches before the final closing of the `if/elseif` chain (after the `monthly_pdf` branch). Use the existing imports plus `App\Exports\AttendanceRangeExport`:

```php
            } elseif ($this->type === 'range_excel') {
                (new \App\Exports\AttendanceRangeExport)->saveToDisk(
                    $this->filters['from'], $this->filters['to'], $this->filters, $filePath, 'public'
                );
            } elseif ($this->type === 'range_pdf') {
                $rows = app(AttendanceReportService::class)->getRangedAttendanceLog(
                    Carbon::parse($this->filters['from']),
                    Carbon::parse($this->filters['to']),
                    $this->filters
                );
                $pdf = PDF::loadView('attendance_range_pdf', [
                    'from' => Carbon::parse($this->filters['from']),
                    'to' => Carbon::parse($this->filters['to']),
                    'rows' => $rows,
                    'generatedOn' => now()->format('F d, Y h:i A'),
                ])->setPaper('a4', 'landscape');
                Storage::disk('public')->put($filePath, $pdf->output());
            }
```

(`AttendanceReportService`, `Carbon`, `PDF`, and `Storage` are already imported in this file.)

- [ ] **Step 4: Create the PDF view**

Create `resources/views/attendance_range_pdf.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        h2 { text-align: center; margin: 0; }
        .meta { text-align: center; color: #555; margin: 4px 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
        th { background: #e3f2fd; }
    </style>
</head>
<body>
    <h2>Attendance Log</h2>
    <div class="meta">
        {{ $from->format('M d, Y') }} to {{ $to->format('M d, Y') }} &middot; Generated: {{ $generatedOn }}
    </div>
    <table>
        <thead>
            <tr>
                <th>Date</th><th>Employee</th><th>Employee ID</th><th>Department</th>
                <th>Designation</th><th>Clock In</th><th>Clock Out</th><th>Work Hours</th><th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($row['date'])->format('M d, Y') }}</td>
                    <td>{{ $row['employee_name'] }}</td>
                    <td>{{ $row['employee_id'] ?? '' }}</td>
                    <td>{{ $row['department'] ?? '' }}</td>
                    <td>{{ $row['designation'] ?? '' }}</td>
                    <td>{{ $row['clock_in'] ? \Carbon\Carbon::parse($row['clock_in'])->format('h:i A') : '—' }}</td>
                    <td>{{ $row['clock_out'] ? \Carbon\Carbon::parse($row['clock_out'])->format('h:i A') : '—' }}</td>
                    <td>{{ $row['work_hours'] }}</td>
                    <td>{{ $row['remarks'] }}</td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;">No records for the selected range and filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, after the existing `/attendance/monthly-calendar/export` route (line ~498), add:

```php
        Route::get('/attendance/log/export', [AttendanceController::class, 'exportAttendanceLog'])->name('attendance.log.export');
```

- [ ] **Step 6: Add the controller method**

In `app/Http/Controllers/AttendanceController.php`, add near the other export methods:

```php
    public function exportAttendanceLog(Request $request)
    {
        try {
            $from = Carbon::parse($request->query('from', now()->toDateString()))->startOfDay();
            $to = Carbon::parse($request->query('to', now()->toDateString()))->startOfDay();
            if ($to->lt($from)) {
                [$from, $to] = [$to, $from];
            }

            $type = $request->query('type', 'excel') === 'pdf' ? 'pdf' : 'excel';

            if ($type === 'pdf' && $from->diffInDays($to) > 62) {
                return response()->json([
                    'error' => 'PDF export is limited to 62 days. Narrow the range or use Excel.',
                ], 422);
            }

            $filters = array_filter([
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'employee' => $request->query('employee'),
                'department_id' => $request->query('department_id') ? (int) $request->query('department_id') : null,
                'designation_id' => $request->query('designation_id') ? (int) $request->query('designation_id') : null,
                'status' => $request->query('status'),
            ], fn ($v) => $v !== null && $v !== '');

            $ext = $type === 'pdf' ? 'pdf' : 'xlsx';
            $jobType = $type === 'pdf' ? 'range_pdf' : 'range_excel';
            $filename = 'Attendance_Log_'.$from->format('Ymd').'_'.$to->format('Ymd').'_'.time().'.'.$ext;

            ExportAttendanceReport::dispatch($jobType, null, null, Auth::id(), $filename, $filters);

            return response()->json([
                'success' => true,
                'queued' => true,
                'filename' => $filename,
                'download_url' => asset('storage/exports/'.$filename),
                'message' => 'Export job has been dispatched.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Attendance log export failed.',
                'details' => $this->safeExceptionMessage($e, 'Export failed.'),
            ], 500);
        }
    }
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter="test_log_export"`
Expected: PASS (both).

- [ ] **Step 8: Run the whole feature file**

Run: `php artisan test --filter=RangedAttendanceLogTest`
Expected: PASS (all backend tests).

- [ ] **Step 9: Commit**

```bash
git add app/Jobs/ExportAttendanceReport.php app/Http/Controllers/AttendanceController.php routes/web.php resources/views/attendance_range_pdf.blade.php tests/Feature/Attendance/RangedAttendanceLogTest.php
git commit -m "feat(attendance): queued ranged log export (xlsx + pdf) with day cap"
```

---

## Task 7: Frontend pure helpers (presets + mode) with vitest

Extract date-range presets and mode derivation into a testable pure module, mirroring `resources/js/Components/DateTimePicker/presets.test.js`.

**Files:**
- Create: `resources/js/Pages/Attendance/logRange.js`
- Test: `resources/js/Pages/Attendance/__tests__/logRange.test.js`

**Interfaces:**
- Produces:
  - `RANGE_PRESETS` — array of `{ value, label }` for the preset Select.
  - `resolvePreset(value, today = dayjs())` → `{ from: 'YYYY-MM-DD', to: 'YYYY-MM-DD' }` (returns `null` for `'custom'`).
  - `isRangeMode(from, to)` → `boolean` (true when `to` is a later day than `from`).

- [ ] **Step 1: Write the failing tests**

Create `resources/js/Pages/Attendance/__tests__/logRange.test.js`:

```js
import { describe, it, expect } from 'vitest';
import dayjs from 'dayjs';
import { RANGE_PRESETS, resolvePreset, isRangeMode } from '../logRange';

describe('logRange helpers', () => {
  const today = dayjs('2026-06-15'); // a Monday

  it('exposes presets including today and custom', () => {
    const values = RANGE_PRESETS.map((p) => p.value);
    expect(values).toContain('today');
    expect(values).toContain('custom');
  });

  it('resolves "today" to a single day', () => {
    expect(resolvePreset('today', today)).toEqual({ from: '2026-06-15', to: '2026-06-15' });
  });

  it('resolves "this_month" to month bounds', () => {
    expect(resolvePreset('this_month', today)).toEqual({ from: '2026-06-01', to: '2026-06-30' });
  });

  it('returns null for custom', () => {
    expect(resolvePreset('custom', today)).toBeNull();
  });

  it('isRangeMode is false for same day, true for a span', () => {
    expect(isRangeMode('2026-06-15', '2026-06-15')).toBe(false);
    expect(isRangeMode('2026-06-15', '2026-06-20')).toBe(true);
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run resources/js/Pages/Attendance/__tests__/logRange.test.js`
Expected: FAIL — cannot resolve `../logRange`.

- [ ] **Step 3: Implement the helper module**

Create `resources/js/Pages/Attendance/logRange.js`:

```js
import dayjs from 'dayjs';

export const RANGE_PRESETS = [
  { value: 'today', label: 'Today' },
  { value: 'yesterday', label: 'Yesterday' },
  { value: 'this_week', label: 'This Week' },
  { value: 'last_week', label: 'Last Week' },
  { value: 'this_month', label: 'This Month' },
  { value: 'last_month', label: 'Last Month' },
  { value: 'custom', label: 'Custom' },
];

const fmt = (d) => d.format('YYYY-MM-DD');

export const resolvePreset = (value, today = dayjs()) => {
  const t = dayjs(today);
  switch (value) {
    case 'today':
      return { from: fmt(t), to: fmt(t) };
    case 'yesterday': {
      const y = t.subtract(1, 'day');
      return { from: fmt(y), to: fmt(y) };
    }
    case 'this_week':
      return { from: fmt(t.startOf('week')), to: fmt(t.endOf('week')) };
    case 'last_week': {
      const lw = t.subtract(1, 'week');
      return { from: fmt(lw.startOf('week')), to: fmt(lw.endOf('week')) };
    }
    case 'this_month':
      return { from: fmt(t.startOf('month')), to: fmt(t.endOf('month')) };
    case 'last_month': {
      const lm = t.subtract(1, 'month');
      return { from: fmt(lm.startOf('month')), to: fmt(lm.endOf('month')) };
    }
    case 'custom':
    default:
      return null;
  }
};

export const isRangeMode = (from, to) => dayjs(to).isAfter(dayjs(from), 'day');
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run resources/js/Pages/Attendance/__tests__/logRange.test.js`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Attendance/logRange.js resources/js/Pages/Attendance/__tests__/logRange.test.js
git commit -m "feat(attendance): date-range preset + mode-derivation helpers"
```

---

## Task 8: React Query hooks for the log + export

**Files:**
- Modify: `resources/js/api/queries/useAttendanceQuery.js`

**Interfaces:**
- Consumes: `/attendance/log` (Task 4), `/attendance/log/export` (Task 6).
- Produces:
  - `useAttendanceLog({ from, to, page, perPage, employee, departmentId, designationId, status })` — React Query `useQuery` hook, enabled when `from` and `to` are set.
  - `useExportAttendanceLog()` — `useMutation` returning the queued-export JSON (blob responseType, same as `useExportDailyTimesheet`).

- [ ] **Step 1: Add the hooks**

In `resources/js/api/queries/useAttendanceQuery.js`, add near `useDailyTimesheet`:

```js
/**
 * Fetch the ranged, filterable attendance log
 */
export const useAttendanceLog = (params = {}) => {
  const { from, to, page = 1, perPage = 25, employee, departmentId, designationId, status } = params;

  return useQuery({
    queryKey: ['attendance', 'log', { from, to, page, perPage, employee, departmentId, designationId, status }],
    queryFn: () => requestJson('get', '/attendance/log', {
      params: {
        from, to, page, perPage, employee,
        department_id: departmentId, designation_id: designationId, status,
      },
    }),
    enabled: !!from && !!to,
    staleTime: 3 * 60 * 1000,
  });
};
```

And near `useExportDailyTimesheet`:

```js
/**
 * Export the ranged attendance log (queued)
 */
export const useExportAttendanceLog = () => {
  return useMutation({
    mutationFn: ({ from, to, type, employee, departmentId, designationId, status }) =>
      requestJson('get', '/attendance/log/export', {
        params: {
          from, to, type, employee,
          department_id: departmentId, designation_id: designationId, status,
        },
        responseType: 'blob',
      }),
  });
};
```

- [ ] **Step 2: Verify the dev bundle compiles**

Run: `npm run dev` (leave running) and confirm no build error in the terminal output for `useAttendanceQuery.js`. Stop is not required; just confirm HMR is clean.
Expected: No module/parse errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/api/queries/useAttendanceQuery.js
git commit -m "feat(attendance): useAttendanceLog + useExportAttendanceLog hooks"
```

---

## Task 9: DailyTimesheetTab — range UI, filters, mode switch, log table, export

The integration task. Add a `to` date + preset Select + Department/Designation/Status selects; derive `isRange`; in range mode render a read-only log table from `useAttendanceLog` and hide the day-only widgets; route exports through `useExportAttendanceLog` with the active range + filters; in single-day mode keep everything exactly as today.

**Files:**
- Modify: `resources/js/Pages/Attendance/AttendancePage.jsx` (pass `designations` down)
- Modify: `resources/js/Pages/Attendance/DailyTimesheetTab.jsx`

**Interfaces:**
- Consumes: `useAttendanceLog`, `useExportAttendanceLog` (Task 8); `RANGE_PRESETS`, `resolvePreset`, `isRangeMode` (Task 7); `handleExportResponse` (existing).

- [ ] **Step 1: Pass designations from the page**

In `resources/js/Pages/Attendance/AttendancePage.jsx`:

Change the component signature (line ~25):

```jsx
const AttendancePage = ({ title, departments = [], designations = [] }) => {
```

Pass both lists to the tab (replace the existing `<DailyTimesheetTab .../>` block, ~lines 192-196):

```jsx
                                        <DailyTimesheetTab
                                            selectedDate={selectedDate}
                                            onDateChange={handleDateChange}
                                            isActive={activeTab === 'timesheet'}
                                            departments={departments}
                                            designations={designations}
                                        />
```

- [ ] **Step 2: Add range + filter state and the new hook to DailyTimesheetTab**

In `resources/js/Pages/Attendance/DailyTimesheetTab.jsx`:

Add to imports (top of file):

```jsx
import { RANGE_PRESETS, resolvePreset, isRangeMode } from './logRange';
```

Extend the hook import to include the log hooks:

```jsx
import { useDailyTimesheet, usePresentUsers, useAbsentUsers, useUpdateTimeCorrection, useMarkAsPresent, useDeleteAttendanceCorrection, useExportDailyTimesheet, useAttendanceLog, useExportAttendanceLog } from '@/api/queries/useAttendanceQuery';
```

Update the component signature to accept the new props:

```jsx
const DailyTimesheetTab = ({
    selectedDate,
    onDateChange,
    isActive = true,
    departments = [],
    designations = [],
}) => {
```

After the existing pagination state (`const [perPage, setPerPage] = useState(25);`), add range + filter state:

```jsx
    // Range + filter state (Log mode)
    const [toDate, setToDate] = useState(selectedDate);
    const [preset, setPreset] = useState('today');
    const [deptFilter, setDeptFilter] = useState('');
    const [desigFilter, setDesigFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');

    // Keep "to" anchored to "from" while in single-day (today/preset) usage.
    useEffect(() => {
        if (!isRangeMode(selectedDate, toDate) && preset !== 'custom') {
            setToDate(selectedDate);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedDate]);

    const rangeMode = isRangeMode(selectedDate, toDate);
```

- [ ] **Step 3: Add the log query and a preset handler**

After the existing `useDailyTimesheet(...)` / `usePresentUsers(...)` / `useAbsentUsers(...)` hooks, add:

```jsx
    const logFilters = {
        employee: employeeQuery || undefined,
        departmentId: deptFilter || undefined,
        designationId: desigFilter || undefined,
        status: statusFilter || undefined,
    };

    const { data: logData, isLoading: isLoadingLog } = useAttendanceLog({
        from: rangeMode ? selectedDate : null,
        to: rangeMode ? toDate : null,
        page: currentPage,
        perPage,
        ...logFilters,
    });

    const exportLog = useExportAttendanceLog();
```

Add a preset handler near the other callbacks:

```jsx
    const applyPreset = useCallback((value) => {
        setPreset(value);
        const resolved = resolvePreset(value);
        if (!resolved) return; // custom: leave dates as-is
        onDateChange({ target: { value: resolved.from } });
        setToDate(resolved.to);
        setCurrentPage(1);
    }, [onDateChange]);
```

- [ ] **Step 4: Make export range/filter aware**

Replace the existing `exportFile` callback so it uses the ranged export whenever in range mode (and still works for single day):

```jsx
    const exportFile = useCallback(async (type) => {
        setDownloading(type);
        try {
            const mime = type === 'pdf' ? 'application/pdf' : undefined;
            const ext  = type === 'excel' ? 'xlsx' : 'pdf';

            let data;
            if (rangeMode) {
                data = await exportLog.mutateAsync({
                    from: selectedDate, to: toDate, type, ...logFilters,
                });
            } else {
                data = await exportDailyTimesheet.mutateAsync({ date: selectedDate, type });
            }

            const label = rangeMode
                ? `Attendance_Log_${dayjs(selectedDate).format('YYYY_MM_DD')}_${dayjs(toDate).format('YYYY_MM_DD')}.${ext}`
                : `Daily_Timesheet_${dayjs(selectedDate).format('YYYY_MM_DD')}.${ext}`;
            await handleExportResponse(data, label, mime, ext);
        } catch (err) {
            console.error('Export failed:', err);
            showToast.error(`Failed to download ${type}.`);
        } finally { setDownloading(''); }
    }, [selectedDate, toDate, rangeMode, exportDailyTimesheet, exportLog, employeeQuery, deptFilter, desigFilter, statusFilter]);
```

- [ ] **Step 5: Add the range + filter controls to the toolbar**

In the toolbar's left `Flex` (the one containing the date `TextField`, after the search field block), add the preset Select, the "to" date (only in custom/range), and the filter selects. Place after the employee search `TextField` block and before the per-page Select:

```jsx
                    <Select.Root value={preset} onValueChange={applyPreset}>
                        <Select.Trigger size="2" style={{ width: 130 }} />
                        <Select.Content>
                            {RANGE_PRESETS.map(p => (
                                <Select.Item key={p.value} value={p.value}>{p.label}</Select.Item>
                            ))}
                        </Select.Content>
                    </Select.Root>

                    {preset === 'custom' && (
                        <TextField.Root
                            type="date"
                            size="2"
                            value={dayjs(toDate).format('YYYY-MM-DD')}
                            onChange={e => { setToDate(e.target.value); setCurrentPage(1); }}
                            style={{ width: 160 }}
                        >
                            <TextField.Slot><CalendarIcon /></TextField.Slot>
                        </TextField.Root>
                    )}

                    {isAdminView && rangeMode && (
                        <>
                            <Select.Root value={statusFilter || 'all'} onValueChange={v => { setStatusFilter(v === 'all' ? '' : v); setCurrentPage(1); }}>
                                <Select.Trigger size="2" placeholder="Status" style={{ width: 130 }} />
                                <Select.Content>
                                    <Select.Item value="all">All statuses</Select.Item>
                                    <Select.Item value="present">Present</Select.Item>
                                    <Select.Item value="absent">Absent</Select.Item>
                                    <Select.Item value="on_leave">On Leave</Select.Item>
                                    <Select.Item value="incomplete">Incomplete</Select.Item>
                                    <Select.Item value="holiday">Holiday</Select.Item>
                                    <Select.Item value="day_off">Day Off</Select.Item>
                                </Select.Content>
                            </Select.Root>

                            <Select.Root value={deptFilter || 'all'} onValueChange={v => { setDeptFilter(v === 'all' ? '' : v); setCurrentPage(1); }}>
                                <Select.Trigger size="2" placeholder="Department" style={{ width: 150 }} />
                                <Select.Content>
                                    <Select.Item value="all">All departments</Select.Item>
                                    {departments.map(d => (
                                        <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>

                            <Select.Root value={desigFilter || 'all'} onValueChange={v => { setDesigFilter(v === 'all' ? '' : v); setCurrentPage(1); }}>
                                <Select.Trigger size="2" placeholder="Designation" style={{ width: 150 }} />
                                <Select.Content>
                                    <Select.Item value="all">All designations</Select.Item>
                                    {designations.map(d => (
                                        <Select.Item key={d.id} value={String(d.id)}>{d.title}</Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                        </>
                    )}
```

- [ ] **Step 6: Render the log table in range mode**

Wrap the existing body (`{error ? (...) : (<Flex ...table + sidebar...>)}`) so that when `rangeMode` is true a read-only log table renders instead. Add this block immediately before the existing `{error ? (` body block, and gate the existing block with `{!rangeMode && ...}`:

```jsx
            {rangeMode ? (
                <Box style={{ border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-3)', overflow: 'hidden', minHeight: 400 }}>
                    <ScrollArea scrollbars="both" style={{ maxHeight: 'calc(100vh - 340px)' }}>
                        <Table.Root size="2" variant="ghost" style={{ minWidth: 720 }}>
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeaderCell>Date</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Employee</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Clock In</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Clock Out</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Work Hours</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {isLoadingLog ? (
                                    [...Array(8)].map((_, i) => (
                                        <Table.Row key={i}>
                                            {[...Array(6)].map((__, j) => (
                                                <Table.Cell key={j}><Skeleton width="80%" height="16px" /></Table.Cell>
                                            ))}
                                        </Table.Row>
                                    ))
                                ) : (logData?.rows?.length ?? 0) === 0 ? (
                                    <Table.Row>
                                        <Table.Cell colSpan={6}>
                                            <Flex direction="column" align="center" py="9" gap="3">
                                                <ClockIcon style={{ color: 'var(--gray-7)', width: 36, height: 36 }} />
                                                <Text size="2" color="gray">No records for this range and filters</Text>
                                            </Flex>
                                        </Table.Cell>
                                    </Table.Row>
                                ) : (
                                    logData.rows.map((row, idx) => (
                                        <Table.Row key={`${row.user_id}-${row.date}-${idx}`}>
                                            <Table.Cell><Text size="2">{dayjs(row.date).format('MMM D, YYYY')}</Text></Table.Cell>
                                            <Table.Cell><Text size="2" weight="medium">{row.employee_name}</Text></Table.Cell>
                                            <Table.Cell><Text size="2">{row.clock_in ? dayjs(row.clock_in).format('h:mm A') : '—'}</Text></Table.Cell>
                                            <Table.Cell><Text size="2">{row.clock_out ? dayjs(row.clock_out).format('h:mm A') : '—'}</Text></Table.Cell>
                                            <Table.Cell><Text size="2">{row.work_hours}</Text></Table.Cell>
                                            <Table.Cell><Badge variant="soft" color="gray">{row.remarks}</Badge></Table.Cell>
                                        </Table.Row>
                                    ))
                                )}
                            </Table.Body>
                        </Table.Root>
                    </ScrollArea>
                    {(logData?.last_page ?? 1) > 1 && !isLoadingLog && (
                        <TablePagination
                            pagination={{ currentPage, perPage, total: logData?.total ?? 0 }}
                            onPageChange={setCurrentPage}
                            onRowsPerPageChange={(v) => setPerPage(v)}
                            loading={isLoadingLog}
                        />
                    )}
                </Box>
            ) : error ? (
```

Then change the original `) : (` that opened the table+sidebar `Flex` to stay as the `else` of the `error` ternary (i.e. the structure becomes `{rangeMode ? (<log>) : error ? (<error>) : (<daily body>)}`). The trailing map/`UserLocationsCard` block stays gated by `isAdminView`; also gate the map so it only shows in single-day mode:

Change:

```jsx
            {isAdminView && (
                <Box mt="4">
                    <ErrorBoundary>
                        <UserLocationsCard selectedDate={selectedDate} updateMap={updateMap} />
                    </ErrorBoundary>
                </Box>
            )}
```

to:

```jsx
            {isAdminView && !rangeMode && (
                <Box mt="4">
                    <ErrorBoundary>
                        <UserLocationsCard selectedDate={selectedDate} updateMap={updateMap} />
                    </ErrorBoundary>
                </Box>
            )}
```

`Badge` is already imported from `@radix-ui/themes` at the top of the file.

- [ ] **Step 7: Reset to page 1 when filters change**

The existing `useEffect(() => { setCurrentPage(1); }, [selectedDate, employeeQuery, perPage]);` should also reset on `toDate` and filter changes. Update its dependency array:

```jsx
    useEffect(() => {
        setCurrentPage(1);
    }, [selectedDate, toDate, employeeQuery, perPage, deptFilter, desigFilter, statusFilter]);
```

- [ ] **Step 8: Manual verification in the browser**

With `npm run dev` running, open `https://aero-enterprise-suite.test` → Attendance → Daily Timesheet as an admin. Verify:
1. Default (Today) shows the present table + Absent sidebar + Map exactly as before.
2. Select preset "This Month" (or set a custom `to` date later than `from`) → sidebar/map hide; the ranged log table appears with Date/Employee/Clock In/Out/Hours/Status.
3. Set Status = Absent → only absent rows show; the count/pagination updates.
4. Set Department/Designation → log narrows accordingly.
5. Click Excel → a toast shows "Export started…" then "Download complete!"; the `.xlsx` contains only the filtered rows.
6. Click PDF with a >62-day range → an error toast appears (422 from the cap).
7. Switch the preset back to "Today" → daily experience returns intact (inline edit, mark-present still work).

Expected: all seven behaviors hold.

- [ ] **Step 9: Commit**

```bash
git add resources/js/Pages/Attendance/AttendancePage.jsx resources/js/Pages/Attendance/DailyTimesheetTab.jsx
git commit -m "feat(attendance): ranged log mode + filters + WYSIWYG export in Daily tab"
```

---

## Task 10: Full regression pass + final verification

**Files:** none (verification only)

- [ ] **Step 1: Run the attendance backend tests**

Run: `php artisan test --filter=Attendance`
Expected: PASS — including the existing `AttendanceExportAndStatsTest` (daily + monthly exports untouched) and the new `RangedAttendanceLogTest`.

- [ ] **Step 2: Run the frontend unit tests**

Run: `npx vitest run resources/js/Pages/Attendance`
Expected: PASS — `logRange.test.js` plus existing attendance JS tests.

- [ ] **Step 3: Confirm the WYSIWYG guarantee manually**

With a filter applied in range mode, compare the on-screen `total` to the exported row count for the same filter. They must match. If they differ, the endpoint and export are not consuming identical filters — fix before closing.
Expected: counts match.

- [ ] **Step 4: Final commit (if any cleanup was needed)**

```bash
git add -A
git commit -m "test(attendance): regression pass for ranged log + export"
```

---

## Self-Review (completed during authoring)

- **Spec coverage:** range + presets (Tasks 7, 9); status/department/designation/employee filters (Tasks 2, 3, 9); auto mode-switch (Tasks 7, 9); on-screen log (Tasks 4, 9); XLSX + PDF export mirroring filters (Tasks 5, 6, 9); queued job reuse + polling (Task 6, existing utils); single source of truth (Task 3 consumed by both 4 and 6); role/admin scoping via existing route group + optional `user_id` filter (Task 3); PDF day cap (Task 6); performance via additive engine reuse + pagination (Tasks 3, 4); tests (every backend task + Tasks 7, 10). No CSV (excluded per spec). Monthly/daily exports untouched (Global Constraints + Task 10 Step 1).
- **Placeholder scan:** none — every code/step contains full content.
- **Type consistency:** `getRangedAttendanceLog(Carbon, Carbon, array)` row keys (`status`, `status_code`, `is_complete`, `clock_in`, `clock_out`, `work_hours`, `employee_name`, …) are produced in Task 3 and consumed identically in Tasks 4/5/6/9; `ExportAttendanceReport` 6th arg `array $filters` defined in Task 6 and dispatched with the same arity in the controller; hook names `useAttendanceLog`/`useExportAttendanceLog` defined in Task 8 and imported in Task 9; helper names `RANGE_PRESETS`/`resolvePreset`/`isRangeMode` defined in Task 7 and imported in Task 9.
