<?php

namespace Tests\Feature\Leave;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveHalfDayColumnsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function half_day_fields_persist_and_no_of_days_holds_a_half(): void
    {
        $user = User::factory()->create();
        $type = LeaveSetting::factory()->create();

        $leave = Leave::create([
            'user_id' => $user->id,
            'leave_type' => $type->id,
            'from_date' => '2026-02-10',
            'to_date' => '2026-02-10',
            'no_of_days' => 0.5,
            'reason' => 'half day',
            'status' => 'pending',
            'is_half_day' => true,
            'half_day_session' => 'first_half',
        ])->fresh();

        $this->assertTrue($leave->is_half_day);
        $this->assertSame('first_half', $leave->half_day_session);
        $this->assertSame(0.5, (float) $leave->no_of_days);
    }
}
