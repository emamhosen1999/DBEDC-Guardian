<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AbsenceCase;
use App\Models\HRM\Holiday;
use App\Models\HRM\Offboarding;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * attendance:absence-streak drives legal escalation (show-cause, deemed resignation), so it must
 * never count an employee who has already left, nor a company holiday.
 * Regression: the offboarded-exclusion went through a users.id column that production no longer
 * has (employee_id is the primary key), which would crash the nightly job.
 */
class AbsenceStreakGuardsTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-07-15';

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shift = Shift::factory()->create(['start_time' => '08:00', 'end_time' => '16:00']);
    }

    private function rostered(string $employeeId): User
    {
        $user = User::factory()->create(['employee_id' => $employeeId]);
        RosterDay::create(['user_id' => $employeeId, 'date' => self::DAY, 'shift_id' => $this->shift->id, 'source' => 'manual', 'locked' => true]);

        return $user;
    }

    public function test_an_absent_rostered_employee_opens_a_case(): void
    {
        $this->rostered('S-100');

        Artisan::call('attendance:absence-streak', ['--date' => self::DAY]);

        $this->assertSame(1, AbsenceCase::where('user_id', 'S-100')->count());
    }

    public function test_an_employee_past_their_last_working_date_is_not_counted(): void
    {
        $this->rostered('S-100');
        $this->rostered('S-200');
        $this->actingAs(User::factory()->create()); // offboardings.created_by is stamped from the actor
        Offboarding::create([
            'employee_id' => 'S-200', 'initiation_date' => '2026-06-01', 'last_working_date' => '2026-07-01',
            'status' => Offboarding::STATUS_IN_PROGRESS, 'reason' => 'resignation',
        ]);

        Artisan::call('attendance:absence-streak', ['--date' => self::DAY]);

        $this->assertNull(AbsenceCase::where('user_id', 'S-200')->first());
        $this->assertNotNull(AbsenceCase::where('user_id', 'S-100')->first());
    }

    public function test_a_company_holiday_is_never_an_absence(): void
    {
        $this->rostered('S-100');
        Holiday::create(['title' => 'Special Day', 'from_date' => self::DAY, 'to_date' => self::DAY, 'type' => 'company', 'is_active' => true, 'is_recurring' => false]);

        Artisan::call('attendance:absence-streak', ['--date' => self::DAY]);

        $this->assertSame(0, AbsenceCase::count());
    }
}
