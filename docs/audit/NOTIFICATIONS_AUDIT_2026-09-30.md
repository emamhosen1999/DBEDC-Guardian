# Notifications Audit — 2026-09-30

Scope: web/API (`DBEDC-Guardian`, working tree as of today incl. uncommitted changes) and mobile (`dbedc-mobile-app`). Static audit plus the existing test suites. No application code was changed. Anything needing a live device, live FCM/Expo, live SMTP or the production `.env` is marked **unverified**.

## (a) Verdict

**Partially working. The plumbing is good; the coverage and operations are not.**

- The core engine is sound: registry (`notification_types`) + per-user preferences + `database`/`mail`/custom `PushChannel` -> FCM (web tokens) and Expo (mobile tokens), with invalid-token cleanup and graceful degradation. 20 notification test files pass (one fails because of a test defect, see F-14).
- Only about 21 notification classes and roughly 8 features are wired: leave (partly), attendance requests, roster, shift alerts, absence streak, offboarding, OM alerts, RFI objections, biometric health. **Most of the business modules the brief lists notify nobody**: payroll/payslip, F&F settlement, assets, onboarding, probation, petty cash, department-scope grants, NCR/site instructions, letters, camera, tasks. Leave cancel and escalation are silent as well.
- **Two operational blockers make even the wired notifications unreliable in production:** the documented cPanel queue worker never drains the `notifications` queue (F-1), and forgot-password never sends an email (F-2).
- **Mobile is push-receive only.** It has no in-app list, no bell or badge, no logout unregistration, and a tap router that does not understand the server's payloads (F-4, F-5, F-6).
- Web push via VAPID (`laravel-notification-channels/webpush`) is installed but dead. The web relies on Firebase web tokens instead, and that path has no click handler.

## (b) Channel health

| Channel | Status | Evidence / notes |
|---|---|---|
| database (`notifications` table) | Working | Migration `2026_06_28_120108_create_notifications_table.php`. Listing, unread count, mark-read and read-all in `app/Http/Controllers/NotificationController.php:12-52`. Web routes `routes/web.php:1255-1259`, Sanctum `routes/api.php:131-137`. Web page `resources/js/Pages/Notifications/Index.jsx`, bell `Layouts/Header.jsx:58`. **Not exposed under `/api/v1`, and not consumed by mobile.** |
| Push: FCM (web tokens) | Works if credentials exist (unverified) | `FcmNotificationService.php` (lazy Firebase, multicast, `invalidTokens()` cleanup, `PushDispatcher.php:26-28`). Credential path is `config/firebase.php` (`FIREBASE_CREDENTIALS`, default `storage/app/firebase-credentials.json`) but `.env.example:127` sets `GOOGLE_APPLICATION_CREDENTIALS=storage/app/firebase/service-account.json`, a different path. `firebase.php` default project is `aero-hr` but `public/firebase-messaging-sw.js` hardcodes `dbedc-erp`. Real delivery: **unverified**. |
| Push: Expo (mobile tokens) | Code OK, delivery unverified | `ExpoGateway.php` chunks by 100, drops `DeviceNotRegistered` tickets. **Push receipts are never polled** (only send tickets). No Expo access token or `channelId`. FCM v1 credentials uploaded to EAS: **unverified**. |
| Web push (VAPID, `HasPushSubscriptions`) | Dead scaffolding | `User.php:46` uses the trait and `config/webpush.php` exists, but no `WebPushChannel`/`WebPushMessage` anywhere in `app/`, no subscription endpoint, no `VAPID_*` in `.env.example`, and `public/service-worker.js` has no `push` or `notificationclick` handler. |
| Web service workers | Partial | `firebase-messaging-sw.js` shows background messages but has **no `notificationclick`** (no deep link) and assumes `payload.notification` is present. `firebaseInit.js:35-60` and `useSt.jsx:49` treat `onMessage` as a one-shot promise (only the first foreground message is handled) and use `alert()`. |
| Mail | Broken for security mail; unverified elsewhere | `.env.example:111` `MAIL_MAILER=log` (SMTP block above it is unused). Only leave and a few attendance notifications have `toMail`. Password reset never sends (F-2). Mailables `SecurePasswordResetMail`, `PasswordChangedNotificationMail` and `PayslipEmail` are never used. Real SMTP: **unverified**. |
| Broadcast / realtime | Working design, limited scope | Firebase RTDB signal, not Reverb/Pusher. `RealtimeNotificationSignal` pings `signals/notif/{id}` on `NotificationSent` for channel `database` (`WriteRealtimeNotificationSignal.php`; `EventServiceProvider` is registered through `config/app.php:175`). Web bell subscribes (`useRealtimeNotifications.js`). The bell query has no polling fallback (`useNotificationsQuery.js:15`: `staleTime` only, despite the "poll" comments). `BROADCAST_CONNECTION=log`. **Mobile does not use it for a bell** (`useRealtimeNotificationSignal` is exported but never called). |
| SMS | Not present | No SMS code, config or dependency. |
| Queue | Configured, worker misconfigured | `QUEUE_CONNECTION=database` (`config/queue.php:16`, `.env.example:91`), `after_commit=false` on every connection. Worker is a cPanel cron (`docs/deploy/cpanel-queue-scheduler-and-token-ttl.md:101`), no Supervisor. Failed jobs go to the `failed_jobs` table but there is **no UI and no alert** (`artisan queue:failed` only). No `queue:prune-failed` in `routes/console.php`. With `sync` every send is inline, so a slow SMTP/FCM blocks the request (all the `try/catch` blocks are fail-soft, so nothing breaks, but latency). |

