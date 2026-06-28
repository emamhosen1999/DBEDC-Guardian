<?php
// tests/Feature/Monitoring/MonitoringRoleSeederTest.php
namespace Tests\Feature\Monitoring;

use Database\Seeders\MonitoringRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MonitoringRoleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_four_monitoring_roles(): void
    {
        $this->seed(MonitoringRoleSeeder::class);

        foreach ([
            'Monitoring Operator',
            'Monitoring Supervisor',
            'Monitoring Manager',
            'Monitoring Viewer',
        ] as $role) {
            $this->assertTrue(
                Role::where('name', $role)->where('guard_name', 'web')->exists(),
                "Missing role: {$role}"
            );
        }
    }

    public function test_seeding_twice_is_idempotent(): void
    {
        $this->seed(MonitoringRoleSeeder::class);
        $this->seed(MonitoringRoleSeeder::class);

        $this->assertSame(1, Role::where('name', 'Monitoring Operator')->count());
    }
}
