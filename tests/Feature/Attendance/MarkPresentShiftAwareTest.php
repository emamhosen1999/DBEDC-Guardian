<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MarkPresentShiftAwareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        // mark-as-present is guarded by attendance.correct|create|update, not the
        // legacy attendance.manage this fixture used to grant alone.
        foreach (['attendance.manage', 'attendance.correct', 'attendance.create', 'attendance.update'] as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }
    }

    public function test_mark_present_punchin_matches_office_start(): void
    {
        AttendanceSetting::create([
            'office_start_time' => '08:30', 'office_end_time' => '17:00',
            'break_time_duration' => 0, 'late_mark_after' => 0,
            'early_leave_before' => 0, 'overtime_after' => 0,
            'weekend_days' => ['friday'], 'auto_punch_out' => false,
        ]);
        $admin = User::factory()->create(); $admin->assignRole('Admin');
        $admin->givePermissionTo(['attendance.manage', 'attendance.correct', 'attendance.create', 'attendance.update']);
        $emp = User::factory()->create();

        $this->actingAs($admin)->postJson(route('attendance.mark-as-present'), [
            'user_id' => $emp->id, 'date' => '2026-06-18',
        ])->assertOk();

        $a = Attendance::where('user_id', $emp->id)->first();
        $this->assertSame('08:30', \Carbon\Carbon::parse($a->punchin)->format('H:i'));
    }
}
