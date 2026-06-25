<?php

namespace Tests\Feature\Leave\Ledger;

use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
        $this->assertFalse(Schema::hasColumn('leave_ledger', 'updated_at'));
    }
}
