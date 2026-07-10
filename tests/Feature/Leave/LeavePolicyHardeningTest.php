<?php

namespace Tests\Feature\Leave;

use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Leave\LeaveApprovalService;
use App\Services\Leave\LeaveCrudService;
use App\Services\Leave\LeaveEncashmentService;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveOverlapService;
use App\Services\Leave\LeaveValidationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class LeavePolicyHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function makeType(array $overrides = []): LeaveSetting
    {
        return LeaveSetting::create(array_merge([
            'type' => 'Casual', 'days' => 10,
            'accrual_method' => 'annual_upfront', 'accrual_rate' => 10,
            'requires_approval' => false, 'auto_approve' => true,
        ], $overrides));
    }

    // ── Backdating window ────────────────────────────────────────────────

    /** @test */
    public function new_leave_older_than_backdate_window_is_rejected(): void
    {
        User::factory()->create(['id' => 1]);
        LeaveSetting::create(['type' => 'Casual', 'days' => 10]);

        $old = now()->subDays((int) config('leave.max_backdate_days', 30) + 5)->format('Y-m-d');
        $req = Request::create('/leaves', 'POST', [
            'user_id' => 1, 'leaveType' => 'Casual',
            'fromDate' => $old, 'toDate' => $old,
            'leaveReason' => 'far too old request',
        ]);

        $validator = (new LeaveValidationService)->validateLeaveRequest($req);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('fromDate', $validator->errors()->toArray());
    }

    /** @test */
    public function backdate_within_window_passes_and_updates_are_exempt(): void
    {
        User::factory()->create(['id' => 1]);
        LeaveSetting::create(['type' => 'Casual', 'days' => 10]);

        $recent = now()->subDays(5)->format('Y-m-d');
        $req = Request::create('/leaves', 'POST', [
            'user_id' => 1, 'leaveType' => 'Casual',
            'fromDate' => $recent, 'toDate' => $recent,
            'leaveReason' => 'post-facto sick leave',
        ]);
        $this->assertFalse((new LeaveValidationService)->validateLeaveRequest($req)->fails());

        // An update (id present) of a historic record is exempt from the window.
        $leave = Leave::create([
            'user_id' => 1, 'leave_type' => 1,
            'from_date' => now()->subDays(90), 'to_date' => now()->subDays(90),
            'no_of_days' => 1, 'reason' => 'historic row', 'status' => 'approved',
        ]);
        $historic = now()->subDays(90)->format('Y-m-d');
        $req = Request::create('/leaves', 'POST', [
            'id' => $leave->id, 'user_id' => 1, 'leaveType' => 'Casual',
            'fromDate' => $historic, 'toDate' => $historic,
            'leaveReason' => 'editing a historic record',
        ]);
        $this->assertFalse((new LeaveValidationService)->validateLeaveRequest($req)->fails());
    }

    /** @test */
    public function min_notice_is_enforced_for_future_leave_when_configured(): void
    {
        config(['leave.min_notice_days' => 7]);
        User::factory()->create(['id' => 1]);
        LeaveSetting::create(['type' => 'Casual', 'days' => 10]);

        $soon = now()->addDays(2)->format('Y-m-d');
        $req = Request::create('/leaves', 'POST', [
            'user_id' => 1, 'leaveType' => 'Casual',
            'fromDate' => $soon, 'toDate' => $soon,
            'leaveReason' => 'short notice request',
        ]);
        $validator = (new LeaveValidationService)->validateLeaveRequest($req);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('fromDate', $validator->errors()->toArray());
    }

    // ── Ledger idempotency key ───────────────────────────────────────────

    /** @test */
    public function keyed_ledger_postings_apply_at_most_once(): void
    {
        $u = User::factory()->create();
        $t = $this->makeType();
        $ledger = app(LeaveLedgerService::class);

        $first = $ledger->post($u->id, $t->id, 2026, 'opening', 10, 'command', null, null, 'grant', "op:{$u->id}:{$t->id}:2026");
        $second = $ledger->post($u->id, $t->id, 2026, 'opening', 10, 'command', null, null, 'grant', "op:{$u->id}:{$t->id}:2026");

        $this->assertSame($first->id, $second->id);
        $this->assertSame(10.0, $ledger->balance($u->id, $t->id, 2026));
        $this->assertSame(1, LeaveLedger::count());
    }

    // ── Untracked-balance bypass closed via lazy auto-seed ───────────────

    /** @test */
    public function unseeded_user_cannot_exceed_entitlement_anymore(): void
    {
        $u = User::factory()->create(['date_of_joining' => '2020-01-01']);
        $t = $this->makeType(['days' => 2, 'accrual_rate' => 2]);

        $from = now()->startOfWeek();  // ledger never seeded for $u
        $to = $from->copy()->addDays(13);

        try {
            app(LeaveCrudService::class)->createLeave([
                'user_id' => $u->id, 'leaveType' => $t->type,
                'fromDate' => $from->toDateString(), 'toDate' => $to->toDateString(),
                'leaveReason' => 'way beyond entitlement',
            ]);
            $this->fail('Expected insufficient-balance rejection.');
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('Insufficient leave balance', $e->getMessage());
        }

        // A legitimate request within entitlement still succeeds — and the lazy
        // seed persists with it, so the balance is tracked from then on.
        $leave = app(LeaveCrudService::class)->createLeave([
            'user_id' => $u->id, 'leaveType' => $t->type,
            'fromDate' => $from->toDateString(), 'toDate' => $from->toDateString(),
            'leaveReason' => 'a single day within entitlement',
        ]);
        $this->assertSame('approved', $leave->status);
        $this->assertTrue(app(LeaveLedgerService::class)->isTracked($u->id, $t->id, (int) $from->year));
        $this->assertSame(1.0, app(LeaveLedgerService::class)->balance($u->id, $t->id, (int) $from->year));
    }

    // ── Eligibility gates ────────────────────────────────────────────────

    /** @test */
    public function gender_restricted_type_rejects_ineligible_employee(): void
    {
        $u = User::factory()->create(['gender' => 'male', 'date_of_joining' => '2020-01-01']);
        $t = $this->makeType(['type' => 'Maternity', 'eligible_gender' => 'female', 'allow_negative' => true]);

        try {
            app(LeaveCrudService::class)->createLeave([
                'user_id' => $u->id, 'leaveType' => $t->type,
                'fromDate' => now()->toDateString(), 'toDate' => now()->toDateString(),
                'leaveReason' => 'not eligible for this',
            ]);
            $this->fail('Expected gender-eligibility rejection.');
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('only available to female', $e->getMessage());
        }
    }

    /** @test */
    public function min_service_months_gate_rejects_new_joiner(): void
    {
        $u = User::factory()->create(['date_of_joining' => now()->subMonths(2)->toDateString()]);
        $t = $this->makeType(['type' => 'Earned', 'min_service_months' => 6, 'allow_negative' => true]);

        try {
            app(LeaveCrudService::class)->createLeave([
                'user_id' => $u->id, 'leaveType' => $t->type,
                'fromDate' => now()->toDateString(), 'toDate' => now()->toDateString(),
                'leaveReason' => 'too junior for earned',
            ]);
            $this->fail('Expected min-service rejection.');
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('6 months of service', $e->getMessage());
        }
    }

    // ── Cancellation workflow ────────────────────────────────────────────

    /** @test */
    public function owner_can_cancel_pending_leave(): void
    {
        $u = User::factory()->create();
        $t = $this->makeType(['requires_approval' => true, 'auto_approve' => false, 'allow_negative' => true]);
        $leave = Leave::create([
            'user_id' => $u->id, 'leave_type' => $t->id,
            'from_date' => now()->addDays(10), 'to_date' => now()->addDays(11),
            'no_of_days' => 2, 'reason' => 'pending trip leave', 'status' => 'pending',
        ]);

        $cancelled = app(LeaveCrudService::class)->cancelLeave($leave->id, $u);

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($u->id, (int) $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
    }

    /** @test */
    public function cancelling_approved_future_leave_reverses_ledger_consumption(): void
    {
        $u = User::factory()->create(['date_of_joining' => '2020-01-01']);
        $t = $this->makeType();
        $ledger = app(LeaveLedgerService::class);
        $year = (int) now()->addDays(10)->year;
        $ledger->post($u->id, $t->id, $year, 'opening', 10);

        $leave = Leave::create([
            'user_id' => $u->id, 'leave_type' => $t->id,
            'from_date' => now()->addDays(10), 'to_date' => now()->addDays(11),
            'no_of_days' => 2, 'reason' => 'approved future leave', 'status' => 'approved',
        ]);
        $ledger->consume($leave);
        $this->assertSame(8.0, $ledger->balance($u->id, $t->id, $year));

        app(LeaveCrudService::class)->cancelLeave($leave->id, $u);

        $this->assertSame(10.0, $ledger->balance($u->id, $t->id, $year));
        $this->assertSame('cancelled', $leave->fresh()->status);
    }

    /** @test */
    public function owner_cannot_cancel_approved_leave_that_already_started(): void
    {
        $u = User::factory()->create();
        $t = $this->makeType();
        $leave = Leave::create([
            'user_id' => $u->id, 'leave_type' => $t->id,
            'from_date' => now()->subDay(), 'to_date' => now()->addDay(),
            'no_of_days' => 3, 'reason' => 'already running leave', 'status' => 'approved',
        ]);

        try {
            app(LeaveCrudService::class)->cancelLeave($leave->id, $u);
            $this->fail('Expected started-leave cancel rejection.');
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
        }
    }

    /** @test */
    public function stranger_cannot_cancel_someone_elses_leave(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $t = $this->makeType();
        $leave = Leave::create([
            'user_id' => $owner->id, 'leave_type' => $t->id,
            'from_date' => now()->addDays(5), 'to_date' => now()->addDays(5),
            'no_of_days' => 1, 'reason' => 'not your leave record', 'status' => 'pending',
        ]);

        try {
            app(LeaveCrudService::class)->cancelLeave($leave->id, $stranger);
            $this->fail('Expected authorization rejection.');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }
    }

    // ── Encashment cap ───────────────────────────────────────────────────

    /** @test */
    public function encashment_respects_yearly_cap(): void
    {
        $u = User::factory()->create();
        $t = $this->makeType(['is_encashable' => true, 'max_encash_days' => 5]);
        $ledger = app(LeaveLedgerService::class);
        $ledger->post($u->id, $t->id, (int) now()->year, 'opening', 20);

        $svc = app(LeaveEncashmentService::class);
        $svc->encash($u->id, $t->id, 4, $u->id); // within cap

        try {
            $svc->encash($u->id, $t->id, 2, $u->id); // 4 + 2 > 5
            $this->fail('Expected encashment-cap rejection.');
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('Encashment cap exceeded', $e->getMessage());
        }
    }

    // ── Team-conflict advisory ───────────────────────────────────────────

    /** @test */
    public function team_conflict_warnings_flag_overlapping_department_leave(): void
    {
        $dept = Department::create(['name' => 'Ops']);
        $a = User::factory()->create(['department_id' => $dept->id]);
        $b = User::factory()->create(['department_id' => $dept->id, 'name' => 'Colleague B']);
        $t = $this->makeType();

        Leave::create([
            'user_id' => $b->id, 'leave_type' => $t->id,
            'from_date' => now()->addDays(3), 'to_date' => now()->addDays(6),
            'no_of_days' => 4, 'reason' => 'colleague overlapping leave', 'status' => 'approved',
        ]);

        $warnings = app(LeaveOverlapService::class)->teamConflictWarnings(
            $a->id, now()->addDays(4), now()->addDays(5)
        );

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Colleague B', $warnings[0]);
    }

    // ── Approval chain: department head resolution ───────────────────────

    /** @test */
    public function department_head_is_resolved_from_root_designation(): void
    {
        $dept = Department::create(['name' => 'Engineering']);
        $rootDesig = Designation::create(['title' => 'Head of Engineering', 'department_id' => $dept->id, 'parent_id' => null]);
        $childDesig = Designation::create(['title' => 'Engineer', 'department_id' => $dept->id, 'parent_id' => $rootDesig->id]);

        $head = User::factory()->create(['department_id' => $dept->id, 'designation_id' => $rootDesig->id]);
        $manager = User::factory()->create(['department_id' => $dept->id, 'designation_id' => $childDesig->id]);
        $employee = User::factory()->create([
            'department_id' => $dept->id, 'designation_id' => $childDesig->id,
            'report_to' => $manager->id,
        ]);

        $t = $this->makeType(['requires_approval' => true, 'auto_approve' => false]);
        $leave = Leave::create([
            'user_id' => $employee->id, 'leave_type' => $t->id,
            'from_date' => now()->addDays(5), 'to_date' => now()->addDays(5),
            'no_of_days' => 1, 'reason' => 'chain resolution test', 'status' => 'pending',
        ]);

        $chain = app(LeaveApprovalService::class)->buildApprovalChain($leave);
        $approverIds = array_column($chain, 'approver_id');

        $this->assertContains($head->id, $approverIds, 'Root-designation holder should be the level-2 department head.');
    }
}
