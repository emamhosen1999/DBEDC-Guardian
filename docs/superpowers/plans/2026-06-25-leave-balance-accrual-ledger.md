# Leave Balance & Accrual Ledger (Phase 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** A first-class, immutable, auditable leave **balance/accrual ledger** with per-type configurable policy that enforces balances on apply and reconciles with the engine's approved-leave counts.

**Architecture:** An append-only `leave_ledger` (signed transactions; balance = running sum) is the source of truth. Policy lives on `LeaveSetting` (configurable). Thin services post transactions: `LeaveLedgerService` (post/balance/available/reverse), `LeaveAccrualService` (monthly + annual grant), `CarryForwardService` (cap + expiry), `LeaveEncashmentService`. The leave lifecycle (`LeaveCrudService`/`LeaveApprovalService`) posts `consumption`/`consumption_reversal` and enforces `available()`. A seeding command makes the live year correct.

**Tech Stack:** Laravel 11, PHP 8.2, MySQL/sqlite, PHPUnit class-style + RefreshDatabase, Inertia v2/React 18.

## Global Constraints

- **Spec:** `docs/superpowers/specs/2026-06-25-leave-balance-accrual-ledger-design.md` governs.
- **Ledger is append-only + immutable** (`UPDATED_AT = null`); never mutate a row — reversals are new rows.
- **`leave_ledger.leave_type` references `leave_settings.id`** (same convention as `leaves.leave_type`). `no_of_days` is decimal (Phase 2). Statuses are canonical `{pending,approved,rejected,cancelled}` (Phase 2).
- **Idempotency** is mandatory for accrual, grant, carry-forward, expiry, and seeding (running twice = same state).
- **DB:** new migrations run on dev MySQL `dbedc_guardian` (`php artisan migrate`); tests sqlite `:memory:`.
- **Dev/test:** never `npm run build`; `npx vite build` only; verify live at `https://aero-enterprise-suite.test` (dev creds emam@dhakabypass.com / 123456789).
- **Allowed pre-existing failures only:** `MobileSyncApiTest > sync push applies leave apply mutation`; `NavigationRoutesTest > organization directory`. No new failure.
- **`users.date_of_joining`** is the join column (NOT `joining_date`); there is no `users.status` column — use `App\Models\Offboarding.last_working_date` for termination if needed (mirror `AttendanceReportService`).
- Invoke `engineering-standards` + `current-tech-stack` before each code task. Commit per task.

---

## File Structure

**New:**
- `database/migrations/2026_06_25_000020_add_policy_columns_to_leave_settings.php`
- `database/migrations/2026_06_25_000021_create_leave_ledger_table.php`
- `app/Models/HRM/LeaveLedger.php`
- `app/Services/Leave/LeaveLedgerService.php`
- `app/Services/Leave/LeaveAccrualService.php`
- `app/Services/Leave/CarryForwardService.php`
- `app/Services/Leave/LeaveEncashmentService.php`
- `app/Console/Commands/Leave/RunLeaveAccrual.php`, `GrantAnnualLeave.php`, `RunCarryForward.php`, `ExpireCarriedLeave.php`, `ReconcileLeaveLedger.php`, `SeedLeaveLedger.php`
- `app/Http/Controllers/LeaveBalanceController.php`
- `resources/js/Components/Leave/LeaveBalanceCards.jsx`
- Tests under `tests/Feature/Leave/Ledger/` (one per task).

**Modified:**
- `app/Models/HRM/LeaveSetting.php` (fillable + casts for policy columns)
- `app/Services/Leave/LeaveCrudService.php` (consumption + enforcement; remove `getRemainingDays`)
- `app/Services/Leave/LeaveApprovalService.php` (consumption on final approval; reversal on reject)
- `routes/console.php` (schedule), `routes/web.php` (balance routes)
- Retire `app/Console/Commands/Leave/AccrueMonthlyLeaves.php` + `ResetAnnualLeaves.php`

---

## Task 1: Policy columns on `leave_settings`

**Files:**
- Create: `database/migrations/2026_06_25_000020_add_policy_columns_to_leave_settings.php`
- Modify: `app/Models/HRM/LeaveSetting.php`
- Test: `tests/Feature/Leave/Ledger/LeaveSettingPolicyTest.php`

**Interfaces:**
- Produces: `LeaveSetting` columns `accrual_method` (`annual_upfront|monthly|none`), `accrual_rate` (decimal), `probation_months` (int), `prorate_on_join` (bool), `carry_forward_cap` (decimal nullable), `carry_expiry_months` (int nullable), `is_encashable` (bool), `allow_negative` (bool). All mass-assignable + cast. Tasks 4–7 read these.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Feature\Leave\Ledger;

