<?php

namespace Tests\Feature\Leave\Ledger;

use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Leave\LeaveLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $last = $svc->post($u->id, $t->id, 2026, 'consumption', -2);

        $this->artisan('leave:reconcile-ledger')->assertExitCode(0);

        // Tamper a stored balance_after directly to simulate drift.
        DB::table('leave_ledger')->where('id', $last->id)->update(['balance_after' => 99]);
        $this->artisan('leave:reconcile-ledger')->assertExitCode(1);
    }
}
