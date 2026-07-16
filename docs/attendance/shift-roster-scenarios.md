# Shift & Roster Management — Complete Scenario Taxonomy and System Assessment

**Date:** 2026-07-16
**Scope:** DBEDC-Guardian attendance engine (`app/Services/Attendance/*`)
**Trigger:** MC shift times changed 2026-07-16 13:22 (MRN 08–16→07–15, EVN 16–24→15–23, NGT 00–08→23–07)
**Verdict:** the engine is well-built at the *rule* layer and structurally unsound at the *time-model* layer.

Everything marked ✅ VERIFIED was read in code or observed in prod data. Everything marked ⚠️ UNVERIFIED
is a gap I did not confirm — do not treat as fact.

---

## 0. The three facts everything below follows from

| # | Fact | Evidence |
|---|---|---|
| F1 | **`attendance.date` = the calendar date of the punch-in. Nothing else.** | `AttendancePunchService.php:40,450,519` — `$punchDate = $punchTime->copy()->startOfDay()` ✅ |
| F2 | **A roster date D means "the shift STARTS on D".** Cross-midnight extends only the *end* into D+1. | `Shift::toSchedule()` — start anchored on D, `if (crosses_midnight) $end->addDay()` ✅ |
| F3 | **Status is derived at read time from the *current* `shifts` row.** Nothing is frozen. | `AttendanceReportService::buildMonthlyDayResults()` resolves schedule live; `attendances` has no status column ✅ |

F1 + F2 mean a punch and its roster day agree **only if the shift starts on the same calendar date as the punch**.
F3 means editing a shift time silently rewrites every historical day that used it.

---

## 1. The root defect: there is no Shift Instance

Every mature attendance system (SAP TM, Kronos/UKG, Workday, Deputy, Quinyx) models **three** layers:

```
Shift Definition   (template: "Night, 23:00, 8h")      — versioned, effective-dated
      ↓ materialise
Shift Instance     (occurrence: user 107, starts 2026-07-16T23:00+06, ends 2026-07-17T07:00+06)
      ↓ bind
Punch Events       (bound to the INSTANCE, not to a calendar date)
```

Guardian has layer 1 (`shifts`) and a *display-only* layer 2 (`roster_days`), and **no binding at all** —
punches attach to a bare `date` column and are re-joined to a re-derived schedule at read time.

Worse: `RosterService::resolveShift()` only honours `roster_days` rows whose `source` is `manual` or `swap`.
**Generated rows are decorative** — the engine re-derives from the pattern every time. So the roster you
*see* and the roster you're *judged against* are two different computations that can silently diverge. ✅ VERIFIED

**Consequences, all observed in prod:**
- Change a shift time → all history re-scored (Kashab 07-13: `early=1352min`).
- A night's identity depends on its start hour → moving 00:00→23:00 moved every night one calendar day.
- Two shifts in one calendar date collapse into one row-set → Elias 07-11: `worked=22.32h`.
- A punch 15 min after midnight for a 23:00 shift lands on D+1 → absent on the real day, phantom row on the next.

**This is one bug, not four.** Fix the time model and all four close.

---

## 2. Scenario taxonomy

Legend — **Std** = what industry practice requires. **Guardian** = what we do. Severity: 🔴 broken · 🟠 fragile · 🟢 fine.

### 2.1 Shift shapes

