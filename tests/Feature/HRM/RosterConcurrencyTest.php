<?php
// tests/Feature/HRM/RosterConcurrencyTest.php
namespace Tests\Feature\HRM;

use App\Models\HRM\RosterDay;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RosterConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    public function test_stale_expected_updated_at_is_rejected_with_409(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('attendance.settings');
        $cell = RosterDay::create([
            'user_id' => $user->id, 'date' => '2026-06-20',
            'shift_id' => null, 'source' => 'manual', 'locked' => true,
        ]);

        $response = $this->actingAs($user)->putJson('/attendance/roster/cell', [
            'user_id' => $user->id,
            'date' => '2026-06-20',
            'shift_id' => null,
            'expected_updated_at' => '2000-01-01T00:00:00+00:00', // stale
        ]);

        $response->assertStatus(409);
        $response->assertJsonStructure(['message', 'cell' => ['updated_at']]);
    }

    public function test_fresh_expected_updated_at_succeeds(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('attendance.settings');
        $cell = RosterDay::create([
            'user_id' => $user->id, 'date' => '2026-06-20',
            'shift_id' => null, 'source' => 'manual', 'locked' => true,
        ]);

        $response = $this->actingAs($user)->putJson('/attendance/roster/cell', [
            'user_id' => $user->id,
            'date' => '2026-06-20',
            'shift_id' => null,
            'expected_updated_at' => $cell->updated_at->toIso8601String(),
        ]);

        $response->assertOk();
    }
}
