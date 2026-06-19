# Attendance Subsystem — Deep-Dive Findings

**Date:** 2026-06-18
**Phase:** 1 (investigation; no code changes)
**Method:** Static trace (FE→route→controller→service→model→DB) + Playwright runtime pass.
**Login used:** `emam@dhakabypass.com` (admin/super-admin). App served at `http://127.0.0.1:8000`.
**Legend:** `[RUNTIME]` = needs/has live confirmation in browser. Severity: Critical / High / Medium / Low.

> Organized by **surface → CRUD operation**, then **cross-surface inconsistencies**, then **missing sub-features**. Findings from the earlier whole-app audit (`PAGES_AUDIT_2026-06-17.md`) for employee + admin records are referenced, not repeated in full.

---

## Surface 4 — Attendance Settings (`Settings/AttendanceSettings.jsx`, `AttendanceSettingController`)

### Read (load settings page)

- **[Critical][Frontend/Migration] The entire Settings page renders undefined components → runtime crash.**
  - Location: `resources/js/Pages/Settings/AttendanceSettings.jsx` — uses `<Tab>` (`:1122,1329`), `<Tabs selectedKey … onSelectionChange>` (HeroUI API, `:1111`), `<Accordion>`/`<AccordionItem>` (`:1343,1350`), `<ScrollShadow>` (`:1331`), `<TableHeader>/<TableColumn>/<TableBody>/<TableRow>/<TableCell>` (`:1418-1454`). Imports (lines 1, 29, 44) only bring Radix Themes + heroicons; none of these names are imported or defined locally (confirmed by grep).
  - Evidence: `Tabs` is imported from `@radix-ui/themes` but driven with HeroUI props/children; `Tab`, `Accordion`, `AccordionItem`, `ScrollShadow`, `TableHeader`, etc. are undefined identifiers.
  - Impact: `ReferenceError` on render → Attendance Settings (both General and Types tabs) is non-functional. Incomplete HeroUI→Radix migration.
  - Fix: Finish the migration — Radix `Tabs.Root/List/Trigger/Content`, `Table.Root/Header/Body/Row/ColumnHeaderCell/Cell`, replace `Accordion`/`ScrollShadow` with Radix equivalents (`Accordion` from a supported lib or `ScrollArea`). [RUNTIME] confirm in browser.

### Update (save general settings)

- **[High][Integration/Logic] Settings update validates and persists a *different* field set than the UI/model expects — several fields silently never save.**
  - Location: `AttendanceSettingController::updateSettings` (`:35-62`) vs `UpdateAttendanceSettingRequest` (unused) vs `AttendanceSetting` `$fillable` (`:12-22`) vs migration (`2025_06_16_072730…:14-26`).
  - Evidence: `updateSettings` uses inline `$request->validate([...])` covering only office times, break/late/early/overtime, weekend_days, auto_punch_out(+time). The `UpdateAttendanceSettingRequest` (with `authorize()` + richer rules: `allow_punch_from_mobile`, `attendance_validation_type`, `location_radius`, `allowed_ips`, `require_location_services`) **is never referenced**. The model `$fillable` and the DB table have **none** of those extra columns either. So any such fields the UI sends are dropped by mass-assignment.
  - Impact: Dead FormRequest; validation asymmetry; if the UI exposes mobile/validation-type/IP settings they don't persist. Confusing partial save.
  - Fix: Pick one source of truth — either use `UpdateAttendanceSettingRequest` and add the missing columns/fillable, or delete the unused fields from the request. Align FE↔request↔fillable↔DB.

- **[Medium][Validation] `weekend_days` required in FormRequest but only `array` (optional) in the live method; FE only offers Fri/Sat/Sun.**
  - Location: `updateSettings:45-46` (`'weekend_days' => 'array'`) vs `UpdateAttendanceSettingRequest:30` (`required|array`); FE checkboxes only Friday/Saturday/Sunday (`AttendanceSettings.jsx:1273-1304`).
  - Impact: Can save empty weekend_days via live path; Mon–Thu weekends not selectable in UI though backend `in:` allows them. Inconsistent with `in:` list.
  - Fix: Decide required-ness; expose all 7 days or restrict the `in:` list to match the UI.

