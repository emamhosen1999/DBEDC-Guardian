<?php
// tests/Feature/Realtime/RosterSignalTest.php
namespace Tests\Feature\Realtime;

use App\Models\User;
use App\Services\Realtime\RealtimeSignal;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RosterSignalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'attendance.settings', 'guard_name' => 'web']);
    }

    public function test_update_cell_publishes_roster_signal_for_month(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('attendance.settings');

        $mock = Mockery::mock(RealtimeSignal::class);
        // NOTE: controller calls touch() with 3 args (action defaults to 'update'),
        // so the Mockery expectation must be 3 args — Mockery matches ACTUAL passed args.
        $mock->shouldReceive('touch')->once()->with('roster', '2026-06', $user->id);
        $this->app->instance(RealtimeSignal::class, $mock);

        $this->actingAs($user)->putJson('/attendance/roster/cell', [
            'user_id' => $user->id, 'date' => '2026-06-20', 'shift_id' => null,
        ])->assertOk();
    }

    public function test_generate_publishes_signal_for_each_month_in_range(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('attendance.settings');

        // Isolate the signal logic from real roster generation.
        $roster = Mockery::mock(\App\Services\Attendance\RosterService::class);
        $roster->shouldReceive('generateRoster')->once()->andReturn(3);
        $this->app->instance(\App\Services\Attendance\RosterService::class, $roster);

        $signals = Mockery::mock(RealtimeSignal::class);
        $signals->shouldReceive('touch')->once()->with('roster', '2026-06', $user->id);
        $signals->shouldReceive('touch')->once()->with('roster', '2026-07', $user->id);
        $this->app->instance(RealtimeSignal::class, $signals);

        $this->actingAs($user)->postJson('/attendance/roster/generate', [
            'user_ids' => [$user->id],
            'from' => '2026-06-15',
            'to' => '2026-07-10',
        ])->assertOk();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