## (c) Coverage matrix

Legend: OK = works, PARTIAL = partial/broken, MISSING = missing. "Web" = in-app center + web push (FCM web token). "Mobile" = Expo push only (no in-app list).

Notification classes and their triggers (`app/Notifications/**`, all `ShouldQueue`, all have `toArray` and `toPush`; mail where noted):

| Class | via | Preference-aware | Recipients / trigger |
|---|---|---|---|
| LeaveApprovalNotification (+mail) | resolver `leave.requested` | Yes | Current approver on submit and on each level advance (`LeaveApprovalService.php:151,275,521`) |
| LeaveApprovedNotification (+mail) | `leave.approved` | Yes | Employee (`:747`) |
| LeaveRejectedNotification (+mail) | `leave.rejected` | Yes | Employee (`:767`) |
| LeaveOverrideNoticeNotification (+mail) | reuses `leave.approved`/`leave.rejected` | Yes | Superseded approvers (`:675`) |
| TimeCorrectionRequested/Decided (+mail on Requested) | `attendance.time_correction_*` | Yes | Approver / requester (Regularization **and Overtime**) |
| ShiftSwapRequested/Decided (+mail on Requested) | `attendance.shift_swap_*` | Yes | Counterparty / requester |
| RosterChangedNotification | `attendance.roster_changed` | Yes | Employee on single-cell edit (`RosterController.php:392`) |
| MissedPunchNotification | `attendance.missed_punch_in/out` | Yes | **Every** active user (`SendAttendanceReminders.php:66`), and users with no punch-out (`SendPunchOutReminder.php:37`) |
| ShiftStartReminder / MissingPunchIn / ShiftAbsence | proactive trait (fallback db+push if unseeded) | Yes | `attendance:shift-alerts` every 5 min (`SendShiftLifecycleAlerts.php:344`) |
| AbsenceStreakEscalationNotification | proactive trait | Registry row **not seeded** | Manager; HR Manager + Super Admin + dept head at higher stages (`ProcessAbsenceStreak.php:309`) |
| OffboardingInitiatedNotification | proactive trait | Registry row **not seeded** | Manager, dept manager, active dept-scope grantees (new `OffboardingInitiationNotifier`, `afterCommit`); HR Manager + Super Admin + manager again at LWD (`ProcessOffboardingLwd.php:211`) |
| BiometricDeviceSilentNotification (+mail) | proactive trait, `biometric.device_silent` | Yes | Holders of `attendance.settings`, de-duplicated by cache |
| OmAlertNotification (+mail) | resolver with fallback, `om.alert` | Registry row **not seeded** | `permission('om.incidents.manage')` or `om.maintenance.manage` |
| RfiObjectionNotification (+mail) | hardcoded `['mail','database',Push]` (`:41`) | **No** | Incharge, assignee, managers by role, creator; actor excluded |

