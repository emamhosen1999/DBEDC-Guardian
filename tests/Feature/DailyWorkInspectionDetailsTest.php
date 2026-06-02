<?php

namespace Tests\Feature;

use App\Models\DailyWork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DailyWorkInspectionDetailsTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $adminUser;

    private User $inchargeUser;

    private User $assignedUser;

    private User $unauthorizedUser;

    private DailyWork $dailyWork;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Create necessary roles and permissions
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Administrator']);
        Permission::firstOrCreate(['name' => 'daily-works.view']);
        Permission::firstOrCreate(['name' => 'daily-works.update']);

        // Create users
        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('Administrator');
        $this->adminUser->givePermissionTo(['daily-works.view', 'daily-works.update']);

        $this->inchargeUser = User::factory()->create();
        $this->inchargeUser->givePermissionTo(['daily-works.view', 'daily-works.update']);

        $this->assignedUser = User::factory()->create();
        $this->assignedUser->givePermissionTo(['daily-works.view', 'daily-works.update']);

        $this->unauthorizedUser = User::factory()->create();

        // Create daily work
        $this->dailyWork = DailyWork::factory()
            ->forIncharge($this->inchargeUser)
            ->forAssigned($this->assignedUser)
            ->create([
                'inspection_details' => null,
            ]);
    }

    /** @test */
    public function admin_can_update_inspection_details(): void
    {
        $newInspectionDetails = 'Quality check completed. All components meet specifications.';

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('dailyWorks.updateInspectionDetails'), [
                'id' => $this->dailyWork->id,
                'inspection_details' => $newInspectionDetails,
            ]);

        $response->assertOk();
        $response->assertJsonFragment(['message' => 'Inspection details updated successfully']);

        $this->dailyWork->refresh();
        $this->assertEquals($newInspectionDetails, $this->dailyWork->inspection_details);
    }

    /** @test */
    public function incharge_can_update_inspection_details(): void
    {
        $newInspectionDetails = 'Incharge review completed.';

        $response = $this->actingAs($this->inchargeUser)
            ->postJson(route('dailyWorks.updateInspectionDetails'), [
                'id' => $this->dailyWork->id,
                'inspection_details' => $newInspectionDetails,
            ]);

        $response->assertOk();

        $this->dailyWork->refresh();
        $this->assertEquals($newInspectionDetails, $this->dailyWork->inspection_details);
    }

    /** @test */
    public function assigned_user_can_update_inspection_details(): void
    {
        $newInspectionDetails = 'Assigned user update.';

        $response = $this->actingAs($this->assignedUser)
            ->postJson(route('dailyWorks.updateInspectionDetails'), [
                'id' => $this->dailyWork->id,
                'inspection_details' => $newInspectionDetails,
            ]);

        $response->assertOk();

        $this->dailyWork->refresh();
        $this->assertEquals($newInspectionDetails, $this->dailyWork->inspection_details);
    }

    /** @test */
    public function it_can_clear_inspection_details(): void
    {
        $this->dailyWork->update(['inspection_details' => 'Existing details']);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('dailyWorks.updateInspectionDetails'), [
                'id' => $this->dailyWork->id,
                'inspection_details' => '',
            ]);

        $response->assertOk();

        $this->dailyWork->refresh();
        $this->assertNull($this->dailyWork->inspection_details);
    }

    /** @test */
    public function it_validates_inspection_details_max_length(): void
    {
        $longText = str_repeat('A', 1001); // 1001 characters, exceeds max 1000

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('dailyWorks.updateInspectionDetails'), [
                'id' => $this->dailyWork->id,
                'inspection_details' => $longText,
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['inspection_details']);
    }

    /** @test */
    public function it_accepts_exactly_1000_character_inspection_details(): void
    {
        $exactlyThousandChars = str_repeat('A', 1000);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('dailyWorks.updateInspectionDetails'), [
                'id' => $this->dailyWork->id,
                'inspection_details' => $exactlyThousandChars,
            ]);

        $response->assertOk();

        $this->dailyWork->refresh();
        $this->assertEquals($exactlyThousandChars, $this->dailyWork->inspection_details);
    }

    /** @test */
    public function it_requires_authentication(): void
    {
        $response = $this->postJson(route('dailyWorks.updateInspectionDetails'), [
            'id' => $this->dailyWork->id,
            'inspection_details' => 'Test details',
        ]);

        $response->assertUnauthorized();
    }

    /** @test */
    public function it_validates_daily_work_exists(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('dailyWorks.updateInspectionDetails'), [
                'id' => 99999,
                'inspection_details' => 'Test details',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['id']);
    }

    /** @test */
    public function it_accepts_null_inspection_details(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('dailyWorks.updateInspectionDetails'), [
                'id' => $this->dailyWork->id,
                'inspection_details' => null,
            ]);

        $response->assertOk();

        $this->dailyWork->refresh();
        $this->assertNull($this->dailyWork->inspection_details);
    }

    /** @test */
    public function unauthorized_user_cannot_update_inspection_details(): void
    {
        $response = $this->actingAs($this->unauthorizedUser)
            ->postJson(route('dailyWorks.updateInspectionDetails'), [
                'id' => $this->dailyWork->id,
                'inspection_details' => 'Attempted update',
            ]);

        $response->assertForbidden();
    }
}

