# MC Attendance Reconciliation — July 2026 transition

**Status: EXECUTED 2026-07-16 (Boss full-GO).** Outcome log at the bottom.
Backup taken before any change: `~/demo-backups/dbedc-erp-preshiftfix-20260716-065605.sql.gz`.

## Ground rules used

1. **Never raw-edit history silently.** Phantom rows (double-tap artifacts) are deleted with this sheet as the record; missing punch-outs go through `attendance_regularizations` (request → approve trail).
2. **Genuinely worked hours are never edited away** — even when they violate policy. They get flagged, not falsified.
3. **Version backfill** (`shift_versions`): MRN/EVN/NGT old times (08–16 / 16–24 / 00–08) effective `2000-01-01`; new times (07–15 / 15–23 / 23–07) effective **2026-07-16** (the day the config was actually changed). This alone repairs the scoring of every pre-Jul-16 night — no attendance row is touched for that.

## A. Phantom rows to DELETE (double-tap artifacts, all `punchout IS NULL`, all created seconds–minutes after a real punch-out)

| # | User | Row date | punchin | Evidence it's an artifact |
|---|---|---|---|---|
| A1 | Elias u104 | 2026-07-03 | 08:03:xx | His Jul-2 night row closed at 07-03 08:03; this row opened by the same tap |
| A2 | Elias u104 | 2026-07-05 | 08:11:xx | Jul-4 night row closed 07-05 08:10; tap at 08:11 opened this (rostered OFF) |
| A3 | Tanvir u105 | 2026-07-07 | 20:14:xx | Day row closed 20:14 same minute |
| A4 | Tanvir u105 | 2026-07-11 | 20:15:14 | Day row closed 20:14:34, this opened 40s later |
| A5 | Kashab u106 | 2026-07-08 | 07:48:xx | Jul-7 night row closed 07-08 07:48 (rostered OFF) |
| A6 | Zidan u107 | 2026-07-13 | 23:50:02 | Early tap for the (old-times) Jul-14 00:00 night; the real row opened 07-14 00:00 |

Impact of deletion: removes `missing_punch_out` noise and phantom present-marks on OFF days. Zero worked minutes lost (all rows are OPEN with no punch-out).

## B. Missing punch-outs to REGULARIZE (real shifts, officer forgot the out-tap)

| # | User | Date | punchin | Proposed punchout | Basis |
|---|---|---|---|---|---|
| B1 | Tanvir u105 | 2026-07-02 | 20:02 | 2026-07-03 08:00 | MCNS night, his adjacent nights out ~08:00–08:15 |
| B2 | Tanvir u105 | 2026-07-09 | 20:28 | 2026-07-10 08:00 | MCNS night, same basis |
| B3 | Tanvir u105 | 2026-07-12 | 08:33 | 2026-07-12 16:00 | MRN (old 08–16); his Jul-13 pattern 08:27→16:48 |
| B4 | Zidan u107 | 2026-07-04 | 10:22 | 2026-07-04 20:00 | MCD day shift (08–20) |
| B5 | Amzad u108 | 2026-07-10 | 19:56 | 2026-07-11 08:00 | MCNS night |
| B6 | Amzad u108 | 2026-07-11 | 19:57 | 2026-07-12 08:00 | MCNS night |
| B7 | Kashab u106 | 2026-07-03 | 07:58 | 2026-07-03 20:00 | MCD day shift |
| B8 | Tanvir u105 | 2026-07-16 | 15:02 | (real out-tap expected 23:00) | New EVN — only regularize if he misses the out-tap |

Proposed outs are shift-end estimates (conservative: exact shift end, no OT credited). Officers/manager can adjust in the regularization request.

## C. Genuine anomalies — KEEP the rows, note the facts