- **[Medium][Data] `office_start_time`/`office_end_time`/`auto_punch_out_time` cast as `date:H:i` on a `TIME` column.**
  - Location: `AttendanceSetting.php:27,28,34` casts vs migration `time()` columns.
  - Impact: Carbon date-casting of a time-only column; serializes oddly and can mismatch the FE `type="time"` `HH:MM` expectation (`AttendanceSettings.jsx:1136`). [RUNTIME] verify the value round-trips into the time input.
  - Fix: Use a plain string/time accessor or `H:i` formatting consistent with the input.

- **[Low][Backend] No `authorize()` in `updateSettings` itself; relies solely on route `permission:attendance.settings`.** The dead `UpdateAttendanceSettingRequest` uses a *different* permission name `update-attendance-settings`. Consolidate.

### Create (first-time settings)
- **[Low][Logic] `AttendanceSetting::create($data)` on first save only sets `weekend_days` default via model `creating` hook if null; the live validator allows empty array, so first save can persist `[]` weekends.** (`AttendanceSetting.php:43-47`).

---

## Surface 5 — Attendance Types + configs (`AttendanceSettingController`, `AttendanceType`, validator factory)

### Create (`storeType`)

- **[High][Logic/Consistency] Slug whitelist omits biometric & QR naming is inconsistent with the validator factory and config keys.**
  - Location: `storeType:326` (`slug in:geo_polygon,wifi_ip,route_waypoint,qr_code`) vs `AttendanceValidatorFactory:27-46` (also handles `biometric`) vs config keys `polygons/ip_locations/routes/qr_codes`.
  - Evidence: Cannot create a `biometric` type here (only seeded). Slug `geo_polygon` ↔ config key `polygons`, `wifi_ip` ↔ `ip_locations`, `route_waypoint` ↔ `routes` — slug names and config-array keys differ, a constant source of confusion across migration/FE/validators.
  - Impact: Biometric types unmanageable via UI; mapping confusion risks mis-keyed configs.
  - Fix: Centralize a slug↔configKey↔validator map; decide whether biometric is user-creatable.

- **[Medium][Logic] No authorization beyond route; no uniqueness/limit on number of types per category; `priority`/`required_permissions` not settable on create.** `storeType` always sets `required_permissions => []`.

### Update (`updateType`) & config items (`addConfigItem`/`removeConfigItem`/`generateQrCode`)

- **[High][Race] All config mutations are read-modify-write on the JSON `config` with no transaction/lock.**
  - Location: `addConfigItem:165-236`, `removeConfigItem:241-274`, `generateQrCode:279-316`, and FE `handlePolygonSubmit`/`handleWaypointSubmit` send the whole rebuilt `config` via `PUT updateType`.
  - Impact: Concurrent edits (two admins, or add-item while editing) silently overwrite each other's config arrays (lost polygons/routes/QR codes).
  - Fix: Wrap in `DB::transaction` + `lockForUpdate`; prefer targeted array ops server-side over whole-config replace from the client.

- **[Medium][Integration] FE primarily updates configs by sending the *entire* rebuilt `config` (PUT), bypassing the purpose-built `addConfigItem`/`removeConfigItem`/`generateQrCode` endpoints.**
  - Location: `AttendanceSettings.jsx:934,980` (`axios.put('/settings/attendance-type/{id}', { config })`) vs unused `add-item`/`remove-item`/`generate-qr` routes (`web.php:503-505`).
  - Impact: Two parallel mechanisms; the server-side QR generation/validation in `generateQrCode` (expiry, codes) is skipped when the client builds config directly. Inconsistent QR creation paths.
  - Fix: Use the dedicated endpoints from the FE, or remove them.

- **[Medium][Validation] `updateType` "config-only" branch is detected by `count($requestData) === 1`** (`:70`). Any extra incidental field (e.g. an axios-added field) flips it to the full-update branch with looser `config => nullable|array` validation. Fragile heuristic.

### Delete (`destroyType`)

- **[High][Data/Cascade] Deleting a type does NOT check if users/biometric devices reference it — orphans `users.attendance_type_id` and `employee_attendance_types`/pivot rows.**
  - Location: `destroyType:369-381` (comment: "we'll allow deletion"); `AttendanceType` has `users()` hasMany (`AttendanceType.php:39`) and `biometricDevices()` belongsToMany pivot.
  - Impact: Employees left pointing at a non-existent type → punch validation throws `Unsupported attendance type` (factory `default` branch) / users can't punch. Pivot rows dangle.
  - Fix: Block deletion when in use (or reassign + detach pivot) inside a transaction.

