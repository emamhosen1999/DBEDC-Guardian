<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\Department;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The analytics breakdown is filled in the same engine pass as the headline
 * monthly stats, so every series must reconcile exactly with the totals.
 */
class AttendanceAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
        Permission::firstOrCreate(['name' => 'attendance.own.view']);

        // Wed 2026-06-03 is "today": the window is Mon 06-01 .. Wed 06-03.
        Carbon::setTestNow(Carbon::parse('2026-06-03 20:00:00'));

        AttendanceSetting::create([
            'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'break_time_duration' => 0, 'late_mark_after' => 15,
            'early_leave_before' => 0, 'overtime_after' => 0,
            'weekend_days' => ['saturday', 'sunday'], 'auto_punch_out' => false,
        ]);

        $ops = Department::create(['name' => 'Operations', 'code' => 'OPS', 'is_active' => true]);
        $tmc = Department::create(['name' => 'Traffic Monitoring Center', 'code' => 'TMC', 'is_active' => true]);

        $this->alice = User::factory()->create(['department_id' => $ops->id, 'name' => 'Alice']);
        $this->bob = User::factory()->create(['department_id' => $tmc->id, 'name' => 'Bob']);
        $this->alice->assignRole('Employee');
        $this->bob->assignRole('Employee');

        // Alice: on time all three days.
        foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $d) {
            $this->punch($this->alice, $d, '09:00');
        }
        // Bob: 45 minutes late Monday, absent Tuesday, on time Wednesday.
        $this->punch($this->bob, '2026-06-01', '09:45');
        $this->punch($this->bob, '2026-06-03', '09:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function punch(User $user, string $date, string $in): void
    {
        Attendance::factory()->for($user)->create([
            'date' => $date, 'punchin' => "{$date} {$in}:00", 'punchout' => "{$date} 17:00:00",
        ]);
    }

    public function test_breakdown_reconciles_with_the_headline_totals(): void
    {
        $stats = app(AttendanceReportService::class)->calculateMonthlyStats(6, 2026, true, null, true);
        $b = $stats['breakdown'];

        $this->assertSame(5, $stats['attendance']['present']);
        $this->assertSame(1, $stats['attendance']['absent']);
        $this->assertSame(1, $stats['attendance']['lateArrivals']);

        $daily = collect($b['daily']);
        $this->assertSame($stats['attendance']['absent'], $daily->sum('absent'));
        $this->assertSame($stats['attendance']['lateArrivals'], $daily->sum('late'));
        $this->assertSame($stats['attendance']['present'], $daily->sum('on_time') + $daily->sum('late'));

        $tuesday = $daily->firstWhere('date', '2026-06-02');
        $this->assertSame(1, $tuesday['absent']);
        $this->assertEqualsWithDelta(50.0, $tuesday['rate'], 0.01);

        $this->assertSame(1, collect($b['weekday'])->firstWhere('day', 'Mon')['late']);
        // Lateness counts from the end of the 15-minute grace: 09:45 is 30 minutes late.
        $this->assertSame(1, collect($b['punctuality']['buckets'])->firstWhere('bucket', '16–30 min')['count']);
        $this->assertEqualsWithDelta(30.0, $b['punctuality']['average_late_minutes'], 0.01);
    }

    public function test_work_on_a_day_off_never_pushes_the_rate_past_100_percent(): void
    {
        // Alice works Thu–Fri and also Saturday 06-06, a weekend day off.
        Carbon::setTestNow(Carbon::parse('2026-06-06 20:00:00'));
        foreach (['2026-06-04', '2026-06-05', '2026-06-06'] as $d) {
            $this->punch($this->alice, $d, '09:00');
        }

        $stats = app(AttendanceReportService::class)->calculateMonthlyStats(6, 2026, false, (string) $this->alice->employee_id, true);
        $rate = $stats['breakdown']['rate'];

        $this->assertSame(100.0, (float) $rate['scheduled']);
        $this->assertSame(1, $rate['off_schedule_days']);
        $this->assertTrue(collect($stats['breakdown']['daily'])->every(fn ($d) => $d['rate'] === null || $d['rate'] <= 100));
    }

    public function test_departments_are_ranked_worst_first_and_the_watchlist_names_people(): void
    {
        $b = app(AttendanceReportService::class)->calculateMonthlyStats(6, 2026, true, null, true)['breakdown'];

        $this->assertSame('Traffic Monitoring Center', $b['departments'][0]['department']);
        $this->assertEqualsWithDelta(66.7, $b['departments'][0]['rate'], 0.05);
        $this->assertEqualsWithDelta(100.0, $b['departments'][1]['rate'], 0.05);

        $this->assertSame('Bob', $b['watchlist']['most_absent'][0]['name']);
        $this->assertSame('Bob', $b['watchlist']['most_late'][0]['name']);
    }

    public function test_a_department_filter_narrows_the_team(): void
    {
        $tmc = Department::where('code', 'TMC')->first();
        $stats = app(AttendanceReportService::class)->calculateMonthlyStats(6, 2026, true, null, true, $tmc->id);

        $this->assertSame(1, $stats['meta']['totalEmployees']);
        $this->assertCount(1, $stats['breakdown']['departments']);
    }

    public function test_the_endpoint_gives_a_manager_the_team_and_an_employee_only_themselves(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(['attendance.view', 'attendance.own.view']);

        $team = $this->actingAs($manager)->getJson(route('attendance.analytics', ['month' => '2026-06']))
            ->assertOk()->json('data');
        $this->assertSame('team', $team['meta']['scope']);
        $this->assertNotEmpty($team['breakdown']['departments']);
        $this->assertArrayHasKey('percentage', $team['comparison']);

        $this->bob->givePermissionTo('attendance.own.view');
        $self = $this->actingAs($this->bob)->getJson(route('attendance.analytics', ['month' => '2026-06']))
            ->assertOk()->json('data');
        $this->assertSame('self', $self['meta']['scope']);
        $this->assertSame(1, $self['meta']['totalEmployees']);
        $this->assertSame([], $self['breakdown']['departments']);
        $this->assertNull($self['breakdown']['watchlist']);
    }

    public function test_a_malformed_month_is_rejected(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(['attendance.view', 'attendance.own.view']);

        $this->actingAs($manager)->getJson(route('attendance.analytics', ['month' => '2026-13']))->assertStatus(422);
    }
}
