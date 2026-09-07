<?php

namespace Tests\Feature\DailyWork;

use App\Models\DailyWork;
use App\Models\RfiObjection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DailyWorkOptimisticConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_web_status_write_returns_conflict_and_preserves_winner(): void
    {
        $user = $this->dailyWorkUser();
        $dailyWork = DailyWork::factory()->forUsers($user, $user)->create([
            'status' => DailyWork::STATUS_NEW,
        ]);

        $this->actingAs($user)->postJson(route('dailyWorks.updateStatus'), [
            'id' => $dailyWork->id,
            'status' => DailyWork::STATUS_IN_PROGRESS,
            'lock_version' => 0,
        ])->assertOk()
            ->assertJsonPath('dailyWork.lock_version', 1);

        $this->actingAs($user)->postJson(route('dailyWorks.updateStatus'), [
            'id' => $dailyWork->id,
            'status' => DailyWork::STATUS_COMPLETED,
            'inspection_result' => DailyWork::INSPECTION_PASS,
            'lock_version' => 0,
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'STALE_WRITE')
            ->assertJsonPath('current_version', 1);

        $this->assertDatabaseHas('daily_works', [
            'id' => $dailyWork->id,
            'status' => DailyWork::STATUS_IN_PROGRESS,
            'lock_version' => 1,
        ]);
    }

    public function test_stale_objection_edit_returns_conflict_and_preserves_winner(): void
    {
        $user = $this->dailyWorkUser();
        $dailyWork = DailyWork::factory()->forUsers($user, $user)->create();
        $objection = new RfiObjection([
            'title' => 'Original title',
            'category' => RfiObjection::CATEGORY_OTHER,
            'description' => 'Original description',
            'reason' => 'Original reason',
            'status' => RfiObjection::STATUS_DRAFT,
            'created_by' => (string) $user->id,
        ]);
        $objection->setAttribute('daily_work_id', $dailyWork->id);
        $objection->save();

        $this->actingAs($user)->putJson(route('objections.update', $objection), [
            'title' => 'Winning title',
            'lock_version' => 0,
        ])->assertOk()
            ->assertJsonPath('objection.title', 'Winning title')
            ->assertJsonPath('objection.lock_version', 1);

        $this->actingAs($user)->putJson(route('objections.update', $objection), [
            'title' => 'Stale title',
            'lock_version' => 0,
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'STALE_WRITE')
            ->assertJsonPath('current_version', 1);

        $this->assertDatabaseHas('rfi_objections', [
            'id' => $objection->id,
            'title' => 'Winning title',
            'lock_version' => 1,
        ]);
    }

    public function test_bulk_status_update_is_available_and_rejects_the_entire_stale_selection(): void
    {
        $user = $this->dailyWorkUser();
        $first = DailyWork::factory()->forUsers($user, $user)->create(['status' => DailyWork::STATUS_NEW]);
        $second = DailyWork::factory()->forUsers($user, $user)->create(['status' => DailyWork::STATUS_NEW]);

        $this->actingAs($user)->postJson(route('dailyWorks.updateStatus'), [
            'id' => $first->id,
            'status' => DailyWork::STATUS_PENDING,
            'lock_version' => 0,
        ])->assertOk();

        $this->actingAs($user)->postJson(route('dailyWorks.bulkUpdateStatus'), [
            'work_ids' => [$first->id, $second->id],
            'versions' => [$first->id => 0, $second->id => 0],
            'status' => DailyWork::STATUS_COMPLETED,
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'STALE_WRITE');

        $this->assertDatabaseHas('daily_works', [
            'id' => $first->id,
            'status' => DailyWork::STATUS_PENDING,
            'lock_version' => 1,
        ]);
        $this->assertDatabaseHas('daily_works', [
            'id' => $second->id,
            'status' => DailyWork::STATUS_NEW,
            'lock_version' => 0,
        ]);

        $this->actingAs($user)->postJson(route('dailyWorks.bulkUpdateStatus'), [
            'work_ids' => [$first->id, $second->id],
            'versions' => [$first->id => 1, $second->id => 0],
            'status' => DailyWork::STATUS_COMPLETED,
        ])->assertOk()
            ->assertJsonPath('updated_count', 2);

        $this->assertDatabaseHas('daily_works', [
            'id' => $first->id,
            'status' => DailyWork::STATUS_COMPLETED,
            'lock_version' => 2,
        ]);
        $this->assertDatabaseHas('daily_works', [
            'id' => $second->id,
            'status' => DailyWork::STATUS_COMPLETED,
            'lock_version' => 1,
        ]);
    }

    public function test_bulk_delete_soft_deletes_every_authorized_selected_work(): void
    {
        $user = $this->dailyWorkUser();
        $user->givePermissionTo(Permission::findOrCreate('daily-works.delete', 'web'));
        $first = DailyWork::factory()->forUsers($user, $user)->create();
        $second = DailyWork::factory()->forUsers($user, $user)->create();

        $this->actingAs($user)->postJson(route('dailyWorks.bulkDelete'), [
            'work_ids' => [$first->id, $second->id],
            'versions' => [$first->id => 0, $second->id => 0],
        ])->assertOk()
            ->assertJsonPath('deleted_count', 2);

        $this->assertSoftDeleted($first);
        $this->assertSoftDeleted($second);
    }

    private function dailyWorkUser(): User
    {
        $user = User::factory()->create();
        $permission = Permission::findOrCreate('daily-works.view', 'web');
        $user->givePermissionTo($permission);

        return $user;
    }
}