| # | Scenario | Std | Guardian | Sev |
|---|---|---|---|---|
| 1.1 | Fixed day shift (09–17) | Trivial | Works | 🟢 |
| 1.2 | Cross-midnight shift (23–07) | Instance owns start+end datetimes; date attribution is an explicit policy | Start pinned to roster date; `crosses_midnight` flag extends end | 🟠 works *only* if punch-in lands on the start date |
| 1.3 | Shift starting exactly at 00:00 | Same as any other | Works, but is a **knife-edge**: any drift across midnight flips the date | 🔴 this is what bit MC |
| 1.4 | Split shift (06–10 + 17–21) | Two instances, one shift day | No concept. Two rows on one date get summed as one span | 🔴 |
| 1.5 | Shift > 24h (offshore/relief) | Explicit multi-day instance | `MAX_OVERNIGHT_HOURS=18` caps pairing; beyond that the out-punch orphans | 🔴 |
| 1.6 | Flexi / no fixed start | Core-hours or band model | `core_start_time`/`core_end_time` columns exist, unused ⚠️ | 🟠 |
| 1.7 | On-call / standby (paid to be available) | Separate instance class, different pay rule | No concept | 🔴 |
| 1.8 | Rotating pattern | Anchored cycle | `shift_rotation_patterns` + `anchor_date` + phase modulo — good design | 🟢 |
| 1.9 | 12h continental (2-2-3) | Pattern | Supported by the pattern engine | 🟢 |

### 2.2 Date attribution — the question that broke MC

There are exactly **four** attribution policies in use worldwide:

| Policy | Rule | Who uses it |
|---|---|---|
| **A. Shift-start date** | Night belongs to the date it begins | Most Western rostering (Deputy, Quinyx) |
| **B. Shift-end date** | Night belongs to the morning it ends | Common in South Asian / Gulf security & industrial rosters |
| **C. Majority-hours** | Whichever date holds >50% of the span | SAP TM option |
| **D. Business-day cutoff** | A day runs e.g. 06:00→06:00; punches bind to the containing window | Hospitality, healthcare, casinos |

**Guardian implements A — but only by accident**, as an emergent consequence of F1+F2. It is not a
setting, not documented, and not enforced. Nobody chose it.

**MC was operating under B** (night = the morning it ends: 00:00–08:00 on D was "D's night"), and the
old 00:00 start made A and B *coincide*. Moving the start to 23:00 broke the coincidence and silently
switched MC from B to A. **That is the entire incident.** ✅ VERIFIED — Zidan's row
`date=2026-07-15 in=07-15 22:55 out=07-16 07:19` is the same physical night that would have been
stamped 07-16 a week earlier.

🔴 **Gap:** no attribution policy setting exists. Best practice: `shifts.date_attribution` enum
(`start`|`end`|`majority`|`cutoff`) + a `business_day_start` org setting, applied at instance
materialisation and frozen onto the row.

### 2.3 Punch → shift binding

| # | Scenario | Std | Guardian | Sev |
|---|---|---|---|---|
| 3.1 | Punch inside window | Bind to instance | Binds by calendar date | 🟠 |
| 3.2 | Early punch-in (22:45 for 23:00) | Bind to instance, clamp | Same date → OK | 🟢 |
| 3.3 | **Late punch-in past midnight (00:15 for 23:00)** | Bind to nearest instance within tolerance → LATE | `date = D+1` → **ABSENT on D + phantom row on D+1** | 🔴 |
| 3.4 | Punch-out next morning | Close the instance | `findOpenAttendanceToClose` handles prior-day rows if `crosses_midnight` && <18h — solid | 🟢 |
| 3.5 | Double-punch (tap twice) | Dedupe window | `DEDUPE_WINDOW_SECONDS` — but only when `check_type` absent | 🟠 observed failing: Elias 07-03 `08:03→OPEN`, Kashab 07-08 `07:48→OPEN` |
| 3.6 | Missing punch-out | Auto-close at shift end + flag | `AttendanceAutoPunchOut` does this ✅ | 🟢 |
| 3.7 | Auto-close when roster says OFF | Should not guess | Falls back to `$in->copy()->endOfDay()` → **23:59 punch-out, ~24h row** | 🔴 |
| 3.8 | Punch with no roster | Flag `unscheduled` | Does flag it ✅ | 🟢 |
| 3.9 | Offline punch replay | Bounded, marked | `was_offline` flag ✅ | 🟢 |

### 2.4 Change management — where we are weakest

