# Prod Deploy Runbook — Attendance/Leaves/Holidays 10/10 initiative

**Run as the owner on prod (`erp.dhakabypass.com`). Do NOT skip the backup or the dry-run.**
Phase 0 (Weekend→roster) is already done on prod. This covers the Phase 1–4 + ledger work.

## 0. Pre-flight
```bash
php artisan down --render="errors::503"          # optional maintenance window
# BACK UP the DB first (mysqldump of dbedc_guardian-equivalent prod DB).
git pull                                          # get the merged branch
composer install --no-dev -o
php artisan migrate:status                        # see which of the below are still "Pending"
```

## 1. Migrations (idempotent + guarded; run in this order)
```bash
php artisan migrate --force
```
This applies any pending initiative migrations:
- `2026_06_23_000001_enforce_unique_employee_id` — **pre-req:** the 4 placeholder employee_ids must be real/unique first (Munirujjaman EMP-7, Nazmul EMP-97, Habibur 126-DUP5, Prodip 126). The migration widens employee_id to varchar then adds a unique index; it errors if dups/nulls remain.
- `…normalize_leaves_status` — converts legacy `Approved/New/Pending/Declined` → `{approved,pending,rejected}` canonical.
- `…add_paid_to_leave_settings`, `…add_half_day_to_leaves`, `…create_leave_audit_logs_table`, `…enforce_leave_relational_fks` (**pre-req:** 0 orphan/null `leaves.user_id` — the migration throws otherwise; backfill first).
- `…create_holiday_audit_logs_table`, `…add_soft_deletes_to_holidays`.
- `…add_policy_columns_to_leave_settings` (back-fills accrual_method/rate from existing flags), `…create_leave_ledger_table`.

## 2. Review per-type accrual policy (UI)
Open **Leave Management → Settings**. For each leave type set the now-configurable policy:
`accrual_method` (annual_upfront/monthly/none), `accrual_rate`, `probation_months`, `prorate_on_join`,
`carry_forward_cap` (blank = no carry), `carry_expiry_months`, `is_encashable`, `allow_negative`.
Defaults (dev): Casual 10 / Sick 14 / Marital 10 = annual_upfront; Earned 12 = monthly; retire/disable Weekend.
**Decide carry-forward caps here BEFORE the schedule runs** (a cap of null = no carry).

## 3. Seed the ledger (makes balances correct; enforcement is DORMANT until this runs)
```bash
php artisan leave:seed-ledger 2026 --dry-run     # inspect grants/accruals/consumptions counts
php artisan leave:seed-ledger 2026               # for real (idempotent)
php artisan leave:reconcile-ledger               # must report "reconciles cleanly" (exit 0)
```
`--user=<id>` to seed one person. Re-running is safe.

## 4. Enable the schedule
The schedule is already wired in `app/Console/Kernel.php` (no edit needed) once the app's cron
runs `php artisan schedule:run`:
- `leave:accrue` monthly (1st), `leave:grant-annual` + `leave:carry-forward` yearly (Jan 1),
  `leave:expire-carried` + `leave:reconcile-ledger` daily.
Confirm the server cron entry exists: `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`.

## 5. Frontend + finish
```bash
# public/build is committed; no build needed on prod. If you rebuild, use: npx vite build
php artisan config:clear && php artisan route:clear && php artisan view:clear
php artisan up
```

## 6. Smoke test (logged in)
- `/leaves-employee` → balance cards show entitled/accrued/taken/remaining.
- `/leaves` → **Balances** tab → pick an employee → cards + ledger history populate.
- Apply a leave on a seeded type with a small balance → over-draw is rejected; within balance posts a `consumption`.
- `/holidays` → Copy year + a holiday add/edit/delete writes a `holiday_audit_logs` row.

## Cross-repo
Mobile app (`dbedc-mobile-app`): no change required — its status mappers already handle canonical `pending`.
The backend sync now writes `pending` (was `new`).

## Rollback
Migrations are non-destructive (down() are blank/guarded); the ledger is append-only. To "undo" balances,
the ledger rows can be cleared per (user,type,year) and re-seeded. Restore the pre-deploy DB backup if needed.
