<?php

namespace Tests\Feature;

use App\Models\DailyWork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_search_returns_only_authorized_matching_groups(): void
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate('daily-works.view', 'web'));

        DailyWork::factory()->forUsers($actor, $actor)->create([
            'number' => 'RFI-SEARCH-101',
            'description' => 'Authorized pavement inspection',
        ]);
        DailyWork::factory()->create([
            'number' => 'RFI-SEARCH-SECRET',
            'description' => 'Unrelated restricted record',
        ]);

        $this->actingAs($actor)
            ->get(route('search', ['q' => 'RFI-SEARCH']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Search/Index')
                ->where('query', 'RFI-SEARCH')
                ->has('groups', 1)
                ->where('groups.0.key', 'daily_works')
                ->has('groups.0.items', 1)
                ->where('groups.0.items.0.title', 'RFI-SEARCH-101')
            );
    }

    public function test_search_requires_authentication_and_ignores_too_short_terms(): void
    {
        $this->get(route('search', ['q' => 'x']))->assertRedirect();

        $actor = User::factory()->create();
        $this->actingAs($actor)
            ->get(route('search', ['q' => 'x']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Search/Index')
                ->where('query', 'x')
                ->has('groups', 0)
            );
    }

    public function test_search_finds_an_older_authorized_record_after_many_unrelated_matches(): void
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate('daily-works.view', 'web'));
        $work = DailyWork::factory()->forUsers($actor, $actor)->create([
            'number' => 'RFI-ARCHIVE-OWN',
            'date' => '2026-01-01',
        ]);
        DailyWork::factory()->count(35)->create([
            'number' => 'RFI-ARCHIVE-OTHER',
            'date' => '2026-09-01',
        ]);

        $this->actingAs($actor)->get(route('search', ['q' => 'RFI-ARCHIVE']))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('groups', 1)
            ->has('groups.0.items', 1)
            ->where('groups.0.items.0.title', $work->number)
            ->where('groups.0.items.0.url', route('daily-works-unified', [
                'search' => $work->number, 'date' => '2026-01-01',
            ]))
            );
    }

    public function test_super_administrator_can_search_without_explicit_permission_assignments(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole(Role::findOrCreate('Super Administrator', 'web'));
        $employee = User::factory()->create(['name' => 'Unique Search Employee']);

        $this->actingAs($actor)->get(route('search', ['q' => 'Unique Search']))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('groups', 1)
            ->where('groups.0.key', 'employees')
            ->where('groups.0.items.0.id', (string) $employee->getKey())
            );
    }

    public function test_account_without_module_permissions_gets_no_results_not_a_server_error(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor)->get(route('search', ['q' => 'Unprivileged search']))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('groups', 0));
    }
}
