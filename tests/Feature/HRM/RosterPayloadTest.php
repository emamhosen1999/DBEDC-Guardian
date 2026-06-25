<?php
// tests/Feature/HRM/RosterPayloadTest.php
namespace Tests\Feature\HRM;

use App\Models\HRM\RosterDay;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RosterPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    public function test_roster_cell_payload_includes_updated_at(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('attendance.settings');
        $cell = RosterDay::create([
            'user_id' => $user->id,
            'date' => '2026-06-20',
            'shift_id' => null,
            'source' => 'manual',
            'locked' => true,
        ]);

        $response = $this->actingAs($user)->getJson(
            '/attendance/roster?from=2026-06-01&to=2026-06-30'
        );

        $response->assertOk();
        $cellPayload = $response->json("roster.{$user->id}.days.2026-06-20");
        $this->assertArrayHasKey('updated_at', $cellPayload);
        $this->assertSame($cell->updated_at->toIso8601String(), $cellPayload['updated_at']);
    }
}
