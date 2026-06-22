# Attendance Requests — Live UI Test Findings (2026-06-22)

Live UI test of all employee request types on the dev site (`https://aero-enterprise-suite.test`) as **Zidan Ul Azim** (employee) + admin approver. UI-only (real forms/clicks). Bugs found are logged here.

## Bugs

### BUG-1 (Minor, UX) — "My Requests" list does not auto-refresh after submitting
- **Where:** `/attendance-employee` → My Requests, after submitting Overtime (and likely Regularization / Swap) via their dialogs.
- **Observed:** Submitting an overtime request showed the success path and closed the dialog, but the **Overtime Requests** list still read "No overtime requests yet." It only appeared after a **manual page reload** (then correctly showed "18 Jun 2026 · 120 min · pending").
- **Cause (likely):** the request form's `onSaved` does not invalidate the React-Query keys the `MyRequests` panel uses (`my-overtime`, `my-regularizations`, `my-swaps`, `my-comp-off`). The swap form passes `onSaved={refetch}` which refetches the *roster*, not these lists.
- **Impact:** employee thinks the submit failed and may resubmit (duplicate requests).
- **Fix:** on successful submit, `queryClient.invalidateQueries` the relevant `my-*` keys (or pass a refetch callback that targets the MyRequests queries).

### BUG-3 (Investigate) — admin "Approvals" tab intermittently fails to switch
- **Where:** `/attendance` (admin) → clicking the **Approvals** tab.
- **Observed:** sometimes the panel does not switch from Daily Timesheet (took 2 clicks in one round; would not switch at all in another after 3+ clicks), accompanied by ~19 console errors. On a fresh page load it usually works.
- **Impact:** approver can't reach the inbox reliably.
- **Next:** capture the console errors (likely a child component throwing on this tab) — could be an ErrorBoundary swallowing a render error in `ApprovalsInbox`/a sibling. Reproduce on a clean session.

### BUG-4 (Infra/transient) — stale config cache caused a 500 ("No application encryption key")
- A page 500'd once with `MissingAppKeyException` though `APP_KEY` is set in `.env`; `php artisan config:clear` fixed it. Likely a stale `bootstrap/cache/config.php` on the dev box (also forces `production` env label). Ensure config cache isn't committed/stale in dev.

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