use App\Models\HRM\LeaveSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveSettingPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function policy_columns_are_mass_assignable_with_defaults(): void
    {
        $s = LeaveSetting::create([
            'type' => 'Earned', 'days' => 12,
            'accrual_method' => 'monthly', 'accrual_rate' => 12,
            'probation_months' => 3, 'carry_forward_cap' => 10, 'carry_expiry_months' => 12,
            'is_encashable' => true, 'allow_negative' => false,
        ])->fresh();

        $this->assertSame('monthly', $s->accrual_method);
        $this->assertSame(12.0, (float) $s->accrual_rate);
        $this->assertSame(3, $s->probation_months);
        $this->assertTrue($s->prorate_on_join);     // default true
        $this->assertSame(10.0, (float) $s->carry_forward_cap);
        $this->assertTrue($s->is_encashable);
        $this->assertFalse($s->allow_negative);
    }

    /** @test */
    public function defaults_apply_when_unspecified(): void
    {
        $s = LeaveSetting::create(['type' => 'Casual', 'days' => 10])->fresh();
        $this->assertSame('annual_upfront', $s->accrual_method);
        $this->assertNull($s->carry_forward_cap);
        $this->assertFalse($s->is_encashable);
        $this->assertFalse($s->allow_negative);
    }
}
```

- [ ] **Step 2: Run — FAIL** (`php artisan test --filter=LeaveSettingPolicyTest`) — columns/casts missing.

- [ ] **Step 3: Migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_settings')) {
            return;
        }
        Schema::table('leave_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_settings', 'accrual_method')) {
                $table->string('accrual_method', 20)->default('annual_upfront');
            }
            if (! Schema::hasColumn('leave_settings', 'accrual_rate')) {
                $table->decimal('accrual_rate', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('leave_settings', 'probation_months')) {
                $table->unsignedTinyInteger('probation_months')->default(0);
            }
            if (! Schema::hasColumn('leave_settings', 'prorate_on_join')) {
                $table->boolean('prorate_on_join')->default(true);
            }
            if (! Schema::hasColumn('leave_settings', 'carry_forward_cap')) {
                $table->decimal('carry_forward_cap', 5, 1)->nullable();
            }
            if (! Schema::hasColumn('leave_settings', 'carry_expiry_months')) {
                $table->unsignedTinyInteger('carry_expiry_months')->nullable();
            }
            if (! Schema::hasColumn('leave_settings', 'is_encashable')) {
                $table->boolean('is_encashable')->default(false);
            }
            if (! Schema::hasColumn('leave_settings', 'allow_negative')) {
                $table->boolean('allow_negative')->default(false);
            }
        });

        // Back-fill from legacy flags: earned/is_earned => monthly; accrual_rate <= days.
        DB::table('leave_settings')->get()->each(function ($s) {
            $method = ($s->earned_leave || ($s->is_earned ?? false)) ? 'monthly' : 'annual_upfront';
            DB::table('leave_settings')->where('id', $s->id)->update([
                'accrual_method' => $method,
                'accrual_rate' => $s->days,
                'carry_forward_cap' => $s->carry_forward ? $s->days : null,
            ]);
        });
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
```

- [ ] **Step 4: Model** — in `app/Models/HRM/LeaveSetting.php` add to `$fillable`: `accrual_method, accrual_rate, probation_months, prorate_on_join, carry_forward_cap, carry_expiry_months, is_encashable, allow_negative`. Add casts: `accrual_method`=>'string', `accrual_rate`=>'decimal:2', `probation_months`=>'integer', `prorate_on_join`=>'boolean', `carry_forward_cap`=>'decimal:1', `carry_expiry_months`=>'integer', `is_encashable`=>'boolean', `allow_negative`=>'boolean'.

- [ ] **Step 5: Run — PASS.** Then migrate dev MySQL: `php artisan migrate`.

- [ ] **Step 6: Commit** — `feat(leave): configurable accrual policy columns on leave_settings`.

---

## Task 2: `leave_ledger` table + immutable model

**Files:**
- Create: `database/migrations/2026_06_25_000021_create_leave_ledger_table.php`, `app/Models/HRM/LeaveLedger.php`
- Test: `tests/Feature/Leave/Ledger/LeaveLedgerModelTest.php`

**Interfaces:**
- Produces: `leave_ledger` table + `LeaveLedger` model (`UPDATED_AT = null`); columns `user_id, leave_type, period_year, txn_type, amount, balance_after, source_type, source_id, actor_id, reason, created_at`. Tasks 3+ insert rows.

- [ ] **Step 1: Failing test**

```php
<?php
namespace Tests\Feature\Leave\Ledger;

use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveLedgerModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_persists_a_signed_transaction_and_is_immutable(): void
    {
        $u = User::factory()->create();
        $t = LeaveSetting::factory()->create();
        $row = LeaveLedger::create([
            'user_id' => $u->id, 'leave_type' => $t->id, 'period_year' => 2026,
            'txn_type' => 'opening', 'amount' => 10, 'balance_after' => 10,
        ])->fresh();

        $this->assertSame(10.0, (float) $row->amount);
        $this->assertSame('opening', $row->txn_type);
        $this->assertNull(LeaveLedger::UPDATED_AT);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('leave_ledger', 'updated_at'));
    }
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_ledger')) {
            return;
        }
        Schema::create('leave_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('leave_type'); // -> leave_settings.id
            $table->smallInteger('period_year');
            $table->string('txn_type', 24); // opening|accrual|consumption|consumption_reversal|carry_forward|carry_expiry|encashment|adjustment
            $table->decimal('amount', 6, 2);          // signed
            $table->decimal('balance_after', 6, 2);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('leave_type')->references('id')->on('leave_settings')->cascadeOnDelete();
            $table->index(['user_id', 'leave_type', 'period_year']);
            $table->index(['txn_type', 'created_at']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_ledger');
    }
};
```

- [ ] **Step 4: Model** `app/Models/HRM/LeaveLedger.php`

```php
<?php
namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveLedger extends Model
{
    protected $table = 'leave_ledger';

    public const UPDATED_AT = null; // immutable: append-only

    protected $fillable = [
        'user_id', 'leave_type', 'period_year', 'txn_type',
        'amount', 'balance_after', 'source_type', 'source_id', 'actor_id', 'reason',
    ];

    protected $casts = [
        'period_year' => 'integer',
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function leaveSetting(): BelongsTo
    {
        return $this->belongsTo(LeaveSetting::class, 'leave_type');
    }
}
```

- [ ] **Step 5: Run — PASS.** Migrate dev MySQL.
- [ ] **Step 6: Commit** — `feat(leave): immutable append-only leave_ledger table + model`.

---

## Task 3: `LeaveLedgerService` (post / balance / available / reverse)

**Files:**
- Create: `app/Services/Leave/LeaveLedgerService.php`
- Test: `tests/Feature/Leave/Ledger/LeaveLedgerServiceTest.php`