Feature × event × channel:

| Feature | Event | Recipient | Web | Mobile push | Notes |
|---|---|---|---|---|---|
| Leave | Submit | First approver | OK | PARTIAL | Auto-approved (no approvers) path notifies nobody, not even the employee (`:129-144`). Push tap route on mobile is unmapped (F-5). |
| Leave | Approve final | Employee | OK | PARTIAL | Same |
| Leave | Approve intermediate | Next approver | OK | PARTIAL | `:275` |
| Leave | Reject | Employee | OK | PARTIAL | |
| Leave | Cancel (`LeaveCrudService::cancelLeave`) | Approvers / employee | MISSING | MISSING | No notify anywhere; `leave.cancelled` is seeded but has no class (F-9) |
| Leave | Escalation / overdue | Next tier | MISSING | MISSING | No leave escalation command exists |
| Attendance | Regularization request / decision | Approver / requester | OK | PARTIAL | Only first approver notified; a multi-level chain never notifies level 2+ (F-8) |
| Attendance | OT request / decision | Approver / requester | PARTIAL | PARTIAL | Reuses time-correction classes: copy says "time correction", `correction_id` = OT id, url `/attendance.unified` (F-13) |
| Attendance | Shift swap | Counterparty / requester | OK | PARTIAL | Manager decision step not notified |
| Attendance | Roster changes | Employee | PARTIAL | PARTIAL | Only `updateCell`; bulk/publish paths do not notify; notifies the actor if self-edited |
| Attendance | Missed punch-in reminder | All users | PARTIAL | PARTIAL | Sent to everyone at 22:17, regardless of leave/attendance, plus a duplicate FCM job (F-7) |
| Attendance | Absence streak / abscond | Manager, HR, dept head | PARTIAL | PARTIAL | Employee never notified of a notice/show-cause; `absconded` stage label reads "Monitoring"; HR not scoped by department (F-11) |
| Onboarding | Initiation / task assignment / completion | New hire, task owner | MISSING | MISSING | No notify in `OnboardingController` / services |
| Offboarding | Initiation | Manager, dept managers, scope grantees | OK | PARTIAL | HR Manager not told at initiation (only at LWD) |
| Offboarding | LWD | HR Manager, Super Admin, manager | OK | PARTIAL | Re-uses "Initiated" title/text at LWD, so managers get the same message twice |
| Offboarding | Task assignment | Task owner | MISSING | MISSING | |
| Assets | Assign / return | Employee / IT | MISSING | MISSING | |
| Payroll / payslip (flagged off) | Publish | Employee | MISSING | MISSING | `PayslipEmail` exists but is never sent (feature flagged off) |
| F&F settlement | Approve / disburse | Employee, HR, finance | MISSING | MISSING | |
| Probation | Due / confirmation | Manager, HR | MISSING | MISSING | `employees.confirm` route has no notify; no due-soon command |
| Department-scope grants (new) | Grant / revoke | Grantee, dept head | MISSING | MISSING | |
| Daily works / RFI objections | Submit / resolve / reject | Incharge, assignee, managers, creator | OK | PARTIAL | Ignores preferences; two code paths (`ObjectionService` and `ObjectionController::notifyRfiIncharges`); no scope filter (F-11) |
| Daily works | Assignment / status changes | Incharge | MISSING | MISSING | |
| Quality NCR / site instructions | Any | | MISSING | MISSING | No code |
| O&M | Major/critical incident create | `om.incidents.manage` | PARTIAL | PARTIAL | Inside `DB::transaction`, silent `catch (\Throwable) {}`, includes actor; **no status-change notices** |
| O&M | High/emergency WO create | `om.maintenance.manage` | PARTIAL | PARTIAL | Same; WO approval/assignment silent |
| O&M | SLA breach | `om.maintenance.manage` | PARTIAL | PARTIAL | Defects only; silent catch |
| O&M | Lane closure | Approver / requester | MISSING | MISSING | Permit is created with `status: requested` and nobody is told |
| Petty cash | Loan request / approve / reject | Approver / requester | MISSING | MISSING | |
| Camera monitoring | Alerts | | MISSING | MISSING | Feature is view-only |
| Tasks | Assignment / status | Assignee / incharge | PARTIAL (dead) | MISSING | `TaskNotificationService` uses `App\Notifications\PushNotification`, which does not exist (F-3) |
| Letters | Issue | Employee | MISSING | MISSING | |
| Biometric | Device silent | `attendance.settings` holders | OK | PARTIAL | |
| Account security | Password reset | User | MISSING | n/a | Email never sent (F-2) |
| Account security | Password changed | User | MISSING | n/a | Mailable exists, unused |
| Account security | New device login / 2FA change | User | MISSING | MISSING | No notification; device revoke exists without any notice |

