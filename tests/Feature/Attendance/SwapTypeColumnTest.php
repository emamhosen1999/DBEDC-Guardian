<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SwapTypeColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_swap_request_persists_a_type(): void
    {
        $user = User::factory()->create();

        $swap = ShiftSwapRequest::create([
            'type' => 'cover',
            'requester_id' => $user->id,
            'requester_date' => '2026-07-01',
            'counterparty_id' => $user->id,
            'status' => 'pending',
        ]);

        $this->assertSame('cover', $swap->fresh()->type);
    }
}
