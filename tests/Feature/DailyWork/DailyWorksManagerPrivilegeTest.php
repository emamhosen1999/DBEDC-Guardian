<?php

namespace Tests\Feature\DailyWork;

use App\Models\User;
use App\Services\Project\DailyWorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The privileged check named 'Daily Work Manager', a role that does not exist; the catalog role is 'Daily Works Manager'. */
class DailyWorksManagerPrivilegeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_daily_works_manager_is_privileged_and_a_contributor_is_not(): void
    {
        Role::findOrCreate('Daily Works Manager', 'web');
        Role::findOrCreate('Daily Works Contributor', 'web');
        $manager = User::factory()->create();
        $manager->assignRole('Daily Works Manager');
        $contributor = User::factory()->create();
        $contributor->assignRole('Daily Works Contributor');

        $service = app(DailyWorkService::class);

        $this->assertTrue($service->isPrivilegedUser($manager));
        $this->assertFalse($service->isPrivilegedUser($contributor));
    }
}