Mobile column note: "PARTIAL" everywhere means push arrives on a registered device, but tapping it lands on a screen only for a few `type` strings (F-5) and nothing is listed in-app (F-4).

## (d) Findings (Critical -> Low)

### Critical

**F-1. Queue worker never drains the `notifications` or `security` queues.**
- Evidence: cron in `docs/deploy/cpanel-queue-scheduler-and-token-ttl.md:101,125` runs `queue:work` with no `--queue`; the default queue is `default`. Jobs pinned elsewhere: `SendAttendanceReminders.php:62` (`onQueue('notifications')`), `app/Mail/Auth/*Mail.php:54,58` (`security`).
- Repro: `php artisan attendance:reminders --user-id=X`, then run the documented worker: the jobs stay in `jobs` forever.
- Impact: attendance reminder FCM jobs never run. Any mail or job later routed to `security` would be dead too.
- Fix: cron flag `--queue=default,notifications,security` (or remove the `onQueue` pins). Add a scheduled `queue:prune-failed --hours=168` and a `failed_jobs` alert or admin view.

**F-2. Forgot-password never sends an email.**
- Evidence: `app/Http/Controllers/Auth/PasswordResetController.php:172-195`: `sendPasswordResetEmail` only `Log::info`s `email` and the **verification code**; `Mail::send` is commented out. `SecurePasswordResetMail` is defined but unused (grep, `app/`, `routes/`, `tests/`).
- Repro: POST `/forgot-password`; check the mail log: nothing sent, the OTP sits in `storage/logs`.
- Impact: password recovery is impossible for users, and the reset OTP leaks to anyone with log access.
- Fix: send `SecurePasswordResetMail` via `Mail::to($user)->send()`, remove the code from the log line, send `PasswordChangedNotificationMail` after a successful reset/change, set a real `MAIL_MAILER` in prod (unverified), add a `Mail::fake` test.

**F-3. `App\Notifications\PushNotification` does not exist, so Task notifications are broken.**
- Evidence: `TaskNotificationService.php:7,32,56,79,93,118` import and `new` it; `class_exists` returns `false` (checked with composer autoload), and it was never in git history. Call sites `TaskCrudService.php:91,94,243` have no `try/catch`.
- Impact: any status change or assignment through `TaskCrudService::updateTask` would throw "Class not found" (500). Today the update route is not registered (only `addTask`/`allTasks`, `routes/web.php:870-873`) and create never notifies, so the feature is latent: dead code that will break when wired.
- Fix: replace with a real `TaskAssignedNotification` / `TaskStatusChangedNotification` using `DeliversViaPreferences` (+ seeded `task.*` types), or delete the service. Wrap in the fail-soft pattern used by leave.

### High

**F-4. Mobile has no in-app notification center, bell or badge, and no read-state.**
- Evidence: mobile `app/(tabs)/*` has no notifications screen; grep for `notifications/`, `unread`, `badge` finds only the token POST and unrelated punch badges. `shouldSetBadge: false` (`pushRegistration.js:60`). `useRealtimeNotificationSignal` (`src/realtime/index.js:45`) is exported but never called. `/api/v1` has no list/unread/read endpoints (`routes/api.php:333` is only `POST /notifications/token`); the list exists only on the legacy `/api/notifications*` (`:131-137`).
- Impact: a missed or dismissed push is lost for mobile users; the `database` channel is invisible to them.
- Fix: add `GET /api/v1/notifications`, `/unread-count`, `POST /{id}/read`, `/read-all` (reuse `NotificationController`) plus a bell/badge and list screen; wire `useRealtimeNotificationSignal` to refetch; set the app icon badge from unread count.

