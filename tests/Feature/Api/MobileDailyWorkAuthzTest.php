<?php

namespace Tests\Feature\Api;

use App\Models\DailyWork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Privilege-escalation regression coverage for the mobile daily-work mutation
 * endpoints (H-5).
 *
 * The bug: PATCH /api/v1/daily-works/{id}/status gated on canAccessDailyWork()
 * — the SAME predicate used for READ visibility — so any user who could merely
 * VIEW a daily work (e.g. because their manager is its incharge) could also
 * change its status. Mutation authorization must be distinct from read
 * visibility: only a privileged manager, or the record's own incharge/assigned
 * worker, may mutate.
 */
class MobileDailyWorkAuthzTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A viewer-only user (can SEE the work because their manager is its
     * incharge, but is neither incharge, assigned, nor privileged) must be
     * blocked from every mutation endpoint.
     */
    public function test_viewer_only_user_is_forbidden_from_mutating_daily_work(): void
    {
        $incharge = User::factory()->create();

        // Viewer reports to the incharge, so canAccessDailyWork() grants READ,
        // but the viewer has no mutation authority over the record.
        $viewer = User::factory()->create([
            'report_to' => $incharge->id,
        ]);

        $dailyWork = DailyWork::factory()->forUsers($incharge, $incharge)->newStatus()->create();

        Sanctum::actingAs($viewer);

        // Sanity check: the viewer genuinely CAN read the record...
        $this->getJson('/api/v1/daily-works/'.$dailyWork->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $dailyWork->id);

        // ...but must NOT be able to change its status (the escalation).
        $this->patchJson('/api/v1/daily-works/'.$dailyWork->id.'/status', [
            'status' => DailyWork::STATUS_COMPLETED,
            'inspection_result' => DailyWork::INSPECTION_PASS,
        ])->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to update the status of this daily work.');

        // ...nor reassign the incharge...
        $this->patchJson('/api/v1/daily-works/'.$dailyWork->id.'/incharge', [
            'incharge' => $viewer->id,
        ])->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to update incharge for this daily work.');

        // ...nor reassign the assignee.
        $this->patchJson('/api/v1/daily-works/'.$dailyWork->id.'/assigned', [
            'assigned' => $viewer->id,
        ])->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to update assigned user for this daily work.');

        // The record is untouched.
        $this->assertDatabaseHas('daily_works', [
            'id' => $dailyWork->id,
            'status' => DailyWork::STATUS_NEW,
            'incharge' => $incharge->id,
            'assigned' => $incharge->id,
        ]);
    }

    /**
     * The record's own incharge — a legitimate mutator — must still be able to
     * update status and reassign the assignee. (Changing the incharge itself is
     * a privileged/admin action, so it remains 403 for a plain incharge; that
     * boundary is unchanged and asserted here for completeness.)
     */
    public function test_incharge_can_still_update_status_and_assigned(): void
    {
        $incharge = User::factory()->create();
        $assignee = User::factory()->create([
            'report_to' => $incharge->id,
        ]);
        $nextAssignee = User::factory()->create([
            'report_to' => $incharge->id,
        ]);

        $dailyWork = DailyWork::factory()->forUsers($incharge, $assignee)->newStatus()->create();

        Sanctum::actingAs($incharge);

        $this->patchJson('/api/v1/daily-works/'.$dailyWork->id.'/status', [
            'status' => DailyWork::STATUS_COMPLETED,
            'inspection_result' => DailyWork::INSPECTION_PASS,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', DailyWork::STATUS_COMPLETED)
            ->assertJsonPath('data.inspection_result', DailyWork::INSPECTION_PASS);

        $this->patchJson('/api/v1/daily-works/'.$dailyWork->id.'/assigned', [
            'assigned' => $nextAssignee->id,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.assigned_user.id', $nextAssignee->id);

        $this->assertDatabaseHas('daily_works', [
            'id' => $dailyWork->id,
            'status' => DailyWork::STATUS_COMPLETED,
            'assigned' => $nextAssignee->id,
        ]);
    }

    /**
     * The assigned worker (not the incharge) is also a legitimate status
     * mutator and must still succeed.
     */
    public function test_assignee_can_still_update_status(): void
    {
        $incharge = User::factory()->create();
        $assignee = User::factory()->create();

        $dailyWork = DailyWork::factory()->forUsers($incharge, $assignee)->newStatus()->create();

        Sanctum::actingAs($assignee);

        $this->patchJson('/api/v1/daily-works/'.$dailyWork->id.'/status', [
            'status' => DailyWork::STATUS_COMPLETED,
            'inspection_result' => DailyWork::INSPECTION_PASS,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', DailyWork::STATUS_COMPLETED);

        $this->assertDatabaseHas('daily_works', [
            'id' => $dailyWork->id,
            'status' => DailyWork::STATUS_COMPLETED,
        ]);
    }

    /**
     * A privileged manager who is neither incharge nor assigned must still be
     * able to drive every mutation endpoint (status, incharge, assigned).
     */
    public function test_privileged_manager_can_mutate_any_daily_work(): void
    {
        $manager = User::factory()->create();
        Role::findOrCreate('Project Manager');
        $manager->assignRole('Project Manager');

        $incharge = User::factory()->create();
        $assignee = User::factory()->create();
        $nextIncharge = User::factory()->create();
        $nextAssignee = User::factory()->create();

        $dailyWork = DailyWork::factory()->forUsers($incharge, $assignee)->newStatus()->create();

        Sanctum::actingAs($manager);

        $this->patchJson('/api/v1/daily-works/'.$dailyWork->id.'/status', [
            'status' => DailyWork::STATUS_COMPLETED,
            'inspection_result' => DailyWork::INSPECTION_PASS,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', DailyWork::STATUS_COMPLETED);

        $this->patchJson('/api/v1/daily-works/'.$dailyWork->id.'/incharge', [
            'incharge' => $nextIncharge->id,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.incharge_user.id', $nextIncharge->id);

        $this->patchJson('/api/v1/daily-works/'.$dailyWork->id.'/assigned', [
            'assigned' => $nextAssignee->id,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.assigned_user.id', $nextAssignee->id);

        $this->assertDatabaseHas('daily_works', [
            'id' => $dailyWork->id,
            'status' => DailyWork::STATUS_COMPLETED,
            'incharge' => $nextIncharge->id,
            'assigned' => $nextAssignee->id,
        ]);
    }
}
