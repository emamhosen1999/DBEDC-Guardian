<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MobileUpcomingUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Employee']);
    }

    private function report(User $manager, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'report_to' => $manager->id]);
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

    public function test_upcoming_users_are_sorted_by_shift_start(): void
    {
        Carbon::setTestNow('2026-07-14 07:00:00');

        $manager = User::factory()->create();
        $manager->assignRole('Admin');

        $evening = $this->report($manager, 'Evening Person');
        $morning = $this->report($manager, 'Morning Person');

        $this->roster($evening, 'E', '16:00', '23:59', '2026-07-14');
        $this->roster($morning, 'M', '08:00', '16:00', '2026-07-14');

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/attendance/absent-users?date=2026-07-14')->assertOk();

        $this->assertSame(
            ['M', 'E'],
            collect($response->json('upcoming_users'))->pluck('shift_code')->all()
        );
        $response->assertJsonPath('upcoming_visible', true);
    }

    public function test_upcoming_is_hidden_on_a_past_date(): void
    {
        Carbon::setTestNow('2026-07-14 07:00:00');

        $manager = User::factory()->create();
        $manager->assignRole('Admin');
        $this->roster($this->report($manager, 'Yesterday Person'), 'M', '08:00', '16:00', '2026-07-13');

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/attendance/absent-users?date=2026-07-13')
            ->assertOk()
            ->assertJsonPath('upcoming_visible', false)
            ->assertJsonPath('upcoming_users', []);
    }
}