**F-5. Mobile tap router does not understand the server payloads.**
- Evidence: `resolveNotificationRoute` (`pushRegistration.js:196-237`) matches `data.route` or exact `type`/`type_key` strings such as `leave`, `attendance`, `objection`. The server sends dotted keys (`leave.approved`, `attendance.time_correction_requested`, `hr.offboarding_initiated`, `om.alert`; `LeaveApprovedNotification.php:66-70`) plus a **web** path in `url` (`/hr/offboarding`, `/attendance.unified`), which mobile ignores. `RfiObjectionNotification` sends `type=rfi_objection` (not mapped). Only `attendance_reminder` and the OM sub-types (`incident_escalated` etc.) hit a case, and only if the payload carries them.
- Impact: most taps open the app with no navigation. Mapped routes also miss the approval screens (`leave-approvals`, `swap-approvals`, `regularization-approvals`, `overtime-approvals`, `my-roster`, `swaps`).
- Fix: server-side, add a `route`/`screen` key in every `toPush` (mobile route names); client-side, prefix-match (`leave.*`, `attendance.*`) and add the missing screens.

**F-6. Push tokens are never unregistered on logout, and there is no delete endpoint or stale-token pruning.**
- Evidence: `AuthController::logout` (`Api/V1/AuthController.php:240-257`) revokes API and refresh tokens only. `NotificationToken` is created by `NotificationController::storeToken:63` and `UserManagementService.php:406`; there is no destroy route. Mobile keeps `lastRegisteredToken` in module scope (`pushRegistration.js:31`) and has no `addPushTokenListener`. `last_used_at` is written but never used to prune.
- Impact: after logout (or user switch on a shared site tablet) the previous user's HR/attendance pushes, with names and offboarding reasons, keep arriving on that device until another user registers the same token. Rotated tokens leave stale rows.
- Fix: `DELETE /api/v1/notifications/token` (called before logout and on `DeviceSessionRevoked`), delete a user's tokens for that device on logout, register `addPushTokenListener` for rotation, prune tokens unused for 60 days, and poll Expo receipts.

**F-7. Attendance reminder is wrong, duplicated, and partly bypasses the channels.**
- Evidence: `routes/console.php:27-28` runs `attendance:reminders` daily at **22:17**. `SendAttendanceReminders.php:56-66` dispatches the `SendAttendanceReminder` job **and** `MissedPunchNotification('in')` for every non-deleted user, unconditionally. The job (`SendAttendanceReminder.php:72`) only sends to `provider='fcm'` tokens (mobile Expo tokens excluded), calls `FcmNotificationService` directly (ignores preferences), and logs the full token array and payload (`:107-117`).
- Impact: everyone, including people already present, on leave or off duty, gets a "missed punch-in" record at night; web users get two pushes; mobile users get the one from the notification only; tokens land in logs.
- Fix: delete the job, keep only the notification through `PushChannel`, and gate recipients on rostered/expected-to-work and no punch today (reuse `attendance:shift-alerts` logic); fix the schedule time.

**F-8. Queued notifications are dispatched inside open DB transactions with `after_commit=false`.**
- Evidence: `LeaveApprovalService::submitForApproval` (`:120-151` `DB::beginTransaction` ... `notifyCurrentApprover` ... `DB::commit`) and `approve` (`:275-279`); `OmIncidentService::create` / `OmWorkOrderService::create` inside `DB::transaction` (`:142-150`, `:162`); `config/queue.php:43` `after_commit=false`; only `ProcessOffboardingLwd` and the new `OffboardingInitiationNotifier` use `afterCommit()`.
- Repro: a rollback after `notifyCurrentApprover` (for example the ledger call fails) leaves a queued approver notification for a leave that no longer exists. With a fast worker the job can also run before commit (`ModelNotFoundException` -> `failed_jobs`).
- Impact: phantom "leave requested" notifications, and lost ones (a failed queue job).
- Fix: set `'after_commit' => true` on the `database` connection (or `->afterCommit()` on the notification classes). Also move notify calls after `DB::commit()`.

