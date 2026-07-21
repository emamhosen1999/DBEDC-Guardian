<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AttendanceTeamDayEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Project Manager']);
        Role::firstOrCreate(['name' => 'Administrator']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
        Permission::firstOrCreate(['name' => 'attendance.correct']);
    }

    private function roster(User $user, string $date, ?int $shiftId): void
    {
        RosterDay::create([
            'user_id' => $user->id,
            'date' => $date,
            'shift_id' => $shiftId,
            'source' => 'manual',
            'locked' => true,
        ]);
    }

    public function test_guest_and_non_manager_are_blocked_on_mobile_team_day_and_mark_present(): void
    {
        $this->getJson('/api/v1/attendance/team-day?date=2026-07-15')->assertUnauthorized();
        $this->postJson('/api/v1/attendance/mark-present', [])->assertUnauthorized();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/attendance/team-day?date=2026-07-15')
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->postJson('/api/v1/attendance/mark-present', ['user_id' => $user->id, 'date' => '2026-07-15'])
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_manager_team_day_returns_frozen_shape_and_mark_present_flips_a_member(): void
    {
        $date = '2026-07-15';
        Carbon::setTestNow(Carbon::parse($date.' 12:00:00'));

        $morning = Shift::factory()->create(['code' => 'MRN', 'start_time' => '09:00', 'end_time' => '17:00']);

        $manager = User::factory()->create();
        $manager->assignRole('Project Manager');

        $present = User::factory()->create(['employee_id' => 'EMP-1001', 'report_to' => $manager->id]);
        $present->assignRole('Employee');
        $this->roster($present, $date, $morning->id);
        Attendance::create(['user_id' => $present->id, 'date' => $date, 'punchin' => $date.' 09:02:00']);

        $absent = User::factory()->create(['employee_id' => 'EMP-1002', 'report_to' => $manager->id]);
        $absent->assignRole('Employee');
        $this->roster($absent, $date, $morning->id);

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/attendance/team-day?date='.$date)->assertOk();
        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.date', $date)
            ->assertJsonPath('data.counts.present', 1)
            ->assertJsonPath('data.counts.absent', 1)
            ->assertJsonPath('data.counts.upcoming', 0)
            ->assertJsonPath('data.counts.off_leave', 0)
            ->assertJsonPath('data.counts.total', 2)
            ->assertJsonPath('data.present.0.user.id', $present->id)
            ->assertJsonPath('data.present.0.punch_in', '09:02')
            ->assertJsonPath('data.absent.0.user.id', $absent->id);

        // Frozen shape: present.user carries the media-backed url key, never a raw column.
        $this->assertArrayHasKey('profile_image_url', $response->json('data.present.0.user'));

        // Mark the absent member present via the mobile parity endpoint.
        $this->postJson('/api/v1/attendance/mark-present', ['user_id' => $absent->id, 'date' => $date])
            ->assertOk()
            ->assertJsonPath('success', true);

        // Idempotent: a second call does not create a duplicate row.
        $this->postJson('/api/v1/attendance/mark-present', ['user_id' => $absent->id, 'date' => $date])
            ->assertOk();
        $this->assertSame(1, Attendance::where('user_id', $absent->id)->whereDate('date', $date)->count());

        // Now both are present.
        $this->getJson('/api/v1/attendance/team-day?date='.$date)
            ->assertOk()
            ->assertJsonPath('data.counts.present', 2)
            ->assertJsonPath('data.counts.absent', 0);

        Carbon::setTestNow();
    }

    public function test_web_day_partition_returns_frozen_shape(): void
    {
        $date = '2026-07-15';
        Carbon::setTestNow(Carbon::parse($date.' 12:00:00'));

        $morning = Shift::factory()->create(['start_time' => '09:00', 'end_time' => '17:00']);

        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $admin->givePermissionTo('attendance.view');

        $emp = User::factory()->create();
        $emp->assignRole('Employee');
        $this->roster($emp, $date, $morning->id);

        $response = $this->actingAs($admin)
            ->getJson(route('attendance.dayPartition', ['date' => $date]))
            ->assertOk();

        $response->assertJsonPath('date', $date)
            ->assertJsonPath('counts.total', 1)
            ->assertJsonPath('counts.absent', 1)
            ->assertJsonPath('absent.0.user.id', $emp->id)
            ->assertJsonPath('absent.0.shift.start', '09:00');

        Carbon::setTestNow();
    }
}
