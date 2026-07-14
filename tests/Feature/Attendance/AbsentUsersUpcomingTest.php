<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AbsentUsersUpcomingTest extends TestCase
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

    private function employee(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole('Employee');

        return $user;
    }

    private function roster(User $user, string $code, string $start, string $end, string $date): void
    {
        $shift = Shift::factory()->create([
            'code' => $code, 'name' => $code, 'start_time' => $start, 'end_time' => $end,
        ]);

        RosterDay::create([
            'user_id' => $user->id, 'date' => $date, 'shift_id' => $shift->id, 'source' => 'manual',
        ]);
    }

    public function test_upcoming_is_sorted_by_shift_start_and_visible_today(): void
    {
        Carbon::setTestNow('2026-07-14 07:00:00');

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        $evening = $this->employee('Evening Person');
        $morning = $this->employee('Morning Person');

        $this->roster($evening, 'E', '16:00', '23:59', '2026-07-14');
        $this->roster($morning, 'M', '08:00', '16:00', '2026-07-14');

        $response = $this->actingAs($admin)
            ->getJson(route('admin.getAbsentUsersForDate', ['date' => '2026-07-14']))
            ->assertOk();

        $response->assertJsonPath('upcoming_visible', true);
        $this->assertSame(
            ['M', 'E'],
            collect($response->json('upcoming_users'))->pluck('shift_code')->all()
        );
    }

    public function test_upcoming_is_hidden_and_empty_on_a_past_date(): void
    {
        Carbon::setTestNow('2026-07-14 07:00:00');

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        $this->roster($this->employee('Yesterday Person'), 'M', '08:00', '16:00', '2026-07-13');

        $this->actingAs($admin)
            ->getJson(route('admin.getAbsentUsersForDate', ['date' => '2026-07-13']))
            ->assertOk()
            ->assertJsonPath('upcoming_visible', false)
            ->assertJsonPath('upcoming_users', []);
    }
}
