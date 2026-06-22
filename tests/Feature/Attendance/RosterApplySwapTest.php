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

    public function test_swap_trades_two_shifts_across_four_cells(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $shiftA = Shift::factory()->create(['code' => 'AAA']);
        $shiftB = Shift::factory()->create(['code' => 'BBB']);

        // A works 06-19; B works 06-20. Each is OFF on the other's date.
        RosterDay::create(['user_id' => $a->id, 'date' => '2026-06-19', 'shift_id' => $shiftA->id, 'source' => 'pattern']);
        RosterDay::create(['user_id' => $b->id, 'date' => '2026-06-20', 'shift_id' => $shiftB->id, 'source' => 'pattern']);

        $swap = ShiftSwapRequest::create([
            'type' => 'swap',
            'requester_id' => $a->id, 'requester_date' => '2026-06-19',
            'counterparty_id' => $b->id, 'counterparty_date' => '2026-06-20',
            'status' => 'approved',
        ]);

        app(RosterService::class)->applySwap($swap);

        $aOwn = RosterDay::where('user_id', $a->id)->whereDate('date', '2026-06-19')->first();
        $bCovers = RosterDay::where('user_id', $b->id)->whereDate('date', '2026-06-19')->first();
        $bOwn = RosterDay::where('user_id', $b->id)->whereDate('date', '2026-06-20')->first();
        $aTakes = RosterDay::where('user_id', $a->id)->whereDate('date', '2026-06-20')->first();

        // A is now OFF on their old day; B works it with A's shift.
        $this->assertNull($aOwn->shift_id);
        $this->assertSame('swap', $aOwn->source);
        $this->assertTrue((bool) $aOwn->locked);
        $this->assertSame($shiftA->id, $bCovers->shift_id);
        $this->assertSame('swap', $bCovers->source);
        $this->assertTrue((bool) $bCovers->locked);

        // B is now OFF on their old day; A works it with B's shift.
        $this->assertNull($bOwn->shift_id);
        $this->assertSame($shiftB->id, $aTakes->shift_id);
        $this->assertSame('swap', $aTakes->source);
    }

    public function test_cover_offloads_requester_shift_to_counterparty(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $shiftA = Shift::factory()->create(['code' => 'AAA']);

        // A works 06-19; B is free that day.
        RosterDay::create(['user_id' => $a->id, 'date' => '2026-06-19', 'shift_id' => $shiftA->id, 'source' => 'pattern']);

        $swap = ShiftSwapRequest::create([
            'type' => 'cover',
            'requester_id' => $a->id, 'requester_date' => '2026-06-19',
            'counterparty_id' => $b->id, 'counterparty_date' => null,
            'status' => 'approved',
        ]);

        app(RosterService::class)->applySwap($swap);

        $aOwn = RosterDay::where('user_id', $a->id)->whereDate('date', '2026-06-19')->first();
        $bCovers = RosterDay::where('user_id', $b->id)->whereDate('date', '2026-06-19')->first();

        $this->assertNull($aOwn->shift_id);          // requester off
        $this->assertSame('swap', $aOwn->source);
        $this->assertSame($shiftA->id, $bCovers->shift_id); // counterparty covers
        $this->assertTrue((bool) $bCovers->locked);
    }

    public function test_non_approved_swap_is_a_no_op(): void
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
            'status' => 'pending',
        ]);

        app(RosterService::class)->applySwap($swap);

        $aDay = RosterDay::where('user_id', $a->id)->whereDate('date', '2026-06-19')->first();
        $bDay = RosterDay::where('user_id', $b->id)->whereDate('date', '2026-06-20')->first();

        $this->assertSame('pattern', $aDay->source);
        $this->assertSame($shiftA->id, $aDay->shift_id);
        $this->assertFalse((bool) $aDay->locked);

        $this->assertSame('pattern', $bDay->source);
        $this->assertSame($shiftB->id, $bDay->shift_id);
        $this->assertFalse((bool) $bDay->locked);
    }
}
