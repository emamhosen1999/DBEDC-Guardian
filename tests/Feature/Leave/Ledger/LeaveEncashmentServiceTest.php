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
        $ledger->post($u->id, $enc->id, now()->year, 'opening', 8);
        $ledger->post($u->id, $fixed->id, now()->year, 'opening', 10);

        app(LeaveEncashmentService::class)->encash($u->id, $enc->id, 3, $u->id);
        $this->assertSame(5.0, $ledger->balance($u->id, $enc->id, now()->year));

        $this->expectException(\RuntimeException::class);
        app(LeaveEncashmentService::class)->encash($u->id, $fixed->id, 1, $u->id); // not encashable
    }
}
