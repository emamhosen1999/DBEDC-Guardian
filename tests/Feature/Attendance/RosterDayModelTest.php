<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterDayModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_user_date_constraint(): void
    {
        $user = User::factory()->create();
        $shift = Shift::factory()->create();

        RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-19', 'shift_id' => $shift->id, 'source' => 'manual']);

        $this->expectException(QueryException::class);
        RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-19', 'shift_id' => null, 'source' => 'pattern']);
    }

    public function test_null_shift_means_off_day(): void
    {
        $user = User::factory()->create();
        $r = RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-20', 'shift_id' => null, 'source' => 'pattern']);
        $this->assertNull($r->shift);
    }
}
