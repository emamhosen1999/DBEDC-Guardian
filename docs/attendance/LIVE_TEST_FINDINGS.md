# Attendance Requests — Live UI Test Findings (2026-06-22)

Live UI test of all employee request types on the dev site (`https://aero-enterprise-suite.test`) as **Zidan Ul Azim** (employee) + admin approver. UI-only (real forms/clicks). Bugs found are logged here.

## Bugs

### BUG-1 (Minor, UX) — "My Requests" list did not auto-refresh after submitting — ✅ FIXED
- **Where:** `/attendance-employee` → My Requests, after submitting Overtime / Regularization / Swap.
- **Observed:** the new request only appeared after a manual page reload.
- **Root cause (confirmed):** `RequestsCard` remounted `MyRequests` via `key={refreshKey}`, but the global React-Query `staleTime` is **5 minutes** (`api/reactQueryClient.js`), so a remount returned cached data instead of refetching. Separately, the swap form's `onSaved` only refetched the roster, never `my-swaps`.
- **Fix (`resources/js/Pages/AttendanceEmployee.jsx`):** replaced the key-remount with `queryClient.invalidateQueries` for `my-regularizations` / `my-overtime` / `my-comp-off` / `my-swaps` on save; the swap form now also invalidates `my-swaps` (in addition to refetching the roster). Invalidation forces an immediate refetch regardless of `staleTime`.
- **Verified live (UI):** submitted a 45-min OT → it appeared in the Overtime list **without a reload**.

### BUG-3 (NOT A DEFECT — test-harness race) — admin "Approvals" tab "needs multiple clicks"
- **Re-tested with a single click + explicit wait-for-content:** the tab switches correctly on ONE click; "Swap Requests" content appears after the lazy `Suspense` skeleton. The earlier "multiple clicks" symptom was the Playwright snapshot firing during the lazy-load transition (and the Daily Timesheet's ~10s poll re-rendering between snapshots) — not a real user-facing bug. The ~19 console "errors" are benign noise (profile images pointing at `127.0.0.1:8000`, Firebase config, update-check). **No code change.**

### BUG-4 (Infra/transient, resolved) — stale config cache caused a 500 ("No application encryption key")
- A page 500'd once with `MissingAppKeyException` though `APP_KEY` is set in `.env`; `php artisan config:clear` fixed it (stale `bootstrap/cache/config.php` on the dev box). Environmental, not an app bug. **No code change.**

### BUG-2 (Minor, UX) — counter for Approvals tab / no submitted-confirmation
- Not a blocker; noted: after an employee submits, there's no inline confirmation in the list (see BUG-1) and the admin Approvals tab has no pending-count badge to signal new items. Consider a count badge on the Approvals tab.

## Results — all request types passed end-to-end (UI only)

Tested as **Zidan Ul Azim** (submit) → **Emam Hosen / Super Admin** (approve), pure UI.

| Type | Submitted (UI) | In My Requests | Approved (UI) | Effect verified |
|---|---|---|---|---|
| **Overtime** | Jun 18, 120 min, reason | ✅ pending → approved | ✅ (admin Approvals, *Grant comp-off* checked) | **Comp-Off Balance → 2h 0m (120 min)** |
| **Comp-off** | (accrued via OT grant) | ✅ balance shown | — | credited 120 min from the OT approval |
| **Regularization** | Jun 17, missing punch-out → 18:00, reason | ✅ pending → approved | ✅ (admin Approvals) | **Jun 17 timesheet: Clock-out 6:00 PM, 5h 17m, "All complete"** (was "No punch out") |
| **Swap / Cover** | Jul 1 NIGHT ↔ Shahadat Jul 3 DAY (roster-driven pickers) | ✅ "Awaiting counterparty consent" | ✅ counterparty **accepted** (UI) → admin **approved** (UI) | **4-cell roster rewrite correct**: Zidan Jul1→OFF, Shahadat Jul1→NIGHT, Shahadat Jul3→OFF, Zidan Jul3→DAY |

**Swap E2E (UI):** submit (Zidan) → My Requests tracking → counterparty consent (Shahadat, Accept) → manager approve (admin) → roster rewritten exactly (verified, then restored). Pickers correctly listed only same-dept coworkers free that day (Emam, Shahadat) and each side's real shifts. Screens: `admin-approvals-state.png`.

Screens: `zidan-requests-approved.png` (My Requests all approved + Jun 17 corrected), `zidan-my-swaps.png` (swap tracking).

**Conclusion:** the full request lifecycle works on the live dev site for every type — submit → My Requests tracking → manager/admin approval → real effect (comp-off credit, attendance correction applied & hours recomputed). Only **BUG-1** (list not auto-refreshing after submit) is worth fixing; it's cosmetic (a reload shows the request).

## Cleanup
Live dev test data removed after verification (teardown only; the *test* itself was UI-only): deleted the test overtime + regularization requests and the comp-off ledger credit, and restored Jun 17's attendance to its original "no punch-out" state (+ removed the regularization's audit row). No prod data touched.
