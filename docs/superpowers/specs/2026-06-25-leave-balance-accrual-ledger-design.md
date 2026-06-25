# Leave Balance & Accrual Ledger — Phase 3 Design

**Date:** 2026-06-25
**Status:** Approved (design pending owner sign-off). Phase 3 of the Attendance·Leaves·Holidays 10/10 initiative.
**Parent design:** `docs/superpowers/specs/2026-06-23-attendance-leaves-holidays-10of10-design.md` (Phase 3 sketch → this is the full spec).
**Builds on:** Phase 2 (fractional `no_of_days`, canonical status, leave audit, FK). Phase 1 (`HolidayService`).

## Goal

Make leave **balances first-class, auditable, and enforced** — an industry-standard accrual ledger that replaces the implicit `LeaveSetting.days` quota and the simplistic `LeaveCrudService::getRemainingDays`. Every leave type's policy (entitlement, accrual, carry-forward, encashment, negative-balance) is **configured per type**, not hardcoded. Balances reconcile with the engine's approved-leave counts.

**Owner direction (2026-06-25):** standardize in every way; don't anchor on the current data; build to industry-standard 10/10 (full carry-forward **and** encashment; correct pro-rated seeding).

## Context: the existing scaffolding is dead

`leave_accruals` + `leave_carry_forwards` tables and `AccrueMonthlyLeaves`/`ResetAnnualLeaves` commands exist but are **non-functional** and have **0 rows**: they query `users.joining_date` (real column: `date_of_joining`), `users.status` (does not exist), and `leaves.leave_type_id` (real column: `leaves.leave_type`). Phase 3 **replaces** them with a correct ledger and retires the dead artifacts.

## Architectural decisions (confirmed)

| Area | Decision |
|---|---|
| Model | **Append-only ledger** (`leave_ledger`): one immutable, signed transaction per event; balance = running sum. The industry-standard auditable pattern — never mutate, only append (reversals are new rows). |
| Policy | **Configurable per `LeaveSetting`**, not hardcoded. Standard fields + sane defaults (below). The engine obeys the config. |
| Accrual | `annual_upfront` (full entitlement on year-start / join, pro-rated) · `monthly` (rate/12, probation-gated) · `none`. Idempotent scheduled run. |
| Consumption | An **approved** leave posts a `consumption` (−days, fractional from Phase 2); cancel/reject **reverses** (a compensating `+days` row). Single source: leave approval lifecycle. |
| Carry-forward | Year-end: carry up to `carry_forward_cap`; the carried block expires after `carry_expiry_months` (a `carry_expiry` row zeroes the unused remainder at expiry). |
| Encashment | A recorded `encashment` transaction (−days) + report; **no payout** (payroll external). Gated by `is_encashable`. |
| Enforcement | On apply, `LeaveCrudService` consults `LeaveLedgerService::available(emp,type,asOf)`; reject if `requested > available` unless `allow_negative`. Replaces `getRemainingDays`. |
| Seeding | Data migration seeds correct opening balances for the live year (pro-rated entitlement + Earned accrual-to-date − approved-taken). |
| Audit/integrity | Ledger is immutable (`UPDATED_AT=null`); every post is attributable (actor/source). Consistent with leave/holiday/attendance audit. FK `user_id`/`leave_type` enforced. |
| Scope | Single global policy per type now (no per-grade/location policy — YAGNI, schema leaves room). |

## Data model

### `leave_ledger` (new, append-only, immutable)
- `id`
- `user_id` → users (cascade), `leave_type` → leave_settings.id (FK; matches `leaves.leave_type` convention)
- `period_year` (smallint) — the entitlement year the txn belongs to
- `txn_type` enum: `opening | accrual | consumption | consumption_reversal | carry_forward | carry_expiry | encashment | adjustment`
- `amount` decimal(6,2) — **signed** (+credit / −debit)
- `balance_after` decimal(6,2) — running balance for (user, leave_type, period_year) after this row (computed at insert under lock)
- `source_type` / `source_id` (nullable morph-lite) — e.g. `leave`/leaveId for consumption, `command`/null for accrual
- `actor_id` → users (nullOnDelete), `reason` (nullable), `created_at` (useCurrent; **no updated_at**)
- Indexes: `(user_id, leave_type, period_year)`, `(txn_type, created_at)`, `(source_type, source_id)`

> Balance is derived (sum), but `balance_after` is stored for fast reads + reconciliation. A reconcile command re-derives and asserts equality.

### `leave_settings` — new policy columns (all nullable/defaulted; existing rows keep working)
- `accrual_method` enum `annual_upfront|monthly|none` default `annual_upfront`
- `accrual_rate` decimal(5,2) nullable — annual entitlement for accrual math; defaults to `days` when null
- `probation_months` unsignedTinyInt default 0
- `prorate_on_join` boolean default true
- `carry_forward_cap` decimal(5,1) nullable (null ⇒ no carry-forward)
- `carry_expiry_months` unsignedTinyInt nullable (null ⇒ carried days never expire)
- `is_encashable` boolean default false
- `allow_negative` boolean default false

