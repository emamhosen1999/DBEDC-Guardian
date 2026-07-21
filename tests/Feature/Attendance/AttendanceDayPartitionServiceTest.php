<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\Department;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Services\Attendance\AttendanceDayPartitionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AttendanceDayPartitionServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceDayPartitionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        $this->service = app(AttendanceDayPartitionService::class);
    }

    private function employee(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        return $user;
    }

    private function roster(User $user, string $date, ?int $shiftId): void
    {
        RosterDay::create([
            'user_id' => $user->id,
            'date' => $date,
            'shift_id' => $shiftId,
            'source' => 'manual',
            'locked' => true,
        ]);
    }

    public function test_classifies_present_absent_upcoming_off_and_leave_including_night_shift(): void
    {
        $date = '2026-07-15';
        Carbon::setTestNow(Carbon::parse($date.' 12:00:00'));

        $morning = Shift::factory()->create(['code' => 'MRN', 'start_time' => '09:00', 'end_time' => '17:00']);
        $evening = Shift::factory()->create(['code' => 'EVE', 'start_time' => '20:00', 'end_time' => '23:00']);
        $night = Shift::factory()->create(['code' => 'NGT', 'start_time' => '23:00', 'end_time' => '07:00', 'crosses_midnight' => true]);

        // present: rostered morning + a real punch-in.
        $empPresent = $this->employee();
        $this->roster($empPresent, $date, $morning->id);
        Attendance::create(['user_id' => $empPresent->id, 'date' => $date, 'punchin' => $date.' 09:05:00']);

        // absent: rostered morning (09:00 already passed at noon), no punch.
        $empAbsent = $this->employee();
        $this->roster($empAbsent, $date, $morning->id);

        // upcoming: rostered evening (20:00 not passed at noon).
        $empUpcoming = $this->employee();
        $this->roster($empUpcoming, $date, $evening->id);

        // upcoming (night): rostered 23:00, not passed at noon.
        $empNight = $this->employee();
        $this->roster($empNight, $date, $night->id);

        // off: rostered explicitly off (null shift).
        $empOff = $this->employee();
        $this->roster($empOff, $date, null);

        // leave: rostered a working morning shift (would be absent) BUT approved leave — leave wins.
        $leaveType = LeaveSetting::factory()->create(['type' => 'Annual Leave']);
        $empLeave = $this->employee();
        $this->roster($empLeave, $date, $morning->id);
        Leave::factory()->create([
            'user_id' => $empLeave->id,
            'leave_type' => $leaveType->id,
            'from_date' => $date,
            'to_date' => $date,
            'status' => 'Approved',
        ]);

        $data = $this->service->partition($date);

        $this->assertSame($date, $data['date']);
        $this->assertSame(1, $data['counts']['present']);
        $this->assertSame(1, $data['counts']['absent']);
        $this->assertSame(2, $data['counts']['upcoming']);
        $this->assertSame(2, $data['counts']['off_leave']);
        $this->assertSame(6, $data['counts']['total']);

        // present row shape
        $present = collect($data['present'])->firstWhere('user.id', $empPresent->id);
        $this->assertNotNull($present);
        $this->assertSame('09:05', $present['punch_in']);
        $this->assertSame('MRN', $present['shift']['code']);

        // absent row: morning shift, start passed
        $absent = collect($data['absent'])->firstWhere('user.id', $empAbsent->id);
        $this->assertNotNull($absent);
        $this->assertSame('09:00', $absent['shift']['start']);

        // upcoming contains both evening and the NIGHT shift (23:00 not yet passed)
        $upcomingIds = collect($data['upcoming'])->pluck('user.id')->all();
        $this->assertContains($empUpcoming->id, $upcomingIds);
        $this->assertContains($empNight->id, $upcomingIds);
        $nightRow = collect($data['upcoming'])->firstWhere('user.id', $empNight->id);
        $this->assertSame('23:00', $nightRow['shift']['start']);

        // off_leave: one off, one leave with the label
        $off = collect($data['off_leave'])->firstWhere('user.id', $empOff->id);
        $this->assertSame('off', $off['kind']);
        $this->assertNull($off['leave_type']);

        $leave = collect($data['off_leave'])->firstWhere('user.id', $empLeave->id);
        $this->assertSame('leave', $leave['kind']);
        $this->assertSame('Annual Leave', $leave['leave_type']);

        Carbon::setTestNow();
    }

    public function test_counts_always_sum_to_total(): void
    {
        $date = '2026-07-15';
        Carbon::setTestNow(Carbon::parse($date.' 12:00:00'));

        $morning = Shift::factory()->create(['code' => 'MRN', 'start_time' => '09:00', 'end_time' => '17:00']);
        $evening = Shift::factory()->create(['code' => 'EVE', 'start_time' => '20:00', 'end_time' => '23:00']);

        for ($i = 0; $i < 3; $i++) {
            $this->roster($this->employee(), $date, $morning->id);       // absent
        }
        $this->roster($this->employee(), $date, $evening->id);           // upcoming
        $this->roster($this->employee(), $date, null);                   // off
        $present = $this->employee();                                    // present
        $this->roster($present, $date, $morning->id);
        Attendance::create(['user_id' => $present->id, 'date' => $date, 'punchin' => $date.' 09:10:00']);

        $c = $this->service->partition($date)['counts'];

        $this->assertSame(
            $c['total'],
            $c['present'] + $c['absent'] + $c['upcoming'] + $c['off_leave'],
        );
        $this->assertSame(6, $c['total']);

        Carbon::setTestNow();
    }

    public function test_scopes_to_department_when_provided(): void
    {
        $date = '2026-07-15';
        Carbon::setTestNow(Carbon::parse($date.' 12:00:00'));
        $morning = Shift::factory()->create(['start_time' => '09:00', 'end_time' => '17:00']);

        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();

        $inA = $this->employee();
        $inA->department_id = $deptA->id;
        $inA->save();
        $this->roster($inA, $date, $morning->id);

        $inB = $this->employee();
        $inB->department_id = $deptB->id;
        $inB->save();
        $this->roster($inB, $date, $morning->id);

        $scoped = $this->service->partition($date, $deptA->id);
        $this->assertSame(1, $scoped['counts']['total']);
        $this->assertSame($inA->id, $scoped['absent'][0]['user']['id']);

        $all = $this->service->partition($date);
        $this->assertSame(2, $all['counts']['total']);

        Carbon::setTestNow();
    }

    public function test_past_date_has_empty_upcoming(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
        $pastDate = '2026-07-10';

        $morning = Shift::factory()->create(['start_time' => '09:00', 'end_time' => '17:00']);
        $evening = Shift::factory()->create(['start_time' => '20:00', 'end_time' => '23:00']);

        // Even an evening shift on a past date has a start that is already behind now.
        $emp = $this->employee();
        $this->roster($emp, $pastDate, $evening->id);
        $emp2 = $this->employee();
        $this->roster($emp2, $pastDate, $morning->id);

        $data = $this->service->partition($pastDate);

        $this->assertSame([], $data['upcoming']);
        $this->assertSame(0, $data['counts']['upcoming']);
        $this->assertSame(2, $data['counts']['absent']);

        Carbon::setTestNow();
    }

    public function test_night_shift_becomes_absent_once_start_has_passed(): void
    {
        $date = '2026-07-15';
        $night = Shift::factory()->create(['code' => 'NGT', 'start_time' => '23:00', 'end_time' => '07:00', 'crosses_midnight' => true]);
        $emp = $this->employee();
        $this->roster($emp, $date, $night->id);

        // 22:30 — before the 23:00 start → upcoming
        Carbon::setTestNow(Carbon::parse($date.' 22:30:00'));
        $before = $this->service->partition($date);
        $this->assertSame(1, $before['counts']['upcoming']);
        $this->assertSame(0, $before['counts']['absent']);

        // 23:30 — after the 23:00 start, still no punch → absent
        Carbon::setTestNow(Carbon::parse($date.' 23:30:00'));
        $after = $this->service->partition($date);
        $this->assertSame(0, $after['counts']['upcoming']);
        $this->assertSame(1, $after['counts']['absent']);

        Carbon::setTestNow();
    }

    public function test_mark_present_is_idempotent_and_uses_shift_start(): void
    {
        $date = '2026-07-15';
        $morning = Shift::factory()->create(['start_time' => '08:30', 'end_time' => '17:00']);
        $emp = $this->employee();
        $this->roster($emp, $date, $morning->id);

        $this->service->markPresent($emp->id, $date);
        $this->service->markPresent($emp->id, $date);

        $rows = Attendance::where('user_id', $emp->id)->whereDate('date', $date)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('08:30', Carbon::parse($rows->first()->punchin)->format('H:i'));
        $this->assertSame('√', $rows->first()->symbol);

        // and the person now classifies as present
        Carbon::setTestNow(Carbon::parse($date.' 12:00:00'));
        $data = $this->service->partition($date);
        $this->assertSame(1, $data['counts']['present']);
        $this->assertSame($emp->id, $data['present'][0]['user']['id']);
        Carbon::setTestNow();
    }
}
