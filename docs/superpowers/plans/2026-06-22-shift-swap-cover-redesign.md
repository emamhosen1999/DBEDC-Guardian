# Shift Swap & Cover Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the broken, free-text shift-swap with the standard roster-driven model: a `cover` leg (a same-department coworker takes over a specific rostered shift) and a `swap` (two reciprocal legs), with correct roster rewrites, real eligibility/availability validation, and roster-driven pickers.

**Architecture:** Add a `type` enum to `shift_swap_requests`; make `counterparty_id` always required (the open/giveaway path is removed). `RosterService::applySwap` rewrites the roster correctly per type (cover = 2 cells, swap = 4 cells) using a new public `effectiveShiftId()` that reads the materialized roster. `ShiftSwapController::store` enforces same-department + free/working availability. Two new employee endpoints (`eligible`, `counterpartyRoster`) feed the rewritten roster-driven `SwapRequestForm`.

**Tech Stack:** Laravel 11, PHP 8.x, Carbon; React 18 + Radix Themes (Vite); PHPUnit class-style on sqlite `:memory:` + `RefreshDatabase`.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-06-22-shift-swap-cover-redesign-design.md`.
- New migration `add_type_to_shift_swap_requests` must be run on the **MySQL dev DB `dbedc_guardian`** (`php artisan migrate`) and on **prod** before this is live there (tests use sqlite and won't catch a missing column).
- Frontend is **source-only per task**; do ONE consolidated `npx vite build` at the end (Task 6). NEVER `npm run build` (its postbuild auto-commits/pushes).
- Availability is decided by the **materialized roster** via `RosterService::effectiveShiftId()` — NOT `resolveShift()` (which ignores `source=pattern` rows).
- Employee swap routes are gated on `attendance.own.view`; admin approve/reject on `attendance.settings`. Match existing gates.
- The only allowed pre-existing suite failures are `MobileSyncApiTest > sync push applies leave apply mutation` and `NavigationRoutesTest > any authenticated user can access organization directory`. Add no new failure.
- `department_id` is an enforced nullable FK on `users`; tests must create a `Department` (factory: `App\Models\HRM\Department::factory()`) and assign both parties to it.
- Web API client signature: `requestJson(method, url, { params | data })`.
- Run tests with `php artisan test --filter=<Class>`.

---

### Task 1: Add `type` to the swap model

**Files:**
- Create: `database/migrations/2026_06_22_000002_add_type_to_shift_swap_requests.php`
- Modify: `app/Models/HRM/ShiftSwapRequest.php`
- Test: `tests/Feature/Attendance/SwapTypeColumnTest.php` (create)

**Interfaces:**
- Produces: `shift_swap_requests.type` (`enum('swap','cover')`, default `'swap'`); `ShiftSwapRequest` mass-assignable `type`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Attendance/SwapTypeColumnTest.php`:

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SwapTypeColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_swap_request_persists_a_type(): void
    {
        $user = User::factory()->create();

        $swap = ShiftSwapRequest::create([
            'type' => 'cover',
            'requester_id' => $user->id,
            'requester_date' => '2026-07-01',
            'counterparty_id' => $user->id,
            'status' => 'pending',
        ]);

        $this->assertSame('cover', $swap->fresh()->type);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=SwapTypeColumnTest`
Expected: FAIL — no `type` column / not mass-assignable.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_06_22_000002_add_type_to_shift_swap_requests.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `type` distinguishes a one-sided COVER (a coworker takes over the requester's
 * shift; requester gets the day off) from a two-sided SWAP (trade two specific
 * rostered shifts). Defaults to 'swap' for any legacy rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            $table->enum('type', ['swap', 'cover'])->default('swap')->after('requester_date');
        });
    }

    public function down(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
```

- [ ] **Step 4: Add `type` to the model fillable**

In `app/Models/HRM/ShiftSwapRequest.php`, change the `$fillable` array's first line:

```php
    protected $fillable = [
        'requester_id', 'requester_date', 'counterparty_id', 'counterparty_date',
        'counterparty_status', 'requested_shift_id', 'reason', 'status', 'approval_chain', 'approved_by',
    ];
```

to:

```php
    protected $fillable = [
        'requester_id', 'requester_date', 'type', 'counterparty_id', 'counterparty_date',
        'counterparty_status', 'requested_shift_id', 'reason', 'status', 'approval_chain', 'approved_by',
    ];
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=SwapTypeColumnTest`
Expected: PASS.

- [ ] **Step 6: Apply the migration to the MySQL dev DB**

Run: `php artisan migrate`
Expected: `2026_06_22_000002_add_type_to_shift_swap_requests` runs (DB `dbedc_guardian`). Confirm with `php artisan migrate:status` if needed.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_06_22_000002_add_type_to_shift_swap_requests.php app/Models/HRM/ShiftSwapRequest.php tests/Feature/Attendance/SwapTypeColumnTest.php
git commit -m "feat(attendance): add type (swap|cover) to shift_swap_requests

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Correct the roster rewrite (`applySwap`) + `effectiveShiftId`

**Files:**
- Modify: `app/Services/Attendance/RosterService.php`
- Test: `tests/Feature/Attendance/RosterApplySwapTest.php` (rewrite expectations + add cover test)

**Interfaces:**
- Consumes: `ShiftSwapRequest.type` (Task 1).
- Produces: `public function effectiveShiftId(int $userId, string $date): ?int` (materialized-roster-aware shift lookup). `applySwap` now branches on `type`: cover → 2-cell rewrite, swap → 4-cell rewrite.

- [ ] **Step 1: Rewrite the test expectations**

Replace the body of `test_swap_rewrites_both_parties_roster` in `tests/Feature/Attendance/RosterApplySwapTest.php` with the corrected 4-cell expectation, and add a cover test. Replace the whole `test_swap_rewrites_both_parties_roster` method with these two methods:

```php
    public function test_swap_trades_two_shifts_across_four_cells(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $shiftA = Shift::factory()->create(['code' => 'AAA']);
        $shiftB = Shift::factory()->create(['code' => 'BBB']);

        // A works 06-19; B works 06-20. Each is OFF on the other's date.
        RosterDay::create(['user_id' => $a->id, 'date' => '2026-06-19', 'shift_id' => $shiftA->id, 'source' => 'pattern']);
        RosterDay::create(['user_id' => $b->id, 'date' => '2026-06-20', 'shift_id' => $shiftB->id, 'source' => 'pattern']);

        $swap = ShiftSwapRequest::create([
            'type' => 'swap',
            'requester_id' => $a->id, 'requester_date' => '2026-06-19',
            'counterparty_id' => $b->id, 'counterparty_date' => '2026-06-20',
            'status' => 'approved',
        ]);

        app(RosterService::class)->applySwap($swap);

        $aOwn = RosterDay::where('user_id', $a->id)->whereDate('date', '2026-06-19')->first();
        $bCovers = RosterDay::where('user_id', $b->id)->whereDate('date', '2026-06-19')->first();
        $bOwn = RosterDay::where('user_id', $b->id)->whereDate('date', '2026-06-20')->first();
        $aTakes = RosterDay::where('user_id', $a->id)->whereDate('date', '2026-06-20')->first();

        // A is now OFF on their old day; B works it with A's shift.
        $this->assertNull($aOwn->shift_id);
        $this->assertSame('swap', $aOwn->source);
        $this->assertTrue((bool) $aOwn->locked);
        $this->assertSame($shiftA->id, $bCovers->shift_id);

        // B is now OFF on their old day; A works it with B's shift.
        $this->assertNull($bOwn->shift_id);
        $this->assertSame($shiftB->id, $aTakes->shift_id);
        $this->assertSame('swap', $aTakes->source);
    }

    public function test_cover_offloads_requester_shift_to_counterparty(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $shiftA = Shift::factory()->create(['code' => 'AAA']);

        // A works 06-19; B is free that day.
        RosterDay::create(['user_id' => $a->id, 'date' => '2026-06-19', 'shift_id' => $shiftA->id, 'source' => 'pattern']);

        $swap = ShiftSwapRequest::create([
            'type' => 'cover',
            'requester_id' => $a->id, 'requester_date' => '2026-06-19',
            'counterparty_id' => $b->id, 'counterparty_date' => null,
            'status' => 'approved',
        ]);

        app(RosterService::class)->applySwap($swap);

        $aOwn = RosterDay::where('user_id', $a->id)->whereDate('date', '2026-06-19')->first();
        $bCovers = RosterDay::where('user_id', $b->id)->whereDate('date', '2026-06-19')->first();

        $this->assertNull($aOwn->shift_id);          // requester off
        $this->assertSame('swap', $aOwn->source);
        $this->assertSame($shiftA->id, $bCovers->shift_id); // counterparty covers
        $this->assertTrue((bool) $bCovers->locked);
    }
```

(Leave `test_non_approved_swap_is_a_no_op` as-is — it still passes; `type` defaults to `swap`.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=RosterApplySwapTest`
Expected: the two new tests FAIL — the legacy `applySwap` exchanges shift IDs only at the two named cells (so `aOwn->shift_id` is `shiftB`, not null) and has no cover branch.

- [ ] **Step 3: Add `effectiveShiftId` and make the private resolver delegate to it**

In `app/Services/Attendance/RosterService.php`, replace the private `resolveScheduledShiftId` method:

```php
    /** Resolve the shift_id for a user on a date from existing roster_days (any source) or via assignment. */
    private function resolveScheduledShiftId(int $userId, string $date): ?int
    {
        $rosterDay = RosterDay::where('user_id', $userId)->whereDate('date', $date)->first();
        if ($rosterDay) {
            return $rosterDay->shift_id;
        }

        return $this->resolveShift($userId, \Carbon\Carbon::parse($date))?->id;
    }
```

with a public version (callers below use `effectiveShiftId`):

```php
    /**
     * The effective shift_id for a user on a date from the MATERIALIZED roster
     * (roster_days of ANY source) first, else the resolved assignment/pattern.
     * Unlike resolveShift(), this honors source=pattern rows — it is the truth
     * the employee sees and what swaps/covers operate on. null = off / no work.
     */
    public function effectiveShiftId(int $userId, string $date): ?int
    {
        $rosterDay = RosterDay::where('user_id', $userId)->whereDate('date', $date)->first();
        if ($rosterDay) {
            return $rosterDay->shift_id;
        }

        return $this->resolveShift($userId, \Carbon\Carbon::parse($date))?->id;
    }
```

- [ ] **Step 4: Rewrite `applySwap`**

In `app/Services/Attendance/RosterService.php`, replace the whole `applySwap` method with:

```php
    public function applySwap(ShiftSwapRequest $swap): void
    {
        if ($swap->status !== 'approved') {
            return;
        }

        DB::transaction(function () use ($swap) {
            $reqDate = $swap->requester_date->toDateString();
            $requesterShiftId = $this->effectiveShiftId($swap->requester_id, $reqDate);

            if ($swap->type === 'cover') {
                // Counterparty takes over the requester's shift; requester gets the day off.
                $this->writeSwapDay($swap->requester_id, $reqDate, null);
                $this->writeSwapDay($swap->counterparty_id, $reqDate, $requesterShiftId);

                return;
            }

            // swap: trade two specific rostered shifts (4-cell exchange).
            $cpDate = $swap->counterparty_date->toDateString();
            $counterpartyShiftId = $this->effectiveShiftId($swap->counterparty_id, $cpDate);

            $this->writeSwapDay($swap->requester_id, $reqDate, null);
            $this->writeSwapDay($swap->counterparty_id, $reqDate, $requesterShiftId);
            $this->writeSwapDay($swap->counterparty_id, $cpDate, null);
            $this->writeSwapDay($swap->requester_id, $cpDate, $counterpartyShiftId);
        });
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=RosterApplySwapTest`
Expected: PASS (all 3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/RosterService.php tests/Feature/Attendance/RosterApplySwapTest.php
git commit -m "fix(attendance): correct swap roster rewrite + add cover (effectiveShiftId)

A swap now does the 4-cell exchange (requester off their date & on the partner's;
partner mirrors) instead of swapping shift IDs in place, which never offloaded the
requester's day. Adds the one-sided cover (2-cell) branch on type, and a public
effectiveShiftId() that reads the materialized roster.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Store validation (department + availability) and consent-test refit

**Files:**
- Modify: `app/Http/Controllers/HRM/ShiftSwapController.php`
- Test: `tests/Feature/Attendance/SwapStoreValidationTest.php` (create); `tests/Feature/Attendance/SwapCounterpartyConsentTest.php` (refit)

**Interfaces:**
- Consumes: `RosterService::effectiveShiftId` (Task 2); `ShiftSwapRequest.type` (Task 1).
- Produces: `POST /attendance/swaps` requiring `type` + `counterparty_id`, enforcing same-department, requester-working, counterparty-free (and for swap, counterparty-working + requester-free); always sets `counterparty_status='pending'`.

- [ ] **Step 1: Write the failing validation test**

Create `tests/Feature/Attendance/SwapStoreValidationTest.php`:

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Department;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SwapStoreValidationTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.own.view']);
        $this->dept = Department::factory()->create();
        $this->shift = Shift::factory()->create(['code' => 'DAY']);
    }

    private function employee(?int $departmentId = null): User
    {
        $u = User::factory()->create(['department_id' => $departmentId ?? $this->dept->id]);
        $u->assignRole('Employee');
        $u->givePermissionTo('attendance.own.view');

        return $u;
    }

    private function works(User $u, string $date): void
    {
        RosterDay::create(['user_id' => $u->id, 'date' => $date, 'shift_id' => $this->shift->id, 'source' => 'pattern']);
    }

    public function test_valid_swap_is_created_pending_consent(): void
    {
        $requester = $this->employee();
        $counterparty = $this->employee();
        $this->works($requester, '2026-07-01');     // requester works their date
        $this->works($counterparty, '2026-07-03');  // counterparty works the return date

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'swap',
            'requester_date' => '2026-07-01',
            'counterparty_id' => $counterparty->id,
            'counterparty_date' => '2026-07-03',
        ])->assertCreated();

        $swap = ShiftSwapRequest::firstOrFail();
        $this->assertSame('swap', $swap->type);
        $this->assertSame('pending', $swap->counterparty_status);
    }

    public function test_valid_cover_is_created(): void
    {
        $requester = $this->employee();
        $counterparty = $this->employee();
        $this->works($requester, '2026-07-01');      // counterparty is free that day

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'cover',
            'requester_date' => '2026-07-01',
            'counterparty_id' => $counterparty->id,
        ])->assertCreated();

        $this->assertSame('cover', ShiftSwapRequest::firstOrFail()->type);
    }

    public function test_counterparty_is_required(): void
    {
        $requester = $this->employee();
        $this->works($requester, '2026-07-01');

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'cover',
            'requester_date' => '2026-07-01',
        ])->assertStatus(422)->assertJsonValidationErrors('counterparty_id');
    }

    public function test_counterparty_must_be_same_department(): void
    {
        $requester = $this->employee();
        $other = $this->employee(Department::factory()->create()->id);
        $this->works($requester, '2026-07-01');

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'cover',
            'requester_date' => '2026-07-01',
            'counterparty_id' => $other->id,
        ])->assertStatus(422)->assertJsonValidationErrors('counterparty_id');
    }

    public function test_requester_must_be_scheduled_on_their_date(): void
    {
        $requester = $this->employee();
        $counterparty = $this->employee();
        // requester has no roster on 2026-07-01 -> nothing to give up.

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'cover',
            'requester_date' => '2026-07-01',
            'counterparty_id' => $counterparty->id,
        ])->assertStatus(422)->assertJsonValidationErrors('requester_date');
    }

    public function test_counterparty_busy_on_requester_date_is_rejected(): void
    {
        $requester = $this->employee();
        $counterparty = $this->employee();
        $this->works($requester, '2026-07-01');
        $this->works($counterparty, '2026-07-01'); // already working that day

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'cover',
            'requester_date' => '2026-07-01',
            'counterparty_id' => $counterparty->id,
        ])->assertStatus(422)->assertJsonValidationErrors('counterparty_id');
    }

    public function test_swap_requires_counterparty_date(): void
    {
        $requester = $this->employee();
        $counterparty = $this->employee();
        $this->works($requester, '2026-07-01');

        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'swap',
            'requester_date' => '2026-07-01',
            'counterparty_id' => $counterparty->id,
        ])->assertStatus(422)->assertJsonValidationErrors('counterparty_date');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=SwapStoreValidationTest`
Expected: FAIL — the current `store` accepts a missing counterparty and does no department/availability checks.

- [ ] **Step 3: Rewrite `store` (and its imports)**

In `app/Http/Controllers/HRM/ShiftSwapController.php`, add these imports after `use App\Models\HRM\ShiftSwapRequest;`:

```php
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
```

Then replace the entire `store` method with:

```php
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:swap,cover',
            'requester_date' => 'required|date',
            'counterparty_id' => 'required|integer|exists:users,id',
            'counterparty_date' => 'nullable|date',
            'reason' => 'nullable|string|max:500',
        ]);

        $requester = $request->user();
        $counterparty = User::findOrFail($data['counterparty_id']);

        $fail = static fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        if ($counterparty->id === $requester->id) {
            $fail('counterparty_id', 'You cannot swap with yourself.');
        }
        if ($counterparty->department_id === null || $counterparty->department_id !== $requester->department_id) {
            $fail('counterparty_id', 'The counterparty must be in your department.');
        }

        // Requester must actually be rostered to work the day they give up.
        if ($this->roster->effectiveShiftId($requester->id, Carbon::parse($data['requester_date'])->toDateString()) === null) {
            $fail('requester_date', 'You are not scheduled to work on that date.');
        }
        // Counterparty must be free that day to take the shift.
        if ($this->roster->effectiveShiftId($counterparty->id, Carbon::parse($data['requester_date'])->toDateString()) !== null) {
            $fail('counterparty_id', 'The counterparty is already scheduled on that date.');
        }

        if ($data['type'] === 'swap') {
            if (empty($data['counterparty_date'])) {
                $fail('counterparty_date', 'Select the shift you will take in return.');
            }
            $cpDate = Carbon::parse($data['counterparty_date'])->toDateString();
            if ($this->roster->effectiveShiftId($counterparty->id, $cpDate) === null) {
                $fail('counterparty_date', 'The counterparty is not scheduled to work on that date.');
            }
            if ($this->roster->effectiveShiftId($requester->id, $cpDate) !== null) {
                $fail('counterparty_date', 'You are already scheduled on that date.');
            }
        } else {
            $data['counterparty_date'] = null; // a cover has no return shift
        }

        $swap = DB::transaction(fn () => ShiftSwapRequest::create([
            'type' => $data['type'],
            'requester_id' => $requester->id,
            'requester_date' => $data['requester_date'],
            'counterparty_id' => $counterparty->id,
            'counterparty_date' => $data['counterparty_date'] ?? null,
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
            'counterparty_status' => 'pending',
        ]));

        return response()->json([
            'message' => 'Swap request sent to the counterparty for confirmation.',
            'swap' => $swap,
        ], 201);
    }
```

- [ ] **Step 4: Run the validation test to verify it passes**

Run: `php artisan test --filter=SwapStoreValidationTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Refit the consent test to valid rosters; replace the open-swap case**

In `tests/Feature/Attendance/SwapCounterpartyConsentTest.php`, the requests must now be valid (same dept + availability), and the removed open-swap path test must become a "counterparty required" test. Apply these edits:

(a) Add imports after `use App\Models\HRM\ShiftSwapRequest;`:

```php
use App\Models\HRM\Department;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
```

(b) Add shared fixtures + helpers. Replace the `employee()` and `admin()` helper methods with:

```php
    private Department $dept;
    private Shift $shift;

    private function dept(): Department
    {
        return $this->dept ??= Department::factory()->create();
    }

    private function shift(): Shift
    {
        return $this->shift ??= Shift::factory()->create(['code' => 'DAY']);
    }

    private function employee(): User
    {
        $u = User::factory()->create(['department_id' => $this->dept()->id]);
        $u->givePermissionTo('attendance.own.view'); // self-service swap routes are gated on this

        return $u;
    }

    private function admin(): User
    {
        $a = User::factory()->create();
        $a->assignRole('Super Administrator'); // Gate::before bypass passes attendance.manage routes

        return $a;
    }

    private function works(User $u, string $date): void
    {
        RosterDay::create(['user_id' => $u->id, 'date' => $date, 'shift_id' => $this->shift()->id, 'source' => 'pattern']);
    }
```

(c) In `test_named_swap_needs_counterparty_consent_then_admin`, immediately after `$admin = $this->admin();`, add the roster setup and add `type`/availability to the POST. Insert:

```php
        $this->works($requester, '2026-06-22');     // requester works their date
        $this->works($counterparty, '2026-06-23');  // counterparty works the return date
```

and change the store POST body to:

```php
        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'swap',
            'requester_date' => '2026-06-22',
            'counterparty_id' => $counterparty->id,
            'counterparty_date' => '2026-06-23',
            'reason' => 'family',
        ])->assertCreated();
```

(d) In `test_counterparty_decline_terminates_the_swap`, after the two `$this->employee()` lines add:

```php
        $this->works($requester, '2026-06-22');
        $this->works($counterparty, '2026-06-23');
```

and change its store POST body to include `'type' => 'swap',` as the first field (keeping `requester_date`, `counterparty_id`, `counterparty_date`).

(e) Replace `test_open_swap_skips_peer_stage_and_goes_to_admin` entirely with:

```php
    public function test_counterparty_is_now_required(): void
    {
        $requester = $this->employee();
        $this->works($requester, '2026-06-22');

        // The open/give-away path is removed: a swap must name a counterparty.
        $this->actingAs($requester)->postJson(route('attendance.swaps.store'), [
            'type' => 'cover',
            'requester_date' => '2026-06-22',
            'reason' => 'give away',
        ])->assertStatus(422)->assertJsonValidationErrors('counterparty_id');
    }
```

(f) Update the class docblock first line to: `Two-stage swap: the counterparty (affected coworker) consents BEFORE a manager/admin authorizes.` (drop the "Open swaps … skip the peer stage" sentence).

- [ ] **Step 6: Run the consent test to verify it passes**

Run: `php artisan test --filter=SwapCounterpartyConsentTest`
Expected: PASS (3 tests: consent-then-admin, decline, counterparty-required).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/HRM/ShiftSwapController.php tests/Feature/Attendance/SwapStoreValidationTest.php tests/Feature/Attendance/SwapCounterpartyConsentTest.php
git commit -m "feat(attendance): swap store enforces dept + roster availability; drop open path

counterparty_id is now required (no anonymous give-away); validates same
department, requester scheduled, counterparty free, and (for swap) the return
shift exists and the requester is free. Always enters peer-consent.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: Roster-driven picker endpoints

**Files:**
- Modify: `app/Http/Controllers/HRM/ShiftSwapController.php`, `routes/web.php`
- Test: `tests/Feature/Attendance/SwapPickerEndpointsTest.php` (create)

**Interfaces:**
- Produces: `GET /attendance/swaps/eligible?date=` → `{ employees: [{id,name}] }` (same-dept Employees free that day). `GET /attendance/swaps/counterparty-roster?counterparty_id=&from=&to=` → `{ days: [{date,code,name,start,end}] }` (403 if cross-department).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Attendance/SwapPickerEndpointsTest.php`:

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Department;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SwapPickerEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.own.view']);
        $this->dept = Department::factory()->create();
    }

    private function employee(?int $departmentId = null): User
    {
        $u = User::factory()->create(['department_id' => $departmentId ?? $this->dept->id]);
        $u->assignRole('Employee');
        $u->givePermissionTo('attendance.own.view');

        return $u;
    }

    public function test_eligible_lists_only_same_dept_free_coworkers(): void
    {
        $me = $this->employee();
        $free = $this->employee();
        $busy = $this->employee();
        $otherDept = $this->employee(Department::factory()->create()->id);
        $shift = Shift::factory()->create();
        RosterDay::create(['user_id' => $busy->id, 'date' => '2026-07-01', 'shift_id' => $shift->id, 'source' => 'pattern']);

        $res = $this->actingAs($me)->getJson(route('attendance.swaps.eligible', ['date' => '2026-07-01']))->assertOk();

        $ids = collect($res->json('employees'))->pluck('id')->all();
        $this->assertContains($free->id, $ids);
        $this->assertNotContains($busy->id, $ids);       // working that day
        $this->assertNotContains($otherDept->id, $ids);  // different department
        $this->assertNotContains($me->id, $ids);         // not self
    }

    public function test_counterparty_roster_returns_working_days(): void
    {
        $me = $this->employee();
        $mate = $this->employee();
        $shift = Shift::factory()->create(['code' => 'DAY', 'start_time' => '08:00', 'end_time' => '20:00']);
        RosterDay::create(['user_id' => $mate->id, 'date' => '2026-07-03', 'shift_id' => $shift->id, 'source' => 'pattern']);
        RosterDay::create(['user_id' => $mate->id, 'date' => '2026-07-04', 'shift_id' => null, 'source' => 'pattern']); // off

        $res = $this->actingAs($me)->getJson(route('attendance.swaps.counterpartyRoster', [
            'counterparty_id' => $mate->id, 'from' => '2026-07-01', 'to' => '2026-07-31',
        ]))->assertOk();

        $days = collect($res->json('days'));
        $this->assertSame(1, $days->count());
        $this->assertSame('2026-07-03', $days->first()['date']);
        $this->assertSame('DAY', $days->first()['code']);
    }

    public function test_counterparty_roster_blocks_cross_department(): void
    {
        $me = $this->employee();
        $stranger = $this->employee(Department::factory()->create()->id);

        $this->actingAs($me)->getJson(route('attendance.swaps.counterpartyRoster', [
            'counterparty_id' => $stranger->id, 'from' => '2026-07-01', 'to' => '2026-07-31',
        ]))->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=SwapPickerEndpointsTest`
Expected: FAIL — routes/methods don't exist (route-not-defined / 404).

- [ ] **Step 3: Add the controller methods**

In `app/Http/Controllers/HRM/ShiftSwapController.php`, add `use App\Models\HRM\RosterDay;` to the imports, then add these two methods (after `store`):

```php
    /**
     * Same-department Employees who are FREE on the given date — the swap/cover
     * partner picker.
     */
    public function eligible(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => 'required|date']);
        $user = $request->user();
        $date = Carbon::parse($data['date'])->toDateString();

        $employees = User::role('Employee')
            ->where('id', '!=', $user->id)
            ->where('department_id', $user->department_id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(fn ($c) => $this->roster->effectiveShiftId($c->id, $date) === null)
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
            ->values();

        return response()->json(['employees' => $employees]);
    }

    /**
     * A same-department coworker's WORKING roster days in a range — the "shift you
     * will take" picker for a swap. Guarded to the requester's own department.
     */
    public function counterpartyRoster(Request $request): JsonResponse
    {
        $data = $request->validate([
            'counterparty_id' => 'required|integer|exists:users,id',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $user = $request->user();
        $counterparty = User::findOrFail($data['counterparty_id']);
        abort_unless(
            $counterparty->department_id !== null && $counterparty->department_id === $user->department_id,
            403,
            'The counterparty must be in your department.'
        );

        $fmt = static fn ($t) => $t ? Carbon::parse($t)->format('H:i') : null;

        $days = RosterDay::with('shift:id,code,name,start_time,end_time')
            ->where('user_id', $counterparty->id)
            ->whereNotNull('shift_id')
            ->whereBetween('date', [$data['from'], $data['to']])
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date' => $r->date->format('Y-m-d'),
                'code' => $r->shift?->code,
                'name' => $r->shift?->name,
                'start' => $fmt($r->shift?->start_time),
                'end' => $fmt($r->shift?->end_time),
            ])
            ->values();

        return response()->json(['days' => $days]);
    }