| # | User | Date(s) | Fact | Disposition |
|---|---|---|---|---|
| C1 | Elias u104 | Jul 11 | Worked day 08:01–18:23 AND night 20:16–08:14 = 22.3h real work | Keep both rows. Compliance validator now exists to prevent recurrence. Consider comp/OT recognition — Boss call |
| C2 | Zidan u107 | Jul 15 | Worked the old-definition night (00:14–08:17) AND the new-definition night (22:55–07:19) = 16.4h — the transition day had two "Jul 15 nights" and he covered both | Keep both rows. Transition cost, honestly earned |
| C3 | Tanvir u105 | Jul 15 | Row `00:16:47 → 08:00` opened 4s after his EVN out-tap (00:16:43) then closed at exactly 08:00 (auto-punch-out) = 7.72h phantom credit | **DELETE** (it's A-class but with hours credited — flagging separately because unlike A1–A6 it inflates worked time) |
| C4 | Amzad u108 | Jul 12 | Rostered NGT (old times 00–08 Jul 12) while his Jul-11 MCNS night (19:57→08:00 Jul 12) covered the same hours — overlapping double-roster on the pattern-switch day. Marked absent though he was in the building | Manual roster cell Jul-12 → OFF (or excused). No attendance edit |
| C5 | Tanvir/Amzad | Jul 16–17 | ✅ RESOLVED — swap #15 (cover, Jul 16) and swap #14 (swap, Jul 17 ↔ Jul 31) both manager-approved 2026-07-16 16:31 and materialized (`roster_days` source=swap locked=1: Tanvir=EVN, Amzad=OFF both days). Tanvir's 15:02 punch is valid; Amzad is not absent. Note: Jul 31 counterpart day owes Amzad a shift per swap #14 | No action |

## D. What the version backfill fixes with no row edits

Every MC night Jul 1–15 currently scored against 23–07 (showing ~22h "early leave", `outside_shift_window`) re-scores automatically against the correct era times: Kashab Jul 13/14, Zidan Jul 13/14/15(first row), Elias Jul 2/4/9 MCNS nights, all MCD/MCR days — verified list available on request.

## Outcome log (2026-07-16)

All sections executed on prod (backup `dbedc-erp-preshiftfix-20260716-065605.sql.gz` taken first):

- **A + C3:** 7 phantom rows deleted (counts all =1, logged).
- **B1–B7:** 7 pending regularizations filed (attendance_ids 8948/8952/8977/9114/9129/9150 + one unlinked) — awaiting manager approval in-app.
- **C4:** Amzad Jul-12 roster cell → OFF (manual, locked).
- **Version backfill:** shifts 6/7/8 sentinel rows = old times (08–16 / 16–24 / 00–08), new times effective **2026-07-16**. All pre-Jul-16 scoring self-corrected.
- **Pattern v2** (`MC3V2`, id 15, `EVN→NGT→OFF→OFF→MRN` ×7) live; old assignments end-dated 2026-07-18; new anchors Amzad 07-19 / Elias 07-12 / Zidan 07-05 / Tanvir 06-28 / Kashab 06-21; roster regenerated Jul 19 → Aug 22.
- **Legacy doubles fixed:** Jul 17 Zidan → OFF (Kashab keeps NGT), Jul 18 Tanvir → OFF (Kashab keeps NGT).
- **Stale future overrides voided** from Jul 19 (roster republication); swap request records kept and annotated.
- **Debt settlements:** #5 → Kashab works Elias's NGT **Jul 23**, Elias off. #7 → 3-way on **Jul 27**: Amzad takes Tanvir's MRN, Tanvir takes Elias's EVN, Elias off (direct transfer proven rest-illegal on this pattern). Pending #13/#16 rejected as moot (off-days under new rotation).
- **Final verification:** coverage Jul 16 → Aug 22 = exactly one MRN+EVN+NGT every day; `WorkTimeComplianceService` = **0 violations** for all five officers Jul 19 → Aug 22; live UI checked in browser (chip fix visible, 0 console errors).
- **Known cosmetic limit:** the roster calendar draws pre-Jul-16 chips with current shift times (frontend receives one shifts catalog, not per-date versions). Scoring is version-correct; drawing-only drift. Candidate follow-up: per-cell effective times in the roster payload.

## Execution order (after sign-off)

1. Deploy code (versioning + punch binding + compliance + UI).
2. `php artisan migrate` (creates `shift_versions`, backfills sentinels).
3. Insert historical version rows for shifts 6/7/8 (old times, effective 2000-01-01; new times effective 2026-07-16). SQL prepared.
4. Apply A-deletes + C3 (single transaction, logged).
5. File B-regularizations (via UI or seeded requests for manager approval).
6. C4 roster cell fix.
7. New rotation pattern + anchors effective 2026-07-19 (per `mc_solution_report.md`), regenerate roster Jul 19 onward, verify coverage.
8. Live UI verification: daily timesheet, roster calendar, team attendance.