**Interfaces:**
- Consumes: `LeaveLedger` (Task 2).
- Produces:
  - `post(int $userId, int $leaveTypeId, int $year, string $txnType, float $amount, ?string $sourceType = null, ?int $sourceId = null, ?int $actorId = null, ?string $reason = null): LeaveLedger`
  - `balance(int $userId, int $leaveTypeId, int $year): float`
  - `available(int $userId, int $leaveTypeId, ?\Carbon\CarbonInterface $asOf = null): float`
  - `reverseConsumption(int $leaveId, ?string $reason = null): void`
  Tasks 4–8 consume these.

- [ ] **Step 1: Failing test**

```php
<?php
namespace Tests\Feature\Leave\Ledger;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Leave\LeaveLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function posting_transactions_tracks_a_running_balance(): void
    {
        $u = User::factory()->create();
        $t = LeaveSetting::factory()->create();
        $svc = app(LeaveLedgerService::class);

        $svc->post($u->id, $t->id, 2026, 'opening', 10);
        $svc->post($u->id, $t->id, 2026, 'consumption', -2.5);
        $last = $svc->post($u->id, $t->id, 2026, 'accrual', 1);

        $this->assertSame(8.5, (float) $last->balance_after);
        $this->assertSame(8.5, $svc->balance($u->id, $t->id, 2026));
    }

    /** @test */
    public function reverse_consumption_restores_the_balance_and_is_idempotent(): void
    {
        $u = User::factory()->create();
        $t = LeaveSetting::factory()->create();
        $leave = Leave::create([
            'user_id' => $u->id, 'leave_type' => $t->id, 'from_date' => '2026-04-01',
            'to_date' => '2026-04-01', 'no_of_days' => 1, 'reason' => 'x', 'status' => 'approved',
        ]);
        $svc = app(LeaveLedgerService::class);

        $svc->post($u->id, $t->id, 2026, 'opening', 10);
        $svc->post($u->id, $t->id, 2026, 'consumption', -1, 'leave', $leave->id);
        $this->assertSame(9.0, $svc->balance($u->id, $t->id, 2026));

        $svc->reverseConsumption($leave->id);
        $this->assertSame(10.0, $svc->balance($u->id, $t->id, 2026));

        $svc->reverseConsumption($leave->id); // idempotent
        $this->assertSame(10.0, $svc->balance($u->id, $t->id, 2026));
        $this->assertSame(1, LeaveLedger::where('source_id', $leave->id)->where('txn_type', 'consumption_reversal')->count());
    }
}
```

- [ ] **Step 2: Run — FAIL** (service missing).

- [ ] **Step 3: Implement**

```php
<?php
namespace App\Services\Leave;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveLedger;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class LeaveLedgerService
{
    public function post(
        int $userId, int $leaveTypeId, int $year, string $txnType, float $amount,
        ?string $sourceType = null, ?int $sourceId = null, ?int $actorId = null, ?string $reason = null
    ): LeaveLedger {
        return DB::transaction(function () use ($userId, $leaveTypeId, $year, $txnType, $amount, $sourceType, $sourceId, $actorId, $reason) {
            $prior = LeaveLedger::query()
                ->where('user_id', $userId)->where('leave_type', $leaveTypeId)->where('period_year', $year)
                ->lockForUpdate()->orderByDesc('id')->value('balance_after');

            $balanceAfter = round((float) ($prior ?? 0) + $amount, 2);

            return LeaveLedger::create([
                'user_id' => $userId, 'leave_type' => $leaveTypeId, 'period_year' => $year,
                'txn_type' => $txnType, 'amount' => round($amount, 2), 'balance_after' => $balanceAfter,
                'source_type' => $sourceType, 'source_id' => $sourceId,
                'actor_id' => $actorId ?? auth()->id(), 'reason' => $reason,
            ]);
        });
    }

    public function balance(int $userId, int $leaveTypeId, int $year): float
    {
        $last = LeaveLedger::query()
            ->where('user_id', $userId)->where('leave_type', $leaveTypeId)->where('period_year', $year)
            ->orderByDesc('id')->value('balance_after');

        return (float) ($last ?? 0);
    }

    public function available(int $userId, int $leaveTypeId, ?CarbonInterface $asOf = null): float
    {
        $year = ($asOf ?? Carbon::now())->year;

        return $this->balance($userId, $leaveTypeId, $year);
    }

    public function reverseConsumption(int $leaveId, ?string $reason = null): void
    {
        $leave = Leave::find($leaveId);
        if (! $leave) {
            return;
        }

        $already = LeaveLedger::where('source_type', 'leave')->where('source_id', $leaveId)
            ->where('txn_type', 'consumption_reversal')->exists();
        if ($already) {
            return; // idempotent
        }

        $consumed = (float) LeaveLedger::where('source_type', 'leave')->where('source_id', $leaveId)
            ->where('txn_type', 'consumption')->sum('amount'); // negative
        if ($consumed === 0.0) {
            return;
        }

        $year = (int) Carbon::parse($leave->from_date)->year;
        $this->post($leave->user_id, (int) $leave->leave_type, $year, 'consumption_reversal', -$consumed,
            'leave', $leaveId, null, $reason ?? 'Leave reversed');
    }
}
```

- [ ] **Step 4: Run — PASS.**
- [ ] **Step 5: Commit** — `feat(leave): LeaveLedgerService (post/balance/available/reverseConsumption)`.

---

## Task 4: `LeaveAccrualService` (monthly accrual + annual grant, idempotent)

**Files:**
- Create: `app/Services/Leave/LeaveAccrualService.php`
- Test: `tests/Feature/Leave/Ledger/LeaveAccrualServiceTest.php`

**Interfaces:**
- Consumes: `LeaveLedgerService::post`/`balance`; `LeaveSetting` policy (Task 1); `users.date_of_joining`.
- Produces:
  - `grantAnnual(int $year, ?int $userId = null, bool $dryRun = false): int` — for `annual_upfront` types, posts one `opening` (pro-rated if joined in `$year`) per user/type/year; returns count posted. Idempotent.
  - `accrueMonthly(int $year, int $month, ?int $userId = null, bool $dryRun = false): int` — for `monthly` types, posts `accrual` = `accrual_rate/12` (pro-rated on join month), probation-gated by `probation_months`; once per user/type/month. Returns count.