```

- [ ] **Step 4: Register the routes**

In `routes/web.php`, immediately after the line defining `attendance.swaps.respond` (line ~125, the employee-scoped group), add:

```php
        Route::get('/attendance/swaps/eligible', [\App\Http\Controllers\HRM\ShiftSwapController::class, 'eligible'])->name('attendance.swaps.eligible');
        Route::get('/attendance/swaps/counterparty-roster', [\App\Http\Controllers\HRM\ShiftSwapController::class, 'counterpartyRoster'])->name('attendance.swaps.counterpartyRoster');
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=SwapPickerEndpointsTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/HRM/ShiftSwapController.php routes/web.php tests/Feature/Attendance/SwapPickerEndpointsTest.php
git commit -m "feat(attendance): eligible + counterparty-roster endpoints for swap pickers

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: Roster-driven swap/cover form (frontend, source-only)

**Files:**
- Modify: `resources/js/Forms/SwapRequestForm.jsx`

**Interfaces:**
- Consumes: `GET /attendance/my-roster`, `GET /attendance/swaps/eligible`, `GET /attendance/swaps/counterparty-roster`; `POST /attendance/swaps` with `{type, requester_date, counterparty_id, counterparty_date|null, reason}`.

- [ ] **Step 1: Replace the form component**

Replace the entire contents of `resources/js/Forms/SwapRequestForm.jsx` with:

