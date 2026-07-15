# cPanel Deploy: Queue Worker, Scheduler & Token TTL

Status: **active** · Host: `erp.dhakabypass.com` (shared cPanel) · Last updated: 2026-07-16
Related: `docs/session-expiry-policy.md`

This backend runs `QUEUE_CONNECTION=database` and defines scheduled tasks in
`routes/console.php`, but on shared cPanel **nothing runs either of them unless you
install two cron jobs**. Without the scheduler cron, every `Schedule::command(...)`
in `routes/console.php` is dead. Without a queue-worker cron, every `ShouldQueue`
job piles up unprocessed in the `jobs` table forever.

There is **no Supervisor** on this plan (and we are intentionally not installing one).
Both moving parts run as ordinary cPanel cron entries.

---

## 1. What must run

| Piece | What it does | Cadence |
|---|---|---|
| **Scheduler** (`schedule:run`) | Ticks Laravel's scheduler; fires the due jobs defined in `routes/console.php` | every minute |
| **Queue worker** (`queue:work`) | Drains `ShouldQueue` jobs from the `jobs` (database) table | every minute, short-lived |

### Currently DEFINED & scheduled (in `routes/console.php`) — these need the scheduler cron

| Command | Schedule |
|---|---|
| `attendance:reminders` | daily 22:17 |
| `attendance:auto-punch-out` | hourly |
| `biometric:process-scheduled-commands` | every minute |
| `biometric:scheduled-log-download` | every 4 hours |
| `model:prune` (NotificationLog, 30-day) | daily |
| `leave:grant-annual` | yearly 00:05 |
| `leave:carry-forward` | yearly 00:10 |
| `leave:accrue` | monthly on the 1st, 00:15 |
| `leave:expire-carried` | daily |
| `leave:grant-comp-off` | daily 01:00 |
| `leave:reconcile-ledger` | daily |
| `test:scheduler` | every minute (**local only**, guarded by `app.env === 'local'`) |

### DEFINED but NOT scheduled — verify intent before wiring (left as-is on purpose)

- `send:punchout-reminder` (`app/Console/Commands/SendPunchOutReminder.php`) — a
  punch-out reminder notification. `attendance:reminders` + `attendance:auto-punch-out`
  already cover reminders and closing forgotten punches, so adding this risks
  double-notifying. **Not wired** — confirm the owner wants it (and at what time)
  before adding `Schedule::command('send:punchout-reminder')`.
- `logs:cleanup` (`app/Console/Commands/CleanupLogs.php`) — a log-pruning/deletion
  command. Safe candidate for a `->daily()` schedule, but it deletes data, so wire it
  only after confirming its retention flags. **Not wired.**

### Jobs that require the QUEUE WORKER cron (`implements ShouldQueue`)

| Job | Dispatched from |
|---|---|
| `ProcessBiometricDownloadSession` | `biometric:scheduled-log-download`, `BiometricWebhookController`, `BiometricDeviceController`, `TriggerLogDownloadOnReconnect` |
| `SendAttendanceReminder` | `attendance:reminders` (`SendAttendanceReminders` command) |
| `ExportAttendanceReport` | `AttendanceController` (Excel/PDF exports) |
| `ExportDailyWorkSummary` | `DailyWorkSummaryController` |
| `ExportPettyCashTransactions` | `PettyCashController` |
| `SyncUsersToDeviceJob` | device user-sync |

> Note the coupling: `biometric:scheduled-log-download` (scheduler) **dispatches**
> `ProcessBiometricDownloadSession` (worker). The scheduler enqueues; the worker
> executes. **Both** cron jobs are required for biometric log download to work
> end-to-end.

---

## 2. The two cron lines

Add these in **cPanel → Cron Jobs**. First resolve two values on the server:

```bash
# SSH into the account, then:
cd ~ && ls -d */public_html 2>/dev/null   # find the app root (contains artisan)
which php                                  # or use the cPanel "Setup PHP" selector binary
php -v                                     # must be >= 8.2 (composer requires ^8.2)
```

Set `APP_PATH` = the directory that contains `artisan` (e.g.
`/home/dhakabyp/erp.dhakabypass.com` — **confirm with `pwd` in that folder**), and
`PHP_BIN` = the correct PHP CLI. On cPanel/CloudLinux the selectable-PHP binary is
usually `/usr/local/bin/ea-php83` (or `ea-php82`) — the bare `php` in cron may be an
older default, so **prefer the explicit `ea-phpNN` path** that matches the domain's
PHP version.

### 2a. Scheduler — every minute

```cron
* * * * * cd /home/dhakabyp/erp.dhakabypass.com && /usr/local/bin/ea-php83 artisan schedule:run >> /dev/null 2>&1
```

This is the canonical Laravel scheduler tick. It exits immediately after dispatching
whatever is due, so running it every minute is cheap. `withoutOverlapping()` on the
long tasks (already set in `routes/console.php`) prevents pile-ups.

### 2b. Queue worker — every minute, self-terminating (recommended for shared cPanel)

```cron
* * * * * cd /home/dhakabyp/erp.dhakabypass.com && flock -n /tmp/dbedc-queue.lock /usr/local/bin/ea-php83 artisan queue:work --stop-when-empty --max-time=55 --sleep=3 --tries=3 >> storage/logs/queue-worker.log 2>&1
```

