# DBEDC Guardian Web + Mobile Gap Remediation

**Date:** 2026-09-03  
**Last verification:** 2026-09-05  
**Repositories:** `DBEDC-Guardian`, `dbedc-mobile-app`  
**Audit dimensions:** page and API parity, sub-features, CRUD/actions, authorization, state transitions, concurrency, reactive/realtime behavior, empty/loading/error states, and identity/schema consistency.

This is the active remediation baseline. “Web-only” is not automatically a defect: administrative configuration may intentionally remain on the web. Every such difference still needs an explicit product decision so an absent mobile action is not mistaken for a bug.

Status labels: **implemented locally** means source changes with the checks listed below, not deployed or visually accepted; **confirmed gap** means source or test evidence; **decision** means intended product scope is not yet established. This is not a certification that every page, role, device, or production dataset has been exercised. The existing worktrees contain many earlier changes; no commit, push, production migration, or deployment was performed in this continuation.

## Executive status

| Area | Current result | Remaining risk |
|---|---|---|
| Employee identity | String `employee_id` is now carried through the audited services, jobs, APIs, relationships, filters, and actors | Production must run the reconciliation migration and review unmatched legacy values before foreign keys are restored |
| Access control | Profile, Quality NCR, Holiday, Attendance settings, and O&M read/write permissions are split; mobile receives permissions | Other legacy modules still need record-level policy tests, not only route-permission checks |
| Profile | Own-profile and directory-profile access are separated; salary/employment writes are privileged; UI hides forbidden forms | Mobile self-profile does not expose bank, education, experience, or employment history |
| O&M workflow | Fake metrics removed; legal transitions, row locks, separation of duties, after-commit realtime, and `lock_version` conflict protection are enforced; web/mobile actions are permission-aware | Mobile exposes only the field subset |
| Attendance/roster | Policy/assignment scopes accept real employee codes; lifecycle alerts and cross-month mobile roster refresh are working | Configuration and bulk roster administration remain web-only by design unless product requires mobile administration |
| Leave | String identity, overlap, ledger, approval, accrual, carry-forward, comp-off, and API paths are aligned | Mobile leave attachments and some administrator override/configuration operations are absent |
| Daily work/objections | String identity, version-aware edits/deletes/bulk actions, response-import mutation authorization, and sync ownership tombstones are covered | Manual create has an assignment/schema blocker; list/policy scoping and import preview authorization still diverge; mobile lacks full create/delete/import/export and inspection parity |
| Realtime/offline | Attendance, roster, leave, daily work, objections, O&M, petty cash, notifications, focus refresh, and durable offline punch sync exist | Remaining legacy modules need explicit realtime product decisions; Firebase credentials/rules must be verified per environment |
| UI state quality | O&M now has honest empty/error/last-updated states; punch initial loading blocks unsafe actions; mobile lint is clean | A shared page-state and mutation-feedback contract is not yet enforced across every legacy web page |

## Completed in Phase 0

### Security and authorization

- Replaced broad read permissions on mutating routes with create/update/delete/manage permissions.
- Enforced own-profile versus other-profile access in controllers and profile sub-resource endpoints.
- Prevented self-service users from changing salary or employment-controlled data.
- Scoped Aeon conversation reads to the authenticated employee.
- Exposed canonical permissions to mobile and hid O&M actions the API would reject.
- Added regression tests for horizontal access, read-only mutation attempts, conversation isolation, and O&M state transitions.

### Stateful and concurrent behavior

- Added explicit O&M transition maps instead of accepting arbitrary status jumps.
- Added row locks around incident, defect, work-order, toll-audit, and shift-handover transitions.
- Enforced separation of duties for work-order approval/verification and shift handover acknowledgement.
- Added the missing `detected → dispatched` mobile incident action.
- Fixed shift lifecycle candidate resolution for string employee IDs; reminder, overdue, absence, and dedupe behavior is tested.
- Added mobile roster realtime refresh for both months when a displayed week crosses a month boundary.
- Added one after-commit `operations/all` invalidation signal across all 18 O&M models; every web O&M page and the mobile field screen now refetch after another operator changes data.
- Fixed web and mobile realtime self-actor comparison so string employee codes are not coerced to `NaN`; system markers with no actor are no longer accidentally ignored.
- Added integer `lock_version` tokens to mutable O&M records. Stale version-aware API writes now return `409 STALE_WRITE`, while stale Inertia writes refresh the page and surface a conflict message without overwriting the winner. API v1 temporarily accepts missing tokens only for already-installed mobile builds.
- Fixed O&M web mutations that used Inertia navigation but returned raw JSON; they now redirect with flash/error state, while JSON and mobile callers retain the API response contract.
- Added `pettycash/all` after-commit invalidation for loans, transactions, audits, and bill evidence, with automatic refresh of the web petty-cash workspace.

