<?php

namespace Tests\Feature\Leave;

use App\Models\HRM\Attendance;
use App\Models\HRM\Holiday;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Leave\CompOffService;
use App\Services\Leave\LeaveCrudService;
use App\Services\Leave\LeaveLedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompOffAndAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompOffType(): LeaveSetting
    {
        return LeaveSetting::create([
            'type' => 'Compensatory Off', 'days' => 0,
            'accrual_method' => 'none', 'is_comp_off' => true,
            'requires_approval' => true, 'auto_approve' => false,
        ]);
    }

    private function punch(int $userId, Carbon $date): void
    {
        Attendance::create([
            'user_id' => $userId,
            'date' => $date->toDateString(),
            'punchin' => $date->copy()->setTime(9, 0),
            'punchout' => $date->copy()->setTime(17, 0),
        ]);
    }

    // ── Comp-off banking ─────────────────────────────────────────────────

    /** @test */
    public function work_on_a_weekend_banks_comp_off_and_is_idempotent(): void
    {
        $type = $this->makeCompOffType();
        $u = User::factory()->create();

        $sunday = Carbon::now()->startOfWeek()->subDay(); // last Sunday
        $this->punch($u->id, $sunday);

        $svc = app(CompOffService::class);
        $this->assertSame(1, $svc->scan($sunday, $sunday));
        $this->assertSame(1.0, app(LeaveLedgerService::class)->balance($u->id, $type->id, (int) $sunday->year));

        // Re-scan never double-grants.
        $this->assertSame(0, $svc->scan($sunday, $sunday));
        $this->assertSame(1, LeaveLedger::where('txn_type', 'comp_off')->count());
    }

    /** @test */
    public function work_on_a_holiday_banks_comp_off(): void
    {
        $type = $this->makeCompOffType();
        $u = User::factory()->create();

        // A guaranteed weekday holiday (mid-week).
        $wednesday = Carbon::now()->startOfWeek()->addDays(2);
        Holiday::create(['title' => 'Test Holiday', 'from_date' => $wednesday->toDateString(), 'to_date' => $wednesday->toDateString()]);
        $this->punch($u->id, $wednesday);

        $this->assertSame(1, app(CompOffService::class)->scan($wednesday, $wednesday));
        $this->assertSame(1.0, app(LeaveLedgerService::class)->balance($u->id, $type->id, (int) $wednesday->year));
    }

    /** @test */
    public function work_during_own_approved_leave_banks_comp_off(): void
    {
        $type = $this->makeCompOffType();
        $u = User::factory()->create();
        $other = LeaveSetting::create(['type' => 'Casual', 'days' => 10]);

        $tuesday = Carbon::now()->startOfWeek()->addDay();
        Leave::create([
            'user_id' => $u->id, 'leave_type' => $other->id,
            'from_date' => $tuesday->toDateString(), 'to_date' => $tuesday->toDateString(),
            'no_of_days' => 1, 'is_half_day' => false,
            'reason' => 'approved but worked anyway', 'status' => 'approved',
        ]);
        $this->punch($u->id, $tuesday);

        $this->assertSame(1, app(CompOffService::class)->scan($tuesday, $tuesday));
        $row = LeaveLedger::where('txn_type', 'comp_off')->first();
        $this->assertStringContainsString('leave-day', $row->reason);
    }

    /** @test */
    public function normal_working_day_banks_nothing_and_no_type_flag_means_noop(): void
    {
        $u = User::factory()->create();
        $monday = Carbon::now()->startOfWeek();
        $this->punch($u->id, $monday);

        // No is_comp_off type at all -> no-op.
        $this->assertSame(0, app(CompOffService::class)->scan($monday, $monday));

        // With the type configured, an owed working day still banks nothing.
        $this->makeCompOffType();
        $this->assertSame(0, app(CompOffService::class)->scan($monday, $monday));
        $this->assertSame(0, LeaveLedger::count());
    }

    /** @test */
    public function banked_comp_off_is_spendable_as_normal_leave(): void
    {
        $type = $this->makeCompOffType();
        $u = User::factory()->create(['date_of_joining' => '2020-01-01']);

        $sunday = Carbon::now()->startOfWeek()->subDay();
        $this->punch($u->id, $sunday);
        app(CompOffService::class)->scan($sunday, $sunday);

        // Spend the banked day on a weekday.
        $thursday = Carbon::now()->startOfWeek()->addDays(3);
        $leave = app(LeaveCrudService::class)->createLeave([
            'user_id' => $u->id, 'leaveType' => $type->type,
            'fromDate' => $thursday->toDateString(), 'toDate' => $thursday->toDateString(),
            'leaveReason' => 'using my banked comp-off',
        ]);

        $this->assertNotNull($leave->id);

        // A second day is rejected — balance exhausted (tracked via the grant).
        $friday = $thursday->copy()->addDay();
        try {
            app(LeaveCrudService::class)->createLeave([
                'user_id' => $u->id, 'leaveType' => $type->type,
                'fromDate' => $friday->toDateString(), 'toDate' => $friday->toDateString(),
                'leaveReason' => 'no comp-off balance left',
            ]);
            $this->fail('Expected insufficient-balance rejection.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            throw $e; // fail() extends RuntimeException — never swallow it
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('Insufficient leave balance', $e->getMessage());
        }
    }

    // ── Attachment requirement gate ──────────────────────────────────────

    /** @test */
    public function long_sick_leave_without_document_is_rejected(): void
    {
        $u = User::factory()->create(['date_of_joining' => '2020-01-01']);
        LeaveSetting::create([
            'type' => 'Sick', 'days' => 14, 'requires_attachment_days' => 2,
            'requires_approval' => false, 'auto_approve' => true, 'allow_negative' => true,
        ]);

        $monday = Carbon::now()->startOfWeek();

        try {
            app(LeaveCrudService::class)->createLeave([
                'user_id' => $u->id, 'leaveType' => 'Sick',
                'fromDate' => $monday->toDateString(), 'toDate' => $monday->copy()->addDays(3)->toDateString(),
                'leaveReason' => 'four days sick no cert',
            ]);
            $this->fail('Expected attachment-required rejection.');
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('supporting document', $e->getMessage());
        }

        // Same request with a document passes the gate.
        $leave = app(LeaveCrudService::class)->createLeave([
            'user_id' => $u->id, 'leaveType' => 'Sick',
            'fromDate' => $monday->toDateString(), 'toDate' => $monday->copy()->addDays(3)->toDateString(),
            'leaveReason' => 'four days sick with cert',
            'hasAttachment' => true,
        ]);
        $this->assertNotNull($leave->id);
    }

    /** @test */
    public function short_leave_below_threshold_needs_no_document(): void
    {
        $u = User::factory()->create(['date_of_joining' => '2020-01-01']);
        LeaveSetting::create([
            'type' => 'Sick', 'days' => 14, 'requires_attachment_days' => 2,
            'requires_approval' => false, 'auto_approve' => true, 'allow_negative' => true,
        ]);

        $monday = Carbon::now()->startOfWeek();
        $leave = app(LeaveCrudService::class)->createLeave([
            'user_id' => $u->id, 'leaveType' => 'Sick',
            'fromDate' => $monday->toDateString(), 'toDate' => $monday->copy()->addDay()->toDateString(),
            'leaveReason' => 'two days sick leave',
        ]);

        $this->assertNotNull($leave->id);
    }
}