### Read (list types)
- **[Low][Perf/Contract] `index` eager-loads `biometricDevices` for every type and ships full config (with QR codes incl. any secrets) to the client.** Verify QR code secrets aren't exposed in the Inertia props. [RUNTIME]

---

## Environment & app-wide blockers discovered during the runtime pass

These are NOT attendance-specific, but each blocked the live pass and several are genuine app bugs. Listed in the order hit.

- **[Env] Local DB lost AUTO_INCREMENT on 217/218 `id` columns and PRIMARY KEY on 192 tables.** Classic mysqldump import artifact. Broke *every* insert app-wide (login, request logs, attendance, …). **Repaired** non-destructively via `docs/audit/fix_autoincrement.sql` after a `mysqldump` backup (`storage/db-backups/`). Verified 0 remaining. This is environmental, not app code.

- **[Critical][App] `upgrade-insecure-requests` CSP meta is hardcoded, ungated by environment.**
  - Location: `resources/views/app.blade.php:14`
  - Evidence: `<meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">` forces every XHR/fetch (login + all API) to `https://`. On the HTTP dev server (and any HTTP deployment) every API call dies with `ERR_CONNECTION_CLOSED`.
  - Impact: App unusable over HTTP; total API failure.
  - **Applied (to unblock runtime):** wrapped in `@production … @endproduction`. Review/keep this fix.

- **[Critical][App] Module-navigation builder triggers `LazyLoadingViolationException` → 500 on authenticated page render in dev.**
  - Location: nav builder consumed by `HandleInertiaRequests` (builds `user_navigation_*`); lazy-loads `module` on `App\Models\SubModule`. Guard at `app/Providers/AppServiceProvider.php:42` (`Model::preventLazyLoading(! isProduction())`).
  - Evidence: GET `/settings/attendance` → 500 `Attempted to lazy load [module] on model [App\Models\SubModule] but lazy loading is disabled` (query log shows modules→sub_modules built, then throws on `SubModule->module`).
  - Impact: In dev, crashes authenticated pages that rebuild nav; in production, silent N+1 across the whole module/sub-module/component tree on every request (the long query list in the error confirms the N+1 fan-out).
  - Fix: eager-load `module` (and the component/permission relations) where the nav tree is assembled; do not rely on lazy loading.
  - Status: **NOT fixed** (outside attendance scope) — currently blocks the attendance runtime pass.

- **[Data] Login account `emam@dhakabypass.com` password did not match `123456789`.** Reset locally to the provided value to enable testing (account = Super Administrator + Employee). Likely just local seed drift.

- **[Deferred / user-flagged] Inconsistent "session expired" behavior across web vs mobile login.** To investigate in Phase 2. Note: Inertia manages XSRF/CSRF tokens automatically (per user) — the inconsistency is likely session lifetime/guard config, not manual token handling. Linked to revisit with the auth flow.

## Runtime confirmations (Playwright, logged in as Super Administrator + Employee)

- **[Critical][CONFIRMED] Attendance Settings page crashes on render: `ReferenceError: Tab is not defined`** (`build/assets/js/AttendanceSettings-*.js`). Validates the static undefined-component finding (incomplete HeroUI→Radix migration). User sees a broken/empty page. Screenshot: `attendance-settings-crash.png`. **The entire Settings + Types management UI is non-functional** — none of its CRUD can be exercised until this is fixed.
- **[Low][CONFIRMED] `/api/log-error` returns 419 (CSRF token mismatch)** — the client-side error-telemetry endpoint itself fails, so front-end crashes aren't being reported. Fix: exempt or correctly token the error-logging route.
- **[Low][CONFIRMED] Employee page double-fetches `/attendance/my-monthly-stats`** on load (manual `refetch()` + query-key) — matches the earlier audit's redundant-fetch finding.

### Corrections to the earlier whole-app audit (now verified fixed in the working tree)
The uncommitted changes to `AttendanceController.php` / `useAttendanceQuery.js` / `AttendanceSetting.php` have **already resolved** two Criticals from `PAGES_AUDIT_2026-06-17.md`:
- **RESOLVED:** `get-current-user-attendance-for-date` now returns `{ attendances:[…], total, current_page, last_page, per_page }` (verified live) — the FE-expected shape. The "permanently empty My Attendance table" is fixed; it now returns paginated records.
- **RESOLVED:** `/attendances-admin-paginate` now includes a `settings` key in its response (verified live) — the monthly calendar's weekend-day source is present.
- Note: `get-current-user-attendance-for-date` still ignores the `date` param and returns a paginated recent list regardless (cosmetic naming/contract smell, not a break).

