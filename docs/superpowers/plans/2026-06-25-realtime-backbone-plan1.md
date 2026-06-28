# Plan 1 — Realtime Backbone (RTDB), Roster Pilot

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** When any user changes a roster cell, every *other* user viewing that month sees it update on their screen within ~1s — no refresh — by having the server publish an ID-only change-signal to Firebase Realtime Database and the client refetch just the affected query.

**Architecture:** Laravel writes a tiny "last-change marker" to RTDB (`signals/{ns}/{entity}/{bucket} = {ts, actor_id, action}`) synchronously after a successful write, via `kreait` over REST (no gRPC — validated working). The browser authenticates to Firebase with a Laravel-minted **custom token**, subscribes to the relevant marker with `onValue`, and on a change whose `actor_id` is not its own user calls React Query's narrow `invalidateQueries` to refetch only that resource. No PII ever leaves Laravel — markers carry only ids/timestamps; the actual data is refetched from the authenticated API.

**Tech Stack:** Laravel 11 · kreait/laravel-firebase 7.1 (`Firebase::database()`, `Firebase::auth()`) · React 18 · @tanstack/react-query v5 · firebase ^11 modular SDK (`firebase/database`, `firebase/auth`) · PHPUnit (sqlite) · vitest ^3 · Mockery.

