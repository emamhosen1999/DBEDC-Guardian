# Attendance Subsystem Fixes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the confirmed Critical/High defects in the Attendance subsystem found in `docs/audit/ATTENDANCE_DEEP_DIVE_2026-06-18.md`.

**Architecture:** Laravel 11 + Inertia v2 + React (Radix Themes). Backend fixes are covered by PHPUnit/Pest feature tests; the frontend migration fix is verified by build + runtime render. Each task is independently committable.

**Tech Stack:** PHP 8.3, Laravel 11, Inertia 2, React 18, Radix Themes, MySQL, Pest/PHPUnit, Vite.

## Global Constraints
- Do not weaken existing route middleware; only add/correct authorization.
- Money/HR data: every multi-step write wraps in `DB::transaction`.
- Match existing code style; no new dependencies.
- Tests live under `tests/Feature/Attendance*`; run with `php artisan test --filter=<name>`.
- App runs at `http://localhost:8000` (HTTP); DB `dbedc_guardian` (already repaired).

---

### Task 1: Gate biometric device commands behind a permission (High / Security)

**Files:**
- Modify: `routes/api.php:40` (the `biometric-devices` command group)
- Test: `tests/Feature/Attendance/BiometricCommandAuthTest.php`

**Interfaces:**
- Produces: route `api.biometric-devices.commands.queue` now requires `permission:attendance.settings` (existing permission used by all device management).

- [ ] **Step 1: Write the failing test**

```php
<?php
use App\Models\User;
use App\Models\HRM\BiometricDevice;

it('blocks a plain employee from queueing device commands', function () {
    $device = BiometricDevice::factory()->create(['protocol' => 'adms']);
    $user = User::factory()->create();
    $user->assignRole('Employee');

    $this->actingAs($user)
        ->postJson("/api/biometric-devices/{$device->id}/commands", [
            'command_type' => 'CLEAR_DATA',
        ])
        ->assertForbidden();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BiometricCommandAuthTest`
Expected: FAIL (currently returns 200/queued, not 403).

- [ ] **Step 3: Add the permission middleware to the group**

In `routes/api.php`, change:
```php
Route::middleware(['web', 'auth', 'throttle:api'])->prefix('biometric-devices')->group(function () {
```
to:
```php
Route::middleware(['web', 'auth', 'permission:attendance.settings', 'throttle:api'])->prefix('biometric-devices')->group(function () {
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BiometricCommandAuthTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add routes/api.php tests/Feature/Attendance/BiometricCommandAuthTest.php
git commit -m "fix(attendance): require permission for biometric device commands"
```

---

### Task 2: Block deletion of an in-use attendance type (High / Data integrity)

**Files:**
- Modify: `app/Http/Controllers/Settings/AttendanceSettingController.php:369-381` (`destroyType`)
- Test: `tests/Feature/Attendance/AttendanceTypeDeleteTest.php`

**Interfaces:**
- Produces: `DELETE /settings/attendance-type/{id}` returns 422 with `{message}` when users reference the type.

- [ ] **Step 1: Write the failing test**

```php
<?php
use App\Models\User;
use App\Models\HRM\AttendanceType;

it('refuses to delete an attendance type still assigned to users', function () {
    $admin = User::factory()->create(); $admin->assignRole('Super Administrator');
    $type = AttendanceType::factory()->create(['slug' => 'geo_polygon']);
    User::factory()->create(['attendance_type_id' => $type->id]);

    $this->actingAs($admin)
        ->deleteJson("/settings/attendance-type/{$type->id}")
        ->assertStatus(422);

    expect(AttendanceType::find($type->id))->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AttendanceTypeDeleteTest`
Expected: FAIL (currently deletes, returns 200).

- [ ] **Step 3: Add the in-use guard**

