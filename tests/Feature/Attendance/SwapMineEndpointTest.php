<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Department;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SwapMineEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.own.view']);
        $this->dept = Department::factory()->create();
    }

    private function employee(): User
    {
        $u = User::factory()->create(['department_id' => $this->dept->id]);
        $u->assignRole('Employee');
        $u->givePermissionTo('attendance.own.view');

        return $u;
    }

    public function test_mine_lists_only_swaps_i_requested_with_counterparty_name(): void
    {
        $me = $this->employee();
        $mate = $this->employee();
        $other = $this->employee();

        ShiftSwapRequest::create([
            'type' => 'cover', 'requester_id' => $me->id, 'requester_date' => '2026-07-01',
            'counterparty_id' => $mate->id, 'counterparty_status' => 'pending', 'status' => 'pending',
        ]);
        // A swap where I am the counterparty (someone else requested) must NOT appear in "mine".
        ShiftSwapRequest::create([
            'type' => 'cover', 'requester_id' => $other->id, 'requester_date' => '2026-07-02',
            'counterparty_id' => $me->id, 'counterparty_status' => 'pending', 'status' => 'pending',
        ]);

        $res = $this->actingAs($me)->getJson(route('attendance.swaps.mine'))->assertOk();

        $swaps = $res->json('swaps');
        $this->assertCount(1, $swaps);
        $this->assertSame($me->id, $swaps[0]['requester_id']);
        $this->assertSame($mate->id, $swaps[0]['counterparty_id']);
        $this->assertSame($mate->name, $swaps[0]['counterparty']['name']);
    }
}
