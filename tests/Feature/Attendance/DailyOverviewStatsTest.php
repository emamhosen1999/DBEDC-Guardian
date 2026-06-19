<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DailyOverviewStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
    }

    public function test_late_count_uses_settings_start_not_hardcoded_nine(): void
    {
        // Office starts 10:00, grace 0 → late threshold 10:00.
        AttendanceSetting::create([
            'office_start_time' => '10:00', 'office_end_time' => '18:00',
            'break_time_duration' => 0, 'late_mark_after' => 0,
            'early_leave_before' => 0, 'overtime_after' => 0,
            'weekend_days' => ['friday'], 'auto_punch_out' => false,
        ]);

        $date = Carbon::parse('2026-06-18'); // Thursday, working day
        $onTime = User::factory()->create(); $onTime->assignRole('Employee');
        $late = User::factory()->create(); $late->assignRole('Employee');

        Attendance::factory()->for($onTime)->create([
            'date' => $date->toDateString(),
            'punchin' => $date->copy()->setTime(9, 30), // before 10:00 → NOT late
            'punchout' => $date->copy()->setTime(18, 0),
        ]);
        Attendance::factory()->for($late)->create([
            'date' => $date->toDateString(),
            'punchin' => $date->copy()->setTime(10, 30), // after 10:00 → late
            'punchout' => $date->copy()->setTime(18, 0),
        ]);

        $admin = User::factory()->create(); $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        $res = $this->actingAs($admin)
            ->getJson(route('attendance.dailyOverview', ['date' => $date->toDateString()]));

        $res->assertOk();
        $this->assertSame(2, $res->json('present'));
        $this->assertSame(1, $res->json('late')); // only the 10:30 punch, NOT the 09:30 one
    }
}
