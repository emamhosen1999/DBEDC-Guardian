<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TodayShiftPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_payload_carries_the_rostered_shift(): void
    {
        Carbon::setTestNow('2026-07-14 09:00:00');

        $user = User::factory()->create();
        $shift = Shift::factory()->create([
            'code' => 'M', 'name' => 'Morning', 'color' => '#22c55e',
            'start_time' => '08:00', 'end_time' => '16:00', 'crosses_midnight' => false,
        ]);

        RosterDay::create([
            'user_id' => $user->id, 'date' => '2026-07-14', 'shift_id' => $shift->id, 'source' => 'manual',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('data.today_shift.code', 'M')
            ->assertJsonPath('data.today_shift.start', '08:00')
            ->assertJsonPath('data.today_shift.end', '16:00')
            ->assertJsonPath('data.today_shift.off', false);
    }

    public function test_today_payload_reports_an_off_day(): void
    {
        Carbon::setTestNow('2026-07-14 09:00:00');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('data.today_shift.off', true)
            ->assertJsonPath('data.today_shift.code', null);
    }
}
