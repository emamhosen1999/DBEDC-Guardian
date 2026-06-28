# Expressway Monitoring Center — Foundation + Traffic/Incidents (Design)

**Date:** 2026-06-28
**Status:** Approved (brainstorming) — pending implementation plan
**Scope of this spec:** Sub-project 1 of 4 — the **Shared Foundation** plus the **Traffic & Incidents** subsystem.

---

## 1. Context & Decomposition

The Expressway Monitoring Center is the **operations/traffic** side of an expressway business whose ERP (Aero Enterprise Suite) already covers HR and an expressway construction/quality/RFI domain (where "chainage" is already a first-class concept). It is **not one feature** — it is four interrelated subsystems, each large enough for its own spec → plan → build cycle:

1. **Traffic & incidents** — live operational heart of a 24/7 control room. *(this spec)*
2. **Patrol & maintenance** — depends on topology + incidents.
3. **Assets & ITS devices** — depends on topology; feeds incidents + maintenance.
4. **Toll / revenue ops** — depends on topology (plazas/lanes); otherwise self-contained.

A thin **Shared Foundation** (topology, control-room duty/shift, incident-scoped journal) is built here and reused later. We deliberately do **not** build a generic event/SLA engine up front (YAGNI); it is extracted when subsystem #2 proves the shared need.

### Operating model & key decisions (confirmed)

- **Ops model:** 24/7 live control room — realtime-first, riding the existing realtime foundation.
- **Lifecycle:** Full TMC lifecycle with timestamped, attributed stage transitions and SLA timing.
- **Location model:** Route → chainage (integer meters) → direction → lane, GPS optional.
- **v1 data source:** Manual operator entry only. (Field mobile, hotline, automated device feeds = later phases.)
- **Roles:** Operator, Shift Supervisor, Manager/Admin, Read-only Viewer.
- **Record integrity:** Append-only journal + full audit (immutable; corrections are amendments).
- **Shift model:** Operators ARE existing employees; a dedicated control-room duty/handover log is added.
- **Outputs (all v1):** Live ops dashboard, shift handover report, SLA & analytics, searchable incident register.
- **Access control:** **Roles only** — no Spatie permission strings (permission usage is being removed app-wide).

---

## 2. Architecture & Module Layout

A new **`Monitoring`** domain, mirroring the existing `HRM` conventions so it feels native:

- **Models:** `app/Models/Monitoring/*`
- **Controllers:** `app/Http/Controllers/Monitoring/*`
- **Pages:** `resources/js/Pages/Monitoring/*` (Inertia v2 / React 18 / React Query v5)
- **Policies:** `app/Policies/Monitoring/*`, gating every action on **role membership** (`$user->hasRole(...)`)
- **Tenancy:** all queries scoped via `stancl/tenancy`
- **Realtime:** reuses the RTDB ID-only signal pattern + React Query invalidation, with **React Query / Inertia polling as the working fallback** until Firebase is provisioned (v1 runs today, upgrades silently later)
- **PDF/Excel:** `barryvdh/laravel-dompdf` (handover report) and `maatwebsite/excel` (register/analytics export) — both already installed

**Installed-version constraints (verified against the repo):** Laravel 11 (`bootstrap/app.php`, no `Kernel.php`), Inertia v2 / `@inertiajs/react` ^2, React **18** (no `use()`), `@tanstack/react-query` ^5 (object-form hooks), zod ^4, Tailwind v3, PHP ^8.2 (enums/readonly OK), `spatie/laravel-permission` ^6 (roles only), `kreait/laravel-firebase` (RTDB over REST — gRPC unavailable on host).

---

## 3. Data Model

Chainage is stored as **integer meters** (`chainage_m`) — sortable, exact — and displayed as `KM 42+500`.

### Foundation — Topology (admin-configured)

- **`monitoring_routes`** — `id, code, name, total_length_m, is_active, timestamps`. Tenant-scoped.
- **`monitoring_sections`** — `id, route_id, code, name, from_chainage_m, to_chainage_m, sort_order, timestamps`. Segments between interchanges, for analytics grouping; an incident's `section_id` is derived from its chainage.
- **`monitoring_route_directions`** — `id, route_id, code, label, timestamps` (e.g. "Toward Dhaka" / "Toward Chittagong"). Avoids hard-coded N/S.
- **`monitoring_lanes`** — `id, route_id (nullable = global), code, label, kind (carriageway|shoulder|ramp), sort_order, timestamps`.

### Foundation — Control-room duty

- **`monitoring_duty_sessions`** — `id, user_id (existing employee), shift_label, started_at, ended_at (null = on duty), handover_to_user_id (nullable), handover_notes (nullable), handover_snapshot (json, nullable), timestamps`. "Who's on shift" + the immutable handover record.

