<?php

namespace Tests\Feature\Leave\Ledger;

use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Leave\LeaveLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LeaveBalanceApiTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_returns_per_type_balances_for_a_user(): void
    {
        Permission::findOrCreate('leave.own.view');
        $u = User::factory()->create();
        $u->givePermissionTo('leave.own.view');
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
