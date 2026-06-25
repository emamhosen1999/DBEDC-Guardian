<?php

namespace Tests\Feature\Leave\Ledger;

use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Leave\LeaveCrudService;
use App\Services\Leave\LeaveLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveConsumptionWiringTest extends TestCase
{
    use RefreshDatabase;

    private function allWorkingDays(): void
    {
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

    /** @test */
    public function an_auto_approved_leave_posts_a_consumption_against_the_balance(): void
    {
        $this->allWorkingDays();
        $u = User::factory()->create();
        $t = LeaveSetting::create(['type' => 'Casual', 'days' => 10, 'requires_approval' => false, 'auto_approve' => true, 'accrual_rate' => 10]);
        app(LeaveLedgerService::class)->post($u->id, $t->id, 2026, 'opening', 10);

        app(LeaveCrudService::class)->createLeave([
            'user_id' => $u->id, 'leaveType' => 'Casual',
            'fromDate' => '2026-03-02', 'toDate' => '2026-03-04', // 3 working days
            'daysCount' => 3, 'leaveReason' => 'consume',
        ]);

        $this->assertSame(7.0, app(LeaveLedgerService::class)->balance($u->id, $t->id, 2026));
        $this->assertSame(1, LeaveLedger::where('txn_type', 'consumption')->count());
    }

    /** @test */
    public function applying_beyond_balance_is_rejected_unless_allow_negative(): void
    {
        $this->allWorkingDays();
        $u = User::factory()->create();
        $t = LeaveSetting::create(['type' => 'Casual', 'days' => 10, 'requires_approval' => false, 'auto_approve' => true, 'accrual_rate' => 10, 'allow_negative' => false]);
        app(LeaveLedgerService::class)->post($u->id, $t->id, 2026, 'opening', 2);

        $this->expectException(\RuntimeException::class);
        app(LeaveCrudService::class)->createLeave([
            'user_id' => $u->id, 'leaveType' => 'Casual',
            'fromDate' => '2026-03-02', 'toDate' => '2026-03-04', // 3 > 2 available
            'daysCount' => 3, 'leaveReason' => 'overdraw',
        ]);
    }

    /** @test */
    public function deleting_an_approved_leave_reverses_its_consumption(): void
    {
        $this->allWorkingDays();
        $u = User::factory()->create();
        $t = LeaveSetting::create(['type' => 'Casual', 'days' => 10, 'requires_approval' => false, 'auto_approve' => true, 'accrual_rate' => 10]);
        $ledger = app(LeaveLedgerService::class);
        $ledger->post($u->id, $t->id, 2026, 'opening', 10);

        $leave = app(LeaveCrudService::class)->createLeave([
            'user_id' => $u->id, 'leaveType' => 'Casual',
            'fromDate' => '2026-03-02', 'toDate' => '2026-03-02',
            'daysCount' => 1, 'leaveReason' => 'temp',
        ]);
        $this->assertSame(9.0, $ledger->balance($u->id, $t->id, 2026));

        app(LeaveCrudService::class)->deleteLeave($leave->id);
        $this->assertSame(10.0, $ledger->balance($u->id, $t->id, 2026));
    }
}
