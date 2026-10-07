<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\Holiday;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Services\Attendance\AttendanceDayPartitionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A company holiday is a rest day: a rostered employee who does not punch is OFF, not ABSENT,
 * in the day partition (web timesheet + mobile team-day), the web absent-users split and the
 * daily overview - the same rule the monthly grid and the shift alerts already follow. Anyone
 * who did punch is still Present.
 */
class HolidayDayPartitionTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-07-15';

    private User $admin;

    private User $quiet;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Administrator']);
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.view']);

        Carbon::setTestNow(Carbon::parse(self::DATE.' 12:00:00'));

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Administrator');
        $this->admin->givePermissionTo('attendance.view');

        $shift = Shift::factory()->create(['code' => 'MRN', 'start_time' => '09:00', 'end_time' => '17:00']);
        $this->quiet = User::factory()->create();
        $this->quiet->assignRole('Employee');
        $this->worker = User::factory()->create();
        $this->worker->assignRole('Employee');
        foreach ([$this->quiet, $this->worker] as $u) {
            RosterDay::create(['user_id' => $u->id, 'date' => self::DATE, 'shift_id' => $shift->id, 'source' => 'manual', 'locked' => true]);
        }
        Attendance::create(['user_id' => $this->worker->id, 'date' => self::DATE, 'punchin' => self::DATE.' 09:00:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function holiday(bool $active = true): void
    {
        Holiday::create([
            'title' => 'Special Day', 'from_date' => self::DATE, 'to_date' => self::DATE,
            'type' => 'company', 'is_active' => $active, 'is_recurring' => false,
        ]);
    }

    public function test_without_a_holiday_the_silent_rostered_employee_is_absent(): void
    {
        $out = app(AttendanceDayPartitionService::class)->partition(self::DATE);

        $this->assertSame(1, $out['counts']['present']);
        $this->assertSame(1, $out['counts']['absent']);
        $this->assertSame(0, $out['counts']['off_leave']);
    }

    public function test_day_partition_puts_the_silent_employee_off_on_a_holiday(): void
    {
        $this->holiday();

        $out = app(AttendanceDayPartitionService::class)->partition(self::DATE);

        $this->assertSame(1, $out['counts']['present'], 'whoever punched is still present');
        $this->assertSame(0, $out['counts']['absent']);
        $this->assertSame(1, $out['counts']['off_leave']);
        $this->assertSame('off', $out['off_leave'][0]['kind']);
        $this->assertSame('Special Day', $out['off_leave'][0]['holiday']);
        $this->assertSame(2, $out['counts']['total']);
    }

    public function test_an_inactive_holiday_changes_nothing(): void
    {
        $this->holiday(active: false);

        $out = app(AttendanceDayPartitionService::class)->partition(self::DATE);

        $this->assertSame(1, $out['counts']['absent']);
    }

    public function test_web_absent_users_endpoint_lists_no_absentees_on_a_holiday(): void
    {
        $this->holiday();

        $this->actingAs($this->admin)
            ->getJson(route('admin.getAbsentUsersForDate', ['date' => self::DATE]))
            ->assertOk()
            ->assertJsonPath('total_absent', 0)
            ->assertJsonPath('total_off', 1);
    }

    public function test_daily_overview_counts_no_absent_on_a_holiday(): void
    {
        $before = $this->actingAs($this->admin)
            ->getJson(route('attendance.dailyOverview', ['date' => self::DATE]))->assertOk();
        $this->assertSame(1, $before->json('absent'));

        $this->holiday();

        $after = $this->actingAs($this->admin)
            ->getJson(route('attendance.dailyOverview', ['date' => self::DATE]))->assertOk();
        $this->assertSame(0, $after->json('absent'));
        $this->assertSame(1, $after->json('present'));
    }
}