## Applied changes (made to unblock the runtime pass — review these)
1. `resources/views/app.blade.php` — gated the `upgrade-insecure-requests` meta to `@production` (was breaking all HTTP/API). **Keep — correct fix.**
2. `app/Http/Middleware/HandleInertiaRequests.php` — `accessibleModules` now returns `[]` instead of the eager DB nav build (per your directive: nav is built from `resources/js/Props/pages.jsx`). Removes the dev crash + prod N+1. `ModulePermissionService` import is now unused. **Keep / clean up the dead nav system (`dynamicNavigation.jsx`, `useSt.jsx`, ModulePermissionService nav, modules/sub_modules tables) in Phase 2.**
3. DB: non-destructive AUTO_INCREMENT/PK repair applied (`fix_autoincrement.sql`), backup in `storage/db-backups/`. Environmental.
4. Local test account password reset to the provided value. Environmental/data.

## Surface 5b — Touchpoints (mobile API, biometric, rate limit)

- **[High][Security] `queueCommand` device commands require only `web`+`auth`, no permission gate.**
  - Location: `routes/api.php:40-42` → `BiometricWebhookController::queueCommand` (`:372-420`), command_type allows `REBOOT, CLEAR_LOG, CLEAR_DATA, DELETE_USER, ...`
  - Impact: ANY authenticated user (e.g. a plain Employee) can POST `/api/biometric-devices/{id}/commands` to reboot/factory-clear a device or delete users from it.
  - Fix: gate with a `permission:biometric.manage` (or similar) + policy.

- **[Medium][Security] ADMS `/iclock/*` endpoints have no authentication — serial number only.**
  - Location: `routes/iclock.php:27-38` (only `throttle:300,1`); `admsPush` accepts attendance logs, **user enrollment**, and **biometric template uploads** keyed solely on `findDeviceBySerial` (`BiometricWebhookController:300-367`).
  - Impact: Anyone who can reach the endpoint with a known/guessed serial can inject attendance, enroll users, or upload templates. Relies entirely on network isolation, which isn't enforced in-app.
  - Fix: IP-allowlist the device subnet and/or require the device token on ADMS where the firmware allows; at minimum document the network-isolation requirement.

- **[Medium][Logic] `admsPush` ignores `processAttendanceLogs` result and always returns `OK`.** `BiometricWebhookController:360-366`. Silent ingestion failures; the device believes logs were accepted. Fix: return ERROR on failure so the device retries.

- **[Low][Security] Rate-limiter "suspicious location jump" only logs, never blocks.** `AttendanceRateLimit:34-40` — toothless anti-spoofing. Fix: reject or flag-for-review on impossible travel.

- **[Low][Validation] Punch `photo` is an unbounded `string`.** `PunchAttendanceRequest:21` (`'photo' => ['nullable','string']`) — no size cap on a base64 image → large-payload risk. Fix: add `max:` / validate as image upload.

- **[Low][Backend] Web punch is rate-limited (`attendance.rate_limit`, 5/5min) but the limiter `hit()`s before the punch resolves**, so failed/blocked punches still consume the quota. Minor.

## Visual / responsive runtime pass (screenshots in repo root)

- **[Medium][Responsive] Employee "My Attendance Records" table overflows horizontally on mobile (375px).** Columns truncate ("Work Ho…", "Incompl…", "No punch out" wraps) with no mobile card layout or horizontal scroll affordance. `attendance-employee-mobile.png`. Fix: responsive table (stacked cards or scroll container).
- **[Low][Typography] Employee "My Attendance Records" heading is oversized**, disproportionate especially on mobile. `attendance-employee-mobile.png`.
- **[Medium][Logic/RUNTIME] Employee stats show 0 Present / 0 Working Days / 0h despite a visible punch-in record for the month.** Stats↔records inconsistency (the only record is "Incomplete"/no punch-out, but Present should still count a punch-in day). Verify `my-monthly-stats` counting logic. `attendance-employee-mobile.png`.
- **[Low][Consistency] Admin stat card label hardcodes "LATE (AFTER 9AM)"** — matches the backend hardcoded `09:00` late threshold (`AttendanceController:559-562`); both ignore `AttendanceSetting.office_start_time`/`late_mark_after`. `attendance-admin.png`.
- **[RUNTIME] Admin Daily Timesheet table showed skeleton loaders at capture** (data exists — paginate returns total 28). Verify it resolves and isn't a stuck skeleton. `attendance-admin.png`.
- Dark-mode pass not completed (theme toggle interaction); recommend a focused dark-mode sweep of employee + admin + (once fixed) settings.