```jsx
import React, { useState, useEffect } from 'react';
import { Dialog, Flex, Box, Select, Button, Text, TextArea, SegmentedControl } from '@radix-ui/themes';
import dayjs from 'dayjs';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';

export default function SwapRequestForm({ open, onOpenChange, onSaved }) {
    const [mode, setMode] = useState('swap');            // 'swap' | 'cover'
    const [myShifts, setMyShifts] = useState([]);        // [{date, label}]
    const [requesterDate, setRequesterDate] = useState('');
    const [coworkers, setCoworkers] = useState([]);      // [{id, name}]
    const [counterpartyId, setCounterpartyId] = useState('');
    const [counterShifts, setCounterShifts] = useState([]); // [{date, label}]
    const [counterpartyDate, setCounterpartyDate] = useState('');
    const [reason, setReason] = useState('');
    const [saving, setSaving] = useState(false);

    const from = dayjs().format('YYYY-MM-DD');
    const to = dayjs().add(60, 'day').format('YYYY-MM-DD');

    // Reset + load the signed-in user's own upcoming working shifts when opened.
    useEffect(() => {
        if (!open) return;
        setMode('swap');
        setRequesterDate(''); setCounterpartyId(''); setCounterpartyDate('');
        setCoworkers([]); setCounterShifts([]); setReason('');

        (async () => {
            try {
                const res = await requestJson('get', '/attendance/my-roster', { params: { from, to } });
                const mine = Object.values(res?.roster ?? {})[0]?.days ?? {};
                const shifts = Object.entries(mine)
                    .filter(([, d]) => !d.off)
                    .map(([date, d]) => ({ date, label: `${dayjs(date).format('ddd DD MMM')} — ${d.code ?? 'Shift'}` }));
                setMyShifts(shifts);
            } catch {
                setMyShifts([]);
            }
        })();
    }, [open]);

    // When the requester's date changes, load eligible same-dept coworkers free that day.
    useEffect(() => {
        setCounterpartyId(''); setCounterShifts([]); setCounterpartyDate('');
        if (!requesterDate) { setCoworkers([]); return; }
        (async () => {
            try {
                const res = await requestJson('get', '/attendance/swaps/eligible', { params: { date: requesterDate } });
                setCoworkers(res?.employees ?? []);
            } catch {
                setCoworkers([]);
            }
        })();
    }, [requesterDate]);

    // For a swap, load the chosen coworker's upcoming working shifts.
    useEffect(() => {
        setCounterpartyDate(''); setCounterShifts([]);
        if (mode !== 'swap' || !counterpartyId) return;
        (async () => {
            try {
                const res = await requestJson('get', '/attendance/swaps/counterparty-roster', {
                    params: { counterparty_id: counterpartyId, from, to },
                });
                setCounterShifts((res?.days ?? []).map(d => ({
                    date: d.date,
                    label: `${dayjs(d.date).format('ddd DD MMM')} — ${d.code ?? 'Shift'}${d.start ? ` ${d.start}–${d.end}` : ''}`,
                })));
            } catch {
                setCounterShifts([]);
            }
        })();
    }, [counterpartyId, mode]);

    const canSubmit = requesterDate && counterpartyId && (mode === 'cover' || counterpartyDate) && !saving;

    const save = async () => {
        setSaving(true);
        try {
            await requestJson('post', '/attendance/swaps', {
                data: {
                    type: mode,
                    requester_date: requesterDate,
                    counterparty_id: Number(counterpartyId),
                    counterparty_date: mode === 'swap' ? counterpartyDate : null,
                    reason: reason || null,
                },
            });
            showToast.success('Swap request sent to the counterparty for confirmation.');
            onSaved?.();
            onOpenChange(false);
        } catch (err) {
            showToast.error(err?.message || 'Failed to submit swap request.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Content maxWidth="460px">
                <Dialog.Title>Request Shift Swap / Cover</Dialog.Title>
                <Flex direction="column" gap="3">
                    <SegmentedControl.Root value={mode} onValueChange={setMode}>
                        <SegmentedControl.Item value="swap">Swap (trade shifts)</SegmentedControl.Item>
                        <SegmentedControl.Item value="cover">Cover (they take mine)</SegmentedControl.Item>
                    </SegmentedControl.Root>

                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">Your shift to give up</Text>
                        <Select.Root value={requesterDate || undefined} onValueChange={setRequesterDate}>
                            <Select.Trigger placeholder="Pick one of your shifts" style={{ width: '100%' }} />
                            <Select.Content>
                                {myShifts.map(s => <Select.Item key={s.date} value={s.date}>{s.label}</Select.Item>)}
                            </Select.Content>
                        </Select.Root>
                    </Box>

                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">Coworker (same department, free that day)</Text>
                        <Select.Root value={counterpartyId || undefined} onValueChange={setCounterpartyId} disabled={!requesterDate}>
                            <Select.Trigger placeholder={requesterDate ? 'Select a coworker' : 'Pick your shift first'} style={{ width: '100%' }} />
                            <Select.Content>
                                {coworkers.map(c => <Select.Item key={c.id} value={String(c.id)}>{c.name}</Select.Item>)}
                            </Select.Content>
                        </Select.Root>
                    </Box>

                    {mode === 'swap' && (
                        <Box>
                            <Text size="1" color="gray" as="div" mb="1">Their shift you'll take</Text>
                            <Select.Root value={counterpartyDate || undefined} onValueChange={setCounterpartyDate} disabled={!counterpartyId}>
                                <Select.Trigger placeholder={counterpartyId ? 'Select their shift' : 'Select a coworker first'} style={{ width: '100%' }} />
                                <Select.Content>
                                    {counterShifts.map(s => <Select.Item key={s.date} value={s.date}>{s.label}</Select.Item>)}
                                </Select.Content>
                            </Select.Root>
                        </Box>
                    )}

                    <TextArea placeholder="Reason (optional)" value={reason} onChange={e => setReason(e.target.value)} rows={3} />

                    <Flex justify="end" gap="2" mt="2">
                        <Button variant="soft" color="gray" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
                        <Button onClick={save} disabled={!canSubmit}>{saving ? 'Submitting…' : 'Submit'}</Button>
                    </Flex>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}
```