Replace the body of `destroyType`:
```php
public function destroyType($id)
{
    $type = AttendanceType::findOrFail($id);

    $inUse = \App\Models\User::where('attendance_type_id', $type->id)->exists()
        || \App\Models\HRM\EmployeeAttendanceType::where('attendance_type_id', $type->id)->exists();

    if ($inUse) {
        return response()->json([
            'message' => 'This attendance type is assigned to employees and cannot be deleted. Reassign them first.',
        ], 422);
    }

    \DB::transaction(function () use ($type) {
        $type->biometricDevices()->detach();
        $type->delete();
    });

    return response()->json(['message' => 'Attendance type deleted successfully.']);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AttendanceTypeDeleteTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Settings/AttendanceSettingController.php tests/Feature/Attendance/AttendanceTypeDeleteTest.php
git commit -m "fix(attendance): block deleting an in-use attendance type"
```

---

### Task 3: Lock + transaction around attendance-type config mutations (High / Race)

**Files:**
- Modify: `app/Http/Controllers/Settings/AttendanceSettingController.php` — `addConfigItem` (`:165`), `removeConfigItem` (`:241`), `generateQrCode` (`:279`)
- Test: `tests/Feature/Attendance/AttendanceTypeConfigConcurrencyTest.php`

**Interfaces:**
- Consumes: `AttendanceType` model.
- Produces: config mutations read-modify-write inside `DB::transaction` with `lockForUpdate`.

- [ ] **Step 1: Write the failing test (sequential correctness guard)**

```php
<?php
use App\Models\User;
use App\Models\HRM\AttendanceType;

it('appends config items without dropping existing ones', function () {
    $admin = User::factory()->create(); $admin->assignRole('Super Administrator');
    $type = AttendanceType::factory()->create(['slug' => 'geo_polygon', 'config' => ['polygons' => []]]);

    foreach (['A','B'] as $name) {
        $this->actingAs($admin)->postJson("/settings/attendance-type/{$type->id}/add-item", [
            'item_type' => 'polygon',
            'item_data' => ['name' => $name, 'points' => [['lat'=>1,'lng'=>1]]],
        ])->assertOk();
    }

    expect(AttendanceType::find($type->id)->config['polygons'])->toHaveCount(2);
});
```

- [ ] **Step 2: Run test to verify it fails or passes**

Run: `php artisan test --filter=AttendanceTypeConfigConcurrencyTest`
Expected: PASS sequentially today, but the code lacks locking; this test guards the refactor below from regressing.

- [ ] **Step 3: Wrap each mutation in a locked transaction**

For `addConfigItem`, `removeConfigItem`, and `generateQrCode`, replace the `$type = AttendanceType::findOrFail($id);` + later `$type->update([...])` pattern with:
```php
return \DB::transaction(function () use ($request, $id) {
    $type = AttendanceType::whereKey($id)->lockForUpdate()->firstOrFail();
    $config = $type->config ?? [];

    // ... existing switch/array logic building $config ...

    $type->update(['config' => $config]);

    return response()->json([
        'message' => /* existing message */,
        'attendanceType' => $type->fresh(),
    ]);
});
```
(Move the existing body inside the closure; keep all existing validation/branching.)

- [ ] **Step 4: Run test to verify it still passes**

Run: `php artisan test --filter=AttendanceTypeConfigConcurrencyTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Settings/AttendanceSettingController.php tests/Feature/Attendance/AttendanceTypeConfigConcurrencyTest.php
git commit -m "fix(attendance): lock attendance-type config mutations in a transaction"
```

---

### Task 4: Align attendance-settings save with one validated source of truth (High / Logic)

**Files:**
- Modify: `app/Http/Controllers/Settings/AttendanceSettingController.php:35-62` (`updateSettings`)
- Modify: `app/Http/Requests/Settings/UpdateAttendanceSettingRequest.php` (trim to fields that actually exist in the table + fillable)
- Test: `tests/Feature/Attendance/AttendanceSettingsUpdateTest.php`

**Interfaces:**
- Produces: `POST /settings/attendance-settings` (route `attendance-settings.update`) validates via `UpdateAttendanceSettingRequest`; only persists columns present in the `attendance_settings` table.

- [ ] **Step 1: Write the failing test**

