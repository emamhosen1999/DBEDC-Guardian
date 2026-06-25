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
