<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MobileRosterApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Employee']);
    }

    public function test_manager_sees_the_whole_team_roster(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Admin');

        $member = User::factory()->create(['name' => 'Team Member', 'report_to' => $manager->id]);
        $shift = Shift::factory()->create(['code' => 'M', 'start_time' => '08:00', 'end_time' => '16:00']);

        RosterDay::create([
            'user_id' => $member->id, 'date' => '2026-07-15', 'shift_id' => $shift->id, 'source' => 'manual',
        ]);

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/attendance/roster?from=2026-07-01&to=2026-07-31')
            ->assertOk()
            ->assertJsonPath("data.roster.{$member->id}.name", 'Team Member')
            ->assertJsonPath("data.roster.{$member->id}.days.2026-07-15.code", 'M')
            ->assertJsonPath("data.roster.{$member->id}.days.2026-07-15.off", false);
    }

    public function test_non_manager_sees_only_their_own_roster(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Admin');

        $employee = User::factory()->create(['report_to' => $manager->id]);
        $employee->assignRole('Employee');
        $colleague = User::factory()->create(['report_to' => $manager->id]);

        $shift = Shift::factory()->create(['code' => 'N', 'start_time' => '00:00', 'end_time' => '08:00']);
        RosterDay::create(['user_id' => $employee->id, 'date' => '2026-07-15', 'shift_id' => $shift->id, 'source' => 'manual']);
        RosterDay::create(['user_id' => $colleague->id, 'date' => '2026-07-15', 'shift_id' => $shift->id, 'source' => 'manual']);

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/v1/attendance/roster?from=2026-07-01&to=2026-07-31')->assertOk();

        $this->assertSame([(string) $employee->id], array_keys($response->json('data.roster')));
    }

    public function test_shifts_catalog_is_ordered_by_start_time(): void
    {
        $user = User::factory()->create();

        Shift::factory()->create(['code' => 'E', 'start_time' => '16:00', 'end_time' => '23:59']);
        Shift::factory()->create(['code' => 'N', 'start_time' => '00:00', 'end_time' => '08:00']);
        Shift::factory()->create(['code' => 'M', 'start_time' => '08:00', 'end_time' => '16:00']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/attendance/shifts')->assertOk();

        $this->assertSame(['N', 'M', 'E'], collect($response->json('data.shifts'))->pluck('code')->all());
    }
}