**F-9. Large feature areas have zero notification wiring.** See section (c): payroll/payslip, F&F, assets, onboarding/offboarding tasks, probation, petty cash, department-scope grants, NCR/site instructions, letters, lane closure, leave cancel/escalation. Evidence: no `notify`/`Notification::` in the corresponding controllers/services (content grep of `app/Http/Controllers/HRM`, `app/Services/HR`, `PettyCash*`, `Services/Access`). `leave.cancelled` is seeded (`NotificationTypeSeeder.php`) with no class.
- Fix: see plan phases 3-4.

### Medium

**F-10. Registry gaps make preference control and the silent-drop behaviour inconsistent.**
- Evidence: seeded keys (`NotificationTypeSeeder.php`) lack `om.alert`, `hr.offboarding_initiated`, `attendance.absence_streak_escalation` (all used via `typeKey()`); `DeliversViaPreferences::via` returns `[]` for an unregistered key, so a reactive notification would send nothing (not even database). `RfiObjectionNotification` bypasses the registry entirely. `LeaveOverrideNoticeNotification` piggybacks on `leave.approved/rejected` (an admin muting "leave approved" also silences override notices).
- Impact: admins cannot mute or lock these types in the Settings UI; fallback channels are hardcoded.
- Fix: seed all keys, add a test that every `typeKey()` and every key in `app/Notifications` exists in the seeder (reflection test), convert `RfiObjectionNotification` to the resolver. Also seed at deploy (check `DatabaseSeeder` / a migration).

**F-11. Recipient scoping and actor handling are inconsistent.**
- Evidence: `DepartmentScope` is used by `OffboardingInitiationNotifier` only in spirit (department managers + grants); `ProcessAbsenceStreak.php:288-292` notifies **all** HR Managers/Super Admins regardless of department; `ObjectionService.php:150-160` notifies all Project Managers/Consultants/Admins; OM uses `permission()` globally; `RosterController.php:392` and OM alerts do not exclude the actor (`ObjectionService` and Offboarding do).
- Impact: out-of-scope managers get HR/absence/objection pings; the actor gets a notification for their own action.
- Fix: a `NotificationRecipients` helper: `->visibleTo($subject)` via `DepartmentScope::canActOn/managedDepartmentIds`, always exclude the actor and inactive/soft-deleted users.

**F-12. Errors are swallowed without a trace.**
- Evidence: `OmIncidentService.php:151`, `OmSlaService.php:69`, `OmWorkOrderService.php:172`, `OmInspectionService.php:162` use `catch (\Throwable) {}` (no log). Others log at `warning` only. `ExpoGateway.php:52` logs but returns no failure signal, so a failed HTTP request does not retry. `PushChannel` never throws, so a failed push is never retried by the queue.
- Impact: a total FCM/Expo outage or bad credentials is invisible: the queued job "succeeds".
- Fix: log with context at `error`; let `PushDispatcher` throw a retryable exception on transport failure (with backoff) while still deleting invalid tokens; add a health-check command (`firebase:ping` exists at `Console/Commands/FirebasePing.php`, schedule it).

**F-13. Overtime reuses the time-correction notifications with wrong copy and payload.** `OvertimeService.php:48,76,100` pass the OT id as `correctionId`; the body says "time correction" and links `/attendance.unified`. Fix: dedicated `OvertimeRequested/Decided` classes with seeded types.

**F-14. One test fails from a test defect, not a product bug.** `tests/Feature/Notifications/AttendanceTriggerWiringTest.php:171` calls `RosterController::updateCell` without an authenticated user; the controller reads `$user->employee_id` (`RosterController.php:374`) -> "Attempt to read property id on null". Fix: `actingAs($admin)` in the test.

**F-15. Multi-level approval chains skip later approvers for attendance requests.** `AttendanceApprovalService::approve` (`:74-106`) has no notify, and `RegularizationService`/`OvertimeService` only notify on the final `approved` status or at submit. Level 2+ approvers learn nothing. Fix: notify the next pending approver after each level.

