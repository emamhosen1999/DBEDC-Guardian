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
