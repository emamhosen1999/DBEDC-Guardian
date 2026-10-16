<?php

namespace Tests\Feature\Attendance;

use App\Exports\AttendancePerEmployeeSummaryExport;
use App\Models\HRM\Attendance;
use App\Models\HRM\CompOffLedger;
use App\Models\HRM\Holiday;
use App\Models\HRM\OvertimeRequest;
use App\Models\HRM\ReplacementLeaveRequest;
use App\Models\HRM\RosterDay;
use App\Models\HRM\RosterDayChange;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\User;
use App\Notifications\Attendance\ReplacementLeaveExpiringNotification;
use App\Services\Attachments\AttachmentRejected;
use App\Services\Attendance\AttendanceReportService;
use App\Services\Attendance\CompOffService;
use App\Services\Attendance\Contracts\ReplacementOffSource;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\OvertimeEligibilityService;
use App\Services\Attendance\OvertimeService;
use App\Services\Attendance\ReplacementLeaveService;
use App\Support\Attendance\AttendanceSymbol;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Support\Attachments\MakesAttachments;
use Tests\TestCase;

/**
 * Day-based overtime (owner policy 2026-10-15): claims only for holiday / rostered rest-day work, credit 1 / 0.5 / none,
 * replacement leave with 60-day expiry, FIFO use and the replacement date becoming a rostered off.
 *
 * Fixture week (today = Tue 2026-10-20): the employee works every day except WEDNESDAY (a staggered rest day, not Friday).
 *   rest day: Wed 2026-10-07 and Wed 2026-10-14; Monday 2026-10-12 is a normal holiday; Wed 2026-10-14 is ALSO a holiday.
 */
class DayBasedOvertimeTest extends TestCase
{
    use MakesAttachments, RefreshDatabase;

    private User $employee;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->fakeAttachments();
        Notification::fake();
        $this->app->forgetInstance(ScheduleResolver::class);

        $this->manager = User::factory()->create();
        $this->employee = User::factory()->create(['report_to' => $this->manager->id]);
        $this->rostered($this->employee, [Carbon::parse('2026-10-07'), Carbon::parse('2026-10-14'), Carbon::parse('2026-10-21'), Carbon::parse('2026-10-28')]);
        Holiday::create(['title' => 'National day', 'from_date' => '2026-10-12', 'to_date' => '2026-10-12', 'is_active' => true]);
        Holiday::create(['title' => 'Festival on a rest day', 'from_date' => '2026-10-14', 'to_date' => '2026-10-14', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Works every day on a 09:00-17:30 shift (480 min), with the given dates as rostered rest days. */
    private function rostered(User $user, array $restDays): void
    {
        $shift = Shift::factory()->create();
        ShiftAssignment::factory()->create(['scope_type' => 'user', 'scope_id' => $user->id, 'shift_id' => $shift->id, 'effective_from' => '2026-01-01', 'anchor_date' => '2026-01-01']);
        foreach ($restDays as $d) {
            RosterDay::create(['user_id' => $user->id, 'date' => $d->toDateString(), 'shift_id' => null, 'source' => 'manual', 'locked' => true]);
        }
    }

    private function punch(string $date, string $in = '09:00', ?string $out = '17:00', ?User $user = null): void
    {
        $user ??= $this->employee;
        Attendance::create([
            'user_id' => $user->id, 'date' => $date, 'punchin' => Carbon::parse("$date $in"),
            'punchout' => $out ? Carbon::parse("$date $out") : null,
        ]);
    }

    private function claim(string $date, string $compensation, array $extra = [], ?User $user = null)
    {
        $user ??= $this->employee;

        return app(OvertimeService::class)->request((string) $user->id, [
            'date' => $date, 'compensation' => $compensation, 'attachment_tokens' => [$this->clearToken($user)],
        ] + $extra);
    }

    private function refusal(callable $fn): string
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }
        $this->fail('Expected the claim to be refused.');
    }

    // ---- which days can be claimed -------------------------------------------------------------

    public function test_a_normal_working_day_is_refused_however_late_the_punch_out(): void
    {
        $this->punch('2026-10-16', '09:00', '23:30'); // Friday: this employee works Fridays
        $msg = $this->refusal(fn () => $this->claim('2026-10-16', 'payment'));
        $this->assertSame('Overtime applies only to work on your rest days and holidays.', $msg);
    }

