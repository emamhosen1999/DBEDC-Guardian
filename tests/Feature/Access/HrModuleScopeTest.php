<?php

namespace Tests\Feature\Access;

use App\Console\Commands\ProcessAbsenceStreak;
use App\Models\FeatureFlag;
use App\Models\HRM\AbsenceCase;
use App\Models\HRM\Asset;
use App\Models\HRM\BiometricDevice;
use App\Models\HRM\BiometricDeviceCommand;
use App\Models\HRM\Department;
use App\Models\HRM\FinalSettlement;
use App\Models\HRM\Offboarding;
use App\Models\HRM\Onboarding;
use App\Models\HRM\Payroll;
use App\Models\HRM\Payslip;
use App\Models\PettyCashLoan;
use App\Models\User;
use App\Models\UserDepartmentScope;
use App\Models\WorkLocation;
use App\Notifications\Attendance\OffboardingInitiatedNotification;
use App\Services\FeatureFlagService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Every HR module (onboarding, offboarding, assets, payroll/payslip, settlement,
 * petty cash) honours the department scope: a D1 department admin reaches D1 only,
 * a plain D1 employee holding the same permissions reaches nobody but themselves,
 * global HR reaches everyone (but never their own admin-flow records).
 */
class HrModuleScopeTest extends TestCase
{
    use RefreshDatabase;

    private Department $d1;

    private Department $d2;

    /** @var array<string, User> */
    private array $u = [];