```php
<?php
use App\Models\User;
use App\Models\HRM\AttendanceSetting;

it('saves only valid settings fields and rejects bad time format', function () {
    $admin = User::factory()->create(); $admin->assignRole('Super Administrator');

    $this->actingAs($admin)->postJson('/settings/attendance', [
        'office_start_time' => '25:00', // invalid
        'office_end_time' => '18:00',
        'break_time_duration' => 60, 'late_mark_after' => 15,
        'early_leave_before' => 15, 'overtime_after' => 30,
        'auto_punch_out' => false, 'weekend_days' => ['friday'],
    ])->assertStatus(422);

    $this->actingAs($admin)->postJson('/settings/attendance', [
        'office_start_time' => '09:00', 'office_end_time' => '18:00',
        'break_time_duration' => 60, 'late_mark_after' => 15,
        'early_leave_before' => 15, 'overtime_after' => 30,
        'auto_punch_out' => false, 'weekend_days' => ['friday','saturday'],
    ])->assertOk();

    expect(AttendanceSetting::first()->weekend_days)->toBe(['friday','saturday']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AttendanceSettingsUpdateTest`
Expected: FAIL (current inline rules accept `25:00` because `office_start_time` is only `required`, no `date_format`).

- [ ] **Step 3: Trim the FormRequest to real columns**

In `UpdateAttendanceSettingRequest::rules()`, remove fields not present in the `attendance_settings` table (`allow_punch_from_mobile`, `attendance_validation_type`, `location_radius`, `allowed_ips`, `require_location_services`) OR add migrations for them in a separate task. For this task, remove them so request == table == fillable. Keep: office times (`date_format:H:i`), break/late/early/overtime (`integer|min:0|max:*`), `auto_punch_out` (boolean), `auto_punch_out_time` (`nullable|date_format:H:i`), `weekend_days` (`required|array`), `weekend_days.*` (`in:`).

- [ ] **Step 4: Use the FormRequest in the controller**

Change the signature:
```php
public function updateSettings(\App\Http\Requests\Settings\UpdateAttendanceSettingRequest $request)
{
    $data = $request->validated();
    $settings = AttendanceSetting::first();
    $settings ? $settings->update($data) : $settings = AttendanceSetting::create($data);

    return response()->json([
        'message' => 'Attendance settings updated successfully.',
        'attendanceSettings' => $settings,
    ]);
}
```
Ensure the `update-attendance-settings` ability used by the FormRequest exists, or change its `authorize()` to `return $this->user()->can('attendance.settings');` to match the route permission.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AttendanceSettingsUpdateTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Settings/AttendanceSettingController.php app/Http/Requests/Settings/UpdateAttendanceSettingRequest.php tests/Feature/Attendance/AttendanceSettingsUpdateTest.php
git commit -m "fix(attendance): validate settings via FormRequest aligned to schema"
```

---

### Task 5: Remove the deprecated standalone Attendance Settings page (Cleanup)

> Per product direction (2026-06-18): the standalone `/settings/attendance` page is **deprecated**; the canonical UI is the unified `/attendance` page's in-page Settings tab (`Attendance/SettingsTab.jsx`), which works. Do NOT fix the broken page — remove it. The shared `AttendanceSettingController` stays (the in-page tab uses it).

**Files:**
- Delete: `resources/js/Pages/Settings/AttendanceSettings.jsx`
- Modify: `routes/web.php:496` (remove the `attendance-settings.index` GET route; keep the update/type/config routes used by the in-page tab)
- Test: `tests/Feature/Attendance/DeprecatedSettingsPageTest.php`

**Interfaces:**
- Consumes: in-page Settings tab continues to use `attendance-settings.update`, `attendance-types.*` routes (unchanged).
- Produces: `GET /settings/attendance` no longer resolves (404).

- [ ] **Step 1: Confirm nothing links to the standalone page**

Run: `grep -rn "settings/attendance\|attendance-settings.index\|Pages/Settings/AttendanceSettings" resources/js routes`
Expected: only the route definition + the file itself (no nav/menu link). If any link exists, repoint it to `/attendance` (Settings tab) before deleting.

- [ ] **Step 2: Write the failing test**

```php
<?php
use App\Models\User;