    public function test_the_rest_day_follows_the_roster_not_friday_for_all(): void
    {
        $other = User::factory()->create(['report_to' => $this->manager->id]);
        $this->rostered($other, [Carbon::parse('2026-10-16')]); // Friday off for the other person
        $this->punch('2026-10-16', '09:00', '17:00', $other);
        $this->punch('2026-10-16'); // the same Friday is a working day for the employee

        $this->assertSame('rest_day', app(OvertimeEligibilityService::class)->evaluate((string) $other->id, Carbon::parse('2026-10-16'))['day_type']);
        $this->assertSame('working_day', app(OvertimeEligibilityService::class)->evaluate((string) $this->employee->id, Carbon::parse('2026-10-16'))['day_type']);
        $this->assertSame(1.0, $this->claim('2026-10-16', 'payment', [], $other)->day_credit);
    }

    public function test_a_normal_holiday_allows_overtime_pay_only(): void
    {
        $this->punch('2026-10-12');
        $check = app(OvertimeEligibilityService::class)->evaluate((string) $this->employee->id, Carbon::parse('2026-10-12'));
        $this->assertSame('holiday', $check['day_type']);
        $this->assertSame(['payment'], $check['compensations']);
        $this->refusal(fn () => $this->claim('2026-10-12', 'replacement'));
        $ot = $this->claim('2026-10-12', 'payment');
        $this->assertSame('holiday', $ot->claim_type);
        $this->assertSame('payment', $ot->compensation);
    }

    public function test_a_normal_rest_day_allows_pay_or_replacement(): void
    {
        $this->punch('2026-10-07');
        $check = app(OvertimeEligibilityService::class)->evaluate((string) $this->employee->id, Carbon::parse('2026-10-07'));
        $this->assertSame('rest_day', $check['day_type']);
        $this->assertEqualsCanonicalizing(['payment', 'replacement'], $check['compensations']);
        $this->assertSame('replacement', $this->claim('2026-10-07', 'replacement')->compensation);
    }

    public function test_a_holiday_on_the_rest_day_worked_offers_both_choices(): void
    {
        $this->punch('2026-10-14');
        $check = app(OvertimeEligibilityService::class)->evaluate((string) $this->employee->id, Carbon::parse('2026-10-14'));
        $this->assertSame('rest_day', $check['day_type']);
        $this->assertTrue($check['holiday_on_rest_day']);
        $this->assertEqualsCanonicalizing(['payment', 'replacement'], $check['compensations']);
        $this->assertSame('replacement', $this->claim('2026-10-14', 'replacement')->compensation);
    }

    public function test_a_holiday_on_the_rest_day_not_worked_credits_nothing_and_shows_no_yellow(): void
    {
        $check = app(OvertimeEligibilityService::class)->evaluate((string) $this->employee->id, Carbon::parse('2026-10-14'));
        $this->assertFalse($check['eligible']);
        $this->assertSame(0.0, $check['credit']);
        $this->assertCount(0, app(OvertimeEligibilityService::class)->unclaimed((string) $this->employee->id), 'no helper entry');
        $marks = AttendanceSymbol::marks(AttendanceSymbol::FESTIVAL, true, false, null, false);
        $this->assertNotSame('ot_unapplied', $marks['fill']);
        $this->refusal(fn () => $this->claim('2026-10-14', 'payment'));
    }

    public function test_the_register_marks_keep_festival_colour_and_the_rest_day_border_for_a_holiday_rest_day(): void
    {
        $unclaimed = AttendanceSymbol::marks(AttendanceSymbol::PRESENT, true, true, null, true);
        $this->assertTrue($unclaimed['border']);
        $this->assertSame('ot_unapplied', $unclaimed['fill'], 'yellow until claimed');
        $approved = AttendanceSymbol::marks(AttendanceSymbol::PRESENT, true, true, AttendanceSymbol::OT_APPROVED, true);
        $this->assertSame('festival_worked', $approved['fill'], 'the festival colouring stays once the claim is approved');
        $this->assertTrue($approved['border']);
        $this->assertSame('ot_green', $approved['font']);
    }

    // ---- the credit ----------------------------------------------------------------------------

    public function test_the_credit_is_one_day_from_half_the_shift_half_a_day_from_two_hours_else_nothing(): void
    {
        $svc = app(OvertimeEligibilityService::class);
        $this->assertSame(1.0, $svc->credit(480, 480));
        $this->assertSame(1.0, $svc->credit(240, 480), 'exactly half the shift');
        $this->assertSame(0.5, $svc->credit(239, 480));
        $this->assertSame(0.5, $svc->credit(120, 480), 'exactly 2 h');
        $this->assertSame(0.0, $svc->credit(119, 480));

        $this->punch('2026-10-07', '09:00', '11:30'); // 150 min on a rest day
        $this->assertSame(0.5, $svc->evaluate((string) $this->employee->id, Carbon::parse('2026-10-07'))['credit']);
    }