## ⚠️ Critical caveat: served frontend assets were STALE
The app serves prebuilt assets from `public/build` (no Vite dev server / hot file; manifest dated Jun 17 16:49). The current `SettingsTab.jsx` source does NOT call `setisMutating` in its general-save path (it uses `updateAttendanceSettings.mutateAsync`), yet the served bundle `SettingsTab-B7DZVccn.js` threw `setisMutating is not defined` — proving the **served JS predates source fixes**. Therefore several runtime FE findings below (Save Settings crash, possibly the empty timesheet/calendar render, employee stats 0, polygon "Save Polygon" no-PUT) **must be re-verified against a fresh `vite build`** before being treated as current-source bugs. Backend findings (verified via direct API calls) are unaffected.

> Note: `npm run build` has a `postbuild` hook that runs `git add . && git commit && git push` — do not run it casually. Rebuild assets with `npx vite build` (no lifecycle hooks).

## 🎯 ROOT CAUSE (systemic) — `requestJson` throws on every call → all React Query data empty
**File:** `resources/js/api/client.js:48,69`. `responseType` is destructured **inside** the `if (isConfigObject)` block (line 48, block-scoped `const`), but referenced at line 69 (`if (responseType === 'blob')`) **outside** that block. In function scope `responseType` is undefined → `ReferenceError: responseType is not defined` thrown inside the `try` **after** the HTTP request succeeds → caught and rethrown as `ApiError`. So **every `requestJson` call rejects even though the server returned 200 with correct data.**

**Blast radius (all the "empty/0 despite data" findings collapse into this one bug):**
- Admin Daily Timesheet "No records" despite 16 rows; "Present/Absent/Total: 0".
- Admin Monthly Calendar "No data" despite 28 rows (uses `useMonthlySummary` → `requestJson`).
- Employee `/attendance-employee` stats 0 despite 7 records (backend returns `present:7, totalWork:22.6`, verified) — FE query errored.
- Any other `requestJson`-backed query/mutation (present/absent users, locations, leaves admin queries, etc.). Mutations' HTTP still hit the server (so writes persisted) but the FE saw an error.

**Fix applied:** `if (clientConfig.responseType === 'blob')` (read from the function-scoped `clientConfig`, set in both branches). One line. Rebuild required.

> This SUPERSEDES the earlier per-symptom hypotheses below (the "timezone date-key" theory for the timesheet, and the separate employee-stats finding). The UTC `date` serialization is still a data smell to clean up, but it was not the cause of the empty render.

## Diagnosis — Daily Timesheet empty-despite-data (narrowed; superseded by root cause above)
Confirmed on fresh build with date=2026-06-18: the page's own network response (`GET /admin/daily-timesheet?date=2026-06-18`) returns **16 records, `total:16`** (response body captured), but the table renders the empty state and the inline **Total shows 0**. The consumer is correct: `attendances = dailyTimesheetData?.attendances || []`, `totalRows = dailyTimesheetData?.total || 0` (`DailyTimesheetTab.jsx:341-342`). Since `total` renders 0, **`dailyTimesheetData` reaching the component is empty/undefined even though the HTTP fetch returned 16** → a **React Query data/key desync**, NOT a backend or render-filter bug. `selectedDate` is a prop; the date input is bound to it and reads 2026-06-18, so the date is correct. Suspects: the query key `{date, page: currentPage, perPage, employee: employeeQuery}` vs the resolved fetch key (e.g. `employeeQuery` from the `useAttendanceStore` Zustand global, or a remount reading a stale/empty key). **Next diagnostic:** add React Query Devtools or a temporary `console.log(dailyTimesheetData, isLoadingTimesheet)` then `npx vite build`, to confirm which key the component reads vs which key holds the 16-row data. (Note: page also showed churn — `?_t=` cache-bust + 185 console warnings — worth checking for a render/poll loop.)

## Granular UI walkthrough — Admin `/attendance`

