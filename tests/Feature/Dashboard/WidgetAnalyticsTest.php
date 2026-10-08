<?php

namespace Tests\Feature\Dashboard;

use App\Models\DailyWork;
use App\Models\HRM\Attendance;
use App\Models\HRM\Department;
use App\Models\HRM\Holiday;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\OmDefect;
use App\Models\OmIncident;
use App\Models\QualityNCR;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Dashboard\AttendanceTrend;
use App\Services\Dashboard\WidgetRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The analytics behind the dashboards' charts: every series is a real aggregate over a stated period, narrowed by
 * DepartmentScope, empty when there is nothing to show, and never fabricated.
 */
class WidgetAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Department $d1;

    private Department $d2;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Carbon::setTestNow('2026-10-08 12:00:00'); // a Thursday, shift already started

        foreach (['Super Administrator' => 1, 'Administrator' => 10, 'HR Manager' => 20, 'Department Admin' => 25, 'Employee' => 60] as $name => $level) {
            Role::updateOrCreate(['name' => $name, 'guard_name' => 'web'], ['hierarchy_level' => $level]);
        }
        foreach (['department.admin', 'attendance.view', 'attendance.own.view', 'leave.own.view', 'leaves.view', 'leaves.approve', 'employees.view', 'daily-works.view', 'quality.ncr.view', 'system.monitoring.view', 'om.dashboard.view', 'om.maintenance.view', 'om.incidents.view'] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        [$this->d1, $this->d2] = [Department::factory()->create(), Department::factory()->create()];
        $this->shift = Shift::factory()->create(['start_time' => '08:00', 'end_time' => '16:00', 'grace_in_minutes' => 10, 'full_day_minutes' => 480]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(Department $department, array $permissions = [], string $role = 'Employee'): User
    {
        $user = User::factory()->create(['department_id' => $department->id, 'is_active' => true]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function rosterAndPunch(User $user, string $date, ?string $punchIn, ?string $punchOut = null): void
    {
        RosterDay::create(['user_id' => $user->employee_id, 'date' => $date, 'shift_id' => $this->shift->id, 'source' => 'manual']);
        if ($punchIn !== null) {
            Attendance::create(['user_id' => $user->employee_id, 'date' => $date, 'punchin' => "{$date} {$punchIn}", 'punchout' => $punchOut ? "{$date} {$punchOut}" : null]);
        }
    }

    private function widget(User $viewer, string $dashboard, string $key): array
    {
        return collect(app(WidgetRegistry::class)->payloadFor($viewer, $dashboard)['widgets'])->firstWhere('key', $key);
    }

    private function chart(array $widget, string $key): array
    {
        return collect($widget['data']['charts'])->firstWhere('key', $key);
    }

    public function test_attendance_trend_is_a_30_day_series_ending_today_with_the_partition_rules(): void
    {
        [$ontime, $late, $absent, $leaver] = [$this->employee($this->d1), $this->employee($this->d1), $this->employee($this->d1), $this->employee($this->d1)];
        $yesterday = '2026-10-07';
        $this->rosterAndPunch($ontime, $yesterday, '07:55:00');
        $this->rosterAndPunch($late, $yesterday, '08:30:00');
        $this->rosterAndPunch($absent, $yesterday, null);
        RosterDay::create(['user_id' => $leaver->employee_id, 'date' => $yesterday, 'shift_id' => $this->shift->id, 'source' => 'manual']);
        Leave::query()->insert(['user_id' => $leaver->employee_id, 'leave_type' => 1, 'from_date' => $yesterday, 'to_date' => $yesterday, 'no_of_days' => 1, 'reason' => 'x', 'status' => 'Approved', 'created_at' => now(), 'updated_at' => now()]);

        $series = app(AttendanceTrend::class)->forPeople(User::query()->where('department_id', $this->d1->id), 30);

        $this->assertCount(30, $series['dates']);
        $this->assertSame('2026-10-08', $series['to']);
        $this->assertSame('2026-09-09', $series['from']);
        foreach (['present', 'late', 'absent', 'on_leave', 'rostered'] as $key) {
            $this->assertCount(30, $series[$key], $key);
        }
        $i = array_search($yesterday, $series['dates'], true);
        $this->assertSame([2, 1, 1, 1, 4], [$series['present'][$i], $series['late'][$i], $series['absent'][$i], $series['on_leave'][$i], $series['rostered'][$i]]);
        $this->assertSame(0, array_sum($series['present']) - 2, 'no other day has punches');
    }

    public function test_team_today_charts_are_scoped_and_kpis_carry_real_sparklines_and_deltas(): void
    {
        $head = $this->employee($this->d1, ['department.admin', 'attendance.view', 'employees.view'], 'Department Admin');
        $mate = $this->employee($this->d1);
        $outsider = $this->employee($this->d2);
        $this->rosterAndPunch($mate, '2026-10-07', '07:50:00');
        $this->rosterAndPunch($outsider, '2026-10-07', '07:50:00');
        $this->rosterAndPunch($mate, '2026-10-08', '07:50:00');

        $widget = $this->widget($head, 'main', 'team.today');
        $chart = $this->chart($this->widget($head, 'main', 'team.trend'), 'attendance_trend');
        $this->assertSame('donut', $this->chart($widget, 'today_distribution')['kind']);

        $this->assertSame('bar', $chart['kind']);
        $this->assertTrue($chart['stacked']);
        $this->assertCount(30, $chart['categories']);
        $this->assertSame(['On time', 'Late', 'Absent', 'On leave'], array_column($chart['series'], 'name'));
        $this->assertSame(['from' => '2026-09-09', 'to' => '2026-10-08'], $chart['period']);
        $this->assertFalse($chart['empty']);
        $this->assertSame(1, $chart['series'][0]['data'][28], 'yesterday: only the colleague from my department, not the outsider');

        $present = collect($widget['data']['kpis'])->firstWhere('key', 'present');
        $this->assertSame(1, $present['value']);
        $this->assertCount(14, $present['spark']);
        $this->assertSame([0, 1, 1], array_slice($present['spark'], -3));
        $this->assertSame('flat', $present['delta']['direction']);
        $this->assertTrue($present['strip']);
    }

    public function test_charts_report_empty_instead_of_a_fake_series(): void
    {
        $head = $this->employee($this->d1, ['department.admin', 'attendance.view', 'employees.view'], 'Department Admin');
        $this->employee($this->d1);

        $widget = $this->widget($head, 'main', 'team.today');
        $chart = $this->chart($this->widget($head, 'main', 'team.trend'), 'attendance_trend');
        $this->assertTrue($chart['empty']);
        $this->assertNotEmpty($chart['empty_message']);
        $this->assertNull(collect($widget['data']['kpis'])->firstWhere('key', 'present')['spark'] ?? null, 'no history, so no sparkline and no delta');
        $this->assertArrayNotHasKey('delta', collect($widget['data']['kpis'])->firstWhere('key', 'present'));
    }

    public function test_department_comparison_lists_only_departments_in_scope(): void
    {
        $head = $this->employee($this->d1, ['department.admin', 'employees.view'], 'Department Admin');
        $mate = $this->employee($this->d1);
        $this->employee($this->d2);
        Attendance::create(['user_id' => $mate->employee_id, 'date' => '2026-10-08', 'punchin' => '2026-10-08 08:00:00']);

        $chart = $this->chart($this->widget($head, 'main', 'team.department'), 'department_comparison');
        $this->assertSame([$this->d1->name], $chart['categories']);
        $this->assertSame([50.0], array_map('floatval', $chart['series'][0]['data']), '1 of 2 present');

        $hr = $this->employee($this->d1, ['department.admin', 'employees.view'], 'HR Manager');
        $global = $this->chart($this->widget($hr, 'main', 'team.department'), 'department_comparison');
        $this->assertEqualsCanonicalizing([$this->d1->name, $this->d2->name], $global['categories']);
    }

    public function test_approvals_are_bucketed_by_age_and_oldest_listed(): void
    {
        $head = $this->employee($this->d1, ['department.admin', 'leaves.approve', 'leaves.view'], 'Department Admin');
        $mate = $this->employee($this->d1);
        $setting = LeaveSetting::factory()->create();
        foreach ([0, 2, 10, 12] as $ageDays) {
            Leave::query()->insert(['user_id' => $mate->employee_id, 'leave_type' => $setting->id, 'from_date' => '2026-11-01', 'to_date' => '2026-11-01', 'no_of_days' => 1, 'reason' => 'x',
                'status' => 'Pending', 'approval_chain' => '[]', 'current_approval_level' => 1, 'created_at' => now()->subDays($ageDays), 'updated_at' => now()]);
        }

        $widget = $this->widget($head, 'main', 'team.approvals');
        $chart = $this->chart($widget, 'approval_ageing');

        $this->assertSame(['0-1', '2-3', '4-7', '>7'], $chart['categories']);
        $this->assertSame([1, 1, 0, 2], $chart['series'][0]['data']);
        $this->assertCount(4, $chart['items']);
        $this->assertSame('12 d', $chart['items'][0]['meta'], 'oldest first');
        $this->assertSame(4, $widget['data']['total']);
        $this->assertSame('oldest 12 days', collect($widget['data']['kpis'])->firstWhere('key', 'approvals')['hint']);
    }

    public function test_leave_donut_sums_approved_days_this_month_inside_scope(): void
    {
        $head = $this->employee($this->d1, ['department.admin', 'leaves.view'], 'Department Admin');
        $mate = $this->employee($this->d1);
        $other = $this->employee($this->d2);
        $setting = LeaveSetting::factory()->create(['type' => 'Casual']);
        $row = fn (User $u, string $from, string $to) => Leave::query()->insert(['user_id' => $u->employee_id, 'leave_type' => $setting->id, 'from_date' => $from, 'to_date' => $to, 'no_of_days' => 3, 'reason' => 'x', 'status' => 'Approved', 'created_at' => now(), 'updated_at' => now()]);
        $row($mate, '2026-10-05', '2026-10-07');   // 3 days
        $row($mate, '2026-09-29', '2026-10-02');   // spans the month start: clipped to 2 days
        $row($other, '2026-10-05', '2026-10-09');  // other department

        $chart = $this->chart($this->widget($head, 'main', 'team.leave_month'), 'leave_by_type');
        $this->assertSame('donut', $chart['kind']);
        $this->assertSame(['Casual'], $chart['labels']);
        $this->assertSame([5.0], array_map('floatval', $chart['series']));
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-31'], $chart['period']);
    }

    public function test_quality_and_daily_works_series_cover_their_periods(): void
    {
        $viewer = $this->employee($this->d1, ['quality.ncr.view', 'daily-works.view'], 'Administrator');
        QualityNCR::query()->forceCreate(['ncr_number' => 'NCR-1', 'title' => 'a', 'description' => 'a', 'severity' => 'major', 'status' => 'open', 'reported_by' => $viewer->employee_id, 'detected_date' => '2026-10-01', 'created_at' => '2026-10-02 10:00:00', 'updated_at' => now()]);
        QualityNCR::query()->forceCreate(['ncr_number' => 'NCR-2', 'title' => 'b', 'description' => 'b', 'severity' => 'minor', 'status' => 'closed', 'reported_by' => $viewer->employee_id, 'detected_date' => '2026-08-01', 'closure_date' => '2026-09-10', 'created_at' => '2026-08-02 10:00:00', 'updated_at' => now()]);

        $ncr = $this->widget($viewer, 'main', 'project.ncr');
        $monthly = $this->chart($ncr, 'ncr_monthly');
        $this->assertCount(6, $monthly['categories']);
        $this->assertSame('May 2026', $monthly['categories'][0]);
        $this->assertSame('Oct 2026', $monthly['categories'][5]);
        $this->assertSame([0, 0, 0, 1, 0, 1], $monthly['series'][0]['data'], 'opened in August and October');
        $this->assertSame([0, 0, 0, 0, 1, 0], $monthly['series'][1]['data'], 'closed in September');
        $this->assertSame(['Open', 'Closed'], $this->chart($ncr, 'ncr_status')['labels']);
        $spark = collect($ncr['data']['kpis'])->firstWhere('key', 'ncr_open')['spark'];
        $this->assertCount(6, $spark);
        $this->assertSame(1, end($spark));

        DailyWork::factory()->create(['incharge' => $viewer->employee_id, 'assigned' => $viewer->employee_id, 'status' => 'new', 'date' => '2026-10-07']);
        DailyWork::factory()->create(['incharge' => $viewer->employee_id, 'assigned' => $viewer->employee_id, 'status' => 'completed', 'date' => '2026-10-06']);
        Cache::flush(); // widget payloads are cached per employee, scope and section
        $weekly = $this->chart($this->widget($viewer, 'main', 'project.daily_works'), 'daily_works_weekly');
        $this->assertCount(8, $weekly['categories']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 2], $weekly['series'][0]['data'], 'both in the current week (from Mon 5 Oct)');
        $this->assertSame(1, $weekly['series'][1]['data'][7]);
    }

    public function test_system_health_counts_server_errors_per_day(): void
    {
        $admin = $this->employee($this->d1, ['system.monitoring.view'], 'Super Administrator');
        $log = fn (string $at, int $status) => DB::table('request_logs')->insert(['ip_address' => '127.0.0.1', 'method' => 'GET', 'url' => '/x', 'response_status' => $status, 'created_at' => $at]);
        $log('2026-10-08 09:00:00', 500);
        $log('2026-10-08 09:05:00', 200);
        $log('2026-10-07 09:00:00', 503);

        $widget = $this->widget($admin, 'main', 'admin.system_health');
        $errors = $this->chart($widget, 'system_errors');
        $requests = $this->chart($widget, 'system_requests');

        $this->assertCount(14, $errors['categories']);
        $this->assertSame([1, 1], array_slice($errors['series'][0]['data'], -2));
        $this->assertSame([1, 2], array_slice($requests['series'][0]['data'], -2));
        $this->assertSame(['from' => '2026-09-25', 'to' => '2026-10-08'], $errors['period']);
    }

    public function test_my_month_heat_strip_and_punctuality_use_my_own_records_only(): void
    {
        $own = ['attendance.own.view', 'leave.own.view'];
        $me = $this->employee($this->d1, $own);
        $other = $this->employee($this->d1, $own);
        $this->rosterAndPunch($me, '2026-10-06', '07:58:00', '16:05:00');
        $this->rosterAndPunch($me, '2026-10-07', '08:20:00', '16:00:00');
        $this->rosterAndPunch($other, '2026-10-08', '07:00:00');

        $month = $this->widget($me, 'employee', 'me.attendance_month');
        $heat = $this->chart($month, 'month_heat');
        $states = collect($heat['days'])->pluck('state', 'date');
        $this->assertCount(31, $heat['days']);
        $this->assertSame('present', $states['2026-10-06']);
        $this->assertSame('late', $states['2026-10-07']);
        $this->assertSame('future', $states['2026-10-09']);
        $this->assertSame('off', $states['2026-10-01']);
        $this->assertSame(50, $this->chart($month, 'on_time')['value']);

        $punctuality = $this->widget($me, 'employee', 'me.punctuality');
        $arrival = $this->chart($punctuality, 'arrivals');
        $this->assertCount(14, $arrival['categories']);
        $this->assertSame([-2, 20], array_slice(array_values(array_filter($arrival['series'][0]['data'], fn ($v) => $v !== null)), 0, 2));
        $week = $this->chart($punctuality, 'week_hours');
        $this->assertSame(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], $week['categories']);
        $this->assertSame(8.1, $week['series'][0]['data'][1], 'Tuesday 07:58 to 16:05');
        $this->assertSame(8.0, $week['series'][1]['data'][1]);
    }

    public function test_my_requests_timeline_lists_only_my_requests(): void
    {
        $me = $this->employee($this->d1, ['attendance.own.view', 'leave.own.view']);
        $other = $this->employee($this->d1, ['attendance.own.view', 'leave.own.view']);
        $setting = LeaveSetting::factory()->create();
        foreach ([$me, $other] as $u) {
            Leave::query()->insert(['user_id' => $u->employee_id, 'leave_type' => $setting->id, 'from_date' => '2026-10-20', 'to_date' => '2026-10-21', 'no_of_days' => 2, 'reason' => 'x', 'status' => 'Approved', 'created_at' => now()->subDay(), 'updated_at' => now()]);
        }

        $widget = $this->widget($me, 'employee', 'me.pending_requests');
        $this->assertCount(1, $widget['data']['items']);
        $this->assertSame('Approved', $widget['data']['items'][0]['subtitle']);
        $this->assertSame('good', $widget['data']['items'][0]['tone']);
    }

    public function test_no_dashboard_source_contains_mock_data(): void
    {
        $roots = ['app/Services/Dashboard', 'app/Services/CommandCenterService.php', 'resources/js/Components/Dashboard', 'resources/js/Components/Cyber', 'resources/js/Pages/Dashboard.jsx', 'resources/js/Pages/EmployeeDashboard.jsx'];
        $offenders = [];
        foreach ($roots as $root) {
            $path = base_path($root);
            $files = is_dir($path)
                ? iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)))
                : [new \SplFileInfo($path)];
            foreach ($files as $file) {
                $name = $file->getPathname();
                if (! preg_match('/\.(php|jsx?)$/', $name) || str_contains($name, '__tests__') || str_ends_with($name, 'Cyber/icons.js')) {
                    continue;
                }
                $source = file_get_contents($name);
                if (preg_match('/Math\.random|faker|lorem ipsum|sample data|dummy data|mock data|placeholder data/i', $source)
                    || preg_match('/\[\s*(?:-?\d+(?:\.\d+)?\s*,\s*){4,}-?\d+(?:\.\d+)?\s*\]/', $source)) {
                    $offenders[] = $root === $name ? $root : str_replace(base_path().'/', '', $name);
                }
            }
        }

        $this->assertSame([], $offenders, 'Hard-coded series or sample data in: '.implode(', ', $offenders));
    }

    public function test_om_charts_bucket_defects_by_chainage_and_measure_sla_compliance(): void
    {
        $viewer = $this->employee($this->d1, ['om.dashboard.view', 'om.maintenance.view', 'om.incidents.view'], 'Administrator');
        $defect = fn (string $n, string $chainage, ?string $due, ?string $rectified) => OmDefect::query()->forceCreate([
            'defect_number' => $n, 'title' => $n, 'distress_type' => 'pothole', 'chainage' => $chainage, 'severity' => 'medium', 'status' => $rectified ? 'rectified' : 'reported',
            'sla_hours' => 48, 'sla_due_at' => $due, 'rectified_at' => $rectified,
        ]);
        $defect('D1', 'K4+100 to 4+320', '2026-10-01 00:00:00', null);          // open, overdue: breached
        $defect('D2', 'K13+500', '2026-10-20 00:00:00', null);                  // open, still inside its SLA
        $defect('D3', 'K13+900', '2026-10-01 00:00:00', '2026-09-30 00:00:00'); // resolved on time (not open)
        OmIncident::query()->forceCreate(['incident_number' => 'I1', 'title' => 'x', 'chainage' => 'K40+200', 'severity' => 'major', 'status' => 'detected', 'reported_at' => '2026-10-06 10:00:00']);

        $widget = $this->widget($viewer, 'main', 'ops.om');
        $bands = $this->chart($widget, 'om_chainage');
        $this->assertCount(8, $bands['categories']);
        $this->assertSame('K0-6', $bands['categories'][0]);
        $this->assertSame([0, 0, 0, 0, 0, 0, 1, 0], $bands['series'][0]['data'], 'incident at K40 falls in K36-42');
        $this->assertSame([1, 0, 1, 0, 0, 0, 0, 0], $bands['series'][1]['data'], 'open defects at K4 and K13 (K13 is band K12-18)');

        $sla = $this->chart($widget, 'om_sla');
        $this->assertSame('radial', $sla['kind']);
        $this->assertSame(66.7, (float) $sla['value'], '2 of 3 defects resolved on time or still within SLA');
        $this->assertSame(3, $sla['total']);
        $this->assertFalse($sla['empty']);

        $this->assertSame('detected', 'detected');
        $this->assertNotNull(collect($widget['data']['kpis'])->firstWhere('key', 'incidents'));
    }

    public function test_every_widget_computes_for_a_viewer_who_holds_everything(): void
    {
        Holiday::query()->forceCreate(['title' => 'Victory Day', 'from_date' => '2026-12-16', 'to_date' => '2026-12-16', 'type' => 'public', 'is_active' => true]);
        $root = $this->employee($this->d1, Permission::pluck('name')->all(), 'Administrator');

        foreach (['employee', 'main'] as $dashboard) {
            foreach (app(WidgetRegistry::class)->payloadFor($root, $dashboard)['widgets'] as $widget) {
                if ($widget['key'] === 'main.command') {
                    continue; // CommandCenterService still has MySQL-only SQL (DATE_FORMAT) that the SQLite test database cannot run
                }
                $this->assertNull($widget['error'], $widget['key'].' failed to compute');
            }
        }

        $holidays = $this->widget($root, 'employee', 'me.holidays');
        $this->assertSame('Victory Day', $holidays['data']['items'][0]['title']);
    }
}
