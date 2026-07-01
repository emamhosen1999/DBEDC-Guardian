<?php

namespace Tests\Feature\Api;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileRosterOverlayTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_my_roster_includes_leave_and_holidays(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $shift = Shift::factory()->create(['code' => 'N']);
        $type = LeaveSetting::create(['type' => 'Annual Leave', 'symbol' => 'AL', 'days' => 20]);

        RosterDay::create(['user_id' => $user->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern']);
        Leave::create([
            'user_id' => $user->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-06', 'to_date' => '2026-07-06',
            'status' => 'approved', 'is_half_day' => false,
            'no_of_days' => 1, 'reason' => 'test',
        ]);

        $res = $this->getJson(route('api.v1.attendance.my-roster', [
            'from' => '2026-07-01', 'to' => '2026-07-31',
        ]))->assertOk();

        $this->assertTrue($res->json('success'));
        $this->assertSame('AL', $res->json('data.days.2026-07-06.leave.type'));
        $this->assertArrayHasKey('holidays', $res->json('data'));
    }
}
