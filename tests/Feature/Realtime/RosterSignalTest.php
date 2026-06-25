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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
