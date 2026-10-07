# Attendance module audit - 2026-10-04

Scope: punch in/out (web, mobile, offline sync), roster / shifts / patterns / pinned cells, daily partition,
monthly grid, regularization / overtime / swaps / punch exceptions, auto punch-out, shift alerts, absence streak,
biometric ingest + clock offset, reports and exports. Method: code reading plus the failing-test triage; every
fix below has a test. Production was not contacted; `.env` was not read.

Root cause worth knowing first: the test database is SQLite and still has an integer `users.id` column, while
production (MariaDB) dropped it in `2026_08_22_000002_convert_employee_id_to_primary_key` (employee_id is the
primary key). Anything that touches `users.id` in SQL passes in tests and fails only in production. A grep for
that pattern across `app/` is now clean (the only hits were the two below).

## Part 1 - failing-test triage

| Test | Verdict | Cause | Resolution |
|---|---|---|---|
| Api/MobileAttendanceApiTest (present-users) | REAL | `(int) $user->id` cast turned every string employee_id into `0` in the mobile + web payloads | product fix (F-04) |
| Api/MobileProfileApiTest | STALE | asserted `users.id = $user->id` (employee_id string) | asserts `employee_id` |
| Api/MobileSwapPickupTest (200 not 422) | STALE | controller deliberately clamps to 62 days (mobile asks for 60); test still expected 422 over 31 days | replaced with a clamp test + an inverted-range 422 test |
| AbsentUsersTest, AbsentUsersUpcomingTest, AuditHistoryApiTest (404), DailyOverviewStatsTest (late 0 vs 2), LeaveStatusGridTest, MonthlyCalendarOtBucketsTest, UnifiedPagePropsTest | STALE | acting admin held the retired `Admin` role; DepartmentScope is fail-closed so the actor saw nobody (404 / empty) | use `Administrator` (a GLOBAL_ROLES member) |
| MonthlyGridEngineCollapseTest | STALE | helper typed `int $id` | `string $id` |
| Coverage/CoverageEndpointTest, CoverageServiceTest | REAL | `CoverageService::actualPunchWeights` selects `attendances.shift_id` / `work_location_id`, columns that no migration creates: every coverage request touching a past/today date returned 500 in production too (F-03) | product fix + new regression test |
| Auth/MustChangePasswordTest (422) | STALE | admin create now requires an attendance method (AttendanceMethodRequiredTest) | test supplies one |
| Admin/ServerErrorTelemetryTest | STALE | `bootstrap/app.php` deliberately `stopIgnoring(ValidationException)` so failed forms land on the diagnostics board as warnings | test now asserts the warning row and that passwords are redacted |
| Biometric/BiometricAdminActionsTest (5) | REAL | `linkAttLogUser` queried `users.id`, re-keyed a PK via `save()` and released placeholders that cannot exist any more (F-02) | endpoint rewritten, 6 tests rewritten to the PK contract |
| BiometricModelIntegrityTest, TemplateAcquisitionTest, TemplateRoamingTest, DeviceClockOffsetTest | STALE | int assertions on `user_id` (model casts it to string; DeviceClockOffset looked up `users.id`) | string assertions |

## Part 2 - findings

Severity: Critical = breaks a production job/endpoint or crosses a data boundary; High = wrong numbers / permission gap
with clear impact; Medium = degraded or needs a product decision; Low = hygiene. "Fixed" = fixed in this change with tests.

