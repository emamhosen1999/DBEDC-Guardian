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
