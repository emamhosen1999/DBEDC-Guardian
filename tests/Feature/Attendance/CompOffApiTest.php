<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use App\Services\Attendance\CompOffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CompOffApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.own.view']);
    }

    public function test_employee_can_fetch_comp_off_balance(): void
    {
        $emp = User::factory()->create();
        $emp->assignRole('Employee');
        $emp->givePermissionTo('attendance.own.view');

        /** @var CompOffService $service */
        $service = app(CompOffService::class);
        $service->credit($emp->id, 120, 'overtime', null, 'Test credit');

        $response = $this->actingAs($emp)
            ->getJson(route('attendance.compoff.mine'));

        $response->assertOk()
            ->assertJsonStructure(['balance_minutes', 'entries'])
            ->assertJsonFragment(['balance_minutes' => 120]);
    }

    public function test_unauthenticated_user_cannot_fetch_comp_off(): void
    {
        $this->getJson(route('attendance.compoff.mine'))->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_fetch_comp_off(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('attendance.compoff.mine'))
            ->assertForbidden();
    }
}
