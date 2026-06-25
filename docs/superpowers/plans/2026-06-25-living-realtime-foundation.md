# Living / Realtime ESS — Foundation (Plan 0) + Roadmap

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the ERP feel *living* — every create/update/delete applies instantly (optimistic), reconciles safely under concurrent edits, and propagates to every other user/tab within ~1s — starting with a measured baseline and a safe concurrency guard on the roster pilot.

**Architecture:** React Query stays the client source-of-truth (the existing `useOptimisticMutation` already does cancel→snapshot→patch→rollback→revalidate). We add (a) an empirical baseline, (b) optimistic-concurrency guarding via `updated_at` + HTTP 409, and (c) Firestore connectivity verification — the prerequisites every later plan depends on. Realtime push (Firestore `onSnapshot`), helper hardening (409 reconcile + retry + animation), and dead-layer cleanup follow in Plans 1–3.

**Tech Stack:** Laravel 11 · Inertia v2 / @inertiajs/react ^2 · React 18 · @tanstack/react-query ^5 · @radix-ui/themes · firebase ^11 (modular SDK) · kreait/laravel-firebase · PHPUnit/Pest (sqlite) · vitest ^3 · Playwright ^1.53.

## Global Constraints

- **Versions are fixed:** React 18 (no `use()`), React Query v5 object-form hooks, zod v4, Tailwind v3, Inertia v2. Verify against installed versions; never write v19/v4-Tailwind/zod3/Inertia1 idioms.
- **Server is always authoritative.** Optimistic UI may show a pending state instantly, but a server rejection MUST visibly roll back. This applies even to money/approvals (user-confirmed: optimistic everywhere, but self-correcting).
- **No PII in Firebase.** Firestore signals carry identifiers + action + actor id + timestamp ONLY. Never names, pay, or attendance detail. Clients refetch the row from the authenticated Laravel API.
- **Online-only.** No offline mutation queue. Offline mutations fail with a clear toast.
- **Dev workflow:** `npm run dev`, test at `https://aero-enterprise-suite.test`. NEVER `npm run build` (it auto-commits/pushes). New migrations must be run on the MySQL `dbedc_guardian` dev DB (`php artisan migrate`), not just sqlite.
- **Conflict policy:** optimistic-concurrency via `updated_at`; stale write → HTTP 409; client reconciles to server truth (no last-write-wins, no silent loss).
- **Pilot scope:** roster cell + attendance punch first. Do not touch other domains in this plan.

---

## Task 1: Establish the empirical baseline

**Files:**
- Create: `docs/superpowers/plans/baseline-2026-06-25.md` (results table)

**Interfaces:**
- Produces: a recorded baseline (payload bytes + wall-clock ms) for the roster-cell update and the punch, referenced by later "before/after" claims.

This is a measurement task (not TDD). The point is that all future "it's faster/lighter now" claims are evidence-backed, using tooling that already exists in the repo.

- [ ] **Step 1: Confirm the dev server is up**

Run: `curl -k -s -o /dev/null -w "%{http_code}" https://aero-enterprise-suite.test`
Expected: `200` (or `302` redirect to login). If connection refused, start it: `npm run dev` in a background terminal, then retry.

- [ ] **Step 2: Capture the roster-cell update network cost**

Drive the app with Playwright (MCP browser tools): log in, open Attendance → Roster, open one cell's popover, pick a shift. In the network panel record, for the `PUT /attendance/roster/cell` request AND any follow-up `GET /attendance/roster` it triggers: request+response byte sizes and total time. Record under "Roster cell — BEFORE".

- [ ] **Step 3: Capture the punch network cost**