    public function test_under_two_hours_cannot_be_claimed(): void
    {
        $this->punch('2026-10-07', '09:00', '10:30');
        $this->assertStringContainsString('At least 2 hours', $this->refusal(fn () => $this->claim('2026-10-07', 'replacement')));
    }

    // ---- a missing punch-out -------------------------------------------------------------------

    public function test_rest_day_without_punch_out_can_claim_replacement_on_a_declared_end_time_and_is_flagged(): void
    {
        $this->punch('2026-10-07', '09:00', null);
        $check = app(OvertimeEligibilityService::class)->evaluate((string) $this->employee->id, Carbon::parse('2026-10-07'));
        $this->assertTrue($check['eligible']);
        $this->assertTrue($check['punch_out_missing']);
        $this->assertSame('declare_end', $check['code']);

        $this->refusal(fn () => $this->claim('2026-10-07', 'replacement')); // the end time must be declared
        $ot = $this->claim('2026-10-07', 'replacement', ['declared_end_time' => '15:00']);
        $this->assertTrue($ot->end_time_declared);
        $this->assertSame(1.0, $ot->day_credit);
        $this->assertSame(360, $ot->requested_minutes);
    }

    public function test_a_declared_span_under_two_hours_is_refused(): void
    {
        $this->punch('2026-10-07', '09:00', null);
        $this->assertStringContainsString('At least 2 hours', $this->refusal(fn () => $this->claim('2026-10-07', 'replacement', ['declared_end_time' => '10:30'])));
    }

    public function test_pay_without_punch_out_follows_the_config_switch_and_holidays_are_always_allowed(): void
    {
        $this->punch('2026-10-07', '09:00', null);
        $this->punch('2026-10-12', '09:00', null);
        // Default: rest-day pay on a declared end time is allowed (owner: consistent rules, to be confirmed).
        $this->assertSame('payment', $this->claim('2026-10-07', 'payment', ['declared_end_time' => '15:00'])->compensation);

        config(['overtime_claims.allow_ot_pay_without_punch_out' => false]);
        Attendance::query()->delete();
        $this->punch('2026-10-14', '09:00', null); // holiday on the rest day: rest-day rule
        $this->refusal(fn () => $this->claim('2026-10-14', 'payment', ['declared_end_time' => '15:00']));
        $this->assertSame('replacement', $this->claim('2026-10-14', 'replacement', ['declared_end_time' => '15:00'])->compensation);

        $this->punch('2026-10-12', '09:00', null); // a normal holiday: overtime on a declared end time is always allowed
        $ot = $this->claim('2026-10-12', 'payment', ['declared_end_time' => '15:00']);
        $this->assertTrue($ot->end_time_declared);
    }

    public function test_the_approver_can_adjust_the_credit_with_a_recorded_reason(): void
    {
        $this->punch('2026-10-07', '09:00', null);
        $ot = $this->claim('2026-10-07', 'replacement', ['declared_end_time' => '15:00']);
        $svc = app(OvertimeService::class);

        $this->assertFalse($svc->approve($ot, $this->manager, null, false, null, 0.5, '')['success'], 'a reason is mandatory');
        $res = $svc->approve($ot->fresh(), $this->manager, 'ok', false, null, 0.5, 'Left early by the security log');
        $this->assertTrue($res['success']);
        $ot->refresh();
        $this->assertSame(0.5, $ot->day_credit);
        $this->assertSame(1.0, $ot->original_day_credit);
        $this->assertSame('Left early by the security log', $ot->credit_adjust_reason);
        $this->assertSame((string) $this->manager->employee_id, $ot->credit_adjusted_by);
        $this->assertSame(0.5, app(CompOffService::class)->balance((string) $this->employee->id));
    }

    public function test_the_helper_lists_only_rest_day_and_holiday_work_with_the_credit_worked_out(): void
    {
        $this->punch('2026-10-16', '09:00', '23:00'); // working-day extra hours: never listed
        $this->punch('2026-10-14', '09:00', '17:00'); // holiday on the rest day: listed, 1 day
        $this->punch('2026-10-07', '09:00', null);    // rest day, no punch-out: listed to claim replacement
        $list = app(OvertimeEligibilityService::class)->unclaimed((string) $this->employee->id);
        $this->assertEqualsCanonicalizing(['2026-10-07', '2026-10-14'], $list->pluck('date')->all());
        $this->assertSame(1.0, $list->firstWhere('date', '2026-10-14')['credit']);
        $this->assertSame('declare_end', $list->firstWhere('date', '2026-10-07')['code']);

        $this->claim('2026-10-14', 'payment');
        $this->assertSame(['2026-10-07'], app(OvertimeEligibilityService::class)->unclaimed((string) $this->employee->id)->pluck('date')->all(), 'a claimed day leaves the list');
    }

