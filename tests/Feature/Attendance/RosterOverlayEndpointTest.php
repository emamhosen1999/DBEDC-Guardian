<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Holiday;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RosterOverlayEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
        Permission::firstOrCreate(['name' => 'attendance.own.view']);
    }

    public function test_index_merges_leave_onto_cell_and_holidays_top_level(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.settings');

        $emp = User::factory()->create();
        $shift = Shift::factory()->create(['code' => 'D']);
        $type = LeaveSetting::create(['type' => 'Annual Leave', 'symbol' => 'AL', 'days' => 20]);

        RosterDay::create(['user_id' => $emp->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern']);
        Leave::create([
            'user_id' => $emp->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-06', 'to_date' => '2026-07-06',
            'status' => 'approved', 'is_half_day' => false, 'no_of_days' => 1.0, 'reason' => 'Vacation',
        ]);
        Holiday::create([
            'title' => 'Founders Day', 'from_date' => '2026-07-07', 'to_date' => '2026-07-07',
            'type' => 'company', 'is_active' => true,
        ]);

        $res = $this->actingAs($admin)->getJson(route('attendance.roster.index', [
            'from' => '2026-07-01', 'to' => '2026-07-31',
        ]))->assertOk();

        $this->assertSame('AL', $res->json("roster.{$emp->id}.days.2026-07-06.leave.type"));
        $this->assertSame('approved', $res->json("roster.{$emp->id}.days.2026-07-06.leave.status"));
        $this->assertSame('Founders Day', $res->json('holidays.2026-07-07'));
    }

    public function test_my_roster_overlay_is_scoped_to_requester(): void
    {
        $a = User::factory()->create();
        $a->assignRole('Admin');
        $a->givePermissionTo('attendance.own.view');
        $shift = Shift::factory()->create(['code' => 'D']);
        $type = LeaveSetting::create(['type' => 'Sick', 'symbol' => 'SL', 'days' => 10]);

        RosterDay::create(['user_id' => $a->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern']);
        Leave::create([
            'user_id' => $a->id, 'leave_type' => $type->id,
            'from_date' => '2026-07-06', 'to_date' => '2026-07-06',
            'status' => 'approved', 'is_half_day' => true, 'half_day_session' => 'first_half', 'no_of_days' => 0.5, 'reason' => 'Doctor appointment',
        ]);

        $res = $this->actingAs($a)->getJson(route('attendance.myRoster', [
            'from' => '2026-07-01', 'to' => '2026-07-31',
        ]))->assertOk();

        $this->assertSame(0.5, $res->json("roster.{$a->id}.days.2026-07-06.leave.fraction"));
        $this->assertSame('first_half', $res->json("roster.{$a->id}.days.2026-07-06.leave.session"));
    }
}