### Data and API contracts

- Removed lossy integer casts from user/actor/approver/incharge/assignee paths.
- Corrected eager-load column selections from `users.id` to `users.employee_id`.
- Corrected Sanctum permission evaluation to use the shared `web` guard.
- Replaced fake O&M arrays/statistics with database-backed responses and honest empty states.
- Added a shared mobile O&M API client with response validation and tests.
- Added a production migration that changes missed user-reference columns to strings and maps known legacy numeric IDs, while preserving department/designation scope IDs.

### UI consistency and safety

- Profile edit controls now follow server-provided capability flags.
- O&M forms expose distress type, direction, severity, and required chainage rather than submitting silent defaults.
- O&M pages show loading, failure, empty, and last-updated states and refresh on focus.
- Attendance policy and shift assignment forms select real employee/department/designation targets rather than accepting raw numeric scope IDs.
- Initial attendance loading now disables the punch action to avoid acting on unknown state.
- Removed 19 mobile lint warnings, including stale imports and unsafe hook dependency omissions.
- Removed the web build script’s automatic `git add`, commit, and push side effect.

## Platform parity matrix

| Domain | Web | Mobile | Gap / decision |
|---|---|---|---|
| Authentication/account | Login, password, profile, user/device administration | Login, self-profile/image/password, own-device revocation, push | Mobile intentionally excludes other-user administration |
| Employee profile | Personal, emergency, bank, employment, salary, education, experience | Personal, emergency, image, account security | Add the missing self-service sections only if field/mobile editing is required |
| Attendance | Punch, history, reports, regularization, overtime, shifts, roster, policies, coverage, devices | Punch/offline sync, history, own/team roster, requests, manager approvals | Policy, coverage, shift definitions, and device configuration are web-only |
| Leave | Self CRUD, approvals, ledger/balances, bulk actions, settings, exports | Self create/edit/cancel, calendar/analytics, manager approval/history | Attachments, configuration, ledger reconciliation, and admin overrides are not mobile surfaces |
| Daily work/RFI | Full CRUD, assignment, status/inspection, import/export, objections | List/detail, status, assignment (authorized roles), objections/files | Create/delete/import/export and full inspection editing are absent on mobile |
| O&M | Dashboard, assets, defects, incidents, equipment, work orders, toll, traffic/VMS, lane permits, patrols, shift logs | Dashboard plus field defects, active incidents/patrols, and work-order actions | Add photos, surveys, permits, toll reconciliation, VMS/traffic, equipment, and handover parity by field priority |
| Quality | NCR register and related web workflows | None | Decide whether inspectors need offline/mobile NCR capture and photo evidence |
| Petty cash | Loan, transactions, evidence, analytics/export | None | Decide whether field expense capture/receipt upload is required |
| Organization/access/settings | Users, roles, permissions, departments, designations, work locations, holidays, system settings | None | Keep web-only unless a narrow manager self-service use case is approved |
| Reports/monitoring | Reporting, request logs, feature flags, system/client-error monitoring | Diagnostics upload only | Operational monitoring should remain web-first; mobile may expose service health only |

## Remaining remediation plan

### P0 — deployment and integrity gate

1. Back up production and run the employee-reference reconciliation migration in staging.
2. Produce an orphan report for every user-reference column; manually map any numeric value not present in the known legacy map.
3. Recreate foreign keys for non-polymorphic user references after the orphan report is clean. The 2026-08-22 conversion removed old `users.id` foreign keys and did not restore them.
4. Verify Firebase Auth, Realtime Database credentials, namespace, and `database.rules.json` in staging. Realtime must degrade safely, but enabled environments must prove marker write/read end to end.
5. Seed the new permissions before exposing the updated pages.
6. Release the version-aware mobile build, enforce it through the existing minimum-version policy, then remove the temporary API v1 no-token concurrency fallback.

### P1 — behavior and feature parity

