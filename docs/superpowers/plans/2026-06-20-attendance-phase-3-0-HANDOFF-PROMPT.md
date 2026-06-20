# Handoff prompt — Attendance Phase 3.0 (Policy Core)

Paste everything below the line into a fresh session on this repo.

---

Execute the Attendance **Phase 3.0 (Policy Core)** implementation plan on the Aero-Enterprise-Suite app.

DESIGN INTENT (read first — the philosophy the plan encodes):
Phase 3 turns the roster from an *interpretation* lens (Phase 1) and *workflow trigger* (Phase 2) into an *enforcement* lens. Phase 3.0 builds the foundational, **extensible policy engine**: effective-dated, scoped, **versioned** policies resolved per employee-day and applied at TWO points — (capture) a strictness mode `warn|flag|restrict`, and (compute) graduated **grace tiers** + **rounding**. The engine is a **registry of single-responsibility `RuleEvaluator`s** so later sub-phases (3.1 overtime/breaks, 3.2 attendance points, 3.3 differentials) just register new evaluators — never rewrite.
CRITICAL PRINCIPLE — **capture is NEVER blocked.** Even `restrict` RECORDS the punch and marks it `provisional` (doesn't count until approved); it never returns an error/denies a punch. Do NOT add any code that blocks/denies a punch. And **back-compat is a hard requirement**: a neutral/empty `PolicyProfile` MUST make `AttendanceStatusService` produce output identical to Phase 2 — the existing `--filter=Attendance` tests must stay green unchanged.

START HERE:
1. Read the plan: `docs/superpowers/plans/2026-06-20-attendance-phase-3-0-policy-core.md` (10 tasks: policies table → resolver → rule engine + rounding → grace tiers + status threading → punch policy guard → exception approvals → simulation → policy CRUD/activate/simulate API → admin UI + inbox section → acceptance sweep).
2. Read the design spec for the full industrial vision + the 3.0→3.3 decomposition: `docs/superpowers/specs/2026-06-20-attendance-phase-3-policy-engine-design.md`.
3. Read the module reference: `docs/attendance/ATTENDANCE_MODULE.md` (§3 engine, §5 rostering, §13 roadmap).
4. Read memory: `dev-server-and-build-workflow`, `run-migrations-on-mysql-dev-db`, and `mobile-app-cross-repo`.
5. Use the **superpowers:subagent-driven-development** skill to execute task-by-task: a fresh implementer subagent per task, a task review (spec + quality) after each, fixes for Critical/Important findings, then a final whole-branch review. Keep a progress ledger.

PROJECT: Laravel 11 + Inertia v2 + React 18 (Radix Themes), MySQL, PHPUnit (NOT Pest). Single-tenant, TZ Asia/Dhaka. Working dir: `c:\laragon\www\Aero-Enterprise-Suite`. Branch: `main` (commit per task to main; a background auto-committer also makes harmless "ok"/"build: auto commit" commits that may interleave — when generating a per-task review diff use `<taskcommit>^` as the base, not the recorded BASE, to exclude that noise). Phases 0+1+2 are DONE, committed, and live in production.

DEV / TEST RULES (these caused real bugs before — obey exactly):
- Run the Vite dev server (`npm run dev`, background); test at `https://aero-enterprise-suite.test` (NOT 127.0.0.1:8000). NEVER run `npm run build` (its postbuild auto-commits/pushes). Build assets with `npx vite build` ONLY; frontend task commits are SOURCE-ONLY (do NOT stage `public/build`); do ONE consolidated `public/build` rebuild + commit in the final sweep task.
- Tests are PHPUnit class-style (NOT Pest), sqlite `:memory:` + RefreshDatabase. Run `php artisan test --filter=<Class>`. Exactly 2 KNOWN pre-existing failures are unrelated and must stay the ONLY failures: `MobileSyncApiTest > sync push applies leave apply mutation` and `NavigationRoutesTest > any authenticated user can access organization directory`. Add no NEW failure.
- BACK-COMPAT GATE: after the status-engine change (plan Task 4), the entire `php artisan test --filter=Attendance` set must stay green with NO edits to existing assertions. Gate all new engine behavior behind `! $policy->isNeutral()`.
- After adding ANY migration, run `php artisan migrate` against the MySQL dev DB `dbedc_guardian` (mysql bin `/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin`; root, no password). The sqlite suite will NOT catch a missing MySQL table (this caused live 500s). Verify live pages by HTTP STATUS via Playwright `fetch`, not by the rendered shell (empty-state UIs render identically on 200 and 500).
- Frontend client signature is `requestJson(method, url, { params | data })` (`resources/js/api/client.js`). NEVER `(url,{method,body})`. Web endpoints return PLAIN json (no `{success,data}` envelope).
- Inertia props that are Eloquent models MUST be mapped to plain arrays before `Inertia::render` (`->get()->map(fn($m)=>[...])->values()`) — a raw model whose accessor touches a relation 500s under preventLazyLoading.

REUSE (don't reinvent): schedule via the bound `App\Services\Attendance\Contracts\ScheduleResolver`; approvals/audit via Phase 2's `App\Services\Attendance\AttendanceApprovalService` and `App\Services\Attendance\AttendanceAuditService::record(action, attendanceId, before, after, reason, request)`. The `ShiftSchedule` VO + `DayAttendance` DTO live in `app/Services/Attendance/DTO/`. Punch entry point is `App\Services\Attendance\AttendancePunchService::processPunch($user, $request)`. Bind the new `PolicyResolver` next to where `ScheduleResolver` is bound.

PERMISSIONS: policy management gated `permission:attendance.settings`; punch-exception approval gated `permission:attendance.manage`. Employees must never reach policy management or the exception queue.

LOGIN for manual verification: emam@dhakabypass.com / 123456789 (Super Administrator + Employee). Drive the UI with Playwright; wait for skeletons to resolve before snapshotting.

DO NOT wipe existing data: real shifts/roster + Phase 2 regularization/overtime/comp-off data exist on the dev DB — leave them. Seed only the global-default policy (plan Task 2/10).

Begin by reading the plan + spec + the design intent above, then dispatch the Task 1 implementer.
