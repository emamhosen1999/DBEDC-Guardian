<?php

namespace Tests\Feature\HRM;

use App\Models\HRM\Offboarding;
use App\Models\HRM\OffboardingTask;
use App\Models\HRM\Onboarding;
use App\Models\HRM\OnboardingTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Both task models use SoftDeletes, but their tables were created without
 * deleted_at, so any query through the model or the parent's tasks()
 * relation failed with "no such column".
 */
class OnboardingOffboardingTaskSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_tasks_can_be_soft_deleted_and_restored(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $onboarding = Onboarding::create([
            'employee_id' => $user->id,
            'start_date' => '2026-10-01',
            'expected_completion_date' => '2026-10-15',
        ]);
        $task = $onboarding->tasks()->create(['task' => 'Issue laptop']);

        $this->assertFalse($onboarding->isCompletable());

        $task->delete();

        $this->assertSoftDeleted('onboarding_tasks', ['id' => $task->id]);
        $this->assertSame(0, $onboarding->tasks()->count());
        $this->assertTrue($onboarding->isCompletable());

        OnboardingTask::withTrashed()->findOrFail($task->id)->restore();
        $this->assertSame(1, $onboarding->tasks()->count());
    }

    public function test_offboarding_tasks_can_be_soft_deleted_and_restored(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $offboarding = Offboarding::create([
            'employee_id' => $user->id,
            'initiation_date' => '2026-10-01',
            'last_working_date' => '2026-10-31',
            'reason' => 'resignation',
        ]);
        $task = $offboarding->tasks()->create(['task' => 'Return laptop']);

        $this->assertFalse($offboarding->isCompletable());

        $task->delete();

        $this->assertSoftDeleted('offboarding_tasks', ['id' => $task->id]);
        $this->assertSame(0, $offboarding->tasks()->count());
        $this->assertTrue($offboarding->isCompletable());

        OffboardingTask::withTrashed()->findOrFail($task->id)->restore();
        $this->assertSame(1, $offboarding->tasks()->count());
    }
}
