<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterDayModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The (user_id, date) unique constraint was dropped so a single day can
     * carry multiple roster rows (double-rostered nights, day+night doubles).
     * The DB no longer rejects a second row for the same user+date.
     */
    public function test_multiple_rows_per_user_date_are_allowed(): void
    {
        $user = User::factory()->create();
        $shiftOne = Shift::factory()->create();
        $shiftTwo = Shift::factory()->create();

        RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-19', 'shift_id' => $shiftOne->id, 'source' => 'manual']);
        RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-19', 'shift_id' => $shiftTwo->id, 'source' => 'manual']);

        $this->assertSame(2, RosterDay::where('user_id', $user->id)->whereDate('date', '2026-06-19')->count());
    }

    public function test_null_shift_means_off_day(): void
    {
        $user = User::factory()->create();
        $r = RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-20', 'shift_id' => null, 'source' => 'pattern']);
        $this->assertNull($r->shift);
    }
}