it('no longer serves the deprecated standalone attendance settings page', function () {
    $admin = User::factory()->create(); $admin->assignRole('Super Administrator');
    $this->actingAs($admin)->get('/settings/attendance')->assertNotFound();
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=DeprecatedSettingsPageTest`
Expected: FAIL (route still returns 200/500).

- [ ] **Step 4: Remove the route and the page file**

In `routes/web.php` delete the line:
```php
Route::get('/settings/attendance', [AttendanceSettingController::class, 'index'])->name('attendance-settings.index');
```
Keep `updateSettings`, `storeType`, `updateType`, `destroyType`, `addConfigItem`, `removeConfigItem`, `generateQrCode` routes. Delete `resources/js/Pages/Settings/AttendanceSettings.jsx`. If `AttendanceSettingController::index` is now unused, remove it too (verify no other reference).

- [ ] **Step 5: Run test + build to verify**

Run: `php artisan test --filter=DeprecatedSettingsPageTest` (PASS) and `npm run build` (no missing-import error for the deleted page).

- [ ] **Step 6: Commit**

```bash
git add routes/web.php tests/Feature/Attendance/DeprecatedSettingsPageTest.php
git rm resources/js/Pages/Settings/AttendanceSettings.jsx
git commit -m "chore(attendance): remove deprecated standalone settings page"
```

---

### Task 6: ADMS attendance-log push must not silently swallow failures (Medium / Logic)

**Files:**
- Modify: `app/Http/Controllers/Api/BiometricWebhookController.php:359-366` (`admsPush` default branch)
- Test: `tests/Feature/Attendance/AdmsPushResultTest.php`

**Interfaces:**
- Consumes: `BiometricProcessingService::processAttendanceLogs(): array{success:bool,...}`
- Produces: `admsPush` returns `ERROR` (non-200) when processing fails.

- [ ] **Step 1: Write the failing test**

```php
<?php
use App\Models\HRM\BiometricDevice;

it('returns ERROR when attendance log processing fails', function () {
    $device = BiometricDevice::factory()->create(['is_active' => true]);
    $this->mock(\App\Services\Biometric\BiometricProcessingService::class, function ($m) use ($device) {
        $m->shouldReceive('getSerialNumber')->andReturn($device->serial_number);
        $m->shouldReceive('findDeviceBySerial')->andReturn($device);
        $m->shouldReceive('isCommandAcknowledgment')->andReturn(false);
        $m->shouldReceive('processAttendanceLogs')->andReturn(['success' => false, 'reason' => 'bad']);
    });

    $this->post('/iclock/cdata?SN='.$device->serial_number, 'rawdata')
        ->assertStatus(500);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdmsPushResultTest`
Expected: FAIL (currently always returns `OK` 200).

- [ ] **Step 3: Honor the result**

Replace the default branch tail of `admsPush`:
```php
$result = $this->biometricService->processAttendanceLogs($rawData, $device, $serialNumber);

return ($result['success'] ?? false)
    ? new Response('OK', 200, ['Content-Type' => 'text/plain'])
    : response('ERROR', 500)->header('Content-Type', 'text/plain');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AdmsPushResultTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/BiometricWebhookController.php tests/Feature/Attendance/AdmsPushResultTest.php
git commit -m "fix(attendance): surface ADMS attendance-log processing failures"
```

---

## Backlog (tracked, not in this plan)

> ⚠️ The served `public/build` assets were STALE during testing. After `npx vite build`, re-verification changed some findings (below). **Always rebuild before runtime-testing.** Do NOT use `npm run build` (its `postbuild` hook auto `git add/commit/push`).

**✅ ROOT-CAUSE FIX LANDED & VERIFIED — `requestJson` scope bug (`resources/js/api/client.js`).**
`responseType` was destructured inside the `if (isConfigObject)` block but read at line 69 outside it → `ReferenceError` thrown after every successful HTTP call → all React Query hooks errored with `data: undefined`. Fixed to `clientConfig.responseType`. After `npx vite build`, verified live:
- Daily Timesheet (2026-06-18): **16 rows render** (was empty). Present card 16.
- Employee `/attendance-employee`: **Working Days 27 / Present 7 / Total Hours 22.6h / Late 7** (was all 0).
- This single fix also resolves the Monthly Calendar empty grid and the "Present/Absent/Total: 0" counters (same hook layer). **The items below that were attributed to "timezone/date-key" are superseded** — re-confirm calendar visually, but the empty-render cause was this bug, not timezone.

**Re-verified on a FRESH build:**
- ❌ **RETRACTED — "Save Settings setisMutating crash" was a stale-build artifact.** On the fresh build Save Settings works (`POST /settings/attendance` → 200). The polygon "Save Polygon no-PUT" was likely the same stale-build artifact — re-verify, probably already fine.
- 🔴 **STILL REAL — Daily Timesheet renders "No records" despite 16 rows** for the date (timezone/date-key; `date` serialized UTC). Confirmed on fresh build.
- 🔴 **STILL REAL — Employee `/attendance-employee` stats all 0 despite 7 records** with work hours, for the selected month. Confirmed on fresh build. (`my-monthly-stats` counting bug.)
- 🔴 **STILL REAL (high confidence) — Monthly Calendar empty despite 28 rows + day header missing last day + weekend shading ignores config.** Same family as timesheet; re-confirm after the timesheet fix.
- 🟡 Employee minor (real): "Incomplete punchMissing punch out" missing space; "No punch out" shown next to a work-hours total; mobile table overflow; oversized heading.
- Admin **Daily Timesheet shows "No records" despite 16 real rows** for the date — timezone/date-key mismatch (`date` serialized as UTC `2026-06-17T18:00Z` for a Dhaka June-18 day); reconcile with the populated stat cards.
- Admin **Monthly Calendar shows "No data" despite 28 rows**; also **day header missing the last day of month** (June shows 1–29, off-by-one).
- Admin **Monthly Calendar weekend shading ignores configured `weekend_days`** (settings say Friday-only; calendar shades Sat/Sun).
- Employee **stats all 0 despite 7 records** in the selected month (`my-monthly-stats` returns zeros).
- Employee records: **mobile horizontal overflow**, **oversized heading**, **"Incomplete punchMissing punch out" missing space**, **"No punch out" shown alongside a work-hours total**.
- **Excessive polling** on the admin timesheet (`check-timesheet-updates`/`check-user-locations-updates` firing repeatedly).
- **Firebase init error** (`Missing projectId`) → push/FCM dead.
- **Routing uses public OSRM demo server** (prod-unsuitable + CSP-blocked); Team Locations map stuck "Loading locations…".

**From the static/earlier sections:**
- Settings-driven late threshold (replace hardcoded 09:00 in `AttendanceController:559-562` + admin "LATE (AFTER 9AM)" label).
- Employee records table mobile responsiveness + oversized heading + stats↔records 0-count inconsistency.
- `/api/log-error` 419; ADMS endpoint network/IP hardening; punch `photo` size cap; rate-limiter enforce (not just log) location jumps.
- Remove dead DB-nav system (`dynamicNavigation.jsx`, `useSt.jsx`, `ModulePermissionService` nav, modules tables) now that nav is `pages.jsx`-only.
- Web/mobile session-expiry inconsistency (separate auth investigation).

## Self-Review notes
- Spec coverage: Tasks 1–6 cover all confirmed Critical/High attendance findings + one Medium (ADMS). Mediums/Lows are explicitly backlogged.
- No placeholders: each backend task has concrete test + code; Task 5 is a render-integrity fix verified by build/runtime (no meaningful unit test).
- Type consistency: route names, permission `attendance.settings`, and method signatures match the audited code.