### Incidents (core)

- **`monitoring_incidents`** — `id, reference_no (INC-YYYYMMDD-####, unique), route_id, section_id (derived, nullable), chainage_m, direction_id, lane_id (nullable), lat (nullable), lng (nullable), incident_type_id, severity, status (current stage), title, description, reported_via ('operator'), reported_by_user_id, duty_session_id, detected_at, verified_at, dispatched_at, on_scene_at, cleared_at, recovered_at, closed_at, updated_at, timestamps`.
  - The stage timestamps are a **denormalized cache** written inside the same DB transaction as the transition. **Source of truth = the transitions table.**
- **`monitoring_incident_status_transitions`** *(append-only)* — `id, incident_id, from_status (nullable for initial), to_status, occurred_at, performed_by_user_id, note (nullable), created_at`. The defensible SLA/audit trail.
- **`monitoring_incident_journal_entries`** *(append-only)* — `id, incident_id, entry_type (note|action|dispatch|communication|amendment|system), body, performed_by_user_id, occurred_at, amends_entry_id (nullable), created_at`. **No update/delete**; corrections add a new entry linked via `amends_entry_id`.
- **`monitoring_incident_dispatches`** — `id, incident_id, resource_type (patrol|ambulance|recovery|police|fire|other), identifier (e.g. vehicle no), dispatched_at, arrived_at (nullable), cleared_at (nullable), performed_by_user_id, notes, timestamps`. Lightweight response logging (no full fleet management in v1).

### Config

- **`monitoring_incident_types`** — `id, code, name, color, icon, default_severity, sla_response_minutes (nullable), sla_clearance_minutes (nullable), is_active, timestamps`. SLA targets live here.

### Indexing / performance

Indexes: `incidents(status)`, `incidents(route_id, chainage_m)`, `incidents(detected_at)`, `incidents.reference_no` (unique); `status_transitions(incident_id, occurred_at)`; `journal_entries(incident_id, occurred_at)`. Eager-load relations to avoid N+1; paginate all lists server-side.

---

## 4. Lifecycle Rules & SLA Mechanics

### State machine (`IncidentStatus` PHP enum)

```
Detected → Verified → Dispatched → OnScene → Cleared → Recovered → Closed
```

Plus two terminal states reachable via the shortcuts below: **`Closed`** (normal completion / false alarm) and **`Cancelled`**. The enum therefore has 9 cases: the 7 progressive stages above plus `Cancelled` (and `Closed`, already listed).

- **Forward** transitions follow the chain.
- **Allowed shortcuts** (sane defaults): `Detected → Closed` (false alarm); any open stage → `Cancelled` (mandatory reason). No other skipping.
- **Backward correction** allowed only for Shift Supervisor / Manager, recorded as a transition with a mandatory note (never a silent rewrite).
- An **`IncidentLifecycle` service** is the single source of truth for "is this transition legal." Each transition: validates server-side → writes one immutable `status_transitions` row → updates cached stage timestamp + `status` → optionally writes a journal note — all in **one DB transaction**. Illegal transitions fail **422**.

### SLA

- Targets from `incident_type` (`sla_response_minutes`, `sla_clearance_minutes`); null = no SLA for that type.
- **Response time** = `detected_at → on_scene_at`. **Clearance time** = `detected_at → cleared_at`. (Documented so reports are unambiguous.)
- Open incidents: elapsed computed live (`now − detected_at`) vs target → **On-track / At-risk (≥80%) / Breached**. Closed incidents freeze the final actual.
- SLA state is **derived, never stored as truth** (no drift). Dashboard shows ticking timers + breach highlight; analytics aggregate actuals from the transition records.

### Reference number

Concurrency-safe per-day sequence (DB transaction / atomic counter), format `INC-YYYYMMDD-####`, tenant-scoped.

---

## 5. Realtime & Polling Fallback

- **Signal emission (server):** on incident create, every status transition, every journal entry, and dispatch changes, the controller synchronously writes an **ID-only** signal to RTDB via the existing `kreait` pattern: `{ entity: 'incident', id, action: 'created'|'status_changed'|'journal_added'|'dispatch_changed', actor_id, ts }`. **No PII** ever crosses Firebase; clients refetch the row from the authenticated Laravel API.
- **Subscription (client):** `useRealtimeSignals('incident', onSignal)` → `queryClient.invalidateQueries` for the **narrow** affected key, with a subtle Radix highlight on the changed card. Same-device echoes short-circuited via `actor_id`.
- **Fallback (works today):** while Firebase is BLOCKED on provisioning, the live dashboard and open-incident detail use React Query `refetchInterval` polling (≈7s dashboard / ≈10s detail). A single capability flag — derived from whether Firebase config is present — switches polling **off** automatically when realtime signals are live (no double-fetch, zero code change at cutover).
- **Optimistic + concurrency:** incident edits use `useOptimisticMutation` + `expected_updated_at` → **HTTP 409** reconcile (no silent clobber). Lifecycle transitions are server-authoritative (optimistic show, visible rollback on rejection).