| ID | Sev | Where | Evidence | Impact | Status / fix |
|---|---|---|---|---|---|
| F-01 | Critical | `app/Console/Commands/ProcessAbsenceStreak.php:70-77` (now rewritten) | offboarded-exclusion did `select('id')->from('users')` and `User::whereIn('id', ...)`; production has no `users.id` | the 23:30 absence-streak job (show-cause / deemed-resignation escalation, a legal process) crashes every night there is a roster. Also ignored holidays for "today" | Fixed: keyed on `offboardings.employee_id`, holiday short-circuit, `last_working_date < $today`. `AbsenceStreakGuardsTest` |
| F-02 | Critical | `Settings/BiometricDeviceController.php:1691` `linkAttLogUser` | `where('id','!=',$user->id)` (no such column), `$user->employee_id = $pin; save()` (re-keying a PK), `user #{id}` messages | the unknown-PIN remediation endpoint 500s in production; its "claim the PIN" step is impossible by design | Fixed: relinks stranded punches only when the target already carries the PIN (otherwise 422 as before); `BiometricAdminActionsTest` |
| F-03 | Critical | `Services/Attendance/CoverageService.php:193-215` | query on `attendances.shift_id`, `attendances.work_location_id` | `/attendance/coverage` 500s for any range including today/past; coverage warnings built on it are dead | Fixed: shift/location come from the rostered `roster_days` row (distinct per person per rostered shift). `CoverageServiceTest::test_actual_counts...` |
| F-04 | High | `Api/V1/AttendanceController.php:205,439-441`, `AttendanceQueryService.php:397`, `AttendanceController.php:621-623,822-823` | `(int) ($user?->id ?? 0)` | mobile present/absent/team payloads and web absent-users returned `user_id: 0` for every employee, so client keying/dedupe by user_id collapses | Fixed: strings. `MobileAttendanceApiTest` |
| F-05 | High | day partition `AttendanceDayPartitionService.php`, `UpcomingShiftService.php`, `AttendanceController::getDailyOverviewStats` | no holiday handling, while monthly grid, shift alerts, absence streak and `AttendanceStatusService` all treat a holiday as a rest day | on every company holiday each rostered employee without a punch was counted Absent (web timesheet, mobile team-day, absent-users, overview), contradicting the monthly grid | Fixed: `HolidayService::onDate/isHoliday` (memoised), holiday => `off` (additive `holiday` title key; shape otherwise frozen). `HolidayDayPartitionTest` |
| F-06 | High | `Api/V1/AttendanceController.php:1081`, `Services/Sync/DataSyncService.php` (punch branch) | web punch needs `attendance.own.punch`; `/api/v1/attendance/punch` and offline-queued punches checked nothing | a role stripped of own.punch (or a person who must not self-punch) could still punch from the app | Fixed: same permission on both mobile paths. `MobilePermissionParityTest` |
| F-07 | High | `Api/V1/AttendanceController.php:1163` `markPresent` | gated by "is a manager" + scope only; web needs `attendance.correct|create|update` | any person with direct reports / `leaves.approve` could write attendance (payroll-affecting) from mobile | Fixed: same permission set as the web route |
| F-08 | High | `Console/Commands/AttendanceAutoPunchOut.php` | (a) shift resolved from the punch-in moment, not the business date; (b) shift end earlier than punch-in gave punch-out < punch-in; (c) off-day rows were closed at "now" after at most one hour | night-shift punch-ins after midnight resolved the wrong day; negative worked time; people working an off day were punched out mid-shift and their day fragmented | Fixed: business date, `end <= in` falls back to in+8h, off-day row closes only after in+8h. Test that encoded (c) was replaced; `AutoPunchOutCommandTest` |
| F-09 | High | `Services/Attendance/RegularizationService.php::request` | requested punch-out could precede punch-in; any datetime (any month) accepted; both clients send `<date> <time>`, so a night-shift 06:00 out arrives as D 06:00 | approvers could apply a negative-duration or wrong-day correction | Fixed: window [D, D+1 EOD]; out<=in rejected unless the shift crosses midnight, in which case out rolls to D+1. `RegularizationServiceTest` |
| F-10 | High | `Models/HRM/Shift.php::toSchedule` | `end` rolled to tomorrow when end<=start but `crossesMidnight` stayed false unless the stored flag was set | `resolveBusinessDate` (punch rebind after midnight) keys off `crossesMidnight`; a night shift saved without the flag marked workers absent / created phantom rows | Fixed: flag follows the real window. Full shift/overnight suites green |
| F-11 | Medium | `Api/V1/AttendanceController.php:880+` monthly summary | read `holidays` raw (ignored `is_active`, annual_fixed recurrence) | mobile monthly working days differed from the web grid | Fixed: uses `HolidayService::forRange` |
| F-12 | Medium | `ResolvesTeamMembers::isManagerUser` (mobile) vs web `permission:attendance.view` | mobile team reads open to anyone with direct reports / department head / leaves.approve | scope still applies, but route/UI/mobile do not share one permission (granular-permissions standard) | Open: decide whether team reads should also require `attendance.view` (would lock out managers whose role lacks it) |
| F-13 | Medium | `AttendanceReportService.php:869-940`, `RosterService::resolveShift`, `RosterScheduleResolver::resolve` | per user-day: `RosterDay::exists()` + `resolveShift` query + lazy `->shift` (>= 3 queries) | monthly grid page of 25 users x 31 days ~ 2,300+ queries; `generateRoster` is ~5 queries per user-day | Open: preload the month's roster/assignments per page |
| F-14 | Medium | `RosterController::generate` (`to` has no max), `RosterService::generateRoster` in `DB::transaction` | unbounded range; MyISAM tables make the transaction a no-op | a mistaken 10-year range, or a mid-run failure, leaves a half-written roster | Open: cap range (e.g. 93 days), validate before writing |
| F-15 | Medium | `ExportAttendanceReport.php:104-166`, `AttendanceController::exportAdminExcel` | exports written to the PUBLIC disk, URL returned, never pruned; `month` unvalidated and placed in the filename | PII files reachable by URL indefinitely (names are random but unauthenticated); filename injection | Open: private disk + signed/authorised download, schedule a prune, validate `month` |
| F-16 | Medium | `Services/Attendance/OvertimeService.php::approve` | comp-off credited from `requested_minutes`, not worked minutes; no date bound, no duplicate guard | approver is the only control over inflated claims | Open: cap against actual punched OT, reject duplicate date |
| F-17 | Medium | `AttendanceController.php:1066-1095` daily overview | `present` counts any role with a punch, `total/absent` count only the Employee role | present + absent + leave can exceed total; on_leave ignores whether the person punched | Open: reuse `AttendanceDayPartitionService` counts |
| F-18 | Medium | `config/app.php:108` | `APP_TIMEZONE` defaults to `UTC` (`.env.example` sets Asia/Dhaka) | a production env missing the variable silently shifts every business-date and shift-start comparison by 6h | Ops: confirm `APP_TIMEZONE=Asia/Dhaka` in production `.env` (not read here) |
| F-19 | Medium | `ShiftLifecycleAlertService::hasPunchedIn` / `resolveShift` per candidate | one query per rostered employee every 5 minutes | scales linearly with headcount | Open: batch |
| F-20 | Low | `AttendanceController::updateAttendance` (:356) | not routed, validates `user_id` as `integer`, no scope check | dead, unsafe if ever wired | Open: delete |
| F-21 | Low | `RegularizationService` types `missing_punchin` / `missing_punchout` accept no time | an existing mobile test relies on it | approving it is a no-op | Open: product decision |
| F-22 | Low | `AttendanceController::getUserLocationsForDate` | returns `AttendanceType::all()` configs to every `attendance.view` holder | configs may carry QR/IP settings | Open: trim to base_slug/name |
| F-23 | Low | `OvertimeService` | overtime requests reuse `TimeCorrectionRequestedNotification` wording; sends via `->notify()` rather than `NotificationRecipients` | copy/policy drift | Open |
| F-24 | Low | tests use the retired `Project Manager` role (MobileAttendanceApiTest etc.) | pass through the direct-reports heuristic | masks role-catalog drift | Open |

Not deeply audited (time): biometric live-push ingest internals and the swap pipeline internals (both have large,
green suites); the web React attendance pages (no resources/js file changed).

## Operations / production actions

1. Deploy, then run `php artisan attendance:absence-streak --date=<yesterday> --dry-run` to confirm the job runs
   (it could not have run on production since the PK change when anything was rostered).
2. Confirm `APP_TIMEZONE=Asia/Dhaka` in production `.env` (F-18).
3. Confirm every Employee-base user holds `attendance.own.punch` before deploying F-06 (the role catalog gives it to
   Employee; a hand-edited role would now be blocked on mobile and offline sync). Same for `attendance.update` /
   `attendance.correct` on anyone who marks attendance from the app (F-07).
4. The mobile app needs no change: `user_id` was a number only because of the bug and is a string everywhere else.
5. `storage/app/public/exports` is world-readable and unpruned (F-15): delete old files and schedule a prune.