**F-16. Dev/staging silently drops notifications because of lazy-loading prevention (verified statically only).** `AppServiceProvider.php:105` `Model::preventLazyLoading(! isProduction())` and `NotificationChannelResolver.php:56` lazy-loads `$user->notificationPreferences` on models fetched by `User::find(...)`. Every send is inside a fail-soft `try/catch` (see the comment at `LeaveApprovalService.php:739-741` describing the same trap). Impact: in non-production, notifications to queried users likely throw `LazyLoadingViolationException`, which is logged at warning and dropped; tests pass only because factory-created models are exempt. **Unverified by execution.** Fix: `loadMissing('notificationPreferences')` in the resolver, and `NotificationType` lookup cached per request.

**F-17. Push payload and log privacy.** Lock-screen bodies include employee name, offboarding reason and absence/abscond status (`OffboardingInitiatedNotification.php:36`, `AbsenceStreakEscalationNotification.php:43`) and are sent to FCM/Expo third parties. `FcmNotificationService::sendNotification:47-52` logs full device token, title and body at `info`. Fix: generic push body ("Offboarding update, tap to view") with details only in the authenticated in-app record; redact tokens in logs.

**F-18. Secrets and config drift in tracked files.**
- `.env.example:106-107` contains what appears to be a real Microsoft 365 mailbox and password (tracked in git, `git ls-files` confirms). Rotate it and blank the file.
- `public/firebase-messaging-sw.js:6` hardcodes a Firebase web API key (a public key by design, but it is a second source of truth next to `VITE_FIREBASE_*`) and project `dbedc-erp` while `config/firebase.php` defaults to `aero-hr`; `.env.example` has three different credential variable names (`FIREBASE_SERVER_KEY` is the legacy protocol, unused). Consolidate.

### Low

- **F-19.** `AbsenceStreakEscalationNotification.php:33-38` has no `absconded` case, so the abscond stage reads "Monitoring" (`AbsenceCase::STAGE_ABSCONDED`).
- **F-20.** All notification copy is hardcoded English; `lang/` has `en`, `bn`, `zh-CN`, `zh-TW` but no notification keys. The Bengali-speaking site workforce receives English pushes.
- **F-21.** N+1 per recipient: the resolver runs one `NotificationType` query and one lazy preference query per recipient, and `PushChannel` one token query per recipient; `Notification::send` to an OM list issues these per user (`OmAlertNotification`). Fine at current scale; cache the type and eager-load `notificationPreferences`/`notificationTokens`.
- **F-22.** `LeaveOverrideNoticeNotification` and the "Initiated" reuse at LWD produce misleading copy; consider dedicated types.
- **F-23.** `NotificationController::index` returns 20 unpaginated-by-client items with no `type`/`category` filter and no "delete" action; no retention policy for the `notifications` table.
- **F-24.** `firebase-messaging-sw.js` and `onMessage` handling: add `notificationclick` (open `data.url`), tolerate data-only messages, replace `alert()` with the toast system and make the foreground listener persistent.
- **F-25.** The user-facing notification preferences are web only (`/settings/notifications`); mobile has no preference screen.
- **F-26.** Inactive/terminated (not soft-deleted) users are not excluded from recipient lists (`whereNull('deleted_at')` only); soft-deleted users are excluded because `User::find`/`permission()` apply the scope. Check the `status` flag semantics (**unverified**).

## Test results

Docker (`dev-php:8.3`), one file at a time, no full suite.

| File | Result |
|---|---|
| tests/Feature/UpdateFcmTokenTest.php | PASS (1) |
| tests/Feature/Notifications/AttendanceNotificationChannelsTest.php | PASS (4) |
| tests/Feature/Notifications/AttendanceTriggerWiringTest.php | **FAIL** (1 of 4): test defect F-14 |
| .../DeliversViaPreferencesTest, ExpoGatewayChunkingTest, FcmGracefulDegradationTest, InAppCenterWebRoutesTest, LeaveNotificationChannelsTest, LegacyTokenCleanupTest, NotificationPreferenceApiTest, NotificationPreferenceModelTest, NotificationTokenModelTest, NotificationTypeSeederTest, PushDispatcherTest, RealtimeSignalTest | PASS (all) |
| tests/Unit/Notifications/NotificationChannelResolverTest.php | PASS (3) |
| tests/Feature/Api/MobileObjectionNotificationTest.php, NotificationTokenApiTest.php, NotificationApiTest.php | PASS |
| tests/Feature/Admin/NotificationSettingsTest.php | PASS |
| JS: `resources/js/api/queries/__tests__/useNotificationsQuery.test.jsx` | **Could not run**: `jest-environment-jsdom` is not installed in the repo (and jest rejects the `name` config key) |
| Mobile jest | **No notification-related tests exist** (`pushRegistration`, `usePushRegistration`, `webPush` untested) |