> The existing `days`, `carry_forward` (bool), `earned_leave`, `is_earned` remain; the migration back-fills `accrual_method` from them (`earned_leave|is_earned ⇒ monthly`, else `annual_upfront`) and `accrual_rate` from `days`.

## Services (each one responsibility, pure where possible, fully tested)

- **`LeaveLedgerService`**
  - `post(userId, leaveTypeId, year, txnType, amount, source=null, actorId=null, reason=null): LeaveLedger` — appends under a per-(user,type,year) lock, computes `balance_after`.
  - `balance(userId, leaveTypeId, year): float` — sum (uses latest `balance_after` as fast path; falls back to sum).
  - `available(userId, leaveTypeId, asOf=now): float` — current usable balance for the entitlement year of `asOf`.
  - `reverseConsumption(leaveId, reason): void` — posts `consumption_reversal` for the leave's prior consumption (idempotent: skip if already reversed).

- **`LeaveAccrualService`**
  - `accrueMonthly(year, month, ?userId, dryRun): AccrualResult` — for `monthly` types: rate/12, probation-gated by `date_of_joining`, pro-rated on the join month, **idempotent** (skips if an `accrual` row exists for that user/type/month). Posts `accrual` rows.
  - `grantAnnual(year, ?userId, dryRun): GrantResult` — for `annual_upfront` types at year-start (or on join during the year, pro-rated): posts an `opening` row equal to the (pro-rated) entitlement, once per user/type/year.

- **`CarryForwardService`**
  - `rollOver(fromYear, toYear, ?userId, dryRun)` — for types with `carry_forward_cap`: carried = `min(remaining(fromYear), cap)`; posts `carry_forward` (+) into `toYear`; schedules expiry. Idempotent per user/type/year.
  - `expireCarried(asOf)` — posts `carry_expiry` (−unused carried) when `carry_expiry_months` elapsed.

- **`LeaveEncashmentService`**
  - `encash(userId, leaveTypeId, days, actorId, reason): LeaveLedger` — gated by `is_encashable` + sufficient balance; posts `encashment` (−days). Reporting only; no payout.

- **Scheduled commands** (replace the dead ones): `leave:accrue` (monthly), `leave:grant-annual` + `leave:carry-forward` + `leave:expire-carried` (year-boundary), `leave:reconcile-ledger` (audit). Registered in `routes/console.php`.

## Wiring into the leave lifecycle

- **Apply/enforce:** `LeaveCrudService::create/update` calls `LeaveLedgerService::available()` for the type/year; rejects when `requested > available` unless `allow_negative`. (Replaces `getRemainingDays`; deletes that method.)
- **Approve:** when a leave reaches `approved` (auto-approve in `createLeave`, or `LeaveApprovalService` final approval), post a `consumption` (−`no_of_days`, fractional) sourced to the leave. Idempotent (skip if a consumption already exists for that leave).
- **Reject/cancel/delete of an approved leave:** `reverseConsumption(leaveId)` posts `consumption_reversal` (+). Wired alongside the Phase-2 audit hooks.
- **Edit of an approved leave's days:** reverse + re-post consumption with the new amount.

## Seeding (live cut-over, correct)

A reversible data migration/command seeds the live year per (employee, type):
1. `annual_upfront` types → `opening` = entitlement, **pro-rated** if `date_of_joining` is in the live year.
2. `monthly` types → back-fill `accrual` rows from join/Jan to current month (rate/12 each, probation-gated).
3. Post a `consumption` for each already-`approved` leave in the live year (fractional `no_of_days`).
Result: balances are immediately correct and reconcile with the engine's taken-counts. Idempotent + dry-run.

## UI

- Leave page: a per-type **balance card** — *Entitled / Accrued / Taken / Remaining* (+ Carried, Expiring-soon where applicable), from a `GET /leave-balances` endpoint backed by `LeaveLedgerService`.
- Apply form: show remaining for the selected type; surface the enforcement error inline.
- (Admin) a per-employee ledger view (read-only transaction history) — reuses the immutable rows.

## Testing strategy

- PHPUnit class-style, sqlite `:memory:` + `RefreshDatabase`. Pure ledger math unit-tested; lifecycle wiring feature-tested.
- Invariants: `balance_after` equals re-derived sum (reconcile test); accrual + carry-forward + seeding are **idempotent** (run twice = same result); consumption reversal restores balance exactly; enforcement blocks over-draw unless `allow_negative`.
- Reconciliation with Phase-2: ledger `consumption` total per (emp,type,year) == engine approved-leave days for that scope.
- Allowed pre-existing failures only (`MobileSyncApiTest > sync push applies leave apply mutation`; `NavigationRoutesTest > organization directory`). No new failure.

## Rollout / deploy

- New migrations on dev MySQL `dbedc_guardian`; noted for prod. Seeding command run once per environment (dry-run first).
- Retire dead `AccrueMonthlyLeaves`/`ResetAnnualLeaves` + drop (or repurpose) `leave_accruals`/`leave_carry_forwards` after the ledger is the source of truth.
- One consolidated `public/build` for the balance UI.

## Non-goals

- No payroll payout for encashment (recorded only — external payroll).
- No per-grade/per-location leave policy (single global policy per type; schema leaves room).
- No predictive/negative-accrual forecasting beyond `allow_negative` advance leave.