---

## 6. Role-Based Access Control

**Roles only**, checked via `$user->hasRole(...)` in Policies/controllers/UI. Four seeded, tenant-scoped roles.

| Capability | Operator | Shift Supervisor | Manager/Admin | Viewer |
|---|---|---|---|---|
| View incidents / dashboard / reports | ✓ | ✓ | ✓ | ✓ |
| Create / update / journal / dispatch | ✓ | ✓ | ✓ | — |
| Transition lifecycle (forward) | ✓ | ✓ | ✓ | — |
| Close incident | — | ✓ | ✓ | — |
| Backward correction / override / reassign | — | ✓ | ✓ | — |
| Duty: open/close own | ✓ | ✓ | ✓ | — |
| Duty: override others / force handover | — | ✓ | ✓ | — |
| Config (topology, lanes, types, SLA) | — | — | ✓ | — |

**Enforcement discipline:**

- Every action gated in a **Policy** (`IncidentPolicy`, `DutySessionPolicy`, `MonitoringConfigPolicy`) checking **role membership** — server-side, never trusting the client. UI hides what the role can't do; controllers re-check.
- All queries **tenancy-scoped**.
- **Form Request** validation on every write (types, enum membership, chainage within route bounds, legal transition).
- A seeder creates the four roles so a fresh tenant has a working control room.

---

## 7. Outputs

**1. Live ops dashboard** (`Pages/Monitoring/Dashboard`) — control-room wall-board. Active incidents as cards grouped by **status lane**, filterable by section/severity/type, each showing chainage, type, severity, and a **live SLA timer** (green/amber/red). Side panel: who's on duty now. Realtime via signals, polling fallback.

**2. Shift handover report** (DutySession close flow) — on duty-session end, generate a snapshot: incidents **opened / closed / still-ongoing** that shift, outstanding actions (open dispatches, at-risk/breached SLAs), and handover notes. Stored as immutable `handover_snapshot` (json); rendered to **PDF via dompdf**. Incoming operator opens their shift acknowledging it.

**3. Incident SLA & analytics** (`Pages/Monitoring/Analytics`) — avg/percentile **response & clearance times**, **SLA compliance %**, counts by type / section / direction / time-of-day, trend over a date range. Aggregated from immutable transitions. **Excel export** via maatwebsite/excel.

**4. Searchable incident register** (`Pages/Monitoring/Incidents`) — paginated, server-side filterable (date range, status, type, severity, section, chainage range, reference no); each row drills into the **full immutable journal + transition timeline + dispatches**. Excel export of the filtered set.

All four read-scoped by role (Viewer = read-only, no config) and tenancy-scoped.

---

## 8. Testing Strategy

**Backend (PHPUnit/Pest, sqlite):**

- Reference-no generation: unique & concurrency-safe, tenant-scoped.
- Lifecycle service: legal transitions succeed; illegal fail 422; backward correction requires Supervisor+ role and a note.
- Append-only invariants: journal & transition rows can't be edited/deleted; a correction creates a new `amends_entry_id`-linked entry.
- SLA computation: response/clearance actuals; on-track/at-risk/breached; null-SLA types.
- Role enforcement: each policy action allowed/denied per role (Operator can't close; Viewer can't write; only Manager configs).
- Tenancy scoping: tenant A can't read/write tenant B's incidents.
- Handover snapshot contains the correct opened/closed/ongoing sets.
- Signal emission fires on create/transition/journal/dispatch with ID-only payload (no PII).

**Frontend (vitest):** dashboard grouping & SLA-timer state; optimistic + 409 conflict handling; polling↔realtime switch flag.

**E2E (Playwright):** operator logs incident → advances lifecycle → supervisor closes → appears in register; two-tab realtime/polling propagation.

**Dev DB:** migrations run on MySQL `dbedc_guardian` (not just sqlite) so live pages don't 500.

---

## 9. Out of Scope (later phases)

- The other three subsystems (Toll/Revenue, Patrol/Maintenance, Assets/ITS).
- Non-operator incident sources: field-crew mobile reporting, public/call-center hotline, automated device feeds.
- Map/GPS UI (lat/lng are optional capture fields only — no map view).
- Full fleet/responder management (dispatch stays lightweight logging).
- Extracting a generic event/SLA engine (revisited when subsystem #2 lands).
