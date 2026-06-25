<?php

namespace Tests\Feature\Leave\Ledger;

use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Leave\LeaveLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LeaveLedgerApiTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_returns_the_transaction_history_for_a_user_year(): void
    {
        Permission::findOrCreate('leave.own.view');
        $u = User::factory()->create();
        $u->givePermissionTo('leave.own.view');
        $t = LeaveSetting::create(['type' => 'Casual', 'days' => 10]);
        $ledger = app(LeaveLedgerService::class);
        $ledger->post($u->id, $t->id, 2026, 'opening', 10);
        $ledger->post($u->id, $t->id, 2026, 'consumption', -2);

        $this->actingAs($u)
            ->getJson(route('leave-ledger', ['user_id' => $u->id, 'year' => 2026]))
            ->assertOk()
            ->assertJsonPath('transactions.0.txn_type', 'consumption') // newest first
            ->assertJsonPath('transactions.0.balance_after', 8)
            ->assertJsonPath('transactions.1.txn_type', 'opening');
    }
}
