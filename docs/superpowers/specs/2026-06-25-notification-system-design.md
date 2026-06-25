# Notification System — Design

> **Status:** Approved (brainstorming). Next step: implementation plan via `writing-plans`.
> **Date:** 2026-06-25
> **Branch context:** depends on the in-progress `feat/living-realtime-foundation` RTDB backbone.

## Goal

Replace the current ad-hoc notification handling with one industry-standard,
admin-configurable notification engine that delivers across **four channels** —
in-app, web push, mobile push, and email — with a notification-type registry,
admin channel/recipient matrix, and per-user preferences.

## Why (current-state problems being replaced)

- In-app notifications are served as static **Inertia page props** → refresh only on
  navigation; no pagination, no mark-read API, no realtime.
- Device token is a **single `users.fcm_token` column** → one device per user; a second
  browser/phone overwrites the first.
- **Two competing push paths:** clean `FcmNotificationService` (kreait) **and** a legacy
  `NotificationController::sendPushNotification` hand-rolling OAuth via `Google_Client`
  with a hardcoded project id `dbedc-erp`.
- `laravel-notification-channels/webpush` installed and overlapping with FCM.
- No notification-type registry, no admin config, no per-user preferences.
- Mobile app (Expo SDK 54, EAS) has **no push library installed** — greenfield.

## Decisions (from brainstorming)

1. **Scope:** in-app + web push + mobile push + email, admin-configurable, per industry standard.
2. **Realtime delivery:** reuse the existing **Firebase RTDB signal backbone** (no daemon, works
   on Namecheap shared hosting — same reason the realtime plan chose RTDB over Firestore). The
   server writes an **ID-only** signal `signals/notif/{userId} = { ts }`; the open client refetches
   notification content from the authenticated Laravel API. **No PII in Firebase.**
3. **Push transport:** per-platform gateways behind one abstraction — **Web → FCM (kreait)**,
   **Mobile → Expo Push Service** (idiomatic for the EAS-managed Expo app; Expo manages FCM/APNs
   credentials). One `notification_tokens` table with `provider` + `platform`.
4. **Config depth:** DB-backed type registry → admin matrix (channels + recipient roles per type)
   → per-user preferences. **Effective channel = admin-allowed ∩ user-opted-in.**
5. **v1 triggers:** **Leave** and **Attendance** only. Tasks, Petty Cash, Admin/System alerts
   deferred (engine makes them a small add later).

## Architecture

```
Domain event (LeaveRequested, MissedPunch, …)
        │
        ▼
  Notification class  ──► via($notifiable) resolves EFFECTIVE channels
        │                  = admin_allowed(type) ∩ user_opted_in(type); database always on
        ├─ database  ──► notifications table (in-app center — canonical content/audit record)
        ├─ push      ──► PushChannel ─┬─ FcmGateway   (web tokens)    via kreait
        │                             └─ ExpoGateway  (mobile tokens) via Expo Push API
        ├─ mail      ──► queued, templated
        └─ realtime  ──► RTDB signal  signals/notif/{userId} = { ts }  (ID-only, no PII)
                                │
        Open SPA/app listener ──┘  → refetch GET /api/notifications + unread-count
```

All notifications are `ShouldQueue` so requests stay fast and a dead FCM/Expo token never blocks
the originating user action. The in-app DB record is canonical; push/realtime/email are delivery
surfaces only.

## Components & boundaries

### NotificationChannelResolver (pure, unit-testable)
- Input: `(typeKey, user, adminConfig, userPreferences)`.
- Output: ordered list of channels to deliver on.
- Rules: start from `notification_types.default_channels`; intersect with admin-allowed; intersect
  with user prefs **unless** admin locked a channel on; **`database` is always included** (users can
  tune push/email noise but never lose the in-app audit trail).
- No I/O — a deterministic function of its inputs.

### PushChannel + PushGateway interface
- `PushChannel` (custom Laravel notification channel) calls `notification->toPush($notifiable)` once,
  then for each of the user's `notification_tokens` dispatches to the matching gateway.
- `PushGateway` interface, two drivers:
  - `FcmGateway` — wraps existing kreait `FcmNotificationService` for `provider = fcm` (web) tokens.
  - `ExpoGateway` — POSTs to the Expo Push API for `provider = expo` (mobile) tokens.
- On a hard-invalid-token error, the gateway prunes that row from `notification_tokens`.

### Realtime signal writer
- After a DB notification is persisted, write `signals/notif/{userId} = { ts }` via kreait RTDB.
- Reuses the realtime-foundation backbone; degrades gracefully (no error surfaced to user) when RTDB
  is not yet provisioned — in-app list still refreshes on navigation/poll until then.

### In-app API (consumed by SPA + mobile)
- `GET /api/notifications` (paginated), `GET /api/notifications/unread-count`,
  `POST /api/notifications/{id}/read`, `POST /api/notifications/read-all`.
