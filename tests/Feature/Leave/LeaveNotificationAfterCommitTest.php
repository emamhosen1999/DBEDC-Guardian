<?php

namespace Tests\Feature\Leave;

use App\Models\HRM\Department;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Notifications\LeaveApprovalNotification;
use App\Services\Leave\LeaveApprovalService;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\TestCase;

/** F-8: a notification fires only for a transaction that commits (sync and queued drivers alike). */
class LeaveNotificationAfterCommitTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;

    private User $manager;

    private Leave $leave;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class]);
        $this->buildWorld();
        $this->seed(NotificationTypeSeeder::class);
        Notification::fake();

        $lab = new Department(['name' => 'Commit Lab', 'code' => 'CML-LAB']);
        $lab->forceFill(['is_active' => true])->save();
        $this->manager = $this->person('Commit Manager', $lab, ['Employee']);
        $employee = $this->person('Commit Engineer', $lab, ['Employee']);
        $employee->forceFill(['report_to' => $this->manager->employee_id])->save();

        $type = LeaveSetting::create([
            'type' => 'Commit '.uniqid(), 'days' => 30, 'accrual_method' => 'annual_upfront', 'accrual_rate' => 30,
            'requires_approval' => true, 'auto_approve' => false, 'allow_negative' => true,
        ]);
        $this->leave = Leave::create([
            'user_id' => $employee->employee_id, 'leave_type' => $type->id,
            'from_date' => now()->addDays(20)->toDateString(), 'to_date' => now()->addDays(20)->toDateString(),
            'no_of_days' => 1, 'reason' => 'after commit', 'status' => 'pending',
        ]);
    }

    public function test_a_committed_submit_notifies_the_first_approver(): void
    {
        $this->assertTrue(app(LeaveApprovalService::class)->submitForApproval($this->leave));

        Notification::assertSentTo($this->manager, LeaveApprovalNotification::class);
    }

    public function test_a_rolled_back_submit_sends_nothing(): void
    {
        // Fails AFTER the approver notification was raised and BEFORE commit: exactly the audit repro.
        $service = new class extends LeaveApprovalService
        {
            protected function notifyCurrentApprover(Leave $leave): void
            {
                parent::notifyCurrentApprover($leave);

                throw new \RuntimeException('ledger exploded');
            }
        };

        $this->assertFalse($service->submitForApproval($this->leave));

        Notification::assertNothingSent();
        $this->assertEmpty($this->leave->fresh()->approval_chain, 'the submit itself rolled back');
    }
}