## Decisions (chosen for 10/10; alternatives noted)
- **Client read auth = Laravel-minted Firebase custom token + RTDB rules `".read": "auth !== null"`, `".write": false`.** Server writes via Admin SDK (bypasses rules). *Alternative rejected:* anonymous/public read (would expose the activity stream) or full per-tenant rule trees (deferred — see namespace).
- **Signal model = bounded last-change marker per bucket** (not an append-only event log). No unbounded growth, no pruning cron needed. `onValue` on the marker; client refetches the narrow query on change. *Alternative rejected:* push event list (needs pruning; shared hosting has cron but a marker is simpler and sufficient).
- **Namespace `ns`** = `config('realtime.namespace')`, default `env('FIREBASE_PROJECT_ID','app')`. Keeps multiple deployments/tenants from cross-talking in one Firebase project. Tenant-per-path can be layered later by making `ns` tenant-aware.
- **Fail-open:** a realtime publish failure (RTDB down, creds missing, disabled) must NEVER fail or slow the user's write — `touch()` is wrapped in try/catch + `report()` and is a no-op when `config('realtime.enabled')` is false.
- **Roster bucket = `YYYY-MM`** (the cell's month). `updateCell` touches the cell's month; `generate` touches every month in its range. The client subscribes to the month currently displayed.

## Global Constraints
- Server authoritative; markers are ID-only — NEVER names/pay/attendance detail in RTDB.
- Online-only; no offline queue.
- Work in the worktree `C:\laragon\www\Aero-Enterprise-Suite\.claude\worktrees\realtime-foundation` (own real vendor); NEVER `npm run build`; new migrations also run on MySQL dev DB.
- `storage/app/firebase-credentials.json` and `*firebase-adminsdk*` are gitignored — never commit keys.
- Pilot scope = roster only. Attendance + other domains are Plan 2.
- Firebase env already set & validated: `FIREBASE_PROJECT_ID=dbedc-erp`, `FIREBASE_DATABASE_URL=https://dbedc-erp-default-rtdb.asia-southeast1.firebasedatabase.app`.

---

## Task 1: Realtime config + `RealtimeSignal` service (fail-open publisher)

**Files:**
- Create: `config/realtime.php`
- Create: `app/Services/Realtime/RealtimeSignal.php`
- Test: `tests/Feature/Realtime/RealtimeSignalTest.php`

**Interfaces:**
- Produces: `RealtimeSignal::touch(string $entity, string $bucket, ?int $actorId, string $action = 'update'): void` — writes `signals/{ns}/{entity}/{bucket}` = `['ts'=><iso>, 'actor_id'=>$actorId, 'action'=>$action]`. No-op when `config('realtime.enabled')` is false; never throws.
- Consumes: `Kreait\Firebase\Contract\Database` (resolved lazily from the container; bound by kreait's provider).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Realtime/RealtimeSignalTest.php
namespace Tests\Feature\Realtime;

use App\Services\Realtime\RealtimeSignal;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Database\Reference;
use Mockery;
use Tests\TestCase;

class RealtimeSignalTest extends TestCase
{
    public function test_touch_writes_marker_to_namespaced_path(): void
    {
        config(['realtime.enabled' => true, 'realtime.namespace' => 'testns']);

        $reference = Mockery::mock(Reference::class);
        $reference->shouldReceive('set')->once()->with(Mockery::on(function ($payload) {
            return $payload['actor_id'] === 7
                && $payload['action'] === 'update'
                && isset($payload['ts']);
        }));

        $db = Mockery::mock(Database::class);
        $db->shouldReceive('getReference')->once()
            ->with('signals/testns/roster/2026-06')
            ->andReturn($reference);
        $this->app->instance(Database::class, $db);

        app(RealtimeSignal::class)->touch('roster', '2026-06', 7);
    }

    public function test_touch_is_noop_when_disabled(): void
    {
        config(['realtime.enabled' => false]);
        // No Database bound; if touch() tried to resolve/use it this would error.
        app(RealtimeSignal::class)->touch('roster', '2026-06', 7);
        $this->assertTrue(true);
    }

    public function test_touch_never_throws_when_publish_fails(): void
    {
        config(['realtime.enabled' => true, 'realtime.namespace' => 'testns']);
        $db = Mockery::mock(Database::class);
        $db->shouldReceive('getReference')->andThrow(new \RuntimeException('rtdb down'));
        $this->app->instance(Database::class, $db);

        app(RealtimeSignal::class)->touch('roster', '2026-06', 7); // must not throw
        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=RealtimeSignalTest`
Expected: FAIL — `RealtimeSignal` / `config/realtime.php` don't exist.

- [ ] **Step 3: Create the config**

```php
<?php
// config/realtime.php
return [
    'enabled' => env('REALTIME_ENABLED', true),
    'namespace' => env('REALTIME_NAMESPACE', env('FIREBASE_PROJECT_ID', 'app')),
];
```

- [ ] **Step 4: Create the service**

```php
<?php
// app/Services/Realtime/RealtimeSignal.php
namespace App\Services\Realtime;

use Kreait\Firebase\Contract\Database;

class RealtimeSignal
{
    /**
     * Publish a bounded "last-change" marker for a resource bucket.
     * ID-only: never include names/PII. Fail-open: never throws, never blocks the caller.
     */
    public function touch(string $entity, string $bucket, ?int $actorId, string $action = 'update'): void
    {
        if (! config('realtime.enabled')) {
            return;
        }

        try {
            $ns = config('realtime.namespace');
            /** @var Database $db */
            $db = app(Database::class);
            $db->getReference("signals/{$ns}/{$entity}/{$bucket}")->set([
                'ts' => now()->toIso8601String(),
                'actor_id' => $actorId,
                'action' => $action,
            ]);
        } catch (\Throwable $e) {
            report($e); // log and swallow — realtime must not break the write path
        }
    }
}
```

- [ ] **Step 5: Run to verify pass**

Run: `php artisan test --filter=RealtimeSignalTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add config/realtime.php app/Services/Realtime/RealtimeSignal.php tests/Feature/Realtime/RealtimeSignalTest.php
git commit -m "feat(realtime): fail-open RealtimeSignal publisher + config"
```

---

## Task 2: Publish roster signals from write paths

**Files:**
- Modify: `app/Http/Controllers/HRM/RosterController.php` (`updateCell`, `generate`)
- Test: `tests/Feature/Realtime/RosterSignalTest.php`

**Interfaces:**
- Consumes: `RealtimeSignal::touch`.
- Produces: after a successful `updateCell`, calls `touch('roster', <YYYY-MM of date>, <auth id>)`; after `generate`, calls `touch('roster', <month>, <auth id>)` for each distinct month in `[from,to]`.

- [ ] **Step 1: Write the failing test** (mock the service, assert it's invoked)

```php
<?php
// tests/Feature/Realtime/RosterSignalTest.php
namespace Tests\Feature\Realtime;

use App\Models\User;
use App\Services\Realtime\RealtimeSignal;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RosterSignalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'attendance.settings', 'guard_name' => 'web']);
    }

    public function test_update_cell_publishes_roster_signal_for_month(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('attendance.settings');

        $mock = Mockery::mock(RealtimeSignal::class);
        $mock->shouldReceive('touch')->once()->with('roster', '2026-06', $user->id, 'update');
        $this->app->instance(RealtimeSignal::class, $mock);

        $this->actingAs($user)->putJson('/attendance/roster/cell', [
            'user_id' => $user->id, 'date' => '2026-06-20', 'shift_id' => null,
        ])->assertOk();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=RosterSignalTest`
Expected: FAIL — `touch` never called (mock expectation unmet).

- [ ] **Step 3: Inject + call the service in `RosterController`**

Add to the constructor (it already injects `RosterService`):
```php
use App\Services\Realtime\RealtimeSignal;
// ...
public function __construct(
    private readonly RosterService $roster,
    private readonly RealtimeSignal $signals,
) {}
```

At the end of `updateCell`, before the `return`:
```php
$this->signals->touch('roster', substr($data['date'], 0, 7), $request->user()?->id);
```

At the end of `generate`, before the `return` (touch each distinct month in range):
```php
$months = [];
$cursor = \Carbon\Carbon::parse($data['from'])->startOfMonth();
$end = \Carbon\Carbon::parse($data['to'])->startOfMonth();
while ($cursor->lessThanOrEqualTo($end)) {
    $months[] = $cursor->format('Y-m');
    $cursor->addMonth();
}
foreach ($months as $month) {
    $this->signals->touch('roster', $month, $request->user()?->id);
}
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --filter=RosterSignalTest`
Expected: PASS. Also re-run `php artisan test --filter=RosterConcurrencyTest` to confirm no regression (still 3 passing).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/HRM/RosterController.php tests/Feature/Realtime/RosterSignalTest.php
git commit -m "feat(realtime): publish roster change-signals on cell update and generate"
```

---

## Task 3: Firebase custom-token endpoint (client read auth)

**Files:**
- Create: `app/Http/Controllers/FirebaseTokenController.php`
- Modify: `routes/web.php` (add an authenticated route)
- Test: `tests/Feature/Realtime/FirebaseTokenTest.php`

**Interfaces:**
- Produces: `GET /firebase/token` (auth required) → `{ token: <firebase custom token string> }`. Guests get 401.
- Consumes: `Kreait\Firebase\Contract\Auth::createCustomToken`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Realtime/FirebaseTokenTest.php
namespace Tests\Feature\Realtime;

use App\Models\User;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FirebaseTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_get_token(): void
    {
        $this->getJson('/firebase/token')->assertUnauthorized();
    }

    public function test_authenticated_user_gets_a_token(): void
    {
        $user = User::factory()->create();

        $tokenObj = Mockery::mock(\Lcobucci\JWT\Token::class);
        $tokenObj->shouldReceive('toString')->andReturn('fake.custom.token');

        $auth = Mockery::mock(Auth::class);
        $auth->shouldReceive('createCustomToken')->once()
            ->with((string) $user->id, Mockery::type('array'))
            ->andReturn($tokenObj);
        $this->app->instance(Auth::class, $auth);

        $this->actingAs($user)->getJson('/firebase/token')
            ->assertOk()
            ->assertJson(['token' => 'fake.custom.token']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=FirebaseTokenTest`
Expected: FAIL — route/controller missing (404/500).

- [ ] **Step 3: Create the controller**

```php
<?php
// app/Http/Controllers/FirebaseTokenController.php
namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Auth;

class FirebaseTokenController extends Controller
{
    public function __invoke(Request $request, Auth $auth): JsonResponse
    {
        $user = $request->user();
        $token = $auth->createCustomToken((string) $user->id, ['uid_int' => $user->id]);

        return response()->json(['token' => $token->toString()]);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/web.php`, inside the authenticated middleware group (find the existing `Route::middleware(['auth'...])->group(...)`; match the project's existing group), add:
```php
Route::get('/firebase/token', \App\Http\Controllers\FirebaseTokenController::class)->name('firebase.token');
```
(If the project keeps API-style JSON routes elsewhere, match that location; the test calls `/firebase/token` so the path must be exactly that and behind `auth`.)

- [ ] **Step 5: Run to verify pass**

Run: `php artisan test --filter=FirebaseTokenTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/FirebaseTokenController.php routes/web.php tests/Feature/Realtime/FirebaseTokenTest.php
git commit -m "feat(realtime): authenticated Firebase custom-token endpoint"
```

---

## Task 4: RTDB security rules artifact

**Files:**
- Create: `database.rules.json`

**Interfaces:** documentation/artifact only — no automated test.

- [ ] **Step 1: Create the rules file**

```json
{
  "rules": {
    "signals": {
      ".read": "auth !== null",
      ".write": false
    }
  }
}
```

- [ ] **Step 2: Document the deploy step**

These rules must be applied in the Firebase console (Realtime Database → Rules → paste → Publish) OR via `firebase deploy --only database` if the Firebase CLI is configured. The Admin SDK (server) bypasses these rules, so signal writes keep working; only browser reads are gated to authenticated Firebase sessions.

- [ ] **Step 3: Commit**

```bash
git add database.rules.json
git commit -m "feat(realtime): RTDB security rules (authenticated reads, no client writes)"
```

---

## Task 5: Client Firebase RTDB + Auth bootstrap

**Files:**
- Modify: `resources/js/firebase-config.js` (add database + auth exports)
- Create: `resources/js/api/realtimeClient.js` (token fetch + sign-in + database accessor)

**Interfaces:**
- Produces: `getRealtimeDb(): Promise<Database>` — ensures the user is signed in to Firebase (via Laravel custom token) and returns the RTDB instance. Idempotent (signs in once).
- Consumes: `GET /firebase/token`; `VITE_FIREBASE_DATABASE_URL`.

- [ ] **Step 1: Extend `firebase-config.js`**

Add the database URL to the config object and export db/auth getters (keep the existing FCM exports unchanged):
```js
// add to firebaseConfig object:
databaseURL: import.meta.env.VITE_FIREBASE_DATABASE_URL,
```
At the bottom of the file:
```js
import { getDatabase } from 'firebase/database';
import { getAuth } from 'firebase/auth';

export const realtimeDb = getDatabase(app);
export const firebaseAuth = getAuth(app);
```

- [ ] **Step 2: Create the realtime client**

```js
// resources/js/api/realtimeClient.js
import { signInWithCustomToken } from 'firebase/auth';
import { realtimeDb, firebaseAuth } from '@/firebase-config.js';
import { requestJson } from '@/api/client';

let signInPromise = null;

/** Ensure the browser is signed in to Firebase (via a Laravel-minted custom token), once. */
function ensureSignedIn() {
  if (firebaseAuth.currentUser) return Promise.resolve();
  if (!signInPromise) {
    signInPromise = requestJson('get', '/firebase/token')
      .then((res) => signInWithCustomToken(firebaseAuth, res.token))
      .catch((err) => { signInPromise = null; throw err; });
  }
  return signInPromise;
}

/** Resolve the RTDB instance after ensuring auth. */
export async function getRealtimeDb() {
  await ensureSignedIn();
  return realtimeDb;
}
```

NOTE: confirm `requestJson('get', '/firebase/token')` returns the parsed body `{ token }` (match the project's `requestJson` contract — it may unwrap differently; adjust the `.token` access to the real shape).

- [ ] **Step 3: Commit** (no unit test — this is integration glue verified live in Task 7)

```bash
git add resources/js/firebase-config.js resources/js/api/realtimeClient.js
git commit -m "feat(realtime): client RTDB + custom-token Firebase sign-in"
```

---

## Task 6: `useRealtimeSignals` hook

**Files:**
- Create: `resources/js/api/useRealtimeSignals.js`
- Test: `resources/js/api/__tests__/useRealtimeSignals.test.jsx`

**Interfaces:**
- Produces: `useRealtimeSignals({ path, selfActorId, onSignal })` — subscribes via `onValue` to `signals/{ns}/{path}`; when a marker arrives whose `actor_id !== selfActorId`, calls `onSignal(marker)`. Unsubscribes on unmount / path change. `ns` comes from a Vite env `VITE_REALTIME_NAMESPACE` (default must equal the server's `realtime.namespace`).
- The hook must tolerate the realtime client failing to connect (no throw; just no events).

- [ ] **Step 1: Write the failing test** (mock `firebase/database` + the realtime client)

```jsx
// resources/js/api/__tests__/useRealtimeSignals.test.jsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';

const onValueMock = vi.fn();
const refMock = vi.fn((db, path) => ({ __path: path }));
vi.mock('firebase/database', () => ({
  ref: (...a) => refMock(...a),
  onValue: (...a) => onValueMock(...a),
}));
vi.mock('@/api/realtimeClient', () => ({
  getRealtimeDb: () => Promise.resolve({ __db: true }),
}));

import { useRealtimeSignals } from '../useRealtimeSignals';

describe('useRealtimeSignals', () => {
  beforeEach(() => { onValueMock.mockReset(); refMock.mockReset(); });

  it('calls onSignal for a foreign actor and ignores own actor', async () => {
    const onSignal = vi.fn();
    let cb;
    onValueMock.mockImplementation((_ref, callback) => { cb = callback; return () => {}; });

    renderHook(() => useRealtimeSignals({ path: 'roster/2026-06', selfActorId: 7, onSignal }));
    await waitFor(() => expect(onValueMock).toHaveBeenCalled());

    // foreign actor → fires
    cb({ val: () => ({ ts: 't1', actor_id: 9, action: 'update' }) });
    expect(onSignal).toHaveBeenCalledTimes(1);

    // own actor → ignored
    cb({ val: () => ({ ts: 't2', actor_id: 7, action: 'update' }) });
    expect(onSignal).toHaveBeenCalledTimes(1);

    // null snapshot → ignored
    cb({ val: () => null });
    expect(onSignal).toHaveBeenCalledTimes(1);
  });
});
```

(If `@testing-library/react` is not installed, the implementer should confirm via `npm ls @testing-library/react`; if absent, write the hook to expose a pure internal handler `makeSignalHandler({ selfActorId, onSignal })` and unit-test THAT directly without rendering — same assertions, no new dependency. Prefer the no-new-dependency path.)

- [ ] **Step 2: Run to verify it fails**

Run: `npx vitest run resources/js/api/__tests__/useRealtimeSignals.test.jsx`
Expected: FAIL — hook module missing.

- [ ] **Step 3: Create the hook**

```js
// resources/js/api/useRealtimeSignals.js
import { useEffect } from 'react';
import { ref, onValue } from 'firebase/database';
import { getRealtimeDb } from '@/api/realtimeClient';

const NS = import.meta.env.VITE_REALTIME_NAMESPACE || import.meta.env.VITE_FIREBASE_PROJECT_ID || 'app';

/** Pure handler — testable without React. Returns the onValue callback. */
export function makeSignalHandler({ selfActorId, onSignal }) {
  return (snapshot) => {
    const marker = snapshot?.val?.() ?? null;
    if (!marker) return;
    if (marker.actor_id === selfActorId) return; // ignore my own change
    onSignal(marker);
  };
}

export function useRealtimeSignals({ path, selfActorId, onSignal }) {
  useEffect(() => {
    if (!path) return undefined;
    let unsub = () => {};
    let cancelled = false;

    getRealtimeDb()
      .then((db) => {
        if (cancelled) return;
        const r = ref(db, `signals/${NS}/${path}`);
        unsub = onValue(r, makeSignalHandler({ selfActorId, onSignal }));
      })
      .catch(() => { /* realtime unavailable — degrade silently */ });

    return () => { cancelled = true; unsub(); };
  }, [path, selfActorId, onSignal]);
}
```

- [ ] **Step 4: Run to verify pass**

Run: `npx vitest run resources/js/api/__tests__/useRealtimeSignals.test.jsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/api/useRealtimeSignals.js resources/js/api/__tests__/useRealtimeSignals.test.jsx
git commit -m "feat(realtime): useRealtimeSignals hook (onValue → onSignal, self-actor filtered)"
```

---

## Task 7: Wire realtime into RosterTab (the payoff)

**Files:**
- Modify: `resources/js/Pages/Attendance/RosterTab.jsx`
- Test: manual two-tab acceptance (documented)

**Interfaces:**
- Consumes: `useRealtimeSignals`, the current user id (from `usePage().props.auth.user.id` — confirm the prop path), the displayed month, and `qc.invalidateQueries`.
- Produces: when another user changes a roster cell in the displayed month, the grid refetches `['roster', from, to, selectedDepartmentId]` automatically (~1s), no refresh.

- [ ] **Step 1: Add the subscription**

Near the top of the component, derive the current user id and the displayed month, and subscribe. `month` is already a prop (`YYYY-MM`). Add imports:
```jsx
import { useRealtimeSignals } from '@/api/useRealtimeSignals';
```
Inside the component (after `const qc = useQueryClient();`):
```jsx
const authUserId = usePage().props?.auth?.user?.id ?? null;

useRealtimeSignals({
    path: `roster/${month}`,
    selfActorId: authUserId,
    onSignal: () => {
        qc.invalidateQueries({ queryKey: ['roster', from, to, selectedDepartmentId] });
    },
});
```
(Confirm `usePage().props.auth.user.id` is the real shape in this app; if auth user is exposed differently, use that. `month`, `from`, `to`, `selectedDepartmentId`, `qc` are already in scope.)

- [ ] **Step 2: Verify the build/test still green**

Run: `npx vitest run resources/js/Pages/Attendance/__tests__/RosterTab.conflict.test.jsx`
Expected: PASS (unchanged). Confirm no import/syntax errors via `npx vitest run` on the file set you touched.

- [ ] **Step 3: Manual acceptance (document in report; requires the live app + RTDB rules published)**

1. Publish the Task 4 rules in the Firebase console.
2. `npm run dev`; log in as two different users in two browsers (or one normal + one incognito), both on Attendance → Roster, same month.
3. In browser A, change a cell. Within ~1s, browser B's grid should refetch and show the new value WITHOUT a manual refresh, and browser A should NOT double-refetch (self-actor filtered).

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Attendance/RosterTab.jsx
git commit -m "feat(realtime): RosterTab subscribes to roster signals → live cross-user updates"
```

---

## Self-Review (Plan 1)
- **Coverage:** publish (T1–T2), client auth (T3), rules (T4), client transport (T5), subscription hook (T6), pilot wiring (T7). Attendance + other domains deferred to Plan 2 by design.
- **Types consistent:** marker shape `{ts, actor_id, action}` produced in T1, consumed in T6/T7; `touch(entity,bucket,actorId,action)` signature consistent T1↔T2; path `signals/{ns}/{entity}/{bucket}` consistent server (T1) and client (`signals/{NS}/${path}`, T6) — **NS must match** (`REALTIME_NAMESPACE`/`FIREBASE_PROJECT_ID` server-side vs `VITE_REALTIME_NAMESPACE`/`VITE_FIREBASE_PROJECT_ID` client-side: set both to `dbedc-erp`).
- **Fail-open verified:** server `touch` swallows errors (T1); client hook degrades silently (T6).
- **Env to add:** `VITE_FIREBASE_DATABASE_URL` and `VITE_REALTIME_NAMESPACE=dbedc-erp` (client); `REALTIME_NAMESPACE=dbedc-erp` optional server (defaults to FIREBASE_PROJECT_ID).
- **No placeholders:** every code/test step has runnable content; the two "confirm the real shape" notes (requestJson body, auth prop path) are explicit verification steps, not missing code.

## ⚠️ Cross-cutting note for the implementer
`NS` parity is the #1 failure mode: if the server writes `signals/dbedc-erp/...` but the client subscribes to `signals/app/...`, nothing fires and it looks "broken" with no error. Set the namespace env on both sides before the Task 7 manual check.