There is no test that enforces "every seeded or used type key is registered", "worker drains every queue", or "task notification class exists".

## (e) Implementation plan

Small, testable steps. Phase labels in brackets show where each can be merged with the lifecycle plan.

**Phase 0 — Unblock delivery (1 day, ship first, no schema changes)**
1. Worker cron `--queue=default,notifications,security`; add `queue:prune-failed` schedule and log a failed-job alert (F-1). Ops doc update.
2. Password reset: really send `SecurePasswordResetMail`, remove OTP from logs, send `PasswordChangedNotificationMail` (F-2). Test with `Mail::fake`.
3. Fix or remove `TaskNotificationService` (F-3) and fix the roster test (F-14).
4. Remove tokens/PII from `Log::info` in `FcmNotificationService` and `SendAttendanceReminder` (F-17 partial). Rotate and blank the `.env.example` mail password (F-18).

**Phase 1 — Engine correctness (2-3 days)**
1. `after_commit => true` on the database queue connection (F-8), plus a test that a rolled-back leave submit sends nothing.
2. Seed the missing registry keys, reflection test guarding `typeKey()` vs seeder, move `RfiObjectionNotification` onto the resolver (F-10).
3. Resolver: `loadMissing`, cache type lookups (F-16, F-21). Then verify the lazy-loading behaviour on staging (unverified today).
4. `PushDispatcher`: retryable failure on transport error, log at `error`, schedule `firebase:ping` (F-12). Poll Expo receipts (job every 15 min) and prune tokens unused for 60 days (F-6).
5. Fix `attendance:reminders` (F-7): one code path, only expected-to-work and unpunched users.
6. `NotificationRecipients` helper (scope, actor, inactive exclusion) and adopt it in absence streak, objections, OM, roster (F-11).

**Phase 2 — Data integrity (merge into lifecycle Phase 2)**
- Notify on the state changes that already exist in the lifecycle work: absence streak notice to the **employee**, offboarding tasks, department-scope grant/revoke, probation due (new scheduled command), leave cancel (approver + employee), leave auto-approve, level-2+ approvers for regularization/OT (F-9, F-15, F-19).
- Dedicated Overtime notification classes (F-13). Add `after commit` and idempotency (no duplicate on retry) to each.

**Phase 3 — ESS/UX (merge into lifecycle Phase 3)**
- Mobile: `/api/v1/notifications` list/unread/read endpoints, bell, badge, list screen, realtime refetch, and a preferences screen (F-4, F-25).
- Payload contract: every `toPush` carries `route` (mobile screen) and `url` (web); mobile router prefix-matches and covers the approval screens (F-5). Contract test that every push type resolves to a route.
- Logout/device-revoke token deletion plus `addPushTokenListener` rotation (F-6).
- Web: `notificationclick` handler, persistent foreground listener with toast, data-only tolerant background handler, polling fallback for the bell (F-24).
- Generic push text for HR-sensitive events; move details behind auth (F-17). Localise titles/bodies via `lang/` (`bn` first) (F-20).

**Phase 4 — Remaining modules (after Phase 3, each independent)**
- Assets (assign/return), payroll/payslip publish (behind the existing flag, wire `PayslipEmail`), F&F approve/disburse, onboarding initiation/task assignment, petty cash request/approve/reject, NCR/site instruction, lane closure permits, O&M status changes, letters, security (new-device login, 2FA change) (F-9).
- For each: seeded type row + notification class + preference category + a trigger test + a scope test.

**Unverified items to close during rollout:** real FCM/Expo delivery (needs a device and EAS FCM v1 credentials), production `.env` (`QUEUE_CONNECTION`, `MAIL_MAILER`, `FIREBASE_*`, `APP_ENV`), SMTP delivery, whether the production cron matches the documented one, lazy-loading behaviour in staging.