- [ ] **Step 1: Failing test**

```php
<?php
namespace Tests\Feature\Leave\Ledger;

use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Leave\LeaveAccrualService;
use App\Services\Leave\LeaveLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveAccrualServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function annual_grant_posts_full_entitlement_and_is_idempotent(): void
    {
        $u = User::factory()->create(['date_of_joining' => '2020-01-01']);
        $t = LeaveSetting::create(['type' => 'Casual', 'days' => 10, 'accrual_method' => 'annual_upfront', 'accrual_rate' => 10]);

        $svc = app(LeaveAccrualService::class);
        $this->assertSame(1, $svc->grantAnnual(2026));
        $this->assertSame(10.0, app(LeaveLedgerService::class)->balance($u->id, $t->id, 2026));

        $this->assertSame(0, $svc->grantAnnual(2026)); // idempotent
        $this->assertSame(1, LeaveLedger::where('txn_type', 'opening')->count());
    }

    /** @test */
    public function annual_grant_prorates_a_mid_year_joiner(): void
    {
        $u = User::factory()->create(['date_of_joining' => '2026-07-01']); // ~half the year
        $t = LeaveSetting::create(['type' => 'Casual', 'days' => 12, 'accrual_method' => 'annual_upfront', 'accrual_rate' => 12, 'prorate_on_join' => true]);

        app(LeaveAccrualService::class)->grantAnnual(2026);
        $bal = app(LeaveLedgerService::class)->balance($u->id, $t->id, 2026);
        // Jul..Dec = 6 of 12 months -> 6 days.
        $this->assertEqualsWithDelta(6.0, $bal, 0.01);
    }

    /** @test */
    public function monthly_accrual_respects_probation_and_is_idempotent(): void
    {
        $u = User::factory()->create(['date_of_joining' => '2026-01-01']);
        $t = LeaveSetting::create(['type' => 'Earned', 'days' => 12, 'accrual_method' => 'monthly', 'accrual_rate' => 12, 'probation_months' => 3]);

        $svc = app(LeaveAccrualService::class);
        $this->assertSame(0, $svc->accrueMonthly(2026, 2)); // within 3-month probation
        $this->assertSame(1, $svc->accrueMonthly(2026, 5)); // past probation -> 1 day
        $this->assertSame(1.0, app(LeaveLedgerService::class)->balance($u->id, $t->id, 2026));
        $this->assertSame(0, $svc->accrueMonthly(2026, 5)); // idempotent
    }
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement**

```php
<?php
namespace App\Services\Leave;

use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use Carbon\Carbon;

class LeaveAccrualService
{
    public function __construct(private LeaveLedgerService $ledger) {}

    public function grantAnnual(int $year, ?int $userId = null, bool $dryRun = false): int
    {
        $types = LeaveSetting::where('accrual_method', 'annual_upfront')->get();
        $users = $this->users($userId);
        $posted = 0;

        foreach ($users as $user) {
            $join = $user->date_of_joining ? Carbon::parse($user->date_of_joining) : null;
            if ($join && $join->year > $year) {
                continue; // not yet employed
            }
            foreach ($types as $type) {
                if ($this->hasOpening($user->id, $type->id, $year)) {
                    continue;
                }
                $entitlement = (float) ($type->accrual_rate ?? $type->days);
                if ($type->prorate_on_join && $join && $join->year === $year) {
                    $remainingMonths = 12 - $join->month + 1;
                    $entitlement = round($entitlement * $remainingMonths / 12, 2);
                }
                if ($entitlement <= 0) {
                    continue;
                }
                if (! $dryRun) {
                    $this->ledger->post($user->id, $type->id, $year, 'opening', $entitlement, 'command', null, null, 'Annual entitlement');
                }
                $posted++;
            }
        }

        return $posted;
    }

    public function accrueMonthly(int $year, int $month, ?int $userId = null, bool $dryRun = false): int
    {
        $types = LeaveSetting::where('accrual_method', 'monthly')->get();
        $users = $this->users($userId);
        $accrualDate = Carbon::create($year, $month, 1)->startOfMonth();
        $posted = 0;

        foreach ($users as $user) {
            $join = $user->date_of_joining ? Carbon::parse($user->date_of_joining) : null;
            if (! $join || $join->isAfter($accrualDate->copy()->endOfMonth())) {
                continue;
            }
            // Probation: full months of service at the accrual month.
            $serviceMonths = $join->copy()->startOfMonth()->diffInMonths($accrualDate);
            foreach ($types as $type) {
                if ($serviceMonths < (int) $type->probation_months) {
                    continue;
                }
                if ($this->hasAccrualForMonth($user->id, $type->id, $year, $month)) {
                    continue;
                }
                $monthly = round((float) ($type->accrual_rate ?? $type->days) / 12, 2);
                if ($type->prorate_on_join && $join->year === $year && $join->month === $month) {
                    $dim = $accrualDate->daysInMonth;
                    $monthly = round($monthly * ($dim - $join->day + 1) / $dim, 2);
                }
                if ($monthly <= 0) {
                    continue;
                }
                if (! $dryRun) {
                    $this->ledger->post($user->id, $type->id, $year, 'accrual', $monthly, 'command', null, null, "Accrual {$year}-{$month}");
                }
                $posted++;
            }
        }

        return $posted;
    }

    private function users(?int $userId)
    {
        return User::query()->when($userId, fn ($q) => $q->where('id', $userId))->get();
    }

    private function hasOpening(int $userId, int $typeId, int $year): bool
    {
        return LeaveLedger::where('user_id', $userId)->where('leave_type', $typeId)
            ->where('period_year', $year)->where('txn_type', 'opening')->exists();
    }