    // ---- documents -----------------------------------------------------------------------------

    public function test_every_claim_and_every_replacement_request_needs_a_clear_document(): void
    {
        $this->punch('2026-10-07');
        $this->expectException(AttachmentRejected::class);
        app(OvertimeService::class)->request((string) $this->employee->id, ['date' => '2026-10-07', 'compensation' => 'payment']);
    }

    public function test_a_replacement_request_without_a_document_is_refused(): void
    {
        $this->credit('2026-10-07', 1.0);
        $this->expectException(AttachmentRejected::class);
        app(ReplacementLeaveService::class)->request((string) $this->employee->id, ['date' => '2026-10-22', 'days' => 1]);
    }

    // ---- replacement leave ---------------------------------------------------------------------

    private function credit(string $workDate, float $days): CompOffLedger
    {
        return app(CompOffService::class)->credit((string) $this->employee->id, $days, 'overtime', null, 'test', Carbon::parse($workDate));
    }

    private function replacement(string $date, float $days = 1.0): ReplacementLeaveRequest
    {
        return app(ReplacementLeaveService::class)->request((string) $this->employee->id, [
            'date' => $date, 'days' => $days, 'attachment_tokens' => [$this->clearToken($this->employee)],
        ]);
    }

    public function test_an_approved_replacement_claim_credits_the_day_with_a_60_day_expiry_and_pay_credits_nothing(): void
    {
        $this->punch('2026-10-07');
        $this->punch('2026-10-14');
        $svc = app(OvertimeService::class);
        $pay = $this->claim('2026-10-07', 'payment');
        $repl = $this->claim('2026-10-14', 'replacement');
        $svc->approve($pay, $this->manager, null);
        $svc->approve($repl, $this->manager, null);

        $credit = CompOffLedger::where('days', '>', 0)->sole();
        $this->assertSame(1.0, $credit->days);
        $this->assertSame('2026-10-14', $credit->work_date->toDateString());
        $this->assertSame('2026-12-13', $credit->expires_at->toDateString(), '60 days after the work date');
    }

    public function test_replacement_days_are_drawn_first_expiring_first_and_a_pending_request_reserves_its_credit(): void
    {
        $older = $this->credit('2026-10-01', 1.0);
        $newer = $this->credit('2026-10-07', 1.0);

        $first = $this->replacement('2026-10-22');
        $this->assertSame($older->id, $first->allocations[0]['credit_id'], 'the credit expiring first is used first');
        $second = $this->replacement('2026-10-23');
        $this->assertSame($newer->id, $second->allocations[0]['credit_id'], 'the reserved credit is not used twice');
        $this->assertSame('You do not have enough replacement leave for that.', $this->refusal(fn () => $this->replacement('2026-10-26')));
    }

    public function test_a_half_day_credit_can_only_be_used_as_a_half_day(): void
    {
        $this->credit('2026-10-07', 0.5);
        $this->assertSame('A half-day credit can only be used as a half day.', $this->refusal(fn () => $this->replacement('2026-10-22', 1.0)));
        $this->assertSame(0.5, $this->replacement('2026-10-22', 0.5)->days);
    }

    public function test_the_replacement_date_must_be_a_rostered_working_day_not_a_holiday_or_the_past(): void
    {
        $this->credit('2026-10-07', 1.0);
        $this->assertStringContainsString('rostered to work', $this->refusal(fn () => $this->replacement('2026-10-21'))); // Wednesday = rest day
        $this->assertStringContainsString('today or a future date', $this->refusal(fn () => $this->replacement('2026-10-19')));
        Holiday::create(['title' => 'Later holiday', 'from_date' => '2026-10-22', 'to_date' => '2026-10-22', 'is_active' => true]);
        $this->assertStringContainsString('holiday', $this->refusal(fn () => $this->replacement('2026-10-22')));
    }

