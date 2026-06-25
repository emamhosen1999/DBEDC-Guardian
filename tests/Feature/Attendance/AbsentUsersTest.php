<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
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
}