    private const PERMISSIONS = [
        'hr.onboarding.view', 'hr.onboarding.create', 'hr.onboarding.update', 'hr.onboarding.delete',
        'hr.offboarding.view', 'hr.offboarding.create', 'hr.offboarding.update', 'hr.offboarding.delete',
        'hr.assets.view', 'hr.assets.manage',
        'hr.payroll.view', 'hr.payroll.process',
        'hr.settlement.manage', 'hr.settlement.approve', 'hr.settlement.disburse',
        'petty-cash.view-all', 'petty-cash.approve', 'petty-cash.manage',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'Super Administrator' => 1, 'Administrator' => 10, 'HR Manager' => 20,
            'Department Admin' => 25, 'Employee' => 60,
        ] as $name => $level) {
            Role::updateOrCreate(['name' => $name, 'guard_name' => 'web'], ['hierarchy_level' => $level]);
        }
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->d1 = Department::factory()->create();
        $this->d2 = Department::factory()->create();

        // Same permission set for all three actors: only scope may differ.
        $this->u['hr'] = $this->makeUser('HR Manager', null, 'Global Hr');
        $this->u['hr2'] = $this->makeUser('HR Manager', null, 'Second Hr');
        $this->u['da'] = $this->makeUser('Department Admin', null, 'Dept Admin');
        UserDepartmentScope::create(['user_id' => $this->u['da']->employee_id, 'department_id' => $this->d1->id, 'scope_type' => 'admin']);
        $this->u['emp'] = $this->makeUser('Employee', $this->d1, 'Plain Employee');
        $this->u['e1'] = $this->makeUser('Employee', $this->d1, 'Target One');
        $this->u['e2'] = $this->makeUser('Employee', $this->d2, 'Target Two');

        foreach (['hr', 'hr2', 'da', 'emp'] as $key) {
            $this->u[$key]->givePermissionTo(self::PERMISSIONS);
        }

        $this->ensurePayrollSchema();
        $this->setFlag('hr_payroll', true);
        $this->setFlag('hr_final_settlement', true);
        Queue::fake();
    }

    /**
     * The payroll module is flagged off "until rebuilt against the payroll schema": the
     * migration only creates an empty `payrolls` table and no `payslips`. Give the test DB
     * the minimal columns the (scoped) controller reads so the guards can be exercised.
     */
    private function ensurePayrollSchema(): void
    {
        if (! Schema::hasColumn('payrolls', 'user_id')) {
            Schema::dropIfExists('payrolls');
            Schema::create('payrolls', function (Blueprint $t) {
                $t->id();
                $t->string('user_id')->nullable();
                $t->date('pay_period_start')->nullable();
                $t->date('pay_period_end')->nullable();
                foreach (['basic_salary', 'gross_salary', 'total_deductions', 'net_salary', 'overtime_hours', 'overtime_amount'] as $c) {
                    $t->decimal($c, 12, 2)->default(0);
                }
                foreach (['working_days', 'present_days', 'absent_days', 'leave_days'] as $c) {
                    $t->integer($c)->default(0);
                }
                $t->string('status')->nullable();
                $t->string('processed_by')->nullable();
                $t->timestamp('processed_at')->nullable();
                $t->text('remarks')->nullable();
                $t->timestamps();
            });
        }

        foreach (['payroll_allowances', 'payroll_deductions'] as $table) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, function (Blueprint $t) {
                    $t->id();
                    $t->unsignedBigInteger('payroll_id');
                    $t->decimal('amount', 12, 2)->default(0);
                    $t->timestamps();
                });
            }
        }

        if (! Schema::hasTable('payslips')) {
            Schema::create('payslips', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('payroll_id')->nullable();
                $t->string('user_id')->nullable();
                $t->string('payslip_number')->nullable();
                $t->date('pay_period_start')->nullable();
                $t->date('pay_period_end')->nullable();
                foreach (['basic_salary', 'gross_salary', 'total_allowances', 'total_deductions', 'net_salary'] as $c) {
                    $t->decimal($c, 12, 2)->default(0);
                }
                $t->timestamp('generated_at')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->string('pdf_path')->nullable();
                $t->boolean('email_sent')->default(false);
                $t->string('status')->nullable();
                $t->timestamps();
            });
        }
    }

    private function makeUser(string $role, ?Department $department, string $name): User
    {
        $user = User::factory()->create(['department_id' => $department?->id, 'name' => $name]);
        $user->assignRole($role);

        return $user;
    }

    private function setFlag(string $key, bool $enabled): void
    {
        FeatureFlag::updateOrCreate(['key' => $key, 'role' => null], ['is_enabled' => $enabled]);
        app(FeatureFlagService::class)->forgetMemo();
    }

    private function id(string $key): string
    {
        return (string) $this->u[$key]->employee_id;
    }

    /** Resolve a target keyword ('e1', 'e2', 'self') for an actor to an employee_id. */
    private function target(string $actor, string $target): string
    {
        return $target === 'self' ? $this->id($actor) : $this->id($target);
    }

    private function seedOnboarding(User $employee): Onboarding
    {
        $this->actingAs($this->u['hr']);

        return Onboarding::create([
            'employee_id' => $employee->employee_id,
            'start_date' => now()->toDateString(),
            'expected_completion_date' => now()->addWeek()->toDateString(),
            'status' => Onboarding::STATUS_IN_PROGRESS,
        ]);
    }

    private function seedOffboarding(User $employee, string $status = Offboarding::STATUS_IN_PROGRESS): Offboarding
    {
        $this->actingAs($this->u['hr']);

        return Offboarding::create([
            'employee_id' => $employee->employee_id,
            'initiation_date' => now()->toDateString(),
            'last_working_date' => now()->addDays(30)->toDateString(),
            'reason' => Offboarding::REASON_RESIGNATION,
            'status' => $status,
        ]);
    }

    private function seedAsset(?User $assignee, string $code, string $status = Asset::STATUS_AVAILABLE): Asset
    {
        return Asset::create([
            'asset_code' => $code,
            'name' => "Asset {$code}",
            'category' => 'it_hardware',
            'assignee_id' => $assignee?->employee_id,
            'status' => $assignee ? Asset::STATUS_ASSIGNED : $status,
        ]);
    }

    /** Raw inserts: the sqlite test DB would store cast dates as datetimes and break the period equality filter. */
    private function seedPayslip(User $employee): void
    {
        $now = now()->toDateTimeString();
        $period = ['pay_period_start' => now()->startOfMonth()->toDateString(), 'pay_period_end' => now()->endOfMonth()->toDateString()];

        $payrollId = DB::table('payrolls')->insertGetId([
            'user_id' => $employee->employee_id, 'basic_salary' => 6000, 'gross_salary' => 10000, 'net_salary' => 10000,
            'status' => 'draft', 'created_at' => $now, 'updated_at' => $now,
        ] + $period);

        DB::table('payslips')->insert([
            'payroll_id' => $payrollId, 'user_id' => $employee->employee_id, 'payslip_number' => 'PS-'.$employee->employee_id,
            'gross_salary' => 10000, 'net_salary' => 10000, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now,
        ] + $period);
    }

    private function seedAll(): void
    {
        foreach (['hr', 'da', 'emp', 'e1', 'e2'] as $key) {
            $this->seedOnboarding($this->u[$key]);
            $this->seedOffboarding($this->u[$key]);
            $this->seedPayslip($this->u[$key]);
            $this->seedAsset($this->u[$key], "A-{$key}");
        }
    }

    // ──────────────────────────────────────────────
    //  Per-target endpoint matrix
    // ──────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: string, 2: string, 3: int}> */
    public static function matrix(): array
    {
        $rows = [];
        // endpoint => [actor => [e1, e2, self]]
        $expect = [
            'onboarding_store' => ['da' => [201, 403, 403], 'emp' => [403, 403, 403], 'hr' => [201, 201, 403]],
            'offboarding_store' => ['da' => [201, 403, 403], 'emp' => [403, 403, 403], 'hr' => [201, 201, 403]],
            'onboarding_show' => ['da' => [200, 403, 200], 'emp' => [403, 403, 200], 'hr' => [200, 200, 200]],
            'offboarding_show' => ['da' => [200, 403, 200], 'emp' => [403, 403, 200], 'hr' => [200, 200, 200]],
            'offboarding_update' => ['da' => [200, 403, 403], 'emp' => [403, 403, 403], 'hr' => [200, 200, 403]],
            'offboarding_destroy' => ['da' => [200, 403, 403], 'emp' => [403, 403, 403], 'hr' => [200, 200, 403]],
            'onboarding_destroy' => ['da' => [200, 403, 403], 'emp' => [403, 403, 403], 'hr' => [200, 200, 403]],
            'asset_assign' => ['da' => [200, 403, 403], 'emp' => [403, 403, 403], 'hr' => [200, 200, 403]],
            'assets_by_employee' => ['da' => [200, 403, 200], 'emp' => [403, 403, 200], 'hr' => [200, 200, 200]],
            'payslip_show' => ['da' => [200, 403, 200], 'emp' => [403, 403, 200], 'hr' => [200, 200, 200]],
            'settlement_calculate' => ['da' => [200, 403, 403], 'emp' => [403, 403, 403], 'hr' => [200, 200, 403]],
            'certificate' => ['da' => [200, 403, 200], 'emp' => [403, 403, 200], 'hr' => [200, 200, 200]],
        ];
        foreach ($expect as $endpoint => $byActor) {
            foreach ($byActor as $actor => $statuses) {
                foreach (['e1', 'e2', 'self'] as $i => $target) {
                    $rows["{$endpoint}: {$actor} -> {$target}"] = [$endpoint, $actor, $target, $statuses[$i]];
                }
            }
        }

        return $rows;
    }

    private function hit(string $endpoint, string $actor, string $target): TestResponse
    {
        $employee = $this->u[$target === 'self' ? $actor : $target];
        $this->actingAs($this->u[$actor]);
        $eid = (string) $employee->employee_id;

        return match ($endpoint) {
            'onboarding_store' => $this->postJson('/hr/onboarding', [
                'employee_id' => $eid,
                'start_date' => now()->toDateString(),
                'expected_completion_date' => now()->addWeek()->toDateString(),
            ]),
            'offboarding_store' => $this->postJson('/hr/offboarding', [
                'employee_id' => $eid,
                'last_working_date' => now()->addDays(30)->toDateString(),
                'reason' => 'resignation',
            ]),
            'onboarding_show' => $this->getJson('/hr/onboarding/'.Onboarding::where('employee_id', $eid)->value('id')),
            'offboarding_show' => $this->getJson('/hr/offboarding/'.Offboarding::where('employee_id', $eid)->value('id')),
            'offboarding_update' => $this->putJson('/hr/offboarding/'.Offboarding::where('employee_id', $eid)->value('id'), [
                'initiation_date' => now()->toDateString(),
                'last_working_date' => now()->addDays(30)->toDateString(),
                'reason' => 'resignation',
                'status' => 'in_progress',
                'notes' => 'updated',
            ]),
            'offboarding_destroy' => $this->deleteJson('/hr/offboarding/'.Offboarding::where('employee_id', $eid)->value('id')),
            'onboarding_destroy' => $this->deleteJson('/hr/onboarding/'.Onboarding::where('employee_id', $eid)->value('id')),
            'asset_assign' => $this->postJson('/hr/assets/'.Asset::whereNull('assignee_id')->value('id').'/assign', ['employee_id' => $eid]),
            'assets_by_employee' => $this->getJson("/hr/assets/by-employee/{$eid}"),
            'payslip_show' => $this->getJson('/hr/payroll/payslip/'.Payslip::where('user_id', $eid)->value('id')),
            'settlement_calculate' => $this->getJson('/hr/offboarding/'.Offboarding::where('employee_id', $eid)->value('id').'/settlement/calculate'),
            'certificate' => $this->getJson('/hr/offboarding/'.Offboarding::where('employee_id', $eid)->value('id').'/certificate/experience'),
        };
    }

    #[DataProvider('matrix')]
    public function test_endpoint_matrix(string $endpoint, string $actor, string $target, int $expected): void
    {
        $writes = ['onboarding_store', 'offboarding_store'];
        if (! in_array($endpoint, $writes, true)) {
            $this->seedAll();
        }
        if ($endpoint === 'asset_assign') {
            $this->seedAsset(null, 'POOL-1');
        }

        $this->hit($endpoint, $actor, $target)->assertStatus($expected);
    }

    // ──────────────────────────────────────────────
    //  Lists and stats only contain in-scope rows
    // ──────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function visibleSets(): array
    {
        return [
            'department admin sees D1 people and self' => ['da', ['da', 'emp', 'e1']],
            'plain employee sees only self' => ['emp', ['emp']],
            'global hr sees everyone' => ['hr', ['hr', 'da', 'emp', 'e1', 'e2']],
        ];
    }

    /** @param  list<string>  $visible */
    #[DataProvider('visibleSets')]
    public function test_lists_and_stats_only_contain_in_scope_rows(string $actor, array $visible): void
    {
        $this->seedAll();
        $expectedIds = collect($visible)->map(fn ($k) => $this->id($k))->sort()->values()->all();
        $this->u['da']->fresh(); // da's grant is D1; 'da' itself is visible as self

        foreach ([
            ['/hr/onboarding', 'onboardings'],
            ['/hr/offboarding', 'offboardings'],
        ] as [$url, $prop]) {
            $json = $this->actingAs($this->u[$actor])->getJson($url)->assertOk()->json('data');
            $this->assertSame($expectedIds, collect($json)->pluck('employee_id')->map(fn ($v) => (string) $v)->sort()->values()->all(), "{$url} list");

            $props = $this->actingAs($this->u[$actor])->get($url)->assertOk()->viewData('page')['props'];
            $this->assertSame(count($expectedIds), $props['stats']['total'], "{$url} stats total");
            $this->assertSame(count($expectedIds), $props[$prop]['total'], "{$url} paginator total");
        }

        // Payroll (period = current month)
        $payroll = $this->actingAs($this->u[$actor])->getJson('/hr/payroll?month='.now()->format('Y-m'))->assertOk()->json('data');
        $this->assertSame($expectedIds, collect($payroll)->pluck('user_id')->map(fn ($v) => (string) $v)->sort()->values()->all());
        $stats = $this->actingAs($this->u[$actor])->get('/hr/payroll?month='.now()->format('Y-m'))->viewData('page')['props']['stats'];
        $this->assertSame(count($expectedIds), $stats['total_employees']);
    }

    public function test_assets_list_and_stats_include_in_scope_assignments_plus_available_pool_only(): void
    {
        $this->seedAll();
        $this->seedAsset(null, 'POOL-OK');
        $this->seedAsset(null, 'POOL-DAMAGED', Asset::STATUS_DAMAGED);

        $codes = fn (string $actor) => collect($this->actingAs($this->u[$actor])->getJson('/hr/assets')->assertOk()->json('data'))
            ->pluck('asset_code')->sort()->values()->all();

        $this->assertSame(['A-da', 'A-e1', 'A-emp', 'POOL-OK'], $codes('da'));
        $this->assertSame(['A-emp', 'POOL-OK'], $codes('emp'));
        $this->assertCount(7, $codes('hr'));

        $stats = $this->actingAs($this->u['da'])->get('/hr/assets')->viewData('page')['props']['stats'];
        $this->assertSame(4, $stats['total']);
        $this->assertSame(1, $stats['available']);
        $this->assertSame(0, $stats['damaged']);
    }

    public function test_absence_cases_prop_is_scoped(): void
    {
        foreach (['e1', 'e2'] as $key) {
            AbsenceCase::create([
                'user_id' => $this->id($key), 'first_absent_date' => now()->subDays(5)->toDateString(),
                'streak_days' => 5, 'stage' => AbsenceCase::STAGE_MONITORING,
            ]);
        }

        $props = $this->actingAs($this->u['da'])->get('/hr/offboarding')->viewData('page')['props'];
        $this->assertSame([$this->id('e1')], collect($props['absenceCases'])->pluck('user_id')->map(fn ($v) => (string) $v)->all());
        $this->assertSame(1, $props['stats']['active_cases']);

        $json = $this->actingAs($this->u['da'])->getJson('/hr/absence-cases')->assertOk()->json('data');
        $this->assertCount(1, $json);
    }

    public function test_absence_case_actions_are_scoped(): void
    {
        $out = AbsenceCase::create([
            'user_id' => $this->id('e2'), 'first_absent_date' => now()->subDays(5)->toDateString(),
            'streak_days' => 5, 'stage' => AbsenceCase::STAGE_MONITORING,
        ]);
        $in = AbsenceCase::create([
            'user_id' => $this->id('e1'), 'first_absent_date' => now()->subDays(5)->toDateString(),
            'streak_days' => 5, 'stage' => AbsenceCase::STAGE_MONITORING,
        ]);

        $this->actingAs($this->u['da'])->postJson("/hr/absence-cases/{$out->id}/resolve", ['action' => 'regularize'])->assertForbidden();
        $this->actingAs($this->u['da'])->getJson("/hr/absence-cases/{$out->id}/notice/show_cause")->assertForbidden();
        $this->actingAs($this->u['da'])->postJson("/hr/absence-cases/{$in->id}/resolve", ['action' => 'regularize'])->assertOk();
    }

    // ──────────────────────────────────────────────
    //  eligibleEmployees (onboarding / offboarding)
    // ──────────────────────────────────────────────

    public function test_offboarding_eligible_employees_are_scoped_and_exclude_self(): void
    {
        $ids = collect($this->actingAs($this->u['da'])->getJson('/hr/offboarding/eligible-employees')->assertOk()->json())
            ->pluck('employee_id')->map(fn ($v) => (string) $v)->sort()->values()->all();

        $this->assertSame(collect([$this->id('emp'), $this->id('e1')])->sort()->values()->all(), $ids);

        $hrIds = collect($this->actingAs($this->u['hr'])->getJson('/hr/offboarding/eligible-employees')->json())->pluck('employee_id')->all();
        $this->assertNotContains($this->id('hr'), $hrIds);
        $this->assertContains($this->id('e2'), $hrIds);
    }

    public function test_onboarding_eligible_employees_scoped_recent_joiners_only_unless_all(): void
    {
        $this->u['e1']->forceFill(['date_of_joining' => now()->subDays(10)->toDateString()])->save();
        $this->u['emp']->forceFill(['date_of_joining' => now()->subYears(3)->toDateString()])->save();
        $this->u['e2']->forceFill(['date_of_joining' => now()->subDays(5)->toDateString()])->save();

        $ids = fn (string $qs) => collect($this->actingAs($this->u['da'])->getJson('/hr/onboarding/eligible-employees'.$qs)->assertOk()->json())
            ->pluck('employee_id')->map(fn ($v) => (string) $v)->all();

        $this->assertSame([$this->id('e1')], $ids(''));
        $all = $ids('?all=1');
        $this->assertContains($this->id('emp'), $all);
        $this->assertContains($this->id('e1'), $all);
        $this->assertNotContains($this->id('e2'), $all);

        $this->assertSame([$this->id('e1')], $ids('?search=Target One'));
    }

    // ──────────────────────────────────────────────
    //  Onboarding biometric enrollment
    // ──────────────────────────────────────────────

    private function device(string $serial): BiometricDevice
    {
        return BiometricDevice::create(['name' => "Terminal {$serial}", 'serial_number' => $serial, 'is_active' => true]);
    }

    public function test_onboarding_biometric_without_mapping_queues_nothing_and_warns(): void
    {
        $this->device('SN-1');
        $this->device('SN-2');

        $response = $this->actingAs($this->u['hr'])->postJson('/hr/onboarding', [
            'employee_id' => $this->id('e1'),
            'start_date' => now()->toDateString(),
            'expected_completion_date' => now()->addWeek()->toDateString(),
        ])->assertCreated();

        $response->assertJsonPath('devices_queued', 0);
        $this->assertNotEmpty($response->json('warning'));
        $this->assertSame(0, BiometricDeviceCommand::count(), 'must never fan out to every terminal');
    }

    public function test_onboarding_biometric_targets_only_work_location_devices(): void
    {
        $mapped = $this->device('SN-1');
        $this->device('SN-2');
        $location = WorkLocation::create(['name' => 'Plaza A', 'code' => 'PA', 'is_active' => true]);
        DB::table('work_location_biometric_device')->insert(['work_location_id' => $location->id, 'biometric_device_id' => $mapped->id]);
        $this->u['e1']->forceFill(['work_location_id' => $location->id])->save();

        $this->actingAs($this->u['hr'])->postJson('/hr/onboarding', [
            'employee_id' => $this->id('e1'),
            'start_date' => now()->toDateString(),
            'expected_completion_date' => now()->addWeek()->toDateString(),
        ])->assertCreated()->assertJsonPath('devices_queued', 1);

        $this->assertSame([$mapped->id], BiometricDeviceCommand::pluck('biometric_device_id')->all());
    }

    // ──────────────────────────────────────────────
    //  Offboarding: system-created + notification
    // ──────────────────────────────────────────────

    public function test_absence_streak_auto_create_succeeds_without_authenticated_user(): void
    {
        $this->u['e1']->forceFill(['report_to' => $this->id('da')])->save();
        $case = AbsenceCase::create([
            'user_id' => $this->id('e1'), 'first_absent_date' => now()->subDays(20)->toDateString(),
            'streak_days' => 20, 'stage' => AbsenceCase::STAGE_SHOW_CAUSE,
        ]);

        $this->makeUser('Super Administrator', null, 'Root');
        auth()->logout();
        $this->assertNull(auth()->id());

        $command = new ProcessAbsenceStreak;
        $e1 = $this->id('e1');
        (fn () => $this->autoCreateOffboarding($case, $e1))->call($command);

        $offboarding = Offboarding::where('employee_id', $this->id('e1'))->firstOrFail();
        $this->assertNotNull($offboarding->created_by);
        $this->assertSame(Offboarding::REASON_ABSCONDED, $offboarding->reason);
    }

    public function test_model_prefers_explicit_created_by_over_authenticated_user(): void
    {
        $this->actingAs($this->u['hr']);
        $offboarding = new Offboarding([
            'employee_id' => $this->id('e1'), 'initiation_date' => now()->toDateString(),
            'last_working_date' => now()->addDays(3)->toDateString(), 'reason' => 'other',
        ]);
        $offboarding->created_by = $this->id('hr2');
        $offboarding->save();

        $this->assertSame($this->id('hr2'), (string) $offboarding->fresh()->created_by);
    }

    public function test_offboarding_self_initiation_is_forbidden(): void
    {
        foreach (['hr', 'da'] as $actor) {
            $this->actingAs($this->u[$actor])->postJson('/hr/offboarding', [
                'employee_id' => $this->id($actor),
                'last_working_date' => now()->addDays(30)->toDateString(),
                'reason' => 'resignation',
            ])->assertForbidden();
        }
        $this->assertSame(0, Offboarding::count());
    }

    public function test_initiation_notifies_manager_and_department_managers(): void
    {
        Notification::fake();
        $this->u['e1']->forceFill(['report_to' => $this->id('emp')])->save();

        $this->actingAs($this->u['hr'])->postJson('/hr/offboarding', [
            'employee_id' => $this->id('e1'),
            'last_working_date' => now()->addDays(30)->toDateString(),
            'reason' => 'resignation',
        ])->assertCreated();

        Notification::assertSentTo($this->u['emp'], OffboardingInitiatedNotification::class);
        Notification::assertSentTo($this->u['da'], OffboardingInitiatedNotification::class);
        Notification::assertNotSentTo($this->u['e2'], OffboardingInitiatedNotification::class);
    }

    // ──────────────────────────────────────────────
    //  Settlement
    // ──────────────────────────────────────────────

    private function draftSettlement(User $employee, string $preparedBy): FinalSettlement
    {
        $offboarding = $this->seedOffboarding($employee);

        return FinalSettlement::create([
            'offboarding_id' => $offboarding->id,
            'employee_id' => $employee->employee_id,
            'last_working_date' => $offboarding->last_working_date,
            'monthly_gross_salary' => 10000, 'daily_rate' => 333.33, 'payable_working_days' => 10, 'earned_salary' => 3333.3,
            'total_earnings' => 3333.3, 'total_deductions' => 0, 'net_payable' => 3333.3,
            'status' => FinalSettlement::STATUS_DRAFT,
            'prepared_by' => $preparedBy,
        ]);
    }

    public function test_settlement_approve_by_preparer_is_forbidden_but_another_approver_succeeds(): void
    {
        $settlement = $this->draftSettlement($this->u['e1'], $this->id('hr'));

        $this->actingAs($this->u['hr'])->postJson("/hr/offboarding/settlement/{$settlement->id}/approve")->assertForbidden();
        $this->assertSame('draft', $settlement->fresh()->status);

        $this->actingAs($this->u['hr2'])->postJson("/hr/offboarding/settlement/{$settlement->id}/approve")->assertOk();
        $this->assertSame('approved', $settlement->fresh()->status);

        // Only drafts can be approved.
        $this->actingAs($this->u['hr2'])->postJson("/hr/offboarding/settlement/{$settlement->id}/approve")->assertStatus(422);
    }

    public function test_settlement_approve_is_scoped_and_blocked_for_the_subject_employee(): void
    {
        $outOfScope = $this->draftSettlement($this->u['e2'], $this->id('hr'));
        $this->actingAs($this->u['da'])->postJson("/hr/offboarding/settlement/{$outOfScope->id}/approve")->assertForbidden();

        $own = $this->draftSettlement($this->u['da'], $this->id('hr'));
        $this->actingAs($this->u['da'])->postJson("/hr/offboarding/settlement/{$own->id}/approve")->assertForbidden();
    }

    public function test_settlement_disburse_requires_approved_status(): void
    {
        $settlement = $this->draftSettlement($this->u['e1'], $this->id('hr'));
        $payload = ['payment_method' => 'cash'];

        $this->actingAs($this->u['hr2'])->postJson("/hr/offboarding/settlement/{$settlement->id}/disburse", $payload)->assertStatus(422);

        $settlement->update(['status' => FinalSettlement::STATUS_APPROVED, 'approved_by' => $this->id('hr2')]);
        $this->actingAs($this->u['hr2'])->postJson("/hr/offboarding/settlement/{$settlement->id}/disburse", $payload)->assertOk();
        $this->assertSame('paid', $settlement->fresh()->status);
    }

    public function test_settlement_disburse_is_scoped(): void
    {
        $settlement = $this->draftSettlement($this->u['e2'], $this->id('hr'));
        $settlement->update(['status' => FinalSettlement::STATUS_APPROVED]);

        $this->actingAs($this->u['da'])->postJson("/hr/offboarding/settlement/{$settlement->id}/disburse", ['payment_method' => 'cash'])->assertForbidden();
    }

    // ──────────────────────────────────────────────
    //  Payroll generate / assets / petty cash
    // ──────────────────────────────────────────────

    public function test_payroll_generate_is_global_only(): void
    {
        $this->actingAs($this->u['da'])->postJson('/hr/payroll/generate', ['month' => now()->format('Y-m')])->assertForbidden();
        $this->actingAs($this->u['hr'])->postJson('/hr/payroll/generate', ['month' => now()->format('Y-m')])->assertOk();
    }

    public function test_asset_return_update_destroy_follow_the_assignee_scope(): void
    {
        $mine = $this->seedAsset($this->u['e1'], 'HELD-1');
        $theirs = $this->seedAsset($this->u['e2'], 'HELD-2');
        $pool = $this->seedAsset(null, 'POOL-9');
        $da = $this->u['da'];

        $this->actingAs($da)->postJson("/hr/assets/{$mine->id}/return", ['condition_on_return' => 'good'])->assertOk();
        $this->actingAs($da)->postJson("/hr/assets/{$theirs->id}/return", ['condition_on_return' => 'good'])->assertForbidden();
        $this->actingAs($da)->putJson("/hr/assets/{$theirs->id}", ['name' => 'x', 'category' => 'other'])->assertForbidden();
        $this->actingAs($da)->deleteJson("/hr/assets/{$theirs->id}")->assertForbidden();
        $this->actingAs($da)->deleteJson("/hr/assets/{$pool->id}")->assertForbidden(); // pool: global only
        $this->actingAs($this->u['hr'])->deleteJson("/hr/assets/{$pool->id}")->assertOk();
    }

    public function test_asset_assign_rejects_soft_deleted_employee(): void
    {
        $pool = $this->seedAsset(null, 'POOL-3');
        $this->u['e1']->delete();

        $this->actingAs($this->u['hr'])->postJson("/hr/assets/{$pool->id}/assign", ['employee_id' => $this->id('e1')])->assertForbidden();
    }

    public function test_petty_cash_approval_rules(): void
    {
        $own = PettyCashLoan::create(['user_id' => $this->id('da'), 'fund_name' => 'F', 'loan_amount' => 100, 'original_amount' => 100, 'current_balance' => 0, 'status' => 'pending_approval', 'loan_date' => now()->toDateString()]);
        $inScope = PettyCashLoan::create(['user_id' => $this->id('e1'), 'fund_name' => 'F', 'loan_amount' => 100, 'original_amount' => 100, 'current_balance' => 0, 'status' => 'pending_approval', 'loan_date' => now()->toDateString()]);
        $outScope = PettyCashLoan::create(['user_id' => $this->id('e2'), 'fund_name' => 'F', 'loan_amount' => 100, 'original_amount' => 100, 'current_balance' => 0, 'status' => 'pending_approval', 'loan_date' => now()->toDateString()]);

        $da = $this->u['da'];
        $this->actingAs($da)->postJson('/petty-cash/loan/approve', ['loan_id' => $own->id])->assertForbidden();
        $this->actingAs($da)->postJson('/petty-cash/loan/reject', ['loan_id' => $own->id])->assertForbidden();
        $this->actingAs($da)->postJson('/petty-cash/loan/approve', ['loan_id' => $outScope->id])->assertForbidden();
        $this->actingAs($da)->postJson('/petty-cash/loan/approve', ['loan_id' => $inScope->id])->assertOk();

        // Global approver: own loan still blocked.
        $ownHr = PettyCashLoan::create(['user_id' => $this->id('hr'), 'fund_name' => 'F', 'loan_amount' => 100, 'original_amount' => 100, 'current_balance' => 0, 'status' => 'pending_approval', 'loan_date' => now()->toDateString()]);
        $this->actingAs($this->u['hr'])->postJson('/petty-cash/loan/approve', ['loan_id' => $ownHr->id])->assertForbidden();
        $this->actingAs($this->u['hr'])->postJson('/petty-cash/loan/approve', ['loan_id' => $outScope->id])->assertOk();
    }

    public function test_petty_cash_permissions_replace_role_names_and_overview_is_scoped(): void
    {
        foreach (['e1', 'e2'] as $key) {
            PettyCashLoan::create(['user_id' => $this->id($key), 'fund_name' => 'F', 'loan_amount' => 100, 'original_amount' => 100, 'current_balance' => 0, 'status' => 'active', 'loan_date' => now()->toDateString()]);
        }

        $loans = $this->actingAs($this->u['da'])->getJson('/petty-cash/admin/overview')->assertOk()->json('loans');
        $this->assertCount(1, $loans);

        // Employee without petty-cash permissions cannot approve or open the overview.
        $plain = $this->makeUser('Employee', $this->d1, 'No Perms');
        $this->actingAs($plain)->getJson('/petty-cash/admin/overview')->assertForbidden();
        $loan = PettyCashLoan::first();
        $this->actingAs($plain)->postJson('/petty-cash/loan/approve', ['loan_id' => $loan->id])->assertForbidden();

        // Legacy role name still passes during the transition (documented fallback).
        Role::findOrCreate('Accountant', 'web');
        $legacy = $this->makeUser('Employee', $this->d2, 'Legacy Acct');
        $legacy->assignRole('Accountant');
        $this->actingAs($legacy)->getJson('/petty-cash/admin/overview')->assertOk();
    }

    public function test_petty_cash_owner_keeps_access_others_need_permission_and_scope(): void
    {
        $loan = PettyCashLoan::create(['user_id' => $this->id('e1'), 'fund_name' => 'F', 'loan_amount' => 100, 'original_amount' => 100, 'current_balance' => 0, 'status' => 'active', 'loan_date' => now()->toDateString()]);
        $peer = $this->makeUser('Employee', $this->d1, 'Peer');

        $this->actingAs($this->u['e1'])->getJson('/petty-cash/transactions?loan_id='.$loan->id)->assertOk();
        $this->actingAs($peer)->getJson('/petty-cash/transactions?loan_id='.$loan->id)->assertForbidden();
        $this->actingAs($this->u['da'])->getJson('/petty-cash/transactions?loan_id='.$loan->id)->assertOk();
        $outside = PettyCashLoan::create(['user_id' => $this->id('e2'), 'fund_name' => 'F', 'loan_amount' => 100, 'original_amount' => 100, 'current_balance' => 0, 'status' => 'active', 'loan_date' => now()->toDateString()]);
        $this->actingAs($this->u['da'])->getJson('/petty-cash/transactions?loan_id='.$outside->id)->assertForbidden();
    }

    // ──────────────────────────────────────────────
    //  Leave + user directory props
    // ──────────────────────────────────────────────

    public function test_leave_pages_ship_only_scoped_directory(): void
    {
        foreach (['leave.own.view', 'leaves.view'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        foreach (['da', 'emp', 'hr'] as $actor) {
            $this->u[$actor]->givePermissionTo(['leave.own.view', 'leaves.view']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $ids = fn (string $actor, string $url) => collect($this->actingAs($this->u[$actor])->get($url)->assertOk()->viewData('page')['props']['allUsers'])
            ->pluck('employee_id')->map(fn ($v) => (string) $v)->sort()->values()->all();

        $this->assertNotContains($this->id('e2'), $ids('da', '/leaves-employee'));
        $this->assertContains($this->id('e1'), $ids('da', '/leaves-employee'));
        $this->assertSame([$this->id('emp')], $ids('emp', '/leaves-employee'));
        $this->assertContains($this->id('e2'), $ids('hr', '/leaves-employee'));
    }

    public function test_user_index_limits_roles_for_scoped_actor(): void
    {
        foreach (['users.view', 'employees.view'] as $p) {
            Permission::findOrCreate($p, 'web');
            $this->u['da']->givePermissionTo($p);
            $this->u['hr']->givePermissionTo($p);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = fn (string $actor) => collect($this->actingAs($this->u[$actor])->get(route('employees'))->viewData('page')['props']['roles'] ?? [])->pluck('name')->all();

        $scoped = $roles('da');
        $this->assertContains('Employee', $scoped);
        $this->assertNotContains('Administrator', $scoped);
        $this->assertNotContains('Super Administrator', $scoped);
        $this->assertNotContains('Department Admin', $scoped); // equal level: not grantable
        $this->assertContains('Administrator', $roles('hr'));
    }
}