> Methodology note: all findings below were captured **after** skeleton/loading states resolved (≥6s settle). Three items from an earlier pre-load snapshot were retracted after re-checking loaded state: the "Present:…/Total:… stuck", "6 empty rows", and "Excel/PDF disabled" were **loading artifacts** — once loaded, counts resolve to numbers, the table shows a proper empty state, and Excel/PDF enable. Lesson applied: snapshot only post-load.

### Daily Timesheet tab
- **[High][Integration/Timezone] Table shows "No attendance records for this date" despite 16 real records for that date.** Verified live (post-load): `/admin/daily-timesheet?date=2026-06-18` returns `total:16` with real punches (`punchin_time:"2026-06-18T04:05:52Z"`), yet the UI renders the empty state and "Total: 0". The row `date` serializes as UTC (`"2026-06-17T18:00:00Z"` for a Dhaka June-18 day), so the client's date matching/grouping drops every row. Meanwhile the stat cards (Present 16 / Absent 11, from `daily-overview`) DO count them — a direct stats-vs-table contradiction users will see. Fix: make the client match on the same timezone-normalized date the BE filtered by (or return a plain `YYYY-MM-DD` date), and reconcile timesheet vs overview.
- **[Medium][Perf] Excessive polling.** `check-timesheet-updates/{date}` and `check-user-locations-updates/{date}` fire repeatedly (multiple calls within the same second observed), plus extra `/admin/daily-timesheet` refetches. Fix: single focus-gated interval reconciled with React Query.
- **[Medium][Config] Firebase init fails:** `FirebaseError: Missing App configuration value: "projectId"` on every load → push/FCM notifications are dead. Fix: provide Firebase config (or guard init when unconfigured).
- **[Medium][Prod/CSP] Map routing uses the public OSRM demo server** (`router.project-osrm.org`) — "NOT SUITABLE FOR PRODUCTION" per its own console warning; also absent from the middleware CSP `connect-src`, so blocked in production. Fix: self-host/paid OSRM + CSP entry, or drop routing.
- **[Medium][Render] Team Locations map stuck on "Loading locations…"** though `/attendance/locations-today` returns 200 (verified persists after load). Fix: investigate map init/marker render.
- **[Low][UX] Stats-card label hardcodes "LATE (AFTER 9AM)"** (see also backend hardcoded 09:00).

### Monthly Calendar tab
> ✅ POST-FIX (requestJson): calendar now populates — **28 employee rows**, day header **1Mo…30Tu (all 30 days present)**, leave-type columns. The "empty grid", "missing day 30", and "weekend shading ignores config" were ALL the requestJson bug (no `settings`/rows delivered → empty grid + Sat/Sun fallback). Day-range code (`days = Array.from({length: daysInMonth})`, line 414) was always correct. Weekend shading now reads `settings.weekend_days`. Re-verify weekend coloring matches saved config once a real weekend config is saved.

- **[RESOLVED via requestJson fix] "No attendance data for this month" despite the month having records.** `/attendances-admin-paginate?currentMonth=6` returns `total:28` (verified live), but the calendar grid shows the empty state. Same family as the Daily Timesheet bug — data fetched, not rendered (likely the same date/timezone or key handling). Fix alongside the timesheet render bug.
- **[Medium][Logic] Calendar day header is missing the last day of the month.** June 2026 has 30 days; the header renders only `1 Mo … 29 Mo` (no `30 Tu`). Off-by-one in the day-range generation (likely `< daysInMonth` instead of `<=`, or a UTC date-range end). [RUNTIME] confirmed visually.
- Controls present: month picker, prev (`‹`) arrow, department filter, employee search, refresh, Excel/PDF. **[Low] No visible "next month" (`›`) arrow** — verify forward navigation exists.