    private function hasAccrualForMonth(int $userId, int $typeId, int $year, int $month): bool
    {
        return LeaveLedger::where('user_id', $userId)->where('leave_type', $typeId)
            ->where('period_year', $year)->where('txn_type', 'accrual')
            ->where('reason', "Accrual {$year}-{$month}")->exists();
    }
}
```

> Probation note: `diffInMonths` between start-of-join-month and the accrual month gives whole months of service; `probation_months = 3` means accrual starts in the 4th month (the test's Feb is month 1 of service → skipped; May is month 4 → accrues).

- [ ] **Step 4: Run — PASS** (all three).
- [ ] **Step 5: Commit** — `feat(leave): LeaveAccrualService (idempotent annual grant + monthly accrual w/ probation & proration)`.

---

## Task 5: `CarryForwardService` (cap + expiry, idempotent)

**Files:**
- Create: `app/Services/Leave/CarryForwardService.php`
- Test: `tests/Feature/Leave/Ledger/CarryForwardServiceTest.php`

**Interfaces:**
- Consumes: `LeaveLedgerService::balance`/`post`; `LeaveSetting` (`carry_forward_cap`, `carry_expiry_months`).
- Produces:
  - `rollOver(int $fromYear, int $toYear, ?int $userId = null, bool $dryRun = false): int` — for types with non-null `carry_forward_cap`, posts `carry_forward` (+`min(balance(fromYear), cap)`) into `toYear`. Idempotent per user/type/toYear.
  - `expireCarried(\Carbon\CarbonInterface $asOf, ?int $userId = null, bool $dryRun = false): int` — when `carry_expiry_months` have elapsed since the toYear start, posts `carry_expiry` (−unused carried). Idempotent.

- [ ] **Step 1: Failing test**

```php
<?php
namespace Tests\Feature\Leave\Ledger;

use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Leave\CarryForwardService;
use App\Services\Leave\LeaveLedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarryForwardServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_carries_up_to_the_cap_and_is_idempotent(): void
    {
        $u = User::factory()->create();
        $t = LeaveSetting::create(['type' => 'Earned', 'days' => 12, 'accrual_method' => 'monthly', 'accrual_rate' => 12, 'carry_forward_cap' => 5]);
        $ledger = app(LeaveLedgerService::class);
        $ledger->post($u->id, $t->id, 2025, 'opening', 12);
        $ledger->post($u->id, $t->id, 2025, 'consumption', -2); // balance 10, cap 5

        $cf = app(CarryForwardService::class);
        $this->assertSame(1, $cf->rollOver(2025, 2026));
        $this->assertSame(5.0, $ledger->balance($u->id, $t->id, 2026)); // capped at 5

        $this->assertSame(0, $cf->rollOver(2025, 2026)); // idempotent
    }

    /** @test */
    public function unused_carried_days_expire_after_the_window(): void
    {
        $u = User::factory()->create();
        $t = LeaveSetting::create(['type' => 'Earned', 'days' => 12, 'accrual_method' => 'monthly', 'accrual_rate' => 12, 'carry_forward_cap' => 5, 'carry_expiry_months' => 3]);
        $ledger = app(LeaveLedgerService::class);
        $ledger->post($u->id, $t->id, 2025, 'opening', 12);
        app(CarryForwardService::class)->rollOver(2025, 2026); // +5 into 2026

        // 4 months into 2026 -> past the 3-month expiry window; 5 still unused.
        $expired = app(CarryForwardService::class)->expireCarried(Carbon::create(2026, 5, 1));
        $this->assertSame(1, $expired);
        $this->assertSame(0.0, $ledger->balance($u->id, $t->id, 2026));
    }
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement**

```php
<?php
namespace App\Services\Leave;

use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class CarryForwardService
{
    public function __construct(private LeaveLedgerService $ledger) {}

    public function rollOver(int $fromYear, int $toYear, ?int $userId = null, bool $dryRun = false): int
    {
        $types = LeaveSetting::whereNotNull('carry_forward_cap')->get();
        $posted = 0;

        foreach ($this->users($userId) as $user) {
            foreach ($types as $type) {
                if ($this->hasCarry($user->id, $type->id, $toYear)) {
                    continue;
                }
                $carried = min($this->ledger->balance($user->id, $type->id, $fromYear), (float) $type->carry_forward_cap);
                if ($carried <= 0) {
                    continue;
                }
                if (! $dryRun) {
                    $this->ledger->post($user->id, $type->id, $toYear, 'carry_forward', $carried, 'command', null, null, "Carry-forward from {$fromYear}");
                }
                $posted++;
            }
        }

        return $posted;
    }

    public function expireCarried(CarbonInterface $asOf, ?int $userId = null, bool $dryRun = false): int
    {
        $types = LeaveSetting::whereNotNull('carry_forward_cap')->whereNotNull('carry_expiry_months')->get();
        $posted = 0;

        foreach ($this->users($userId) as $user) {
            foreach ($types as $type) {
                $year = $asOf->year;
                $carryRow = LeaveLedger::where('user_id', $user->id)->where('leave_type', $type->id)
                    ->where('period_year', $year)->where('txn_type', 'carry_forward')->first();
                if (! $carryRow) {
                    continue;
                }
                $expiry = Carbon::create($year, 1, 1)->addMonths((int) $type->carry_expiry_months);
                if ($asOf->lessThan($expiry)) {
                    continue;
                }
                if ($this->hasExpiry($user->id, $type->id, $year)) {
                    continue;
                }
                // Unused carried = min(carried, current balance).
                $unused = min((float) $carryRow->amount, max(0.0, $this->ledger->balance($user->id, $type->id, $year)));
                if ($unused <= 0) {
                    continue;
                }
                if (! $dryRun) {
                    $this->ledger->post($user->id, $type->id, $year, 'carry_expiry', -$unused, 'command', null, null, 'Carried days expired');
                }
                $posted++;
            }
        }

        return $posted;
    }

    private function users(?int $userId)
    {
        return User::query()->when($userId, fn ($q) => $q->where('id', $userId))->get();
    }

    private function hasCarry(int $userId, int $typeId, int $toYear): bool
    {
        return LeaveLedger::where('user_id', $userId)->where('leave_type', $typeId)
            ->where('period_year', $toYear)->where('txn_type', 'carry_forward')->exists();
    }

    private function hasExpiry(int $userId, int $typeId, int $year): bool
    {
        return LeaveLedger::where('user_id', $userId)->where('leave_type', $typeId)
            ->where('period_year', $year)->where('txn_type', 'carry_expiry')->exists();
    }
}
```

