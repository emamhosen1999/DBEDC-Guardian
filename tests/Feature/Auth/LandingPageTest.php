<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** After login nobody lands on a 403: the first page they may open is their home. */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['core.dashboard.view', 'om.dashboard.view'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_home_sends_each_person_to_the_first_page_they_may_open(): void
    {
        $this->actingAs($this->userWith(['core.dashboard.view']))->get('/home')->assertRedirect(route('dashboard'));
        $this->actingAs($this->userWith(['om.dashboard.view']))->get('/home')->assertRedirect(route('om.dashboard'));
        $this->actingAs($this->userWith([]))->get('/home')->assertRedirect(route('employee-dashboard'));
    }

    public function test_a_director_without_the_dashboard_permission_never_lands_on_a_403(): void
    {
        $director = $this->userWith(['om.dashboard.view']);
        $this->actingAs($director)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($director)->get('/')->assertRedirect('/home');
        $this->actingAs($director)->get(route('login'))->assertRedirect(route('om.dashboard'));
    }
}
