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
