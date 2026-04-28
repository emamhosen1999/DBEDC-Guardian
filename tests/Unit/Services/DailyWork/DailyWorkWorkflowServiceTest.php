<?php

namespace Tests\Unit\Services\DailyWork;

use App\Models\DailyWork;
use App\Models\RfiObjection;
use App\Services\DailyWork\DailyWorkWorkflowService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DailyWorkWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    private DailyWorkWorkflowService $workflowService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workflowService = app(DailyWorkWorkflowService::class);
        Notification::fake();
    }

    /** @test */
    public function it_auto_completes_work_with_passing_inspection()
    {
        $user = User::factory()->create();
        $dailyWork = DailyWork::factory()->forUsers($user, $user)->create([
            'status' => DailyWork::STATUS_COMPLETED,
            'inspection_result' => DailyWork::INSPECTION_PASS,
        ]);

        $this->workflowService->handleAutomaticTransitions($dailyWork);

        // Should remain completed since it already is
        $this->assertEquals(DailyWork::STATUS_COMPLETED, $dailyWork->fresh()->status);
    }

    /** @test */
    public function it_blocks_work_with_active_objections()
    {
        $user = User::factory()->create();
        $dailyWork = DailyWork::factory()->forUsers($user, $user)->create([
            'status' => DailyWork::STATUS_IN_PROGRESS,
        ]);

        // Create an active objection and attach it via pivot table
        $objection = $this->createObjectionWithPivot($dailyWork, ['status' => RfiObjection::STATUS_SUBMITTED]);

        $this->workflowService->handleAutomaticTransitions($dailyWork);

        // Should be blocked to pending status
        $this->assertEquals(DailyWork::STATUS_PENDING, $dailyWork->fresh()->status);
    }

    /** @test */
    public function it_requires_resubmission_for_rejected_rfi_responses()
    {
        $user = User::factory()->create();
        $dailyWork = DailyWork::factory()->forUsers($user, $user)->create([
            'status' => DailyWork::STATUS_COMPLETED,
            'rfi_response_status' => DailyWork::RFI_RESPONSE_REJECTED,
        ]);

        $this->workflowService->handleAutomaticTransitions($dailyWork);

        // Should require resubmission
        $this->assertEquals(DailyWork::STATUS_RESUBMISSION, $dailyWork->fresh()->status);
    }

    /** @test */
    public function it_escalates_overdue_work()
    {
        $user = User::factory()->create();
        $yesterday = now()->subDay();
        $pastTime = now()->subHours(3)->format('H:i:s');

        $dailyWork = DailyWork::factory()->forUsers($user, $user)->create([
            'status' => DailyWork::STATUS_IN_PROGRESS,
            'date' => $yesterday,
            'planned_time' => $pastTime,
        ]);

        $this->workflowService->handleAutomaticTransitions($dailyWork);

        // Should be escalated
        $this->assertEquals(DailyWork::STATUS_EMERGENCY, $dailyWork->fresh()->status);
    }

    /** @test */
    public function it_finds_overdue_work_for_reminders()
    {
        $user = User::factory()->create();
        $yesterday = now()->subDay();
        $pastTime = now()->subHours(2)->format('H:i:s');

        $overdueWork = DailyWork::factory()->forUsers($user, $user)->create([
            'status' => DailyWork::STATUS_IN_PROGRESS,
            'date' => $yesterday,
            'planned_time' => $pastTime,
        ]);

        $onTimeWork = DailyWork::factory()->forUsers($user, $user)->create([
            'status' => DailyWork::STATUS_IN_PROGRESS,
            'date' => now(),
            'planned_time' => now()->addHours(2)->format('H:i:s'),
        ]);

        $overdueItems = $this->workflowService->getOverdueWorkForReminders();

        $this->assertCount(1, $overdueItems);
        $this->assertEquals($overdueWork->id, $overdueItems->first()->id);
    }

    /** @test */
    public function it_finds_pending_objections_for_reminders()
    {
        $user = User::factory()->create();
        $dailyWork = DailyWork::factory()->forUsers($user, $user)->create();

        // Create an old pending objection
        $oldObjection = $this->createObjectionWithPivot($dailyWork, [
            'status' => RfiObjection::STATUS_SUBMITTED,
            'created_at' => now()->subDays(3),
        ]);

        // Create a recent objection (should not be included)
        $recentObjection = $this->createObjectionWithPivot($dailyWork, [
            'status' => RfiObjection::STATUS_SUBMITTED,
            'created_at' => now()->subHours(1),
        ]);

        $pendingObjections = $this->workflowService->getPendingObjectionsForReminders();

        $this->assertCount(1, $pendingObjections);
        $this->assertEquals($oldObjection->id, $pendingObjections->first()->id);
    }

    /**
     * Helper method to create objections with pivot table relationship.
     */
    private function createObjectionWithPivot(DailyWork $dailyWork, array $objectionData = []): RfiObjection
    {
        // Create the objection data
        $data = array_merge([
            'title' => 'Test Objection',
            'category' => RfiObjection::CATEGORY_OTHER,
            'description' => 'Test description',
            'reason' => 'Test reason',
            'status' => RfiObjection::STATUS_DRAFT,
            'created_by' => $dailyWork->incharge,
            'updated_by' => $dailyWork->incharge,
        ], $objectionData);

        // Handle legacy daily_work_id column if it exists
        if (Schema::hasColumn('rfi_objections', 'daily_work_id')) {
            $data['daily_work_id'] = $dailyWork->id;
        }

        // Insert directly to avoid factory issues
        $objectionId = DB::table('rfi_objections')->insertGetId($data);
        $objection = RfiObjection::find($objectionId);

        // Attach via pivot table if it exists
        if (Schema::hasTable('daily_work_objection')) {
            DB::table('daily_work_objection')->insert([
                'daily_work_id' => $dailyWork->id,
                'rfi_objection_id' => $objection->id,
                'attached_by' => $dailyWork->incharge,
                'attached_at' => now(),
                'attachment_notes' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $objection;
    }
}