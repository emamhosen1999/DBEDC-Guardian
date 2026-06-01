<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemMonitoringControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear Spatie cached permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Ensure default roles exist
        Role::findOrCreate('Super Administrator');
        Role::findOrCreate('Employee');
    }

    /**
     * Test guest is redirected to login.
     */
    public function test_guest_is_redirected_to_login()
    {
        $this->get(route('admin.system-monitoring'))->assertRedirect(route('login'));
        $this->get(route('api.system-monitoring.overview'))->assertStatus(401);
        $this->get(route('api.system-monitoring.metrics'))->assertStatus(401);
        $this->get(route('admin.optimization-report'))->assertRedirect(route('login'));
    }

    /**
     * Test unauthorized user is forbidden.
     */
    public function test_unauthorized_user_is_forbidden()
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $this->actingAs($user);

        $this->get(route('admin.system-monitoring'))->assertStatus(403);
        $this->get(route('api.system-monitoring.overview'))->assertStatus(403);
        $this->get(route('api.system-monitoring.metrics'))->assertStatus(403);
        $this->get(route('admin.optimization-report'))->assertStatus(403);
    }

    /**
     * Test admin can access monitoring dashboard index.
     */
    public function test_super_admin_can_access_dashboard()
    {
        $user = User::factory()->create();
        $user->assignRole('Super Administrator');
        $this->actingAs($user);

        $response = $this->get(route('admin.system-monitoring'));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Administration/SystemMonitoringEnhanced')
            ->has('initialData')
        );
    }

    /**
     * Test api overview returns structure.
     */
    public function test_super_admin_can_access_overview_api()
    {
        $user = User::factory()->create();
        $user->assignRole('Super Administrator');
        $this->actingAs($user);

        $response = $this->get(route('api.system-monitoring.overview'));
        $response->assertStatus(200);
        
        // Assert JSON structure returned from system overview
        $response->assertJsonStructure([
            'performance_summary',
            'error_summary',
            'user_activity',
            'system_health',
            'database_stats',
            'system_resources',
            'security_metrics',
            'capacity_planning',
            'service_availability',
            'compliance_metrics',
        ]);
    }

    /**
     * Test api metrics returns structure.
     */
    public function test_super_admin_can_access_metrics_api()
    {
        $user = User::factory()->create();
        $user->assignRole('Super Administrator');
        $this->actingAs($user);

        // Seed some performance metrics
        \Illuminate\Support\Facades\DB::table('performance_metrics')->insert([
            [
                'metric_type' => 'api_response',
                'identifier' => 'test-api',
                'execution_time_ms' => 150,
                'created_at' => now(),
            ],
            [
                'metric_type' => 'page_load',
                'identifier' => 'test-page',
                'execution_time_ms' => 250,
                'created_at' => now(),
            ]
        ]);

        // Test Default/Overview
        $this->get(route('api.system-monitoring.metrics'))
            ->assertStatus(200)
            ->assertJsonStructure([
                'performance_summary',
                'error_summary',
                'user_activity',
                'system_health',
                'database_stats',
            ]);

        // Test performance
        $this->get(route('api.system-monitoring.metrics', ['type' => 'performance', 'period' => '24h']))
            ->assertStatus(200)
            ->assertJsonStructure([
                'api_response' => [
                    '*' => [
                        'hour',
                        'metric_type',
                        'avg_time',
                        'request_count'
                    ]
                ],
                'page_load' => [
                    '*' => [
                        'hour',
                        'metric_type',
                        'avg_time',
                        'request_count'
                    ]
                ]
            ]);

        // Test errors
        $this->get(route('api.system-monitoring.metrics', ['type' => 'errors', 'period' => '24h']))
            ->assertStatus(200)
            ->assertJsonStructure([
                'error_trends',
                'error_types',
            ]);

        // Test users
        $this->get(route('api.system-monitoring.metrics', ['type' => 'users', 'period' => '24h']))
            ->assertStatus(200)
            ->assertJsonStructure([
                'user_activity',
                'top_users',
            ]);

        // Test system
        $this->get(route('api.system-monitoring.metrics', ['type' => 'system']))
            ->assertStatus(200)
            ->assertJsonStructure([
                'server_load',
                'memory_usage',
                'uptime',
            ]);
    }

    /**
     * Test optimization report endpoint.
     */
    public function test_super_admin_can_access_optimization_report()
    {
        $user = User::factory()->create();
        $user->assignRole('Super Administrator');
        $this->actingAs($user);

        $response = $this->get(route('admin.optimization-report'));
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'dependencies',
            'database_optimization',
            'file_system',
            'performance_bottlenecks',
            'security_recommendations',
            'cache_analysis',
            'recommendations',
        ]);
    }
}
