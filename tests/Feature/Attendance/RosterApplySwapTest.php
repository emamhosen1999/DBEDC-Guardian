<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use App\Services\Attendance\RosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterApplySwapTest extends TestCase
{
    use RefreshDatabase;

    public function test_swap_rewrites_both_parties_roster(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $shiftA = Shift::factory()->create(['code' => 'AAA']);
        $shiftB = Shift::factory()->create(['code' => 'BBB']);

        RosterDay::create(['user_id' => $a->id, 'date' => '2026-06-19', 'shift_id' => $shiftA->id, 'source' => 'pattern']);
        RosterDay::create(['user_id' => $b->id, 'date' => '2026-06-20', 'shift_id' => $shiftB->id, 'source' => 'pattern']);

        $swap = ShiftSwapRequest::create([
            'requester_id' => $a->id, 'requester_date' => '2026-06-19',
            'counterparty_id' => $b->id, 'counterparty_date' => '2026-06-20',
            'status' => 'approved',
        ]);

        app(RosterService::class)->applySwap($swap);

        $aDay = RosterDay::where('user_id', $a->id)->whereDate('date', '2026-06-19')->first();
        $bDay = RosterDay::where('user_id', $b->id)->whereDate('date', '2026-06-20')->first();

        $this->assertSame('swap', $aDay->source);
        $this->assertSame($shiftB->id, $aDay->shift_id);
        $this->assertSame($shiftA->id, $bDay->shift_id);
        $this->assertTrue($aDay->locked);
    }
}