- [ ] **Step 4: Run — PASS.**
- [ ] **Step 5: Commit** — `feat(leave): CarryForwardService (cap + expiry, idempotent)`.

---

## Task 6: `LeaveEncashmentService`

**Files:**
- Create: `app/Services/Leave/LeaveEncashmentService.php`
- Test: `tests/Feature/Leave/Ledger/LeaveEncashmentServiceTest.php`

**Interfaces:**
- Consumes: `LeaveLedgerService`; `LeaveSetting.is_encashable`.
- Produces: `encash(int $userId, int $leaveTypeId, float $days, int $actorId, ?string $reason = null): LeaveLedger` — throws `\RuntimeException` if the type is not encashable or balance insufficient; else posts `encashment` (−days). Recording only.

- [ ] **Step 1: Failing test**

```php
<?php
namespace Tests\Feature\Leave\Ledger;

use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Leave\LeaveEncashmentService;
use App\Services\Leave\LeaveLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveEncashmentServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_encashes_when_eligible_and_blocks_when_not(): void
    {
        $u = User::factory()->create();
        $enc = LeaveSetting::create(['type' => 'Earned', 'days' => 12, 'is_encashable' => true]);
        $fixed = LeaveSetting::create(['type' => 'Casual', 'days' => 10, 'is_encashable' => false]);
        $ledger = app(LeaveLedgerService::class);
        $ledger->post($u->id, $enc->id, 2026, 'opening', 8);
        $ledger->post($u->id, $fixed->id, 2026, 'opening', 10);

        app(LeaveEncashmentService::class)->encash($u->id, $enc->id, 3, $u->id);
        $this->assertSame(5.0, $ledger->balance($u->id, $enc->id, 2026));

        $this->expectException(\RuntimeException::class);
        app(LeaveEncashmentService::class)->encash($u->id, $fixed->id, 1, $u->id); // not encashable
    }
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement**

```php
<?php
namespace App\Services\Leave;

use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use Carbon\Carbon;

class LeaveEncashmentService
{
    public function __construct(private LeaveLedgerService $ledger) {}

    public function encash(int $userId, int $leaveTypeId, float $days, int $actorId, ?string $reason = null): LeaveLedger
    {
        $type = LeaveSetting::find($leaveTypeId);
        if (! $type || ! $type->is_encashable) {
            throw new \RuntimeException('This leave type is not encashable.', 422);
        }
        if ($days <= 0) {
            throw new \RuntimeException('Encashment days must be positive.', 422);
        }
        $year = Carbon::now()->year;
        if ($this->ledger->balance($userId, $leaveTypeId, $year) < $days) {
            throw new \RuntimeException('Insufficient balance to encash.', 422);
        }

        return $this->ledger->post($userId, $leaveTypeId, $year, 'encashment', -$days, 'manual', null, $actorId, $reason ?? 'Leave encashment');
    }
}
```

- [ ] **Step 4: Run — PASS.**
- [ ] **Step 5: Commit** — `feat(leave): LeaveEncashmentService (recorded encashment, eligibility + balance gated)`.

---

## Task 7: Wire consumption + enforcement into the leave lifecycle

**Files:**
- Modify: `app/Services/Leave/LeaveCrudService.php` (post consumption on auto-approve; enforce via `available()`; remove `getRemainingDays`; reverse on delete), `app/Services/Leave/LeaveApprovalService.php` (consumption on final approval; reversal on reject)
- Test: `tests/Feature/Leave/Ledger/LeaveConsumptionWiringTest.php`

**Interfaces:**
- Consumes: `LeaveLedgerService::post/available/reverseConsumption`.
- Produces: approved leaves post a single `consumption`; reject/cancel/delete reverse it; apply is rejected when over-drawn (unless `allow_negative`).

- [ ] **Step 1: Failing test**

```php
<?php
namespace Tests\Feature\Leave\Ledger;

