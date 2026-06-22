# Attendance Employee Requests — Real-World Scenarios

How each employee self-service request works end-to-end (who raises it, what they enter, who approves, what changes), with concrete scenarios. All requests are raised from the employee app (`/attendance-employee`), tracked by the requester under **My Requests**, and actioned by a manager/HR in the **Approvals** inbox. Single real approver: manager → HR/admin (super-admin can always act).

| Request | Employee submits | Approver action | Effect on approval |
|---|---|---|---|
| **Regularization** | a correction to a day's punches | approve/reject | the attendance record is corrected (audited) |
| **Overtime** | extra minutes worked on a date | approve (± grant comp-off) / reject | OT recorded for pay; optionally credited as comp-off |
| **Comp-off (TOIL)** | *(not submitted — accrued)* | — | balance grows when OT is approved "as comp-off"; spent as time off |
| **Shift Swap / Cover** | give up a rostered shift to a same-dept coworker | counterparty consents → manager approves | roster is rewritten (see ATTENDANCE_MODULE §5.5) |

Where the employee sees status: **My Requests** panel shows Regularization, Overtime, Comp-Off balance, and **Shift Swaps** (the requests they initiated, with live status). Swaps awaiting *their* consent (as counterparty) appear separately under "awaiting my response".

---

## 1. Regularization — fix a wrong or missing punch

**Endpoint:** `POST /attendance/regularizations` → `{date, type, requested_punchin?, requested_punchout?, reason}`; `type ∈ missing_punchin | missing_punchout | wrong_time | missed_day | other`. On approval the engine recomputes the day and writes an immutable audit row (actor, before→after, reason, IP).

**Scenario 1 — Forgot to punch out (most common).**
Zidan clocks in at 09:00 but leaves without clocking out; the timesheet shows **"Not Punched Out"** and 00:00 hours. He opens *Regularize a day*, picks the date, `type = missing_punchout`, `requested_punchout = 18:00`, reason "Forgot to punch out — left at 6pm." His manager approves → the day's punch-out becomes 18:00, worked hours recompute to ~9h, status flips to Present, audit logged.

**Scenario 2 — Biometric missed the entry.**
The fingerprint reader didn't capture Zidan's morning punch, so only a punch-out exists. `type = missing_punchin`, `requested_punchin = 08:55`. Approval fills the missing in-punch.

**Scenario 3 — Wrong recorded time (clock drift / late sync).**
A device with a drifted clock stamped 10:15 for a 09:00 arrival, marking him Late. `type = wrong_time`, `requested_punchin = 09:00`. Approval corrects the time and clears the false Late.

**Scenario 4 — Whole day missed (off-site / device down).**
Zidan worked a client site with no device. `type = missed_day`, `requested_punchin = 09:00`, `requested_punchout = 17:00`, reason "Field visit — no device." Approval creates the day's record so he isn't marked Absent.

**Scenario 5 — Other.**
Anything not covered above, explained in `reason` (e.g. "Left early for medical, approved verbally").

**Rejection:** the manager rejects with a reason; the day is unchanged and Zidan sees "Rejected" with the reason in My Requests.

---

## 2. Overtime — claim extra hours worked

**Endpoint:** `POST /attendance/overtime` → `{date, requested_minutes (1–1440), reason}`. Approve supports `grant_comp_off` (boolean): when true the approved minutes are credited to the comp-off ledger instead of/along with OT pay.

**Scenario 1 — Deadline crunch (paid OT).**
Zidan stays 2 extra hours to finish a delivery. He submits OT for that date, `requested_minutes = 120`, reason "Release deadline." Manager approves (no comp-off) → 120 OT minutes are recorded for the day, feeding the payroll OT bucket.

**Scenario 2 — Weekend/holiday cover taken as time-off-in-lieu.**
Zidan works 4 hours on a public holiday. He requests `requested_minutes = 240`. The manager approves **with "grant comp-off"** → 240 minutes are credited to Zidan's **comp-off balance** instead of cash OT, to be taken as time off later.

**Scenario 3 — Over-claim trimmed / rejected.**
Zidan claims 180 min but the manager judges only the sanctioned work qualifies and **rejects** with a reason ("OT not pre-approved for this date"); Zidan sees the rejection in My Requests and can resubmit with justification.

---

## 3. Comp-off (TOIL) — accrued, not submitted

Comp-off is **not a submitted request** — it is a balance. It is **credited** when a manager approves an overtime request "as comp-off" (Scenario 2.2) or when an admin grants it; the ledger records each credit/debit with its source. The employee sees **Comp-Off Balance** and recent ledger entries in My Requests.

**Scenario — Accrue then take.**
Zidan accrues 240 min from holiday cover (above). His balance shows **4h 0m (240 min)**. Later he takes a half-day off; HR/admin debits 240 min against the balance, which drops to 0. (Self-service comp-off *redemption* as a leave type is a planned enhancement; today the balance is accrued automatically from OT and spent administratively.)

---

## 4. Shift Swap / Cover — trade or give away a rostered shift

Fully described in `ATTENDANCE_MODULE.md` §5.5. In brief: the employee picks one of **their own rostered shifts** and a **same-department coworker who is free that day**; **Cover** = the coworker takes it (requester gets the day off), **Swap** = a two-sided trade where the requester also takes one of the coworker's shifts. The coworker **consents**, then a manager **approves**, and the roster is rewritten (cover = 2 cells, swap = 4 cells). The requester tracks status under **My Requests → Shift Swaps**; the coworker responds under "awaiting my response".

**Scenario — Zidan can't work Jul 1.**
Zidan is rostered NIGHT on Jul 1 and wants it off. He opens *Request swap*, picks **Jul 1 — NIGHT**, sees same-department coworkers free that day, and either: **Cover** → Shahadat (off Jul 1) takes Zidan's Jul 1 NIGHT, Zidan owes nothing; or **Swap** → Zidan takes Shahadat's **Jul 3 — DAY** in exchange. Shahadat accepts, the manager approves, and the roster updates so Zidan is off Jul 1.

---

## Approval routing & audit (all types)

- One real approver: the requester's manager (`report_to`), falling back to HR/admin; a super-admin can always act. No self-approval.
- Every approve/reject is recorded; attendance-mutating approvals (regularization) write an immutable audit row. Swap approvals lock the rewritten roster cells (`source=swap`).
- Statuses an employee sees: **pending** → **approved** / **rejected** (swaps add the peer stage: *awaiting counterparty consent* → *awaiting manager approval*).