| # | Scenario | Std | Guardian | Sev |
|---|---|---|---|---|
| 4.1 | **Edit a shift's times** | New *version*, effective from date X; history scored against the old version | Single mutable row. **All history re-scored instantly** | 🔴 CRITICAL |
| 4.2 | Change pattern mid-cycle | Supersede: end-date old assignment, add new | Supported (`effective_from/to`, overlap rejected) ✅ | 🟢 |
| 4.3 | Regenerate roster | Preserve manual overrides | `locked`/`source=manual\|swap` survive ✅ | 🟢 |
| 4.4 | Shift-time change mid-roster | Warn + require regeneration + reconcile in-flight shifts | **Nothing.** No warning, no regeneration prompt, no reconciliation | 🔴 |
| 4.5 | Retro-correct an old day | Regularisation request + approval | `attendance_regularizations` exists ✅ | 🟢 |
| 4.6 | Delete/deactivate a shift in use | Block or cascade | `nullOnDelete` → roster day becomes OFF silently ⚠️ | 🟠 |

**4.1 is the headline.** `shifts` has `updated_at` and nothing else — no `effective_from`, no version,
no history table. There is no way to answer "what were this shift's hours on July 13?" The evidence
is already lost; I only know the old times because you told me.

### 2.5 Compliance & safety rules

| # | Scenario | Std | Guardian | Sev |
|---|---|---|---|---|
| 5.1 | Min rest between shifts (11h EU) | Hard block or violation | **None** | 🔴 |
| 5.2 | One shift per 24h | Hard block | **None** — Elias 07-11 = 22.32h, no flag | 🔴 |
| 5.3 | Max consecutive nights | Warn | Only in the offline rota solver, not the system | 🟠 |
| 5.4 | Max hours/week | Violation | ⚠️ not found | 🔴 |
| 5.5 | Forbidden transitions (E→M etc.) | Roster validation | Encoded in the *pattern*, not validated by the app. **A shift-time change invalidates them silently** | 🔴 |
| 5.6 | Coverage gap (nobody on shift) | Alert | `CoverageService` exists ⚠️ scope unverified | 🟠 |
| 5.7 | Lone-worker / min headcount | Alert | ⚠️ | 🟠 |

**5.5 deserves emphasis.** Your fairness pattern forbids M→N, E→M, E→N, N→M — computed against
00–08 nights. Under 23–07, **N→E on consecutive roster days** becomes punch-out 07:00, punch-in 15:00
*the same day*: 16h in 24. That transition was legal before and is now dangerous. The rules live in a
Python solver in a scratchpad, so nothing rechecked them when the times moved.

### 2.6 Exceptions

| # | Scenario | Guardian | Sev |
|---|---|---|---|
| 6.1 | Approved leave on a rostered day | `ON_LEAVE`, precedence holiday > off > leave > absent ✅ | 🟢 |
| 6.2 | Worked while on full-day leave | `worked_on_leave` flag, no silent relabel ✅ | 🟢 |
| 6.3 | Half-day leave | `leave_fraction`/`leave_session` ✅ | 🟢 |
| 6.4 | Holiday worked | `worked_on_off_day`, all OT-eligible ✅ | 🟢 |
| 6.5 | Comp-off banking | `CompOffService` ✅ | 🟢 |
| 6.6 | Formal swap | `applySwap` → `source=swap` ✅ | 🟢 |
| 6.7 | **Informal swap (A covers B, unrecorded)** | B=absent, A=`worked_on_off_day`. **Observed: Tanvir covered Amzad's 07-16 EVN** | 🔴 no "cover" concept |
| 6.8 | Future rostered day | Returns `ABSENT` unless caller passes `$until` | 🟠 fragile default |

### 2.7 Time, timezone, boundaries

| # | Scenario | Guardian | Sev |
|---|---|---|---|
| 7.1 | Timezone | `APP_TIMEZONE=Asia/Dhaka`, MySQL session UTC ✅ | 🟢 |
| 7.2 | DST | Bangladesh has none → dormant. Times stored as wall-clock `datetime`, no offset → **would break on any DST tenant** | 🟢 here / 🔴 if exported |
| 7.3 | Org timezone change | No re-anchoring | 🔴 ⚠️ |
| 7.4 | **Month-boundary night (Jul 31 23:00 → Aug 1 07:00)** | Stamped **Jul 31** now, **Aug 1** before the change → moves between payroll periods | 🔴 |
| 7.5 | Device clock drift | ⚠️ | 🟠 |
| 7.6 | Multi-site timezones | Single app TZ | 🔴 ⚠️ |