1. Finish the open daily-work/objection gaps in the dated backlog below; extend the implemented O&M/daily-work/objection version contract to roster, attendance corrections, and holiday edits. API v1 no-token compatibility remains a concurrency exception, not full lost-update protection.
2. Complete mobile O&M in field-value order: evidence photos, incident timeline/escalation, work-order crew/material/QC evidence, shift handover, asset survey, lane closure, then toll/traffic/VMS.
3. Add mobile leave attachments and daily-work create/inspection actions if the product owner confirms field creation is required.
4. Add record-level policy tests to legacy web mutations still protected only by broad route permissions.
5. Replace the two production-connected “deep audit” commands with environment-guarded, rollback-complete test harnesses; they currently create tokens/devices outside their transaction.
6. Verify realtime reconnect, namespace/rules deployment, and multi-operator conflict UX end to end; signal delivery is invalidation, not concurrency control.

### P2 — consistent product quality

1. Standardize every page on one loading/error/empty/stale/last-updated component contract.
2. Standardize confirmation, rejection-reason, success, and validation feedback across web and native platforms.
3. Add automated accessibility checks for labels, focus order, keyboard operation, contrast, and touch targets.
4. Unify duplicate semantics across manual creation, the active Excel preview/import, and response/submission imports. Correction: the active `DailyWorkImportService` already checks duplicate dates during preview and import. The placeholder in `utils/ExcelImportValidator.js` has no discovered caller and does not prove the active preview lacks validation.
5. Remove the unused hardcoded permission utility or replace it with the canonical Inertia auth permission payload before any new caller adopts it.
6. Extend the new route-integrity tests into route-to-capability tests. Controller-method and literal frontend route-name existence are now checked; parameter shapes, dynamic route names, permissions, payloads, and UI outcomes are not automatically covered by those two tests.

## Definition of done for each page/action

Every page, sub-feature, CRUD action, and operation is complete only when it has:

1. A visible entry point gated by the same capability enforced by the server.
2. Server-side validation and record-level authorization.
3. A legal transition rule for stateful actions and a transaction/lock where concurrent writes matter.
4. Loading, empty, validation, conflict, network-error, and success states.
5. Reactive invalidation after local writes and realtime/focus refresh after remote writes.
6. Stable string employee identifiers end to end.
7. Feature and access-regression tests for allowed and forbidden roles.
8. Accessible labels, focus behavior, contrast, and mobile touch targets.

## Earlier verification snapshot (before the September 5 continuation)

- Guardian representative suite: **146 tests, 693 assertions, all passing**.
- Attendance policy/shift suite: **31 tests, 89 assertions, all passing** (24 policy/assignment tests plus 7 lifecycle tests).
- O&M workflow, authorization, concurrency, response-contract, and realtime suite: **17 tests, 76 assertions, all passing**.
- Petty cash workflow and realtime suite: **12 tests, 34 assertions, all passing**.
- Web realtime signal handler: **6 tests, all passing**.
- Mobile full Jest suite: **257 tests, all passing**.
- Mobile Expo lint: **0 errors, 0 warnings**.
- PHP Pint: passing.
- Migration syntax and MySQL pretend preflight: passing.
- Guardian Vite production bundle: passing; generated artifacts are not retained as source changes.

## September 5 continuation: implemented locally

- Closed registered routes pointing at missing controller actions and literal frontend route names that did not exist. Added regression checks and regenerated Ziggy declarations.
- Added the missing daily-work bulk incharge/status/completion/delete handlers, per-record policy checks, ordered row locks, and version conflicts. Bulk status values now match the model; the bulk-delete dialog describes soft deletion.
- Added daily-work and objection version propagation through the audited web/mobile mutation paths. Corrected a follow-up regression where create validation required a token but update validation discarded it. The web detail form now sends the version and retains its draft on a 409 instead of permitting a blind resubmit.
- Response imports now authorize each mutation and write through the locked/version-aware service. Import tests cover forbidden mutation, version increment, and an intervening edit. This does **not** resolve all preview/override/duplicate issues below.
- Separated synchronous daily-work ownership tombstones from after-commit realtime publication. Tombstones must capture the previous owner before Eloquent synchronizes original attributes; remote invalidation must wait for commit. Existing departure/delete/paging tests pass.
- Added holiday session-API list/detail/stats/CRUD routes and a dedicated edit route. Legacy create-with-id requests also require update permission. An update-only user can edit through PUT, and editing preserves the original creator. Added after-commit holiday invalidation and web list refresh.
- Added access-filtered global search for employees, RFIs, objections, work orders, and incidents. Search honors the super-administrator Gate bypass, handles accounts with no module permissions, retains older authorized results beyond unrelated recent matches, and links RFIs with their date. Search input follows new Inertia query props and has processing/error feedback.
- Added a scoped employee-detail response containing directory fields, not private birthday/address/salary/device data; added missing work-location detail routing.
- Completed bulk user role/deletion handlers, protected super-administrator role assignment, and added monitoring PDF export/error resolution handlers.
- Corrected password-reset and device-management route references and retired dead legacy report/task routes and footer links.
- Removed five unreferenced recruitment/training/performance form shells whose routes/controllers were absent. They are recoverable from Git; this does not implement those HR modules or authorize their product scope.
- Corrected an outdated mobile pickup comment. Pickup already existed; the actual failure was `ShiftSwapService::rosterAvailabilityProblem` still requiring integer employee IDs. String IDs now pass pickup creation, availability rejection, consent, approval, and roster application tests.

