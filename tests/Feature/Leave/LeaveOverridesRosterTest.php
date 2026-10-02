<?php

namespace Tests\Feature\Leave;

use App\Models\HRM\AbsenceCase;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Services\Leave\LeaveApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\TestCase;

/**
 * An APPROVED leave always beats a rostered - even manually locked - working shift: the person is On
 * Leave, never Absent, in the web timesheet, the mobile endpoints and the absence-streak job. A
 * pending leave does not.
 */
class LeaveOverridesRosterTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;

    private User $worker;

    private User $pendingWorker;

    private LeaveSetting $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();

        $shift = Shift::factory()->create(['start_time' => '08:00', 'end_time' => '16:00']);
        $this->type = LeaveSetting::query()->first() ?? LeaveSetting::create(['type' => 'Annual', 'symbol' => 'AL', 'days' => 20, 'eligibility' => 0, 'carry_forward' => false, 'earned_leave' => false, 'is_earned' => false, 'is_paid' => true, 'requires_approval' => true, 'auto_approve' => false]);

        $this->worker = $this->person('Leave Worker', $this->d1, ['Employee']);
        $this->pendingWorker = $this->person('Pending Worker', $this->d1, ['Employee']);

        foreach ([$this->worker, $this->pendingWorker] as $user) {
            // A manually set, LOCKED working shift - exactly what the incident looked like.
            RosterDay::create(['user_id' => $user->employee_id, 'date' => self::DAY, 'shift_id' => $shift->id, 'source' => 'manual', 'locked' => true]);
        }

        $this->leave($this->worker, 'approved');
        $this->leave($this->pendingWorker, 'pending');
    }

    private function leave(User $user, string $status): Leave
    {
        return Leave::create(['user_id' => $user->employee_id, 'leave_type' => $this->type->id, 'from_date' => self::DAY, 'to_date' => self::DAY, 'no_of_days' => 1, 'status' => $status, 'reason' => 'test']);
    }

    public function test_web_partition_puts_the_approved_leave_person_on_leave_not_absent(): void
    {
        $json = $this->actingAs($this->hr)->getJson(route('attendance.dayPartition', ['date' => self::DAY]))->assertOk()->json();

        $absent = collect($json['absent'])->pluck('user.employee_id')->all();
        $onLeave = collect($json['off_leave'])->where('kind', 'leave')->pluck('user.employee_id')->all();

        $this->assertContains($this->worker->employee_id, $onLeave);
        $this->assertNotContains($this->worker->employee_id, $absent);
        $this->assertContains($this->pendingWorker->employee_id, $absent, 'a PENDING leave never hides an absence');
    }

    public function test_web_absent_list_excludes_the_approved_leave_person(): void
    {
        $json = $this->actingAs($this->hr)->getJson(route('admin.getAbsentUsersForDate', ['date' => self::DAY]))->assertOk()->json();

        $absent = collect($json['absent_users'])->pluck('employee_id')->all();
        $off = collect($json['off_users'])->pluck('employee_id')->all();

        $this->assertNotContains($this->worker->employee_id, $absent);
        $this->assertContains($this->worker->employee_id, $off);
        $this->assertContains($this->pendingWorker->employee_id, $absent);
    }

    public function test_daily_overview_counts_the_approved_leave_person_as_on_leave_not_absent(): void
    {
        $stats = $this->actingAs($this->hr)->getJson(route('attendance.dailyOverview', ['date' => self::DAY, 'department_id' => $this->d1->id]))->assertOk()->json();

        $this->assertSame(1, $stats['on_leave'], 'exactly the approved-leave worker');
        // Absent = rostered/working, unpunched, NOT on approved leave: the pending-leave worker is in, the leave worker is out.
        $this->assertGreaterThanOrEqual(1, $stats['absent']);
        $withoutLeave = $stats['absent'];
        Leave::query()->where('user_id', $this->worker->employee_id)->update(['status' => 'pending']);
        $after = $this->actingAs($this->hr)->getJson(route('attendance.dailyOverview', ['date' => self::DAY, 'department_id' => $this->d1->id]))->json();
        $this->assertSame($withoutLeave + 1, $after['absent'], 'without the approval the same person is absent');
    }

    public function test_mobile_absent_users_excludes_the_approved_leave_person(): void
    {
        $this->worker->forceFill(['report_to' => $this->admin->employee_id])->save();
        $this->pendingWorker->forceFill(['report_to' => $this->admin->employee_id])->save();
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/attendance/absent-users?date='.self::DAY)->assertOk();

        $absent = collect($response->json('absent_users'))->pluck('employee_id')->all();
        $this->assertNotContains($this->worker->employee_id, $absent);
        $this->assertContains($this->pendingWorker->employee_id, $absent);
    }

    public function test_absence_streak_job_never_counts_an_approved_leave_day(): void
    {
        Artisan::call('attendance:absence-streak', ['--date' => self::DAY]);

        $this->assertNull(AbsenceCase::where('user_id', $this->worker->employee_id)->first(), 'approved leave: no absence case');
        $this->assertNotNull(AbsenceCase::where('user_id', $this->pendingWorker->employee_id)->first(), 'pending leave: still absent');
    }

    public function test_a_backdated_leave_by_a_manager_less_admin_routes_to_hr_never_to_himself_nor_auto_approves(): void
    {
        $this->assertNull($this->admin->report_to);
        $leave = Leave::create(['user_id' => $this->admin->employee_id, 'leave_type' => $this->type->id, 'from_date' => '2026-06-02', 'to_date' => '2026-06-02', 'no_of_days' => 1, 'status' => 'pending', 'reason' => 'backdated']);

        app(LeaveApprovalService::class)->submitForApproval($leave);

        $leave->refresh();
        $this->assertSame('pending', strtolower($leave->status), 'must not auto-approve');
        $approvers = collect($leave->approval_chain)->pluck('approver_id')->map(fn ($id) => (string) $id)->all();
        $this->assertNotEmpty($approvers);
        $this->assertNotContains((string) $this->admin->employee_id, $approvers, 'never routed to the requester');
        // The fallback picks the lowest employee_id among HR Managers (then global admins);
        // factory ids are random, so assert the approver's authority, not a specific person.
        foreach ($approvers as $approverId) {
            $this->assertTrue(
                \App\Models\User::find($approverId)?->hasAnyRole(['HR Manager', 'Administrator', 'Super Administrator']) ?? false,
                "approver {$approverId} must hold HR or global-admin authority"
            );
        }
    }
}