On the dashboard, trigger a punch (`POST /attendance/punch`). Record the punch request/response plus EVERY query that refetches afterward (today, present-users, absent-users, locations-today, daily-timesheet, my-monthly-stats — six keys per [useAttendanceQuery.js:117-122](resources/js/api/queries/useAttendanceQuery.js#L117-L122)): count them, sum their bytes and the time until the UI settles. Record under "Punch — BEFORE".

- [ ] **Step 4: Write the baseline file**

Create `docs/superpowers/plans/baseline-2026-06-25.md` with a table:

```markdown
# Reactivity Baseline — 2026-06-25

| Interaction | Requests fired | Total bytes | Time to UI settle (ms) | Notes |
|-------------|----------------|-------------|------------------------|-------|
| Roster cell update | <n> | <bytes> | <ms> | optimistic patch already in place |
| Punch | <n> | <bytes> | <ms> | 6-key invalidation, no optimism |
```

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/plans/baseline-2026-06-25.md
git commit -m "docs(perf): capture reactivity baseline for roster + punch"
```

---

## Task 2: Expose `updated_at` per roster cell (server)

**Files:**
- Modify: `app/Http/Controllers/HRM/RosterController.php:55-70` (`formatRoster`)
- Test: `tests/Feature/HRM/RosterPayloadTest.php`

**Interfaces:**
- Consumes: existing `RosterDay` model with `shift`, `user` relations.
- Produces: each cell in the `/attendance/roster` payload gains `updated_at` (ISO 8601 string). Shape per cell becomes `{ code, color, off, updated_at }`. Task 3 (409 guard) and the client reconcile rely on this field.

- [ ] **Step 1: Confirm `roster_days` has timestamps**

Run: `php artisan tinker --execute="echo Schema::hasColumn('roster_days','updated_at') ? 'yes' : 'no';"`
Expected: `yes`. (If `no`, add a migration `$table->timestamps();` to `roster_days`, run `php artisan migrate`, and re-run before proceeding.)

- [ ] **Step 2: Write the failing test**

```php
<?php
// tests/Feature/HRM/RosterPayloadTest.php
namespace Tests\Feature\HRM;

use App\Models\HRM\RosterDay;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RosterPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_roster_cell_payload_includes_updated_at(): void
    {
        $user = User::factory()->create();
        $cell = RosterDay::create([
            'user_id' => $user->id,
            'date' => '2026-06-20',
            'shift_id' => null,
            'source' => 'manual',
            'locked' => true,
        ]);

        $response = $this->actingAs($user)->getJson(
            '/attendance/roster?from=2026-06-01&to=2026-06-30'
        );

        $response->assertOk();
        $cellPayload = $response->json("roster.{$user->id}.days.2026-06-20");
        $this->assertArrayHasKey('updated_at', $cellPayload);
        $this->assertSame($cell->updated_at->toIso8601String(), $cellPayload['updated_at']);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --filter=test_roster_cell_payload_includes_updated_at`
Expected: FAIL — `Failed asserting that an array has the key 'updated_at'`.

- [ ] **Step 4: Add `updated_at` to the formatted cell**

In `app/Http/Controllers/HRM/RosterController.php`, change the `days` mapping inside `formatRoster`:

```php
'days' => $userRows->keyBy(fn ($row) => $row->date->format('Y-m-d'))
    ->map(fn ($row) => [
        'code' => $row->shift?->code,
        'color' => $row->shift?->color,
        'off' => $row->shift_id === null,
        'updated_at' => $row->updated_at?->toIso8601String(),
    ]),
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=test_roster_cell_payload_includes_updated_at`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/HRM/RosterController.php tests/Feature/HRM/RosterPayloadTest.php
git commit -m "feat(roster): expose updated_at per cell for optimistic-concurrency guarding"
```

---

## Task 3: Optimistic-concurrency guard on `updateCell` (server, HTTP 409)

**Files:**
- Modify: `app/Http/Controllers/HRM/RosterController.php:86-101` (`updateCell`)
- Test: `tests/Feature/HRM/RosterConcurrencyTest.php`

**Interfaces:**
- Consumes: `updated_at` from Task 2.
- Produces: `PUT /attendance/roster/cell` now accepts optional `expected_updated_at` (ISO 8601). If present and it does NOT match the stored cell's `updated_at`, the server returns HTTP **409** with `{ message, cell }` (cell = current server truth, loaded with shift). On match (or when the cell doesn't yet exist), it writes as before and returns 200 with the fresh cell incl. `updated_at`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/HRM/RosterConcurrencyTest.php
namespace Tests\Feature\HRM;

use App\Models\HRM\RosterDay;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RosterConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_expected_updated_at_is_rejected_with_409(): void
    {
        $user = User::factory()->create();
        $cell = RosterDay::create([
            'user_id' => $user->id, 'date' => '2026-06-20',
            'shift_id' => null, 'source' => 'manual', 'locked' => true,
        ]);

        $response = $this->actingAs($user)->putJson('/attendance/roster/cell', [
            'user_id' => $user->id,
            'date' => '2026-06-20',
            'shift_id' => null,
            'expected_updated_at' => '2000-01-01T00:00:00+00:00', // stale
        ]);

        $response->assertStatus(409);
        $response->assertJsonStructure(['message', 'cell' => ['updated_at']]);
    }

    public function test_fresh_expected_updated_at_succeeds(): void
    {
        $user = User::factory()->create();
        $cell = RosterDay::create([
            'user_id' => $user->id, 'date' => '2026-06-20',
            'shift_id' => null, 'source' => 'manual', 'locked' => true,
        ]);

        $response = $this->actingAs($user)->putJson('/attendance/roster/cell', [
            'user_id' => $user->id,
            'date' => '2026-06-20',
            'shift_id' => null,
            'expected_updated_at' => $cell->updated_at->toIso8601String(),
        ]);

        $response->assertOk();
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=RosterConcurrencyTest`
Expected: FAIL — `test_stale...` returns 200 instead of 409.

- [ ] **Step 3: Implement the guard in `updateCell`**

Replace the body of `updateCell` in `app/Http/Controllers/HRM/RosterController.php`:

```php
public function updateCell(Request $request): JsonResponse
{
    $data = $request->validate([
        'user_id' => 'required|integer|exists:users,id',
        'date' => 'required|date',
        'shift_id' => 'nullable|integer|exists:shifts,id',
        'note' => 'nullable|string|max:255',
        'expected_updated_at' => 'nullable|date',
    ]);

    $existing = RosterDay::where('user_id', $data['user_id'])
        ->whereDate('date', $data['date'])
        ->first();

    if (
        $existing
        && ! empty($data['expected_updated_at'])
        && $existing->updated_at->toIso8601String() !== \Carbon\Carbon::parse($data['expected_updated_at'])->toIso8601String()
    ) {
        return response()->json([
            'message' => 'This cell was changed by someone else. Showing the latest version.',
            'cell' => $existing->load('shift'),
        ], 409);
    }

    $cell = RosterDay::updateOrCreate(
        ['user_id' => $data['user_id'], 'date' => $data['date']],
        ['shift_id' => $data['shift_id'] ?? null, 'source' => 'manual', 'locked' => true, 'note' => $data['note'] ?? null],
    );

    return response()->json(['message' => 'Roster updated.', 'cell' => $cell->load('shift')]);
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=RosterConcurrencyTest`
Expected: PASS (both).

- [ ] **Step 5: Run on the MySQL dev DB**

If Step 1 required a new migration, run `php artisan migrate` (MySQL `dbedc_guardian`) now so the live page doesn't 500.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/HRM/RosterController.php tests/Feature/HRM/RosterConcurrencyTest.php
git commit -m "feat(roster): reject stale roster-cell writes with 409 (optimistic concurrency)"
```

---

## Task 4: Client sends `expected_updated_at` and reconciles on 409 (frontend)

**Files:**
- Modify: `resources/js/Pages/Attendance/RosterTab.jsx:95-128` (the `updateCell` mutation + `handlePick`)
- Test: `resources/js/Pages/Attendance/__tests__/RosterTab.conflict.test.jsx`

**Interfaces:**
- Consumes: 409 contract from Task 3; existing `useOptimisticMutation` (its `onError` already rolls the cache back to the snapshot, and `onSettled` invalidates the narrow key).
- Produces: when a cell write 409s, the optimistic patch reverts (existing behavior) AND a specific "changed by someone else" warning toast fires; the narrow-key invalidation pulls server truth.

- [ ] **Step 1: Write the failing test**

```jsx
// resources/js/Pages/Attendance/__tests__/RosterTab.conflict.test.jsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { extractErrorMessage } from '@/utils/toastUtils';

// Unit-test the 409 branch handler in isolation (extracted in Step 3).
import { handleCellConflict } from '../rosterCellConflict';

describe('roster cell 409 handling', () => {
  it('surfaces the server conflict message', () => {
    const showToast = { warning: vi.fn() };
    const err = { response: { status: 409, data: { message: 'changed by someone else' } } };
    handleCellConflict(err, showToast);
    expect(showToast.warning).toHaveBeenCalledWith('changed by someone else');
  });

  it('ignores non-409 errors (leaves them to the default error toast)', () => {
    const showToast = { warning: vi.fn() };
    const err = { response: { status: 500, data: {} } };
    expect(handleCellConflict(err, showToast)).toBe(false);
    expect(showToast.warning).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run resources/js/Pages/Attendance/__tests__/RosterTab.conflict.test.jsx`
Expected: FAIL — cannot resolve `../rosterCellConflict`.

- [ ] **Step 3: Create the conflict helper**

```js
// resources/js/Pages/Attendance/rosterCellConflict.js
import { extractErrorMessage } from '@/utils/toastUtils';

/**
 * Handle a 409 from the roster-cell write. Returns true if it was a conflict
 * (and a warning toast was shown), false otherwise so the caller can fall
 * back to its default error handling.
 */
export function handleCellConflict(err, showToast) {
  if (err?.response?.status !== 409) return false;
  showToast.warning(extractErrorMessage(err, 'This cell was changed by someone else.'));
  return true;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx vitest run resources/js/Pages/Attendance/__tests__/RosterTab.conflict.test.jsx`
Expected: PASS (both).

- [ ] **Step 5: Wire it into RosterTab**

In `resources/js/Pages/Attendance/RosterTab.jsx`: import the helper at the top, send `expected_updated_at`, and use the helper in `onError`.

```jsx
import { handleCellConflict } from './rosterCellConflict';
```

Change the `updateCell` mutation's `mutationFn` and `onError`:

```jsx
const updateCell = useOptimisticMutation({
    mutationFn: ({ userId, date, shiftId, expectedUpdatedAt }) => requestJson('put', '/attendance/roster/cell', {
        data: {
            user_id: Number(userId),
            date,
            shift_id: shiftId,
            expected_updated_at: expectedUpdatedAt ?? null,
        },
    }),
    queryKey: ['roster', from, to, selectedDepartmentId],
    updateFn: (oldData, { userId, date, shiftId }) => {
        // ...unchanged...
    },
    onError: (err) => {
        if (handleCellConflict(err, showToast)) return; // 409 → warning + auto-revert + revalidate
        showToast.error(err?.message || 'Failed to update roster cell.');
    },
});
```

And in `handlePick`, pass the cell's currently-known `updated_at` so the server can detect staleness:

```jsx
const handlePick = (shiftId) => {
    if (!selectedCell) return;
    setPopoverOpen(false);
    const { userId, date } = selectedCell;
    const expectedUpdatedAt = roster?.[userId]?.days?.[date]?.updated_at ?? null;
    updateCell.mutate({ userId, date, shiftId, expectedUpdatedAt });
    setSelectedCell(null);
};
```

- [ ] **Step 6: Manually verify in the running app**

With `npm run dev` running, open the roster in two browser tabs. In tab A change a cell; in tab B (stale) change the same cell — tab B should show the "changed by someone else" warning and snap to the latest value. (Cross-tab auto-update without the warning arrives in Plan 1.)

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/Attendance/RosterTab.jsx resources/js/Pages/Attendance/rosterCellConflict.js resources/js/Pages/Attendance/__tests__/RosterTab.conflict.test.jsx
git commit -m "feat(roster): send expected_updated_at and reconcile on 409 conflict"
```

---

## Task 5: Verify realtime DB connectivity on this host (prerequisite for Plan 1)

> **PIVOT (2026-06-25): use Firebase Realtime Database (RTDB), NOT Firestore.** Pre-flight found the `grpc` PHP extension is absent and not installable on Namecheap shared hosting; Firestore's PHP client requires gRPC, so it cannot work here. RTDB writes through `kreait` over REST/HTTPS (no gRPC) and is viable on shared hosting; the client uses `firebase/database` `onValue`/`onChildAdded` in place of `onSnapshot` — identical realtime UX. The command below should use `Firebase::database()->getReference('signals/ping')->set([...])` instead of `Firebase::firestore()`, and be named `firebase:ping`.
>
> **BLOCKED on provisioning (user only):** requires a Firebase service-account JSON at `storage/app/firebase-credentials.json`, `FIREBASE_DATABASE_URL` in `.env`, and the RTDB enabled in the Firebase console for project `aero-hr`. None exist in dev today. This task (and Plan 1) cannot run until provisioned. The Firestore-specific code below is superseded by the RTDB note above and will be rewritten just-in-time once unblocked.

**Files:**
- Create: `app/Console/Commands/FirestorePing.php`
- Test: `tests/Feature/Firestore/FirestorePingTest.php` (skipped unless credentials present)

**Interfaces:**
- Consumes: `kreait/laravel-firebase` config (`config/firebase.php`), service-account credentials.
- Produces: an `artisan firestore:ping` command proving the shared host can write a Firestore doc via `kreait` during a normal request (no daemon). This de-risks the entire realtime approach before Plan 1 builds on it.

- [ ] **Step 1: Confirm the kreait Firestore component is available**

Run: `composer show kreait/laravel-firebase | grep versions`
Expected: a version prints. Then confirm Firestore is enabled: open `config/firebase.php` and ensure a `credentials` path/JSON is configured for the default project. If the Firestore client class is missing, run `composer require google/cloud-firestore` (the gRPC/REST Firestore client kreait delegates to) and note it in the commit.

- [ ] **Step 2: Write the failing test**

```php
<?php
// tests/Feature/Firestore/FirestorePingTest.php
namespace Tests\Feature\Firestore;

use Tests\TestCase;

class FirestorePingTest extends TestCase
{
    public function test_firestore_ping_command_exists(): void
    {
        $this->artisan('list')->expectsOutputToContain('firestore:ping');
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --filter=FirestorePingTest`
Expected: FAIL — command not registered.

- [ ] **Step 4: Implement the command**

```php
<?php
// app/Console/Commands/FirestorePing.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kreait\Laravel\Firebase\Facades\Firebase;

class FirestorePing extends Command
{
    protected $signature = 'firestore:ping';
    protected $description = 'Write a test signal doc to Firestore to verify connectivity from this host.';

    public function handle(): int
    {
        try {
            $firestore = Firebase::firestore()->database();
            $ref = $firestore->collection('signals')->document('ping');
            $ref->set([
                'entity' => 'ping',
                'action' => 'test',
                'ts' => now()->toIso8601String(),
            ]);
            $this->info('Firestore write OK: signals/ping');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Firestore write FAILED: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=FirestorePingTest`
Expected: PASS.

- [ ] **Step 6: Run the real connectivity check**

Run: `php artisan firestore:ping`
Expected: `Firestore write OK: signals/ping`. If it fails, STOP and resolve credentials/SDK before Plan 1 — the realtime approach depends on this working from the host.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/FirestorePing.php tests/Feature/Firestore/FirestorePingTest.php composer.json composer.lock
git commit -m "feat(firestore): add firestore:ping connectivity check (Plan 1 prerequisite)"
```

---

## Self-Review (Plan 0)

- **Coverage:** baseline ✓ (T1), conflict policy server+client ✓ (T2–T4), Firestore prerequisite ✓ (T5). Realtime/animation/cleanup intentionally deferred to Plans 1–3.
- **Types consistent:** `updated_at` (ISO 8601 string) produced in T2, consumed in T3/T4; `expected_updated_at` request field consistent across T3 server validation and T4 client payload; `handleCellConflict(err, showToast)` signature matches its test and call-site.
- **No placeholders:** every code/test step contains runnable content.

---

# Roadmap — Plans 1–3 (written just-in-time before each executes)

These are intentionally NOT fully task-decomposed yet: each builds on what the previous plan teaches (real Firestore latency, baseline numbers, helper ergonomics). Each will be expanded into a full bite-sized TDD plan immediately before execution.

### Plan 1 — Realtime backbone (the headline "living" feature)
> **Transport = Firebase Realtime Database (RTDB), not Firestore** (gRPC unavailable on host — see Task 5 pivot). Server writes ID-only signals via `kreait` `Firebase::database()` (REST); client subscribes via `firebase/database` `onChildAdded`/`onValue`. BLOCKED until Firebase provisioning is done.
- **Server:** `FirestoreSignal` service (wraps `kreait`) writing ID-only docs `{ entity, id, action, actor_id, ts }` to a `signals` collection; call it synchronously (`ShouldBroadcastNow`-style, no queue daemon) from `RosterController::updateCell`, `generate`, and the attendance punch/correct/mark-present paths.
- **Client:** `useRealtimeSignals(entity, onSignal)` hook using Firestore `onSnapshot`; on a signal whose `actor_id !== currentUserId`, call `queryClient.invalidateQueries` for the narrow affected key (targeted refetch — no PII over the wire). Subtle Radix highlight on the changed row.
- **Cross-tab:** the same `onSnapshot` runs per tab, so multi-tab updates come for free; additionally short-circuit same-device echoes via `actor_id`.
- **Outcome:** a colleague's roster/attendance change appears on your open screen in ~0.5–1.5s with no refresh.

### Plan 2 — Optimistic engine upgrade + standardization
- Upgrade `useOptimisticMutation`: built-in 409 reconcile (snap to `error.response.data.cell`), a `retry()` returned for the rollback toast, and an `onRollback` hook to drive an exit/enter animation.
- Animated revert + toast + **retry** UX (Radix/CSS transitions only — no new dep).
- Convert `usePunchAttendance`, `useUpdateTimeCorrection`, `useMarkAsPresent` from raw `useMutation` + 6-key invalidation to the upgraded helper (instant apply; realtime/Plan 1 handles cross-user reconciliation).

### Plan 3 — Cross-tab persistence, config & dead-layer cleanup
- React Query config: re-enable `refetchOnWindowFocus`, lower global `staleTime` (realtime now keeps data fresh), add `broadcastQueryClient` for instant same-device multi-tab cache sync, and clear the cache on logout (user-scoping).
- Remove dead/conflicting deps: `@inertiajs/inertia@^0.11`, `@inertiajs/progress`, `laravel-precognition-react` (installed, unused). Verify Zustand remains only for UI/toast state, not server state.
- Re-run Task 1's measurements → record "AFTER" numbers next to the baseline.
