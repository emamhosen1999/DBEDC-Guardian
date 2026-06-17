<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuthGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset cached roles and permissions
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        // Create the dashboard permission
        Permission::firstOrCreate(['name' => 'core.dashboard.view']);
    }

    public function test_unauthenticated_user_cannot_access_dashboard(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_access_dashboard(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->givePermissionTo('core.dashboard.view');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
    }

    public function test_session_check_returns_false_for_unauthenticated_user(): void
    {
        $response = $this->get('/session-check');

        $response->assertOk()
            ->assertJson(['authenticated' => false]);
    }

    public function test_session_check_returns_true_for_authenticated_user(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/session-check');

        $response->assertOk()
            ->assertJson(['authenticated' => true]);
    }

    public function test_inertia_shares_correct_auth_data_for_authenticated_user(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->givePermissionTo('core.dashboard.view');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();

        $page = $response->viewData('page');
        $this->assertNotNull($page);
        $sharedData = $page['props'];

        $this->assertTrue($sharedData['auth']['isAuthenticated']);
        $this->assertTrue($sharedData['auth']['sessionValid']);
        $this->assertNotNull($sharedData['auth']['user']);
        $this->assertEquals($user->id, $sharedData['auth']['user']['id']);
    }

    public function test_inertia_shares_correct_auth_data_for_unauthenticated_user(): void
    {
        $response = $this->get('/login');

        $response->assertOk();

        $page = $response->viewData('page');
        $this->assertNotNull($page);
        $sharedData = $page['props'];

        $this->assertFalse($sharedData['auth']['isAuthenticated']);
        $this->assertFalse($sharedData['auth']['sessionValid']);
        $this->assertNull($sharedData['auth']['user']);
    }
}
