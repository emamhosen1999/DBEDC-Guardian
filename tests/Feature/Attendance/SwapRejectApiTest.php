<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SwapRejectApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    public function test_reject_sets_status_and_does_not_write_roster(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.settings');
        $requester = User::factory()->create();

        $swap = ShiftSwapRequest::create([
            'requester_id' => $requester->id, 'requester_date' => '2026-06-19', 'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson(route('attendance.swaps.reject', $swap->id))
            ->assertOk();

        $this->assertSame('rejected', $swap->fresh()->status);
        $this->assertSame($admin->id, $swap->fresh()->approved_by);
        $this->assertSame(0, RosterDay::count()); // applySwap NOT called
    }
}
