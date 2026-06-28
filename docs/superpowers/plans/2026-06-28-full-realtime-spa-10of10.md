# Full 10/10 — Realtime · SPA · Reactive · Living (complete roadmap)

> Executable roadmap to take the WHOLE app to 10/10. The high-traffic realtime core is already shipped + prod-proven (roster, attendance, leave, daily-works). This plan covers everything remaining. Recommended execution: subagent-driven, one task = one commit, per the established pattern (server `touch()` + client subscribe; build only when client changes).

**Reusable infra already in place (don't rebuild):** `RealtimeSignal::touch(entity,bucket,actorId,action)` (fail-open, ID-only), `useRealtimeSignals({path,selfActorId,onSignal})` (server-shared namespace via Inertia, self-actor filtered, initial-snapshot skipped), `/firebase/token` custom-token endpoint, RTDB rules, `firebase-config` (RTDB+Auth). **Build only runs from a checkout whose `.env` has `VITE_FIREBASE_*`.**

---

## Phase A — finish write-path coverage on the 4 live domains (server-only, NO build)
Clients already subscribe to these buckets, so each is a one-line `touch()` before the success return. Push PHP → prod `git pull` + `optimize` (no hard-refresh).

- [ ] Leave: `update`, `destroy`, `bulkStatusUpdate`, `updateStatus` → `touch('leave','all', <actor>, <action>)`.
- [ ] Leave (mobile `MobileLeaveController`): `approve`, `reject`, `bulkApprove`, `bulkReject` → `touch('leave','all',...)` (so mobile-driven approvals light up the web queue).
- [ ] Daily-works: `updateCompletionTime`, `updateSubmissionTime`, `import` (after rows created) → `touch('dailywork','all',...)`. (create is via import.)
- [ ] Attendance: time-correction (`updateTimeCorrection`/`correct`) + correction delete → `touch('attendance', <date>, <actor>, 'correction')`.

## Phase B — remaining domains (server `touch()` + client subscribe; build per client change)
Each row: add server `touch(entity, bucket, actor)` on its write paths + a `useRealtimeSignals` subscription in its list component with the data-layer-appropriate reaction.

| Domain | Server write seam(s) | Client component | onSignal reaction |
|---|---|---|---|
| Objections | `ObjectionController` store/update/destroy/submit/review/resolve/reject (+ RfiObjectionController per-DW) | `Pages/Project/Objections/Index.jsx` | `router.reload({only:['objections','statistics']})` |
| Petty-cash | `PettyCashController` transaction create/approve/reject/loan | `Components/PettyCash/TransactionsPanel.jsx` (+ kill `window.location.reload` in `PettyCashUnified.jsx`) | its existing fetch / `router.reload` |
| Holidays | `HolidayController` store/update/destroy | `Pages/Holidays` | RQ invalidate or `router.reload` |
| Swap approvals | swap approve/reject controller | `SwapApprovals` / `SwapResponses` | RQ invalidate |
| Regularizations | `RegularizationController` approve/reject | approvals inbox | RQ invalidate |
| Overtime | `OvertimeController` approve/reject | overtime pending | RQ invalidate |
| Punch exceptions | `PunchExceptionController` approve/reject | punch-exceptions pending | RQ invalidate |
| Shifts / attendance settings | `ShiftController` / settings update | `ShiftsSettings` / `SettingsTab` | RQ invalidate |
| Employees / org | user/department/designation CRUD | `EmployeesPage` tabs | RQ invalidate |
| Jurisdictions | `JurisdictionController` CRUD | `JurisdictionsManager` | RQ invalidate / `router.reload` |

Bucket guidance: per-date for attendance-like, `'all'` for low-frequency queues, per-month for calendar-like. Always pass `actor_id` for self-filtering.

## Phase C — optimistic + row-patch upgrade (gap c)
Today only roster has full optimism; others are signal→refetch. To reach 10/10 "reactive":
- [ ] Standardize all mutating screens on `useOptimisticMutation` (cancel→snapshot→`setQueryData` patch→rollback→narrow revalidate) — extend it with the 409-reconcile + retry + animation hooks already prototyped for roster.
- [ ] Where data is in React Query, make `onSignal` do a **targeted `setQueryData` row-patch** (refetch the single changed row by id) instead of refetching the whole list — requires ID-rich signals (already have `id` slot) + a per-domain row fetch endpoint.
- [ ] Animated enter/exit on list add/remove (Radix/CSS), toast+retry on rollback.
This is per-screen work; sequence after Phase B so each domain gets optimism + realtime together.

## Phase D — SPA consolidation (gap d)
The original audit's core critique — still open:
- [ ] Make **React Query the single server-state source**; hydrate its cache from Inertia initial props on first paint; retire the manual-`axios`+`useState` fetching (e.g. `AdminLeavesPanel`) and the duplicated Inertia-prop refetch lists.
- [ ] Retire the legacy data-paradigm deps — NOTE: these are NOT unused (audit was wrong), so each needs a migration first: `@inertiajs/inertia@^0.11` (the `Inertia` facade is used in `ErrorBoundary.jsx` + `Layouts/useSt.jsx` → migrate to v2 `router`), `laravel-precognition-react` (used in `Forms/AddEditUserFormRadix.jsx` → migrate to Inertia `useForm`/react-hook-form), then drop `@inertiajs/progress`. Confirm Zustand is UI-state only.
- [x] Kill the last full reload: `window.location.reload()` in `PettyCashUnified.jsx` → `router.reload()`. DONE.
- [ ] Add `broadcastQueryClient` for instant same-device multi-tab cache sync; clear the RQ cache on logout (user-scoping).
- [ ] Re-enable `refetchOnWindowFocus`, lower global `staleTime` (realtime keeps data fresh).

## Honest sequencing & effort
- **A:** ~1 short session (server one-liners). Highest value-per-effort remaining.
- **B:** ~1 session per 2–3 domains (each = touch + subscribe + build).
- **C:** multi-session (per-screen mutation rewrites).
- **D:** dedicated mini-project (touches nearly every page; do behind a branch with full regression pass).

**Definition of done (10/10):** every mutating action in every domain (a) applies optimistically with rollback, (b) patches the affected row, (c) broadcasts an ID-only signal, and (d) every other client patches that row in <1s — with one client-side source of truth and zero full-page reloads.