use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Leave\LeaveCrudService;
use App\Services\Leave\LeaveLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveConsumptionWiringTest extends TestCase
{
    use RefreshDatabase;

    private function allWorkingDays(): void
    {
        $this->app->bind(ScheduleResolver::class, fn () => new class implements ScheduleResolver {
            public function resolve(int $userId, \Carbon\CarbonInterface $date): ShiftSchedule
            {
                return new ShiftSchedule(
                    start: $date->copy()->setTime(9, 0), end: $date->copy()->setTime(17, 0),
                    crossesMidnight: false, graceInMinutes: 0, graceOutMinutes: 0,
                    fullDayMinutes: 480, halfDayMinutes: 240, minPresentMinutes: 0,
                    breakMinutes: 0, isWorkingDay: true, isScheduled: true,
                );
            }
        });
    }

    /** @test */
    public function an_auto_approved_leave_posts_a_consumption_against_the_balance(): void
    {
        $this->allWorkingDays();
        $u = User::factory()->create();
        $t = LeaveSetting::create(['type' => 'Casual', 'days' => 10, 'requires_approval' => false, 'auto_approve' => true, 'accrual_rate' => 10]);
        app(LeaveLedgerService::class)->post($u->id, $t->id, 2026, 'opening', 10);

        app(LeaveCrudService::class)->createLeave([
            'user_id' => $u->id, 'leaveType' => 'Casual',
            'fromDate' => '2026-03-02', 'toDate' => '2026-03-04', // 3 working days
            'daysCount' => 3, 'leaveReason' => 'consume',
        ]);

        $this->assertSame(7.0, app(LeaveLedgerService::class)->balance($u->id, $t->id, 2026));
        $this->assertSame(1, LeaveLedger::where('txn_type', 'consumption')->count());
    }

    /** @test */
    public function applying_beyond_balance_is_rejected_unless_allow_negative(): void
    {
        $this->allWorkingDays();
        $u = User::factory()->create();
        $t = LeaveSetting::create(['type' => 'Casual', 'days' => 10, 'requires_approval' => false, 'auto_approve' => true, 'accrual_rate' => 10, 'allow_negative' => false]);
        app(LeaveLedgerService::class)->post($u->id, $t->id, 2026, 'opening', 2);

        $this->expectException(\RuntimeException::class);
        app(LeaveCrudService::class)->createLeave([
            'user_id' => $u->id, 'leaveType' => 'Casual',
            'fromDate' => '2026-03-02', 'toDate' => '2026-03-04', // 3 > 2 available
            'daysCount' => 3, 'leaveReason' => 'overdraw',
        ]);
    }
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement** — in `LeaveCrudService`:
  - Inject `LeaveLedgerService $ledger` (5th ctor arg; update `LeaveBalanceTest::makeService` accordingly — it constructs the service manually).
  - Replace the balance block in `createLeave`/`updateLeave`: compute `$available = $this->ledger->available((int)$data['user_id'], (int)$leaveTypeId, $fromDate);` and, when `$leaveSetting && ! $leaveSetting->allow_negative && $serverDays > $available`, throw `new \RuntimeException('Insufficient leave balance for selected leave type.', 422);`. **Delete `getRemainingDays`.**
  - After the auto-approve branch sets status `approved`, post consumption (idempotent): if no consumption row exists for this leave, `$this->ledger->post($leave->user_id, (int)$leaveTypeId, (int)$fromDate->year, 'consumption', -$serverDays, 'leave', $leave->id);`.
  - In `deleteLeave`: before delete, if the leave was `approved`, call `$this->ledger->reverseConsumption($leaveId, 'Leave deleted')`.
  - In `updateLeave` when an already-`approved` leave's dates change: `$this->ledger->reverseConsumption($leaveId, 'Leave edited')` then re-post consumption for the new `$serverDays`.

  In `LeaveApprovalService`:
  - Inject/resolve `LeaveLedgerService` (mirror the Phase-2 `app(LeaveAuditService::class)` pattern — same namespace, use `app(LeaveLedgerService::class)`).
  - On **final approval** (the branch that sets `status => 'approved'`), post consumption (idempotent) for `$leave->no_of_days` to year `from_date`.
  - On **reject**, `app(LeaveLedgerService::class)->reverseConsumption($leave->id, 'Leave rejected')`.

- [ ] **Step 4: Run — PASS;** then `php artisan test --filter="Leave"` (update `LeaveBalanceTest` for the new ctor arg + seed opening balances so its existing balance assertions still hold — those tests now need an `opening` posted, or set `allow_negative` on their settings; prefer posting `opening` equal to `days`).

- [ ] **Step 5: Commit** — `feat(leave): post consumption on approval + reverse on reject/delete/edit; enforce balance via ledger.available() (remove getRemainingDays)`.

---

## Task 8: Seeding command (correct live cut-over)

**Files:**
- Create: `app/Console/Commands/Leave/SeedLeaveLedger.php`
- Test: `tests/Feature/Leave/Ledger/SeedLeaveLedgerTest.php`

**Interfaces:**
- Consumes: `LeaveAccrualService::grantAnnual/accrueMonthly`, `LeaveLedgerService::post`, approved `leaves`.
- Produces: `php artisan leave:seed-ledger {year} {--dry-run} {--user=}` — grants annual + back-fills monthly accrual Jan..currentMonth + posts consumption for each approved leave in `year`. Idempotent.

- [ ] **Step 1: Failing test**

```php
<?php
namespace Tests\Feature\Leave\Ledger;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Leave\LeaveLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedLeaveLedgerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function seeding_produces_correct_remaining_and_is_idempotent(): void
    {
        $u = User::factory()->create(['date_of_joining' => '2020-01-01']);
        $t = LeaveSetting::create(['type' => 'Casual', 'days' => 10, 'accrual_method' => 'annual_upfront', 'accrual_rate' => 10]);
        Leave::create(['user_id' => $u->id, 'leave_type' => $t->id, 'from_date' => '2026-02-10', 'to_date' => '2026-02-12', 'no_of_days' => 3, 'reason' => 'x', 'status' => 'approved']);

        $this->artisan('leave:seed-ledger', ['year' => 2026])->assertExitCode(0);
        $this->assertSame(7.0, app(LeaveLedgerService::class)->balance($u->id, $t->id, 2026)); // 10 - 3

        $this->artisan('leave:seed-ledger', ['year' => 2026])->assertExitCode(0); // idempotent
        $this->assertSame(7.0, app(LeaveLedgerService::class)->balance($u->id, $t->id, 2026));
    }
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement** the command: call `grantAnnual($year)`, loop `accrueMonthly($year, $m)` for `$m = 1..min(12, now month if year==current else 12)`, then for each `approved` leave with `from_date` in `$year` that has no `consumption` row, post `consumption` (−`no_of_days`, source `leave`/id) to `$year`. Respect `--dry-run`/`--user`. Wrap in a transaction per user. (All sub-calls are idempotent, so the command is.)

- [ ] **Step 4: Run — PASS.**
- [ ] **Step 5: Commit** — `feat(leave): leave:seed-ledger command (pro-rated grant + accrual back-fill + consumption, idempotent)`.

---

## Task 9: Scheduled commands + reconcile + retire dead scaffolding

**Files:**
- Create: `app/Console/Commands/Leave/RunLeaveAccrual.php` (`leave:accrue`), `GrantAnnualLeave.php` (`leave:grant-annual`), `RunCarryForward.php` (`leave:carry-forward`), `ExpireCarriedLeave.php` (`leave:expire-carried`), `ReconcileLeaveLedger.php` (`leave:reconcile-ledger`)
- Modify: `routes/console.php` (schedule), delete `app/Console/Commands/Leave/AccrueMonthlyLeaves.php` + `ResetAnnualLeaves.php`
- Test: `tests/Feature/Leave/Ledger/ReconcileLeaveLedgerTest.php`

**Interfaces:**
- Produces: thin command wrappers over the Task 4–5 services + a `leave:reconcile-ledger` that re-derives `balance_after` per (user,type,year) and reports mismatches (exit 1 if any).

- [ ] **Step 1: Failing test** (reconcile detects a tampered row)

```php
<?php
namespace Tests\Feature\Leave\Ledger;

use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Leave\LeaveLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileLeaveLedgerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function reconcile_passes_for_consistent_ledger_and_flags_drift(): void
    {
        $u = User::factory()->create();
        $t = LeaveSetting::factory()->create();
        $svc = app(LeaveLedgerService::class);
        $svc->post($u->id, $t->id, 2026, 'opening', 10);
        $svc->post($u->id, $t->id, 2026, 'consumption', -2);

        $this->artisan('leave:reconcile-ledger')->assertExitCode(0);

        // Tamper a stored balance_after directly.
        LeaveLedger::query()->latest('id')->first()->update(['balance_after' => 99]); // note: model has no updated_at; raw update ok in test
        $this->artisan('leave:reconcile-ledger')->assertExitCode(1);
    }
}
```

> The model is immutable in app code (no `updated_at`); the test tampers directly to simulate drift. If `->update()` is awkward without `updated_at`, use `DB::table('leave_ledger')->where('id',$id)->update(['balance_after'=>99])`.

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement** the five commands (thin wrappers; `ReconcileLeaveLedger` groups `leave_ledger` by (user,type,year) ordered by id, re-runs the running sum, compares to stored `balance_after`, prints mismatches, returns `Command::FAILURE` if any). Schedule in `routes/console.php`:
  - `leave:accrue` monthly on the 1st; `leave:grant-annual` + `leave:carry-forward` on Jan 1; `leave:expire-carried` daily; `leave:reconcile-ledger` daily.
  Delete `AccrueMonthlyLeaves.php` + `ResetAnnualLeaves.php` (replaced).

- [ ] **Step 4: Run — PASS;** `php artisan test --filter="Leave"` clean.
- [ ] **Step 5: Commit** — `feat(leave): scheduled accrual/grant/carry/expire + reconcile command; retire dead AccrueMonthlyLeaves/ResetAnnualLeaves`.

---

## Task 10: Balance API + UI cards

**Files:**
- Create: `app/Http/Controllers/LeaveBalanceController.php`, `resources/js/Components/Leave/LeaveBalanceCards.jsx`
- Modify: `routes/web.php` (`GET /leave-balances`), the leave page that should show balances (e.g. `resources/js/Pages/LeavesEmployee.jsx`)
- Test: `tests/Feature/Leave/Ledger/LeaveBalanceApiTest.php`

**Interfaces:**
- Consumes: `LeaveLedgerService::balance`; `LeaveSetting`.
- Produces: `GET /leave-balances?user_id=&year=` → `{ balances: [{ type, entitled, accrued, taken, carried, remaining }] }`.

- [ ] **Step 1: Failing test**

```php
<?php
namespace Tests\Feature\Leave\Ledger;

