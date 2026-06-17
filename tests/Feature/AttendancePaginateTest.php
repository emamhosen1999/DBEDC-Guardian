<?php

namespace Tests\Feature;

use App\Models\HRM\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AttendancePaginateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset cached roles and permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Create roles and permissions
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
    }

    public function test_paginate_handles_null_punchout_gracefully(): void
    {
        // Create a user with Employee role
        $user = User::factory()->create([
            'name' => 'Test Employee',
            'email' => 'employee@test.com',
        ]);
        $user->assignRole('Employee');

        // Create an admin user to make the request
        $admin = User::factory()->create([
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
        ]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        // Create an attendance record with only punch in (punchout is null)
        Attendance::create([
            'user_id' => $user->id,
            'date' => Carbon::today(),
            'punchin' => Carbon::now()->subHours(2),
            'punchout' => null, // This should not cause an error
            'punchin_location' => json_encode(['test' => 'location']),
        ]);

        // Act as admin and call the paginate endpoint
        $response = $this->actingAs($admin)
            ->getJson(route('attendancesAdmin.paginate'), [
                'currentMonth' => Carbon::now()->format('m'),
                'currentYear' => Carbon::now()->format('Y'),
            ]);

        // Assert the request was successful
        $response->assertStatus(200);

        // Assert the response has the expected structure
        $response->assertJsonStructure([
            'data',
            'total',
            'page',
            'last_page',
            'leaveTypes',
            'leaveCounts',
        ]);

        // Assert no error in the response
        $response->assertJsonMissing(['error']);
    }

    public function test_paginate_with_multiple_punches_and_null_punchouts(): void
    {
        // Create a user with Employee role
        $user = User::factory()->create([
            'name' => 'Test Employee 2',
            'email' => 'employee2@test.com',
        ]);
        $user->assignRole('Employee');

        // Create an admin user to make the request
        $admin = User::factory()->create([
            'name' => 'Test Admin 2',
            'email' => 'admin2@test.com',
        ]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        $today = Carbon::today();

        // Create multiple attendance records for the same day - some with punchout, some without
        Attendance::create([
            'user_id' => $user->id,
            'date' => $today,
            'punchin' => $today->copy()->setHour(9),
            'punchout' => $today->copy()->setHour(12),
            'punchin_location' => json_encode(['test' => 'location']),
            'punchout_location' => json_encode(['test' => 'location']),
        ]);

        Attendance::create([
            'user_id' => $user->id,
            'date' => $today,
            'punchin' => $today->copy()->setHour(13),
            'punchout' => null, // This should not cause an error
            'punchin_location' => json_encode(['test' => 'location']),
        ]);

        // Act as admin and call the paginate endpoint
        $response = $this->actingAs($admin)
            ->getJson(route('attendancesAdmin.paginate'), [
                'currentMonth' => Carbon::now()->format('m'),
                'currentYear' => Carbon::now()->format('Y'),
            ]);

        // Assert the request was successful
        $response->assertStatus(200);

        // Assert no error in the response
        $response->assertJsonMissing(['error']);
    }
}