    public function test_approval_makes_the_date_a_rostered_off_with_history_and_the_register_remark_source(): void
    {
        $this->credit('2026-10-07', 1.0);
        $req = $this->replacement('2026-10-22');
        $res = app(ReplacementLeaveService::class)->approve($req, $this->manager);
        $this->assertTrue($res['success']);

        $day = RosterDay::where('user_id', $this->employee->id)->whereDate('date', '2026-10-22')->sole();
        $this->assertNull($day->shift_id);
        $this->assertTrue($day->locked);
        $this->assertFalse(app(ScheduleResolver::class)->resolve((string) $this->employee->id, Carbon::parse('2026-10-22'))->isWorkingDay, 'it is a weekly off now');
        $this->assertTrue(RosterDayChange::where('roster_day_id', $day->id)->where('field', 'shift_id')->where('actor_id', (string) $this->manager->employee_id)->exists(), 'audited in the roster change history');
        $this->assertSame(0.0, app(CompOffService::class)->balance((string) $this->employee->id));
        $this->assertSame(-1.0, (float) CompOffLedger::where('source_type', 'used')->sole()->days);

        $offs = app(ReplacementOffSource::class)->between([(string) $this->employee->id], Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $this->assertSame([['off' => '2026-10-22', 'worked' => '2026-10-07']], $offs[(string) $this->employee->id]);
    }

    public function test_credit_expires_after_60_days_into_the_ledger_and_reminders_go_out_at_14_and_3_days(): void
    {
        $svc = app(CompOffService::class);
        $this->credit('2026-09-04', 1.0); // expires 2026-11-03: 14 days after today (10-20)
        $gone = $this->credit('2026-08-01', 1.0);     // expired 2026-09-30

        $this->assertSame(1, $svc->expire(), 'expired credit is written to the ledger');
        $row = CompOffLedger::where('source_type', 'expired')->sole();
        $this->assertSame($gone->id, $row->credit_id);
        $this->assertSame(-1.0, $row->days);
        $this->assertSame(1.0, $svc->balance((string) $this->employee->id), 'only the live credit remains; nothing silently dropped');
        $this->assertSame(0, $svc->expire(), 'idempotent');

        $this->assertSame(1, $svc->remind());
        Notification::assertSentToTimes($this->employee, ReplacementLeaveExpiringNotification::class, 1);
        $this->assertSame(0, $svc->remind(), 'the 14-day reminder is sent once');

        Carbon::setTestNow('2026-10-31 07:00:00'); // 3 days before
        $this->assertSame(1, $svc->remind());
        Notification::assertSentToTimes($this->employee, ReplacementLeaveExpiringNotification::class, 2);
    }

    public function test_the_summary_totals_ot_days_and_replacement_earned_used_and_expiring(): void
    {
        Role::firstOrCreate(['name' => 'Employee']);
        $this->employee->assignRole('Employee'); // the summary lists employees by role
        $svc = app(OvertimeService::class);
        $this->punch('2026-10-12');
        $this->punch('2026-10-07');
        $this->punch('2026-10-14');
        $svc->approve($this->claim('2026-10-12', 'payment'), $this->manager, null);   // holiday, paid: 1 OT day (holiday)
        $svc->approve($this->claim('2026-10-07', 'payment'), $this->manager, null);   // rest day, paid: 1 OT day (rest day)
        $svc->approve($this->claim('2026-10-14', 'replacement'), $this->manager, null); // earns 1 replacement day (expires 12-13)
        $this->credit('2026-09-10', 1.0); // earlier credit, expires 11-09: inside the 14 days after the period end? no (20 days) -> not expiring
        $this->credit('2026-08-25', 0.5); // expires 10-24: inside the window

        $row = collect(app(AttendanceReportService::class)
            ->getPerEmployeeMonthlySummary(2026, 10, null, [(string) $this->employee->employee_id], Carbon::parse('2026-10-01'), Carbon::parse('2026-10-20'))['rows'])
            ->firstWhere('employee_id', $this->employee->employee_id);

        $this->assertSame(1.0, $row['ot_days_holiday']);
        $this->assertSame(1.0, $row['ot_days_rest']);
        $this->assertSame(1.0, $row['repl_earned'], 'earned by work date inside the period');
        $this->assertSame(0.0, $row['repl_used']);
        $this->assertSame(0.5, $row['repl_expiring']);
        $this->assertCount(19, AttendancePerEmployeeSummaryExport::HEADINGS);
        $this->assertCount(19, AttendancePerEmployeeSummaryExport::WIDTHS);
    }

    public function test_legacy_minute_based_requests_stay_read_only_history(): void
    {
        $legacy = OvertimeRequest::factory()->create(['user_id' => $this->employee->id, 'date' => '2026-06-18', 'requested_minutes' => 120]);
        $this->assertTrue($legacy->isLegacy());
        $this->assertSame('legacy_hours', $legacy->fresh()->claim_type);
        $res = app(OvertimeService::class)->approve($legacy, $this->manager, null, true);
        $this->assertSame(0, CompOffLedger::count(), 'no replacement credit is made from a legacy request');
        $this->assertTrue($legacy->fresh()->isLegacy());
    }
}