- [ ] **Step 2: Type-check the source builds (no commit of assets yet)**

Run: `npx vite build`
Expected: build completes without errors referencing `SwapRequestForm.jsx`. (Do NOT commit `public/build` here; that happens once in Task 6.)

- [ ] **Step 3: Commit source only**

```bash
git add resources/js/Forms/SwapRequestForm.jsx
git commit -m "feat(attendance): roster-driven Swap/Cover request form

Swap|Cover toggle; pick your own shift, a same-department free coworker, and
(for swap) their shift to take — all from real rosters via the new endpoints.
No free-text dates; submit posts type + ids/dates.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 6: Build, full-suite, live verification, roadmap

**Files:**
- Build output: `public/build/*`
- Modify: `docs/attendance/ATTENDANCE_MODULE.md` (§5.5 swaps) and `docs/attendance/ATTENDANCE_HARDENING_ROADMAP.md`

- [ ] **Step 1: Run the full attendance suite**

Run: `php artisan test --filter=Attendance`
Expected: PASS (incl. `RosterApplySwapTest`, `SwapCounterpartyConsentTest`, `SwapStoreValidationTest`, `SwapPickerEndpointsTest`, `SwapTypeColumnTest`, `SwapRejectApiTest`). No new failures.

- [ ] **Step 2: Run the full suite**

Run: `php artisan test`
Expected: only the two documented pre-existing failures; everything else green.

- [ ] **Step 3: Consolidated frontend build**

Run: `npx vite build`
Expected: success.

```bash
git add public/build
git commit -m "build(attendance): rebuild assets for roster-driven swap/cover form

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

- [ ] **Step 4: Live verification by HTTP status**

Ensure `npm run dev` is running (background). With ms-playwright-mcp Chrome (kill + reopen the process if the profile is locked), log in as `emam@dhakabypass.com` / `123456789` at `https://aero-enterprise-suite.test`, then via `browser_evaluate`:

```js
const today = new Date().toISOString().slice(0,10);
const elig = await fetch(`/attendance/swaps/eligible?date=${today}`, {headers:{'X-Requested-With':'XMLHttpRequest'}});
return { eligible: elig.status };  // expect 200
```

Then open the employee page (`/attendance-employee`), launch the swap dialog, and confirm the three pickers populate and an invalid submit (e.g. busy coworker) surfaces a field error. **Read-only on prod**; do not leave test swaps on prod. Clean up any dev-created swap rows after verifying.

- [ ] **Step 5: Update docs**

In `docs/attendance/ATTENDANCE_MODULE.md` §5.5, replace the swap description with the cover/swap roster-driven model (counterparty required, same-department, picked from rosters; cover = one-sided; reciprocity = a second cover). In `docs/attendance/ATTENDANCE_HARDENING_ROADMAP.md`, add a note under the fixed list that the shift-swap roster rewrite was corrected (4-cell swap / 2-cell cover) and the form is now roster-driven; add a follow-up bullet: "Mobile app (separate repo) swap UI must be updated to the `type` + roster-driven endpoints," and "open-shift pool" as a future option.

- [ ] **Step 6: Commit**

```bash
git add docs/attendance/ATTENDANCE_MODULE.md docs/attendance/ATTENDANCE_HARDENING_ROADMAP.md
git commit -m "docs(attendance): document roster-driven swap/cover model + follow-ups

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

- [ ] **Step 7: Deploy note (manual)**

Before this is live on prod: deploy the rebuilt `public/build` AND run `php artisan migrate` on prod (the `add_type_to_shift_swap_requests` migration). The mobile app (separate repo) needs a matching update before its swap screen works against the new contract.

---

## Self-Review

**Spec coverage:**
- `type` enum + required counterparty (open path removed) → Task 1 + Task 3. ✓
- Same-department + free/working validation → Task 3 (`SwapStoreValidationTest`). ✓
- Corrected `applySwap` (cover 2-cell / swap 4-cell) → Task 2. ✓
- `eligible` + `counterpartyRoster` endpoints, cross-dept 403 → Task 4. ✓
- Roster-driven form (Swap|Cover, pick-from-roster) → Task 5. ✓
- Build + migrate + live + docs + mobile follow-up → Task 6. ✓
- Out of scope (open pool, mobile impl, rest/OT rules, multi-level approval) → not built; logged. ✓

**Placeholder scan:** No TBD/TODO; every code/step is concrete.

**Type consistency:** `effectiveShiftId(int,string):?int` defined in Task 2, consumed by `store`/`eligible`/`counterpartyRoster` (Tasks 3–4). `applySwap` reads `type` (Task 1). Routes `attendance.swaps.eligible` / `attendance.swaps.counterpartyRoster` defined in Task 4, used by tests (Task 4) and the form (Task 5). Form posts `{type, requester_date, counterparty_id, counterparty_date, reason}` exactly matching `store`'s validated fields. `my-roster` response shape (`roster[uid].days[date].{code,off}`) matches the form's parser.

**Note:** `resolveShift()` (ignores `source=pattern`) is deliberately NOT used for availability — `effectiveShiftId()` (reads any roster row) is, so a pattern-materialized roster correctly counts as "working." Tests seed `source=pattern` rows to prove this.