- `--stop-when-empty` — process all pending jobs, then exit (does not idle-spin).
- `--max-time=55` — hard-stop before the next minute's cron fires, so runs never stack.
- `flock -n /tmp/dbedc-queue.lock` — if the previous run is still going, skip this
  tick instead of starting a second overlapping worker. (If `flock` is unavailable on
  the host, drop it — `--max-time=55` already bounds each run to under a minute.)
- `--tries=3` — retry a failing job up to 3 times before it lands in `failed_jobs`.
- `--sleep=3` — only relevant if not `--stop-when-empty`; harmless here.

> **After deploying code that changes a queued job class, restart workers** so they
> pick up new code: `php artisan queue:restart`. With the `--stop-when-empty` cron
> above, workers are already short-lived (≤55s), so a `queue:restart` (or just waiting
> one minute) is enough — no stale long-running process to kill.

### 2c. Alternative — one long-running worker (only if the host permits persistent processes)

Most shared cPanel plans kill long-running CLI processes, so **2b is the safe default.**
If (and only if) this host explicitly allows a persistent process, a single supervised
long-runner is more responsive:

```cron
# keep exactly one long worker alive; relaunch if it died. Still bounded by --max-time.
* * * * * cd /home/dhakabyp/erp.dhakabypass.com && flock -n /tmp/dbedc-queue.lock /usr/local/bin/ea-php83 artisan queue:work --max-time=3600 --sleep=1 --tries=3 >> storage/logs/queue-worker.log 2>&1
```

`--max-time=3600` recycles the process hourly (frees leaked memory); `flock` ensures
only one is ever running. Do **not** run this in addition to 2b — pick one.

---

## 3. Post-deploy cache step (important)

After editing `.env` on the server, the config cache must be rebuilt or your changes
(including `SANCTUM_EXPIRATION`) do nothing:

```bash
php artisan optimize:clear
php artisan config:cache
```

⚠️ **Do NOT run `php artisan route:cache`** (nor bare `php artisan optimize`, which
includes it): `routes/api.php` has closure routes and route caching fails on closures.
Cache **config and views only**. (Same rule as `docs/session-expiry-policy.md`.)

---

## 4. Verify it's actually running

```bash
# Scheduler: list what the scheduler would run and when
php artisan schedule:list

# Fire the due tasks once by hand (what the cron does each minute)
php artisan schedule:run

# Queue: drain once by hand and watch it work
php artisan queue:work --stop-when-empty --max-time=30 -v

# Are jobs accumulating unprocessed? (should trend toward 0 with the worker running)
php artisan tinker --execute="echo DB::table('jobs')->count();"   # pending
php artisan queue:failed                                           # anything dead-lettered

# Confirm the crons exist at the OS level
crontab -l
```

Healthy signs: `jobs` table count stays low / returns to 0, per-task logs under
`storage/logs/` (e.g. `attendance-reminders.log`, `biometric-log-download.log`,
`queue-worker.log`) get fresh timestamps, and `failed_jobs` is empty.

---

## 5. Token TTL change shipped alongside this doc

`config/sanctum.php` `expiration` was `null` (tokens with an active/scripted
keep-alive **never** expired — AUDIT-02 finding 2.3). It is now:

```php
'expiration' => (int) env('SANCTUM_EXPIRATION', 43200),   // 43200 min = 30 days
```

**Combined semantics (verified against `laravel/sanctum` v4.3.2
`Guard::isValidAccessToken`):** the global `expiration` (absolute, from the token's
`created_at`) and each token's own `expires_at` are **ANDed** — both must pass:

```php
(! $this->expiration || $accessToken->created_at->gt(now()->subMinutes($this->expiration)))
    && (! $accessToken->expires_at || ! $accessToken->expires_at->isPast())
```

So the existing **8h sliding idle window is unchanged**: `SlideTokenExpiration` still
sets each token's `expires_at = now + session.lifetime` on every authenticated request
and that value still slides. The new global value only adds a **hard 30-day cap from
issuance** on top. Net effective lifetime = `min(8h idle, 30 days absolute)`.

- `SANCTUM_EXPIRATION=43200` → 30-day absolute backstop (default).
- `SANCTUM_EXPIRATION=0` (or empty) → cap disabled, old never-expire behaviour.
- Existing tokens issued before this deploy have `expires_at` already set by the
  sliding middleware; the 30-day cap now also applies from their `created_at`. No
  forced mass-logout — clients handle `401` → re-login (see session-expiry-policy.md).

> **`docs/session-expiry-policy.md` currently states `sanctum.expiration` stays
> `null`.** That line now needs a one-line reconciliation to reflect the 30-day
> absolute backstop. Left unedited here (out of this change's edit scope) — flagged
> for the owner.

### Deferred: refresh-token rotation

Short-lived access tokens + refresh-token rotation (AUDIT-02 findings 2.1 / 2.3) are
**NOT** implemented in this change. The mobile client still hard-signs-out on any
`401` with no silent re-auth. Adding a `/api/v1/auth/refresh` endpoint with rotating
refresh tokens (and dropping `['*']` abilities for scoped tokens) is a larger,
client-coordinated change — **deferred**, tracked in AUDIT-02.
