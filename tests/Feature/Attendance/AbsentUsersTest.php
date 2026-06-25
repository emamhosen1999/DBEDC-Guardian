<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AbsentUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        
        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
    }

    public function test_rostered_off_employee_is_returned_as_off_user_instead_of_absent(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        // Create two employees
        $empWorking = User::factory()->create();
        $empWorking->assignRole('Employee');

        $empOff = User::factory()->create();
        $empOff->assignRole('Employee');

        // Roster the second employee OFF for 2026-06-19
        RosterDay::create([
            'user_id' => $empOff->id,
            'date' => '2026-06-19',
            'shift_id' => null, // null shift = off
            'source' => 'manual',
            'locked' => true,
        ]);

        // Query getAbsentUsersForDate for 2026-06-19
        $response = $this->actingAs($admin)
            ->getJson(route('admin.getAbsentUsersForDate', ['date' => '2026-06-19']))
            ->assertOk();

        // The working employee who hasn't punched in should be in absent_users
        $response->assertJsonFragment([
            'id' => $empWorking->id,
            'name' => $empWorking->name,
        ]);

        // The off employee should not be in absent_users, but in off_users
        $response->assertJsonMissing([
            'absent_users' => [
                ['id' => $empOff->id]
            ]
        ]);

        $response->assertJsonFragment([
            'id' => $empOff->id,
            'name' => $empOff->name,
        ]);

        $response->assertJsonPath('total_absent', 1);
        $response->assertJsonPath('total_off', 1);
    }

    public function test_upcoming_shift_employee_is_returned_as_upcoming_user_when_viewed_before_start(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        $emp = User::factory()->create();
        $emp->assignRole('Employee');

        // Create a shift starting at 20:00 (8:00 PM)
        $shift = Shift::factory()->create([
            'start_time' => '20:00',
            'end_time' => '08:00',
        ]);

        // Roster this shift for the employee today (2026-06-25)
        RosterDay::create([
            'user_id' => $emp->id,
            'date' => '2026-06-25',
            'shift_id' => $shift->id,
            'source' => 'manual',
            'locked' => true,
        ]);

        // Scenario A: Viewed at 14:00 (2:00 PM) - before shift starts
        Carbon::setTestNow(Carbon::parse('2026-06-25 14:00:00'));

        $response = $this->actingAs($admin)
            ->getJson(route('admin.getAbsentUsersForDate', ['date' => '2026-06-25']))
            ->assertOk();

        // Should be in upcoming_users, not absent_users
        $response->assertJsonFragment([
            'id' => $emp->id,
            'name' => $emp->name,
            'shift_start_time' => '8:00 PM',
        ]);
        $response->assertJsonPath('total_absent', 0);
        $response->assertJsonPath('total_upcoming', 1);

        // Scenario B: Viewed at 21:00 (9:00 PM) - after shift starts
        Carbon::setTestNow(Carbon::parse('2026-06-25 21:00:00'));

        $response = $this->actingAs($admin)
            ->getJson(route('admin.getAbsentUsersForDate', ['date' => '2026-06-25']))
            ->assertOk();

        // Should now be in absent_users, not upcoming_users
        $response->assertJsonFragment([
            'id' => $emp->id,
            'name' => $emp->name,
        ]);
        $response->assertJsonPath('total_absent', 1);
        $response->assertJsonPath('total_upcoming', 0);

        // Reset test now
        Carbon::setTestNow();
    }
}
