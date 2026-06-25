<?php

namespace Tests\Feature;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Leave\LeaveApprovalService;
use App\Services\Leave\LeaveCrudService;
use App\Services\Leave\LeaveDayCalculator;
use App\Services\Leave\LeaveOverlapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic schedule: every calendar day is a working day, so the
        // server-side LeaveDayCalculator count equals the calendar span in range.
        $this->app->bind(ScheduleResolver::class, fn () => new class implements ScheduleResolver
        {
            public function resolve(int $userId, \Carbon\CarbonInterface $date): ShiftSchedule
            {
                return new ShiftSchedule(
                    start: $date->copy()->setTime(9, 0), end: $date->copy()->setTime(17, 0),
                    crossesMidnight: false, graceInMinutes: 0, graceOutMinutes: 0,
                    fullDayMinutes: 480, halfDayMinutes: 240, minPresentMinutes: 0,
                    breakMinutes: 0, isWorkingDay: true, isScheduled: true,
                );
            }
        });
    }

    private function makeService(LeaveApprovalService $approvalMock): LeaveCrudService
    {
        return new LeaveCrudService($approvalMock, new LeaveOverlapService, app(LeaveDayCalculator::class), app(\App\Services\Leave\LeaveAuditService::class));
    }

    public function test_insufficient_balance_throws()
    {
        // Arrange
        $user = User::factory()->create();

        $setting = LeaveSetting::create([
            'type' => 'Annual',
            'days' => 5,
            'requires_approval' => false,
            'auto_approve' => false,
        ]);

        // user already used all 5 days
        Leave::create([
            'user_id' => $user->id,
            'leave_type' => $setting->id,
            'from_date' => now()->startOfYear()->toDateString(),
            'to_date' => now()->startOfYear()->addDays(4)->toDateString(),
            'no_of_days' => 5,
            'reason' => 'Used up',
            'status' => 'approved',
        ]);

        $approvalMock = $this->getMockBuilder(LeaveApprovalService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $service = $this->makeService($approvalMock);

        $payload = [
            'user_id' => $user->id,
            'leaveType' => 'Annual',
            'fromDate' => now()->addMonth()->toDateString(),
            'toDate' => now()->addMonth()->toDateString(),
            'daysCount' => 1,
            'leaveReason' => 'Need day off',
        ];

        $this->expectException(\RuntimeException::class);

        // Act
        $service->createLeave($payload);
    }

    public function test_sufficient_balance_allows_creation()
    {
        // Arrange
        $user = User::factory()->create();

        $setting = LeaveSetting::create([
            'type' => 'Sick',
            'days' => 10,
            'requires_approval' => false,
            'auto_approve' => false,
        ]);

        // existing used 3 days
        Leave::create([
            'user_id' => $user->id,
            'leave_type' => $setting->id,
            'from_date' => now()->startOfYear()->toDateString(),
            'to_date' => now()->startOfYear()->addDays(2)->toDateString(),
            'no_of_days' => 3,
            'reason' => 'Earlier',
            'status' => 'approved',
        ]);

        $approvalMock = $this->getMockBuilder(LeaveApprovalService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $service = $this->makeService($approvalMock);

        $payload = [
            'user_id' => $user->id,
            'leaveType' => 'Sick',
            'fromDate' => now()->addMonth()->toDateString(),
            'toDate' => now()->addMonth()->addDays(1)->toDateString(),
            'daysCount' => 2,
            'leaveReason' => 'Medical',
        ];

        // Act
        $leave = $service->createLeave($payload);

        // Assert
        $this->assertInstanceOf(Leave::class, $leave);
        $this->assertEquals('sick', strtolower($leave->leaveSetting->type ?? 'sick'));
    }

    public function test_overlapping_leave_creation_is_rejected()
    {
        $user = User::factory()->create();

        $setting = LeaveSetting::create([
            'type' => 'Annual',
            'days' => 15,
            'requires_approval' => false,
            'auto_approve' => false,
        ]);

        Leave::create([
            'user_id' => $user->id,
            'leave_type' => $setting->id,
            'from_date' => now()->startOfYear()->addDays(10)->toDateString(),
            'to_date' => now()->startOfYear()->addDays(12)->toDateString(),
            'no_of_days' => 3,
            'reason' => 'Existing leave',
            'status' => 'approved',
        ]);

        $approvalMock = $this->getMockBuilder(LeaveApprovalService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $service = $this->makeService($approvalMock);

        $payload = [
            'user_id' => $user->id,
            'leaveType' => 'Annual',
            'fromDate' => now()->startOfYear()->addDays(12)->toDateString(),
            'toDate' => now()->startOfYear()->addDays(14)->toDateString(),
            'daysCount' => 3,
            'leaveReason' => 'Overlap test',
        ];

        $this->expectException(\RuntimeException::class);
        $service->createLeave($payload);
    }

    public function test_overlapping_leave_update_is_rejected()
    {
        $user = User::factory()->create();

        $setting = LeaveSetting::create([
            'type' => 'Annual',
            'days' => 15,
            'requires_approval' => false,
            'auto_approve' => false,
        ]);

        $existingLeave = Leave::create([
            'user_id' => $user->id,
            'leave_type' => $setting->id,
            'from_date' => now()->startOfYear()->addDays(10)->toDateString(),
            'to_date' => now()->startOfYear()->addDays(12)->toDateString(),
            'no_of_days' => 3,
            'reason' => 'Existing leave',
            'status' => 'approved',
        ]);

        $otherLeave = Leave::create([
            'user_id' => $user->id,
            'leave_type' => $setting->id,
            'from_date' => now()->startOfYear()->addDays(15)->toDateString(),
            'to_date' => now()->startOfYear()->addDays(16)->toDateString(),
            'no_of_days' => 2,
            'reason' => 'Second leave',
            'status' => 'approved',
        ]);

        $approvalMock = $this->getMockBuilder(LeaveApprovalService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $service = $this->makeService($approvalMock);

        $payload = [
            'user_id' => $user->id,
            'leaveType' => 'Annual',
            'fromDate' => now()->startOfYear()->addDays(11)->toDateString(),
            'toDate' => now()->startOfYear()->addDays(13)->toDateString(),
            'daysCount' => 3,
            'leaveReason' => 'Adjusted to overlap',
        ];

        $this->expectException(\RuntimeException::class);
        $service->updateLeave($otherLeave->id, $payload);
    }
}