### 2.8 Data integrity

| # | Scenario | Guardian | Sev |
|---|---|---|---|
| 8.1 | Audit trail on changes | `AttendanceAuditService` ✅ | 🟢 |
| 8.2 | Immutable finalised periods | **None** — every read recomputes from live config | 🔴 |
| 8.3 | Payroll lock | No payroll exists at all | 🔴 |
| 8.4 | Reproducible historical report | **Impossible** — same query returns different answers before/after a config edit | 🔴 |

---

## 3. What good looks like (target design)

1. **Version shift definitions.** `shift_versions(shift_id, effective_from, start_time, end_time, …)`.
   Resolution takes a date. History stops moving.
2. **Materialise Shift Instances.** `shift_instances(user_id, shift_version_id, starts_at, ends_at, business_date)`
   — `business_date` computed once by the attribution policy and **frozen**.
3. **Bind punches to instances.** `attendances.shift_instance_id`. Bind by nearest instance within a
   tolerance window, not by `startOfDay()`. Kills 3.3 and 2.2 outright.
4. **Make attribution explicit.** `date_attribution` enum + `business_day_start`. MC would set
   `end` (or cutoff 06:00) and the incident never happens.
5. **Snapshot the verdict.** Freeze status/worked/late/OT onto the row once a period closes.
6. **Move the rota rules into the app.** Min-rest, one-shift-per-24h, max-consecutive-nights as
   validators that run on roster generation *and* on shift-definition edits.
7. **Guard config edits.** Editing a shift with in-flight or historical instances → diff preview,
   effective-from picker, regeneration prompt.

---

## 4. Priority

| P | Item | Why |
|---|---|---|
| **P0** | Decide MC's attribution policy (start vs end date) and align roster + reality | Live rota; officers and records disagree *tonight* |
| **P0** | Reconcile Jul 12–16 (Zidan 16.45h, Elias 22.32h, Tanvir/Amzad swap) | Payroll-grade wrong data |
| **P1** | Version shift definitions (§3.1) | Root cause of retroactive rewrite |
| **P1** | Shift instances + punch binding (§3.2–3.3) | Root cause of midnight misdating |
| **P2** | Min-rest + one-shift-per-24h validators (§5.1–5.2) | Safety; already violated in prod |
| **P2** | Attribution policy setting (§3.4) | Makes the model explicit |
| **P3** | Cover/informal-swap concept (§6.7) | Data honesty |
| **P3** | Auto-punch-out OFF-day fallback (§3.7) | Produces ~24h rows |

---

## 5. Honest scoring

| Layer | Score | Note |
|---|---|---|
| Rule engine (`AttendanceStatusService`) | **8/10** | Pure, deterministic, good precedence, honest flags. Genuinely well written. |
| Leave / holiday / comp-off | **8/10** | Ledger-based, hardened, idempotent. |
| Rotation patterns | **7/10** | Good anchor model; rules live outside the app. |
| Punch capture | **6/10** | Never blocks capture (right call); dedupe leaks. |
| **Time model** | **2/10** | No versioning, no instances, date-keyed binding. Everything above rests on it. |
| **Change management** | **1/10** | A dropdown edit silently rewrote a month of history with zero warning. |

The rule engine is better than most commercial products I've read. It is computing beautifully
correct answers about the wrong objects.

---

*Grounded in: `AttendancePunchService`, `AttendanceStatusService`, `AttendanceReportService`,
`RosterService`, `RosterScheduleResolver`, `Shift`, `AttendanceAutoPunchOut`, prod DB
`aeos365_dbedc_erp` (roster_days / attendances / shifts / shift_assignments, Jul 1–20 2026).*
