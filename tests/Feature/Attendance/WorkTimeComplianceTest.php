<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\HRM\ShiftRotationPattern;
use App\Models\User;
use App\Services\Attendance\WorkTimeComplianceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WorkTimeComplianceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    private function night(): Shift
    {
        return Shift::factory()->make([
            'code' => 'N', 'start_time' => '23:00', 'end_time' => '07:00', 'crosses_midnight' => true,
        ]);
    }

    private function morning(): Shift
    {
        return Shift::factory()->make([
            'code' => 'M', 'start_time' => '07:00', 'end_time' => '15:00', 'crosses_midnight' => false,
        ]);
    }

    private function evening(): Shift
    {
        return Shift::factory()->make([
            'code' => 'E', 'start_time' => '15:00', 'end_time' => '23:00', 'crosses_midnight' => false,
        ]);
    }

    private function day(): Shift
    {
        return Shift::factory()->make([
            'code' => 'D', 'start_time' => '08:00', 'end_time' => '20:00', 'crosses_midnight' => false,
        ]);
    }

    private function eveningToNight(): Shift
    {
        // A second "night-like" shift starting exactly where the day shift ends (20:00-08:00).
        return Shift::factory()->make([
            'code' => 'N2', 'start_time' => '20:00', 'end_time' => '08:00', 'crosses_midnight' => true,
        ]);
    }

    public function test_min_rest_violated_when_night_immediately_followed_by_morning(): void
    {
        $violations = app(WorkTimeComplianceService::class)->evaluateSequence([
            ['date' => '2026-06-01', 'shift' => $this->night()],
            ['date' => '2026-06-02', 'shift' => $this->morning()],
        ]);

        $violation = collect($violations)->firstWhere('rule', 'min_rest');
        $this->assertNotNull($violation, 'Expected a min_rest violation.');
        $this->assertSame('2026-06-02', $violation['date']);
        $this->assertSame('error', $violation['severity']);
        $this->assertSame(0.0, $violation['details']['rest_hours']);
    }

    public function test_min_rest_violated_when_gap_is_shorter_than_minimum(): void
    {
        $violations = app(WorkTimeComplianceService::class)->evaluateSequence([
            ['date' => '2026-06-01', 'shift' => $this->night()],
            ['date' => '2026-06-02', 'shift' => $this->evening()],
        ]);

        $violation = collect($violations)->firstWhere('rule', 'min_rest');
        $this->assertNotNull($violation, 'Expected a min_rest violation.');
        $this->assertSame('2026-06-02', $violation['date']);
        $this->assertSame(8.0, $violation['details']['rest_hours']);
        $this->assertSame(11.0, $violation['details']['min_rest_hours']);
    }

    public function test_no_min_rest_violation_when_rest_is_sufficient(): void
    {
        $violations = app(WorkTimeComplianceService::class)->evaluateSequence([
            ['date' => '2026-06-01', 'shift' => $this->morning()],
            ['date' => '2026-06-02', 'shift' => $this->night()],
        ]);

        $this->assertNull(collect($violations)->firstWhere('rule', 'min_rest'));
    }

    public function test_max_span_in_24h_violated_by_two_full_shifts_on_the_same_calendar_day(): void
    {
        // The real production incident this rule exists for: a day shift
        // immediately followed by a night shift, both rostered on the same
        // calendar day (e.g. a manual override stacked on top of a pattern).
        $violations = app(WorkTimeComplianceService::class)->evaluateSequence([
            ['date' => '2026-06-01', 'shift' => $this->day()],
            ['date' => '2026-06-01', 'shift' => $this->eveningToNight()],
        ]);

        $violation = collect($violations)->firstWhere('rule', 'max_span_in_24h');
        $this->assertNotNull($violation, 'Expected a max_span_in_24h violation.');
        $this->assertSame('2026-06-01', $violation['date']);
        $this->assertSame('error', $violation['severity']);
        $this->assertSame(24.0, $violation['details']['total_hours']);
    }

    public function test_max_consecutive_nights_violated_on_the_fifth_consecutive_night(): void
    {
        $days = [];
        for ($i = 0; $i < 5; $i++) {
            $days[] = ['date' => Carbon::parse('2026-06-01')->addDays($i)->toDateString(), 'shift' => $this->night()];
        }

        $violations = app(WorkTimeComplianceService::class)->evaluateSequence($days);

        $violation = collect($violations)->firstWhere('rule', 'max_consecutive_nights');
        $this->assertNotNull($violation, 'Expected a max_consecutive_nights violation on the 5th night.');
        $this->assertSame('2026-06-05', $violation['date']);
        $this->assertSame('warning', $violation['severity']);
        $this->assertSame(5, $violation['details']['streak']);

        // The first 4 consecutive nights must NOT be flagged (default max is 4).
        $flaggedDates = collect($violations)->where('rule', 'max_consecutive_nights')->pluck('date')->all();
        $this->assertSame(['2026-06-05'], $flaggedDates);
    }

    public function test_rule_disabled_via_config_produces_no_violation(): void
    {
        config(['attendance.compliance.min_rest_hours' => 0]);

        $violations = app(WorkTimeComplianceService::class)->evaluateSequence([
            ['date' => '2026-06-01', 'shift' => $this->night()],
            ['date' => '2026-06-02', 'shift' => $this->morning()],
        ]);

        $this->assertNull(collect($violations)->firstWhere('rule', 'min_rest'));
    }

    public function test_roster_generate_endpoint_returns_compliance_violations(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.settings');

        $employee = User::factory()->create();

        $night = Shift::factory()->create(['code' => 'N', 'start_time' => '23:00', 'end_time' => '07:00', 'crosses_midnight' => true]);
        $morning = Shift::factory()->create(['code' => 'M', 'start_time' => '07:00', 'end_time' => '15:00', 'crosses_midnight' => false]);

        $pattern = ShiftRotationPattern::factory()->create([
            'cycle_length_days' => 2,
            'definition' => [$night->id, $morning->id],
        ]);

        ShiftAssignment::factory()->create([
            'scope_type' => 'user',
            'scope_id' => $employee->id,
            'shift_id' => null,
            'rotation_pattern_id' => $pattern->id,
            'anchor_date' => '2026-06-01',
            'effective_from' => '2026-06-01',
        ]);

        $response = $this->actingAs($admin)->postJson(route('attendance.roster.generate'), [
            'user_ids' => [$employee->id],
            'from' => '2026-06-01',
            'to' => '2026-06-04',
        ])->assertOk();

        $response->assertJsonStructure(['message', 'count', 'compliance_violations']);

        $violations = $response->json('compliance_violations');
        $this->assertArrayHasKey($employee->id, $violations);
        $this->assertNotEmpty($violations[$employee->id]);
        $this->assertTrue(
            collect($violations[$employee->id])->contains(fn (array $v) => $v['rule'] === 'min_rest'),
            'Expected at least one min_rest violation in the generated roster response.'
        );
    }
}