## Confirmed open gaps: next fix backlog

The entries below are deliberately not marked fixed by a passing build or by unrelated test counts.

| ID / priority | Page or operation | Evidence and impact | Planned fix and acceptance |
|---|---|---|---|
| DW-01 / P1 release blocker | Web daily-work manual create | A valid create with a matching jurisdiction returned 500 in an isolated SQLite feature probe: `NOT NULL constraint failed: daily_works.assigned`. `DailyWorkCrudService::create` sets incharge/status but no assigned value; the form has no assignee input. | Decide mandatory assignee selection versus an explicit default versus a genuinely nullable unassigned state. Align schema, validation, form, import defaults, and API. Require a full create/read/edit/delete test with real employee codes. Current CRUD tests cover create **validation** and existing-row update/delete, not successful manual creation. |
| SEC-01 / P1 | Objections list, statistics, filters, export | `ObjectionController::index` authorizes `viewAny` then queries all objections; `getStatistics` is global. `RfiObjectionPolicy::view` instead restricts records to creator/related RFI or privileged roles. | Introduce one authorized query scope reused by list, statistics, filter choices, export, search, and mobile. Two unrelated users must not see each other's rows, attachment metadata, counts, or creator choices. Verify intentional manager scope. |
| SEC-02 / P1 | Daily-work list/detail/search and objection review roles | `DailyWorkService::isPrivilegedUser` includes project/HR/consultant/daily-work managers, while `DailyWorkPolicy::view` is narrower; objection policy still uses legacy `Admin`/`Super Admin` names. | Establish canonical capability + record-scope definitions. Test Employee with/without jurisdiction, reporting engineer, Department Manager, module manager, Administrator, and Super Administrator across list/detail/mutation. Avoid silently broadening access to make screens agree. |
| IMP-01 / P1 | RFI submission/response import and bulk decision preview | Controller/service decision payloads expose objected-work numbers, locations, and counts before per-row authorization. Response import resolves matching numbers with `keyBy('number')`, although the number unique constraint was removed. Submission and response import selection rules differ. | Scope and authorize before returning previews, fail ambiguous RFI matches, use stable IDs/date-qualified identifiers in templates, and test duplicate numbers and unauthorized mixed selections. |
| IMP-02 / P1 | RFI override race and import retry | Submission/response services re-count active objections under a row lock but only log an override **if** a reason is supplied; they do not reject a newly objected row without a reason. Upload versions are captured during parsing, not retained across the user-confirmation round trip. | Require/revalidate override decisions at commit, coordinate objection-link writes on the same parent lock, preserve preview versions in a signed/owned preview token, and test objection arrival between preview and commit. |
| UI-01 / P1 | Work-location add/edit validation | `WorkLocationController` returns `{error: fieldErrors}` on 422; `Organization/Components/WorkLocationForm.jsx` reads `data.errors` and then dereferences `errors.location/name`. A validation failure can replace the errors object with undefined and crash rendering. | Standardize on `{errors: ...}` and a defensive client fallback. Component test invalid name/coordinates/devices: form remains open, entered values survive, field error is visible, retry succeeds. |
| DATA-01 / P1 | Work-location attendance-method/device update | `syncAttendanceTypes` returns immediately without `attendance_type_ids`, so a device-only update cannot apply. The parent write and pivot writes are not one transaction. | Decouple method/device presence checks, distinguish omitted fields from empty selections, and transact parent+pivots. Test device-only change, explicit clearing, omitted-field preservation, and rollback on pivot failure. |
| STATE-01 / P1 | Swap/roster simultaneous approval | `ShiftSwapService::approve` checks status/availability before its transaction; the checked request is not locked at that boundary. Version support has not been extended here. | Lock/reload the request and affected roster rows in deterministic order, re-check consent/status/availability inside the transaction, define 409/replay behavior, and test two competing approvals plus roster change during approval. |
| UI-02 / P2 | Departments cards versus table | Card edit is always rendered and receives an edit callback; the table receives one only when `canEdit` is true (`DepartmentsTab.jsx`). | Derive shared server-backed capabilities; hide/disable card edit consistently and guard modal entry. Verify read-only and super-administrator views in both layouts; server denial remains mandatory. |
| HOL-01 / P2 | Holiday inactive/restore lifecycle | Management index and mutation responses fetch only active records, but edit form allows `is_active=false`; after save the item disappears with no inactive-management view. Restore endpoint exists without a trash selection surface or overlap check. | Add active/inactive/trash filters, reactivation/restore actions with distinct permissions, overlap validation, and consistent stats. Deactivate -> find -> reactivate and delete -> restore must be completable. |
| RT-01 / P2 | Holiday changes across mobile dashboard/leave/roster | Web now publishes/subscribes to `holiday/all`. Mobile displays holidays but its `SIGNAL_ENTITIES` has no holiday entry; dashboard listens to attendance, leave page to leave, team roster to roster. | Add the shared holiday entity and invalidate each affected mobile resource, or explicitly define focus-only freshness. Test a second operator's edit while each screen stays open, reconnect, and cross-month boundaries. |
| UI-03 / P2 | Single daily-work delete confirmation | `DeleteDailyWorkForm.jsx` says permanently removed/all associated data lost; service performs soft deletion. Bulk wording has already been corrected. | Align single-delete wording and the recoverability promise with the actual restore surface and permissions. Do not promise self-service restore if only an administrator can do it. |
| QA-01 / P1 safety gate | Existing deep-audit commands | `DeepE2ECrudAudit` registers a device before its transaction. `AuditSystemDeep` creates tokens/devices against its configured database. These commands were **not run**. | Require local/testing environment and explicit disposable database, isolate fixtures, and prove cleanup of device/token/media/cache/notification effects. Use feature tests until then. |
| QA-02 / P2 | Whole-system UI/reactivity coverage | Build/static tests do not establish responsive layout, focus, accessibility, real device gestures, actual Firebase delivery, reconnect, or MySQL contention. | Execute the role/device/event matrix below against a disposable staging dataset; attach screenshots and reproducible failures before declaring visual or realtime acceptance. |