### Settings tab (in-page) — and a major duplication finding
- **[High][Duplication] Two attendance-settings UIs exist; one works, one crashes.** The in-page `/attendance` → **Settings tab (`Attendance/SettingsTab.jsx`) renders and works** (General Settings + Attendance Types with add/edit), while the standalone `/settings/attendance` page (`Settings/AttendanceSettings.jsx`) **crashes** (`Tab is not defined`). The standalone page is redundant/dead (not linked in the `pages.jsx` nav). Fix: delete the broken standalone page (and its route) OR fix+consolidate to one source — don't maintain two.
- **[Medium][Logic] Weekend config not applied consistently.** The Settings tab shows **only Friday** checked as a weekend, but the Monthly Calendar shades **Saturday + Sunday** — the calendar isn't honoring the configured `weekend_days` (appears to fall back to hardcoded Sat/Sun). (This revises the earlier "weekend highlighting works" note — it shades, but with the wrong days.) Verify which store is authoritative and make the calendar read the saved config.
- **[Medium][Parity] Weekend options limited to Friday/Saturday/Sunday** (3 of 7 days) in this working UI too — Mon–Thu can't be configured though the backend `in:` rule allows them.
- General Settings fields present and populated: Start 09:00 AM, End 05:00 PM, Break 60, Late Mark After 30, Early Leave Before 30, Overtime After 30, Save Settings button; Attendance Types section with search + "Geo Polygon (2 configs, 2 active)" + Add + table (Name/Description/Status/Actions). CRUD of these to be exercised next.

### Employee `/attendance-employee` — dev-server review (2026-06-19) + fixes
The page had been refactored to use the shared `AttendanceOverview`. Two bugs found & fixed on the dev server:
- **[High][FIXED] Broken import path crashed the page in dev.** `AttendanceEmployee.jsx` imported `./Components/AttendanceOverview` (→ `Pages/Components/…`, nonexistent); the file is at `Pages/Attendance/Components/AttendanceOverview`. Dev server 500'd ("Failed to resolve import"); the prod build had hidden it with a stale chunk. Fixed import to `./Attendance/Components/AttendanceOverview`. Page now loads (title "My Attendance", 0 errors).
- **[High][FIXED] "My Attendance" overview showed ORG-WIDE DAILY stats, not the employee's own.** It rendered `<AttendanceOverview date={selectedDate}>` → `attendance.dailyOverview` (org-wide: Present 0/Absent 18/On Leave 10) while the records table below was the user's own — a mismatch. Extended `AttendanceOverview` with a `scope='self'` (monthly) mode using `/attendance/my-monthly-stats`, and changed the page to `<AttendanceOverview mode="monthly" scope="self" month={filterData.currentMonth} />`. Verified: now shows the employee's own **Present 7 / Absent 3 / On Leave 4 / Late Arrivals 7**.
- **[Low] Card reduction from the refactor:** the overview now shows 4 cards (Present/Absent/Late/On Leave); the previous version also had Working Days / Total Hours / Daily Avg / Overtime. If those matter, add them (data is in `my-monthly-stats` `hours`/`meta`).

### Employee `/attendance-employee` (earlier prebuilt-asset notes; superseded by the dev-server review above)
- **[High][Logic] Stats cards all show 0 despite 7 records in the selected month.** Table lists Jun 1–9 2026 with real clock-ins and work hours (7h 15m / 8h 6m / 7h 14m), but Working Days 0, Present 0, Total Hours 0h, Daily Avg 0h, Overtime 0 for June 2026. `my-monthly-stats` returns zeros while records exist — counting/period bug. (Confirmed post-load, not a skeleton artifact.)
- **[Low][Display] Concatenated text without spacing:** "Incomplete punch" + "Missing punch out" render as "Incomplete punchMissing punch out"; likewise "1 punchAll complete". Add separators.
- **[Low][Logic/Display] Rows show "No punch out" in Clock Out yet display a Work Hours total** (e.g. 7h 15m) — inconsistent; reconcile clock-out display with computed hours.
- Desktop layout is fine; the **table horizontal-overflow is mobile-only** (≤375px) — finding stands for mobile.
- **[Low][Typography] "My Attendance Records" heading oversized** (confirmed both viewports).

## Reclassification (per user direction, 2026-06-18)
**The standalone `/settings/attendance` page (`Settings/AttendanceSettings.jsx`) is DEPRECATED.** The canonical attendance settings/types UI is the **unified `/attendance` page's in-page Settings tab (`Attendance/SettingsTab.jsx`), which works.** Therefore:
- The "`Tab is not defined` crash" on the standalone page is **no longer a bug to fix — the page (and its route) should be REMOVED.** Downgraded from Critical-fix to cleanup-delete.
- All **backend** settings/types findings (settings-save field mismatch, type-delete orphaning, config-edit race, biometric command auth, ADMS) **still stand** — the working in-page tab calls the same `AttendanceSettingController`/routes.
- Likewise the dead DB-nav system and `dynamicNavigation.jsx`/`useSt.jsx` should be removed (nav is `pages.jsx`-only).

