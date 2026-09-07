<?php

namespace Tests\Feature\DailyWork;

use App\Models\DailyWork;
use App\Models\Jurisdiction;
use App\Models\User;
use App\Services\DailyWork\DailyWorkValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DailyWorkCrudContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_validation_needs_no_version_but_update_and_delete_require_current_version(): void
    {
        $actor = User::factory()->create();
        foreach (['view', 'create', 'update', 'delete'] as $action) {
            $actor->givePermissionTo(Permission::findOrCreate('daily-works.'.$action, 'web'));
        }
        $this->actingAs($actor);
        Jurisdiction::create([
            'location' => 'Test jurisdiction', 'start_chainage' => 'K0+000',
            'end_chainage' => 'K48+000', 'incharge' => $actor->getKey(),
        ]);
        $payload = [
            'date' => '2026-09-01', 'number' => 'RFI-CRUD-1',
            'planned_time' => 'Morning shift', 'status' => 'new', 'type' => 'Structure',
            'description' => 'Initial description', 'location' => 'K1+000', 'side' => 'SR-R',
        ];

        $validated = app(DailyWorkValidationService::class)->validateAddRequest(Request::create('/', 'POST', $payload));
        $this->assertArrayNotHasKey('lock_version', $validated);
        // Creation assignment policy is a separate, documented open audit gap.
        $work = DailyWork::factory()->forUsers($actor, $actor)->create($payload);
        $payload['id'] = $work->id;

        $this->actingAs($actor)->postJson(route('dailyWorks.update'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $payload['lock_version'] = 0;
        $payload['description'] = 'Winning edit';
        $this->postJson(route('dailyWorks.update'), $payload)
            ->assertOk()->assertJsonPath('dailyWork.lock_version', 1);

        $payload['description'] = 'Stale edit';
        $this->postJson(route('dailyWorks.update'), $payload)->assertStatus(409);
        $this->assertSame('Winning edit', $work->fresh()->description);

        $this->deleteJson(route('dailyWorks.delete'), ['id' => $work->id])
            ->assertUnprocessable();
        $this->deleteJson(route('dailyWorks.delete'), ['id' => $work->id, 'lock_version' => 0])
            ->assertStatus(409);
        $this->assertNotSoftDeleted($work);
        $this->deleteJson(route('dailyWorks.delete'), ['id' => $work->id, 'lock_version' => 1])
            ->assertOk();
        $this->assertSoftDeleted($work);
    }

    public function test_unrelated_record_mutations_return_forbidden_not_server_errors(): void
    {
        $actor = User::factory()->create();
        foreach (['view', 'update', 'delete'] as $action) {
            $actor->givePermissionTo(Permission::findOrCreate('daily-works.'.$action, 'web'));
        }
        $work = DailyWork::factory()->create();
        $this->actingAs($actor)->postJson(route('dailyWorks.update'), ['id' => $work->id])
            ->assertForbidden();
        $this->deleteJson(route('dailyWorks.delete'), ['id' => $work->id])
            ->assertForbidden();
    }
}