Source anchors (Guardian unless stated otherwise): `app/Services/DailyWork/DailyWorkCrudService.php`, `app/Services/DailyWork/DailyWorkValidationService.php`, `app/Http/Controllers/ObjectionController.php`, `app/Policies/DailyWorkPolicy.php`, `app/Policies/RfiObjectionPolicy.php`, `app/Services/Project/DailyWorkService.php`, `app/Http/Controllers/DailyWorkController.php`, `app/Http/Controllers/WorkLocationController.php`, `app/Services/Attendance/ShiftSwapService.php`, `resources/js/Pages/Organization/Components/WorkLocationForm.jsx`, `resources/js/Pages/Organization/Tabs/DepartmentsTab.jsx`, `app/Http/Controllers/HolidayController.php`, `resources/js/Forms/DeleteDailyWorkForm.jsx`; mobile `src/realtime/paths.js` and `app/(tabs)/{index,leaves,team-roster}.js`.

## Execution order and acceptance plan

1. **Release-blocking CRUD and access:** DW-01, SEC-01/02, UI-01, IMP-01. Resolve the creation assignment rule before implementation. Acceptance: allowed/forbidden real-ID workflows and scoped counts/previews, not merely hidden buttons.
2. **Atomic state and conflicts:** IMP-02, DATA-01, STATE-01; extend token enforcement after the version-aware mobile rollout. Acceptance: two actors, stale versions, double-click/replay, rollback, out-of-order responses, and preserved drafts.
3. **Page lifecycle and parity:** UI-02/03, HOL-01, RT-01. Acceptance: every visible action has a destination and recoverable loading/error/empty/success/conflict state in both table/card and narrow/wide layouts.
4. **Product-approved mobile features:** prioritize evidence/photo capture and leave attachments, then RFI create/inspection, NCR/field expenses, and deeper O&M workflows. Web-only administration and dormant HR shells require explicit scope decisions; do not generate entire modules just to achieve superficial parity.
5. **Staging acceptance and release:** QA-01/02 plus the P0 migration/permissions/Firebase/minimum-version gate above. Run against MySQL and two signed-in devices; perform offline/reconnect and interrupted-mutation exercises. No production-connected audit command should be used as a shortcut.

