<?php

namespace Tests\Feature\Notifications;

use App\Models\DailyWork;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskStatusChangedNotification;
use App\Services\Task\TaskNotificationService;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** F-3: the legacy task endpoints notify through real, registry-driven notifications and fail soft. */
class TaskNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $incharge;

    private DailyWork $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NotificationTypeSeeder::class);
        $this->actor = User::factory()->create();
        $this->incharge = User::factory()->create();
        $this->task = (new DailyWork)->forceFill(['id' => 77, 'number' => 'RFI-77', 'incharge' => $this->incharge->employee_id]);
        Auth::login($this->actor);
    }

    public function test_assignment_notifies_the_assignee_with_the_registered_type(): void
    {
        Notification::fake();

        app(TaskNotificationService::class)->sendTaskAssignmentNotification($this->task, $this->incharge->employee_id);

        Notification::assertSentTo($this->incharge, TaskAssignedNotification::class, function (TaskAssignedNotification $n) {
            return $n->typeKey() === 'task.assigned' && $n->via($this->incharge) !== [];
        });
    }

    public function test_status_change_and_completion_notify_the_incharge_once_and_never_the_actor(): void
    {
        Notification::fake();
        $service = app(TaskNotificationService::class);

        $service->sendTaskStatusUpdateNotification($this->task, 'new', 'completed');
        Notification::assertSentToTimes($this->incharge, TaskStatusChangedNotification::class, 1);

        $this->task->incharge = $this->actor->employee_id;
        $service->sendTaskStatusUpdateNotification($this->task, 'new', 'completed');
        Notification::assertNotSentTo($this->actor, TaskStatusChangedNotification::class);
    }

    public function test_a_notification_failure_is_logged_at_error_and_never_breaks_the_request(): void
    {
        Log::spy();
        // The queue is sync here, so a channel failure surfaces inside notify() exactly as in production.
        Event::listen(NotificationSending::class, fn () => throw new \RuntimeException('push exploded'));

        app(TaskNotificationService::class)->sendTaskStatusUpdateNotification($this->task, 'new', 'completed');

        Log::shouldHaveReceived('error')->once();
    }
}