use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Leave\LeaveLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveBalanceApiTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_returns_per_type_balances_for_a_user(): void
    {
        $u = User::factory()->create();
        $t = LeaveSetting::create(['type' => 'Casual', 'days' => 10, 'accrual_rate' => 10]);
        $ledger = app(LeaveLedgerService::class);
        $ledger->post($u->id, $t->id, 2026, 'opening', 10);
        $ledger->post($u->id, $t->id, 2026, 'consumption', -2.5);

        $this->actingAs($u)
            ->getJson(route('leave-balances', ['user_id' => $u->id, 'year' => 2026]))
            ->assertOk()
            ->assertJsonPath('balances.0.type', 'Casual')
            ->assertJsonPath('balances.0.taken', 2.5)
            ->assertJsonPath('balances.0.remaining', 7.5);
    }
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement** controller (aggregate per type from `leave_ledger`: `entitled` = sum opening, `accrued` = sum accrual, `carried` = sum carry_forward, `taken` = −sum(consumption+consumption_reversal), `remaining` = `balance(year)`), the route (auth + own-or-`leaves.manage` gate, mirror `LeaveController` authorization), and the `LeaveBalanceCards.jsx` (Radix cards mirroring `StatsCards`/HolidayForm style). Wire the cards into the employee leave page.

- [ ] **Step 4: Run — PASS;** `npx vite build`; verify live at `/leaves` (cards render; numbers match a known ledger).

- [ ] **Step 5: Commit** source; one consolidated `public/build` commit at phase end.

---

## Final verification

- [ ] `php artisan test` — only the two allowed failures; ledger reconcile + idempotency invariants hold.
- [ ] Run `php artisan leave:seed-ledger 2026 --dry-run` on dev, inspect, then real; `php artisan migrate:status` confirms the two Phase-3 migrations Ran (note for prod).
- [ ] Whole-branch review (`superpowers:requesting-code-review`).
- [ ] Update roadmap + parent design doc (Phase 3 done). Note prod deploy: 2 migrations + run `leave:seed-ledger` once (dry-run first) + schedule the commands.

## Prod deploy (Phase 3)
Migrations: `add_policy_columns_to_leave_settings`, `create_leave_ledger_table`. Then **review per-type policy** (accrual_method/rate, probation, carry cap/expiry, encashable, allow_negative) with the owner, run `leave:seed-ledger {year} --dry-run` then real, and enable the schedule. Retire the old accrual commands.