### Required scenario matrix for every affected page/sub-feature

| Surface | Actions/sub-functions to trace | Stateful/reactive/realtime acceptance |
|---|---|---|
| Authentication/profile/access | Sign-in/refresh/logout, reset password, device revoke, own/other profile, role change, personal versus employment/bank/salary writes | Expired/revoked sessions close access; role downgrade clears cached capabilities; another user cannot receive cached private data |
| Attendance/roster | Punch, duplicate/offline punch replay, history/date filters, regularization/overtime request-edit-cancel-approve-reject, swap/cover/pickup, shifts/assignments | Dedupe, pending/decided transitions, competing approval, cross-month refresh, background/foreground, device timezone versus business date |
| Leave | Create/read/edit/cancel, overlap/day count, attachments, manager decisions, balances/ledger/history/calendar | Pending-only edit rules, rejection reasons, concurrent approvals, balances/calendar invalidate together, offline submission policy explicit |
| Daily work/RFI | Manual create, details, status, incharge/assignee, inspection, submission/response, bulk operations, import preview/commit/errors, export, delete/restore | Current version required where contracted; preview cannot disclose unauthorized rows; selection survives refresh sensibly; old owner's mobile cache evicts reassigned records |
| Objections/NCR | Create/edit, RFI links/unlinks, evidence upload/download/delete, submit/review/resolve/reject, timeline, filters/export | Role and record scope agree; terminal-state restrictions; parent/link/evidence race handling; list/count/detail refresh together |
| O&M | Asset/defect/incident/work-order CRUD, dispatch/escalate/close, approval/QC verification, crew/material/evidence, patrol/permits/VMS/toll, handover | Legal transitions and separation of duties; two-operator 409; evidence and summary invalidation; action failures cannot leave success-looking state |
| Petty cash | Loan, transaction, bill/receipt, approval/audit, balances, analytics/export | Repeated submission does not double-count; failed evidence upload has retry/cleanup; remote write refreshes dependent totals |
| Organization/settings | User/role bulk actions, department/designation/work-location CRUD/dependencies, holiday copy/reactivate/restore, policy/device/module configuration | Card/table capability parity; omitted versus cleared values; cache/realtime scope; deleted/inactive records remain manageable where authorized |
| Search/reports/monitoring | Search query change, historic result navigation, filters/pagination, report export/download, error resolve | No inaccessible result/count leak, latest query wins, filters match destination, export obeys the same scope, retry and download failures are visible |

Test each action as guest, read-only employee, authorized owner, unrelated employee, relevant manager, administrator, and super-administrator where applicable. Test at least narrow phone, tablet, desktop, Android app, and supported iOS app surfaces. This is the remaining execution checklist, not a claim those visual/device runs have already occurred.

## September 5 verification results and limits

- Consolidated Guardian continuation suite: **76 tests / 445 assertions passed**, covering holiday, search, daily-work concurrency/import/realtime, mobile sync departure, pickup/history/approval, route integrity, directory access, user management, and monitoring.
- Additional daily-work CRUD contract suite after the final validation/form correction: **2 tests / 15 assertions passed**. This tests creation validation, existing-row update/delete versions, and forbidden mutations. It deliberately does not claim DW-01 manual creation is fixed.
- Final re-run of the complete new daily-work suite plus route-integrity checks: **13 tests / 55 assertions passed** (overlaps the runs above; do not add these counts together).
- Web realtime handler: **6 tests passed**. Mobile full Jest: **24 suites / 260 tests passed**. Mobile Expo lint: **0 errors / 0 warnings**.
- Targeted PHP Pint checks passed. Guardian production Vite build passed after the final form changes, using a temporary output directory without running versioning/publishing scripts. Ziggy route declarations were regenerated.
- SQLite tests disable foreign keys in `tests/TestCase.php`; they do not establish production foreign-key integrity or MySQL row-lock behavior. No production migration was run. Existing PHPUnit doc-comment metadata emits deprecation warnings.
- No browser screenshot audit, physical-device run, Firebase end-to-end delivery check, or production performance/load test was completed in this continuation. Existing passing tests are evidence for their assertions only, not whole-system acceptance.
- Both worktrees passed `git diff --check`; mobile Git reports line-ending conversion warnings. The temporary Vite output remains outside the repositories because cleanup was denied by the tool policy; no generated `public/build` changes were retained.
