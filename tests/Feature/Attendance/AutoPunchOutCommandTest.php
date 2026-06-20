<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AutoPunchOutCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_closes_open_row_after_shift_end(): void
    {
        AttendanceSetting::create([
            'auto_punch_out' => true, 'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'weekend_days' => ['friday', 'saturday'],
        ]);
        $user = User::factory()->create();
        $open = Attendance::factory()->for($user)->create([
            'date' => '2026-06-17', 'punchin' => '2026-06-17 09:00:00', 'punchout' => null,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-17 20:00:00')); // past 17:00 end
        Artisan::call('attendance:auto-punch-out');

        $open->refresh();
        $this->assertNotNull($open->punchout);
        $this->assertSame('2026-06-17 17:00:00', Carbon::parse($open->punchout)->format('Y-m-d H:i:s'));
    }

    public function test_does_not_close_row_still_within_shift(): void
    {
        AttendanceSetting::create([
            'auto_punch_out' => true, 'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'weekend_days' => ['friday', 'saturday'],
        ]);
        $user = User::factory()->create();
        $open = Attendance::factory()->for($user)->create([
            'date' => '2026-06-17', 'punchin' => '2026-06-17 09:00:00', 'punchout' => null,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-06-17 14:00:00')); // before end
        Artisan::call('attendance:auto-punch-out');
        $this->assertNull($open->fresh()->punchout);
    }

    public function test_disabled_setting_closes_nothing(): void
    {
        AttendanceSetting::create(['auto_punch_out' => false, 'office_start_time' => '09:00', 'office_end_time' => '17:00']);
        $user = User::factory()->create();
        $open = Attendance::factory()->for($user)->create([
            'date' => '2026-06-17', 'punchin' => '2026-06-17 09:00:00', 'punchout' => null,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-06-17 20:00:00'));
        Artisan::call('attendance:auto-punch-out');
        $this->assertNull($open->fresh()->punchout);
    }
}