- Returns the standard `{ success, data }` envelope used by the mobile app.

### Admin & user UI (Inertia + Radix, matching current app)
- **Admin → Settings → Notifications:** matrix table (rows = types grouped by category; columns =
  In-app / Push / Email toggles + recipient-roles selector + active switch). Server-validated,
  policy-gated by a `notifications.settings` permission (spatie).
- **User → settings → Notification preferences:** per-category channel toggles; admin-locked-on
  channels render disabled.
- **In-app center:** upgrade the header bell from static page-props to a live React Query list,
  unread badge, mark-one / mark-all-read, paginated "View all" page; refetch driven by the RTDB
  listener (falls back to navigation/poll when RTDB unavailable).

## Data model

| Table | Purpose |
|---|---|
| `notifications` (Laravel default) | In-app records: `type`, `data` (json incl. `type_key`, title, body, url, icon), `read_at`. Already partly used by Leave. |
| `notification_tokens` *(new)* | Replaces `users.fcm_token`. Columns: `user_id`, `provider` (`fcm`\|`expo`), `token`, `platform` (`web`\|`android`\|`ios`), `last_used_at`, timestamps. Unique on `token`. Many devices per user. |
| `notification_types` *(new, seeded)* | Registry: `key` (e.g. `leave.requested`), `category`, `label`, `description`, `default_channels` (json), `recipient_roles` (json), `is_active`. Admin-editable. |
| `notification_preferences` *(new)* | Per-user overrides: `user_id`, `category` (or `type_key`), `channel`, `enabled`. Absent row = inherit type default. |

**Migration:** copy existing `users.fcm_token` values into `notification_tokens`
(`provider = fcm`, `platform = web`), then drop the `fcm_token` column.

## v1 trigger catalogue

**Leave** (fold in existing mail+database `LeaveApproval/Approved/Rejected` notifications):
- `leave.requested` → approver(s)
- `leave.approved` / `leave.rejected` / `leave.cancelled` → requesting employee

**Attendance** (fold in existing reminder commands `SendAttendanceReminder` / `SendPunchOutReminder`):
- `attendance.missed_punch_in` / `attendance.missed_punch_out` → employee
- `attendance.roster_changed` → affected employee
- `attendance.shift_swap_requested` → target/approver; `attendance.shift_swap_decided` → requester
- `attendance.time_correction_requested` → approver; `attendance.time_correction_decided` → employee

Each type is seeded into `notification_types` with sensible `default_channels` and `recipient_roles`.

## Cleanup folded into this work

- Delete legacy `NotificationController::sendPushNotification` + `getAccessToken`
  (`Google_Client` OAuth, hardcoded `dbedc-erp`) — fully replaced by the kreait `FcmGateway`.
- Consolidate token-registration routes onto the new `notification_tokens` model (web `web.php`
  `update-fcm-token`, api `/notification-token`, `/api/v1/.../notifications/token`).
- **Flag (not auto-remove):** `laravel-notification-channels/webpush` is redundant once FCM covers
  web push. Decide during implementation whether to remove it.

## Testing strategy (TDD)

- **Unit:** `NotificationChannelResolver` truth table (admin∩user, database-always-on, locked-on).
- **Feature:** each channel with **faked gateways** (no live FCM/Expo calls); in-app API
  (list/unread/read/read-all); admin matrix authorization (policy-gated); token register + prune
  on invalid token.
- **Frontend (vitest):** bell unread-count + mark-read; preferences toggle disabled-when-locked.
- Mobile push token registration verified against the `{ success, data }` API contract.

## Dependencies & graceful degradation

- **Depends on** the RTDB realtime backbone (currently blocked on Firebase service-account JSON +
  `FIREBASE_DATABASE_URL` + RTDB enabled for project `aero-hr`). In-app, push, and email all work
  **without** RTDB; only the *instant* in-app refresh needs it. The bell falls back to
  navigation/poll refresh until RTDB is provisioned, so this work is not blocked on provisioning.
- Mobile push requires adding `expo-notifications` to the Expo app and an EAS push-credential setup
  (separate mobile-repo task; the Laravel side is ready once tokens arrive).

## Out of scope (v1)

- Tasks, Petty Cash, and Admin/System (monitoring/request-log) notifications — added later on the
  same engine.
- Digests / quiet-hours / rate-limiting (future enhancement on the resolver).
- Offline mutation/notification queue (realtime plan is online-only).

## Version notes (verify at implementation time)

Laravel 11 native notifications + custom channel; kreait/laravel-firebase (installed) for FCM + RTDB;
Inertia v2 / @inertiajs/react ^2; React 18; @tanstack/react-query ^5; @radix-ui/themes; zod ^4;
spatie/laravel-permission ^6. Mobile: Expo SDK 54, `expo-notifications` (to be added), EAS.
Confirm exact APIs against installed versions and official docs before coding (per current-tech-stack).
