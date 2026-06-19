<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\User;
use App\Services\Attendance\AttendanceQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkedMinutesConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_night_shift_minutes_correct_without_addday_heuristic(): void
    {
        $user = User::factory()->create();
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-19',
            'punchin' => Carbon::parse('2026-06-19 20:00'),
            'punchout' => Carbon::parse('2026-06-20 04:00'), // 8h across midnight
        ]);

        $svc = app(AttendanceQueryService::class);
        $history = $svc->getAttendanceHistory($user->id, ['scope' => 'self']);

        $row = $history['attendances'][0];
        $this->assertSame(480.0, $row['total_work_minutes']);
    }
}
