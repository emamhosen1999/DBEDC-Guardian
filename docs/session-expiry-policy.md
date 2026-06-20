# Session & Token Expiry Policy (Web + Mobile)

Status: **active** · Owner: Platform/Auth · Last updated: 2026-06-20

This document is the single source of truth for how authentication sessions (web)
and bearer tokens (mobile) expire. It exists because web and mobile previously
expired under *different, competing* rules, producing the "session expired at
unpredictable times" reports.

---

## TL;DR — the one policy

| Property | Value | Applies to |
|---|---|---|
| Idle timeout | **8 hours** (`SESSION_LIFETIME=480` minutes) | Web **and** mobile |
| Timeout type | **Sliding/idle** (renewed on each authenticated request) | Web **and** mobile |
| Logout on browser close | **No** (`SESSION_EXPIRE_ON_CLOSE=false`) | Web |
| Absolute (from-login) cap | **None** (`sanctum.expiration = null`) | Mobile |
| Cookie `SameSite` | **lax** | Web |
| Cookie `Secure` | **true in production** (HTTPS) | Web |
| Session store | `database`, shared across all app servers | Web |
| Mobile auth | Sanctum **bearer token** (not cookies) | Mobile |

`SESSION_LIFETIME` is THE knob. Change it once and both channels move together.

---

## Root cause (why it was inconsistent)

Mobile authenticates with **Sanctum personal-access (bearer) tokens**, not cookies
(`AuthController@login` → `createToken(...)`; `routes/api.php` v1 group uses
`auth:sanctum`; the client sends a native `android`/`ios` device signature). It uses
**no session cookie**, so `SESSION_*`, `SameSite`, and `expire_on_close` never
affected it.

Web authenticates with the **session/cookie** guard, which had **four** independent
expiry triggers, while mobile tokens had **zero**:

| # | Mechanism | Fired on | Effect |
|---|---|---|---|
| 1 | Native Laravel idle lifetime (DB session driver) | Web | Logout after `SESSION_LIFETIME` idle |
| 2 | `expire_on_close = true` (old default) | Web | Logout on browser/tab close — independent of lifetime |
| 3 | `CheckSessionExpiry` custom middleware | Web | A **second**, redundant idle window using a separate `last_activity` payload key; force-logout + 419 |
| 4 | `DeviceAuthMiddleware` | Web (single-device users) | Logout if `X-Device-ID` missing or device mismatch |
| — | `sanctum.expiration = null` | Mobile | Token **never** expired |

Plus a reporting conflation: a **419 CSRF** token mismatch is rendered as
"Your session has expired", and the web client redirects to `/login` on **any 401 or
419** (`resources/js/app.jsx`). So CSRF failures looked identical to real expiry.

**Net divergence:** web was bounded by `min(idle, browser-close, redundant idle,
device-check)`; mobile was unbounded. Hence "web drops while mobile stays logged in,"
plus spurious mid-use web logouts from triggers 2–4 and 419.

> Eliminated hypothesis: "`SameSite=strict` breaks the mobile webview." Mobile is a
> native bearer-token client and sends no cookie, so `SameSite` is web-only. `strict`
> was still wrong for **web** (drops the cookie on cross-site top-level navigations),
> so we moved web to `lax`.

---

## What changed

1. **One idle window, both channels.** `config/session.php` default
   `lifetime = 480`. Mobile tokens are issued with `expires_at = now + lifetime`
   (`AuthController@login`) and **slid forward** on every authenticated request by
   `App\Http\Middleware\SlideTokenExpiration` (registered on the `api/v1` group).
   The Sanctum guard rejects a token whose `expires_at` is past → `401`.
2. **No browser-close logout.** `expire_on_close` default → `false`.
3. **Cookie `SameSite` → `lax`** (was `strict`).
4. **Removed the redundant timer.** `CheckSessionExpiry` middleware deleted and
   unregistered. Native session lifetime is the single web idle source of truth.
