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

class MyRosterScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.own.view']);
    }

    public function test_my_roster_returns_only_the_requesting_users_days(): void
    {
        $a = User::factory()->create();
        $a->assignRole('Employee');
        $a->givePermissionTo('attendance.own.view');

        $b = User::factory()->create();

        $shiftA = Shift::factory()->create(['code' => 'AAA']);
        $shiftB = Shift::factory()->create(['code' => 'BBB']);

        RosterDay::create(['user_id' => $a->id, 'date' => '2026-06-19', 'shift_id' => $shiftA->id, 'source' => 'pattern']);
        RosterDay::create(['user_id' => $b->id, 'date' => '2026-06-19', 'shift_id' => $shiftB->id, 'source' => 'pattern']);

        $res = $this->actingAs($a)->getJson(route('attendance.myRoster', [
            'from' => '2026-06-19',
            'to' => '2026-06-19',
        ]));

        $res->assertOk();

        $roster = $res->json('roster');

        $this->assertArrayHasKey((string) $a->id, $roster);
        $this->assertArrayNotHasKey((string) $b->id, $roster);
    }
}