### CRUD walkthrough — in-page Settings tab (canonical) — INTERACTIVE
> ✅ POST-FIX VERIFIED (dev server, HMR): **all settings/type saves work.** Save Settings → `POST /settings/attendance` 200; Type edit → `PUT /settings/attendance-type/{id}` 200 with live table update (renamed → reverted). The earlier "save broken / no PUT" symptoms were the `requestJson` scope bug (mutations rejected after the request) — all resolved by the one-line client.js fix.

- **[RETRACTED — was stale build] "Save Settings setisMutating crash."** Initially observed on the stale prebuilt bundle. After `npx vite build`, **Save Settings works: `POST /settings/attendance` → 200, no error** (re-verified on fresh build). The current source was already correct. Lesson: rebuild before runtime-testing this SPA.
- Type **Edit modal opens and renders** (Name*, Description, …) — functional. **[Low][UX] Dialog opens low/below the fold** instead of vertically centered.
- Type groups enumerated: Geo Polygon (2), WiFi/IP (3), Route Waypoint (1), QR Code (0 → proper empty state "No qr code types yet"), Biometric (1, **read-only**, "managed from Biometric Devices admin panel" — explains why `storeType` excludes biometric; **intended**, retract the earlier "can't create biometric" as a bug).
- Each type row has Edit / Configure (map) / Delete actions; group-level **Add** buttons present.
- **Polygon map editor works (renders):** "Geo Polygon — K26 Office - Geo", live Leaflet map with tiles, "6 points · valid", editable per-point lat/lng list, "Add Point", "Save Polygon (6/3+ pts)". **[RUNTIME] Save outcome inconclusive** — clicking "Save Polygon" produced **no `PUT /settings/attendance-type/{id}` and no toast, modal stayed open**; needs verification (may share the SettingsTab save-handler defect).
- **[Low][UX] Type Edit and map-editor dialogs open low/below the fold** (not vertically centered) — consistent across modals.
- WiFi/IP and Route Waypoint edit/config use the same `updateType` path; given Save Settings is broken and polygon-save is inconclusive, **verify IP/waypoint saves actually persist** (treat as suspect until confirmed).

### CRUD save — backend vs frontend split (verified across ALL types)
Tested the save endpoints directly via the authenticated session for every type kind:
- **Backend works for all types:** `PUT /settings/attendance-type/{id}` → 200 for `geo_polygon` (id 11), `wifi_ip` (id 8), `route_waypoint` (id 5); `POST /settings/attendance-type` (create `qr_code`) → 200; `DELETE` of an unused type → 200 (confirms the new in-use guard allows deleting unused types).
- **Therefore the failures are in the frontend save handlers, not the API:** "Save Settings" throws `setisMutating is not defined` (no POST), and the polygon map-editor "Save Polygon" fired no PUT in the UI. **The fix target is `SettingsTab.jsx` (and its map-editor submit handlers), not the controller.** Recommend auditing every submit handler in `SettingsTab.jsx` for undefined setters / missing awaits.

## User-requested follow-up checks (backlog)
- **Attendance-settings consistency audit:** trace every field the settings UI sends → DB columns → all consumers (e.g. `office_start_time`/`late_mark_after` vs the hardcoded `09:00` late logic in `AttendanceController:559-562`; `weekend_days` vs the Monthly Calendar's Sat/Sun fallback; break/early/overtime usage). Confirm each saved setting is actually read where attendance is computed.
- **Web vs mobile session-expiry inconsistency** (separate auth investigation; Inertia manages XSRF automatically — likely session-lifetime/guard config).

## Remaining for full completion (not yet done)
- **Runtime CRUD exercises:** punch in/out, manual mark-present, bulk-mark, record edit/delete (admin), settings save, type create/edit/delete — Settings/Types CRUD is **blocked at runtime** until the `Tab is not defined` crash is fixed; records CRUD runtime walkthrough still pending.
- **Static touchpoints not yet exhaustively traced here:** mobile punch API (`Api/V1/AttendanceController`), biometric webhook/processing, sync, exports, `AttendanceRateLimit`. (Records logic, punch race/index, TIME-vs-DATETIME, status casing are covered in `PAGES_AUDIT_2026-06-17.md` and remain valid except where corrected above.)
- **Theme/responsive visual pass** (dark mode, mobile viewports) on employee + admin surfaces.