5. **`sanctum.expiration` stays `null`** on purpose — that global value is an
   *absolute* lifetime from token creation, which would force a re-login a fixed time
   after login regardless of activity. We use per-token sliding `expires_at` instead
   for true idle parity with web.
6. **Documented response contract.** An expired/absent web session (Inertia/XHR) and
   an expired mobile token both return **`401`** with `error_code:
   AUTHENTICATION_REQUIRED` and a `redirect` to login. The client handles both
   identically (→ re-auth).

`DeviceAuthMiddleware` / `ApiDeviceAuthMiddleware` are **unchanged** — they enforce
the opt-in single-device-login security feature (device mismatch → logout), which is
intentional and already parallel across web (session invalidate) and mobile (token
delete + 401). They are a device-binding control, not a timeout, and were left intact
so security is not weakened.

---

## Client handling (must stay true)

- **Web** (`resources/js/app.jsx`): axios response interceptor redirects to `/login`
  on `401`/`419`. Inertia uses the shared axios singleton, so navigations are covered.
- **Mobile**: on `401` (`AUTHENTICATION_REQUIRED` or `invalid_device`), drop the stored
  token and route the user to login. Re-login via `POST /api/v1/auth/login` issues a
  fresh sliding token.

---

## Production `.env`

```dotenv
# --- Idle-timeout policy (single source of truth) ---
SESSION_DRIVER=database          # keep DB-backed + shared across all app servers
SESSION_LIFETIME=480             # 8h idle, drives web sessions AND mobile tokens
SESSION_EXPIRE_ON_CLOSE=false    # do not log out on browser close
SESSION_SAME_SITE=lax            # not 'strict' (cross-site top-level nav keeps cookie)
SESSION_SECURE_COOKIE=true       # REQUIRED on HTTPS
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_DOMAIN=.example.com      # root domain covering web + any subdomain hosts
SANCTUM_STATEFUL_DOMAINS=app.example.com   # only the first-party WEB host(s); native
                                           # mobile uses bearer tokens, not stateful cookies
# SANCTUM token expiry is handled per-token (sliding), so do NOT set a global expiration.
```

Replace `example.com` / `app.example.com` with the real production hosts. **Please
paste the current production `SESSION_*` / `SANCTUM_*` values** so this block can be
diffed against what is live (e.g. confirm `SESSION_DOMAIN` and the mobile API host).

## Deploy notes

1. Set the `.env` values above on **every** app server (identical).
2. `php artisan config:clear && php artisan config:cache`.
3. `SESSION_DRIVER=database` — ensure the `sessions` table is on the **shared**
   DB (not per-server), and the load balancer need not use sticky sessions.
4. Changing `SESSION_LIFETIME` / `expire_on_close` / `SameSite` changes the session
   **cookie attributes**; existing sessions keep their old cookie until next login.
   No forced mass-logout is required, but expect users to re-auth once as old cookies
   age out.
5. Existing **mobile tokens** issued before this change have `expires_at = NULL` and
   will remain non-expiring until re-login. To enforce the policy on the existing
   fleet, either let them roll over naturally on next login, or run a one-off to set
   `expires_at` on current `personal_access_tokens` (optional; coordinate with a
   client release that handles `401` → re-login).

## Tests

- `tests/Feature/SessionTimeoutPolicyTest.php` — single-source-of-truth window,
  `expire_on_close=false`, `SameSite=lax`, `sanctum.expiration` null, and the shared
  `401`+redirect contract for an unauthenticated web request.
- `tests/Feature/Api/MobileTokenExpiryTest.php` — login issues a token whose expiry
  matches `session.lifetime`; an idle token past the window → `401`; activity within
  the window slides the expiry and keeps the token alive past the original window.

`phpunit.xml` pins `SESSION_LIFETIME=480` so the policy value is deterministic in CI
regardless of a developer's local `.env`.
