<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\HRM\ShiftRotationPattern;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class MonthlyGridOffDayTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_punch_on_a_rostered_off_day_is_day_off_not_absent(): void
    {
        $user = User::factory()->create();
        $shift = Shift::factory()->create();
        // 2-day rotation: work, off. Anchor 2026-06-01 => 06-02 is an OFF day.
        $pattern = ShiftRotationPattern::factory()->create([
            'cycle_length_days' => 2, 'definition' => [$shift->id, 'off'],
        ]);
        ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => $user->id,
            'shift_id' => null, 'rotation_pattern_id' => $pattern->id,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        $user->setRelation('attendances', new Collection);
        $user->setRelation('leaves', new Collection);

        $data = app(AttendanceReportService::class)
            ->getUserAttendanceData($user, 2026, 6, collect(), collect());

        // 06-02 is an OFF day in the rotation, no punches -> Day Off, not Absent.
        $this->assertSame('Day Off', $data['2026-06-02']['remarks']);
        $this->assertSame('Absent', $data['2026-06-01']['remarks']);
    }
}
