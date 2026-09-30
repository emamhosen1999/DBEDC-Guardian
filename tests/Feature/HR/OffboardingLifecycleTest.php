<?php

namespace Tests\Feature\HR;

use App\Jobs\ProcessOffboardingLwd;
use App\Models\FeatureFlag;
use App\Models\HRM\Asset;
use App\Models\HRM\BiometricDevice;
use App\Models\HRM\BiometricDeviceCommand;
use App\Models\HRM\FinalSettlement;
use App\Models\HRM\Offboarding;
use App\Models\HRM\OffboardingTask;
use App\Models\User;
use App\Services\DeviceAuthService;
use App\Services\FeatureFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OffboardingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['hr.offboarding.view', 'hr.offboarding.create', 'hr.offboarding.update'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $this->hr = User::factory()->create();
        $this->hr->givePermissionTo(['hr.offboarding.view', 'hr.offboarding.create', 'hr.offboarding.update']);
        // Offboarding is department-scoped: this actor is company-wide HR.
        $this->hr->assignRole(Role::findOrCreate('HR Manager', 'web'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function makeOffboarding(string $lwd, array $overrides = [], ?User $employee = null): Offboarding
    {
        $employee ??= User::factory()->create();
        $this->actingAs($this->hr); // created_by is stamped from Auth::id()

        return Offboarding::create(array_merge([
            'employee_id' => $employee->employee_id,
            'initiation_date' => now()->toDateString(),
            'last_working_date' => $lwd,
            'reason' => Offboarding::REASON_RESIGNATION,
            'status' => Offboarding::STATUS_IN_PROGRESS,
        ], $overrides));
    }

    private function runJob(Offboarding $o): void
    {
        (new ProcessOffboardingLwd($o))->handle(app(DeviceAuthService::class));
    }

    private function addDevice(): BiometricDevice
    {
        return BiometricDevice::forceCreate([
            'name' => 'Gate',
            'serial_number' => 'SN-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function setFlag(string $key, bool $enabled): void
    {
        FeatureFlag::updateOrCreate(['key' => $key, 'role' => null], ['is_enabled' => $enabled]);
        app(FeatureFlagService::class)->forgetMemo();
    }

    private function complete(Offboarding $o): void
    {
        $o->tasks()->create(['task' => 'Handover', 'status' => OffboardingTask::STATUS_COMPLETED]);
    }

    public function test_resignation_with_future_lwd_dispatches_delayed_job(): void
    {
        Queue::fake();
        $employee = User::factory()->create();

        $this->actingAs($this->hr)->postJson('/hr/offboarding', [
            'employee_id' => $employee->employee_id,
            'last_working_date' => now()->addDays(60)->toDateString(),
            'reason' => 'resignation',
        ])->assertCreated();

        Queue::assertPushed(ProcessOffboardingLwd::class, function ($job) {
            return $job->delay !== null
                && now()->addDays(60)->startOfDay()->addDay()->equalTo($job->delay);
        });
    }

    public function test_resignation_with_future_lwd_does_not_revoke_access_or_queue_biometric_delete(): void
    {
        $this->addDevice();
        $employee = User::factory()->create();
        $employee->createToken('mobile');

        // Real (sync) queue in tests: the delay is ignored, so the job's own guard must protect.
        $this->actingAs($this->hr)->postJson('/hr/offboarding', [
            'employee_id' => $employee->employee_id,
            'last_working_date' => now()->addDays(60)->toDateString(),
            'reason' => 'resignation',
        ])->assertCreated();

        $this->assertSame(1, $employee->tokens()->count());
        $this->assertSame(0, BiometricDeviceCommand::where('command_type', 'DELETE_USER')->count());
        $o = Offboarding::where('employee_id', $employee->employee_id)->firstOrFail();
        $this->assertNull($o->lwd_processed_at);
        $this->assertSame(Offboarding::STATUS_IN_PROGRESS, $o->status);
    }

    public function test_job_after_lwd_revokes_access_and_is_idempotent(): void
    {
        $this->addDevice();
        $o = $this->makeOffboarding(now()->subDay()->toDateString());
        $employee = User::find($o->employee_id);
        $employee->createToken('mobile');

        $this->runJob($o);

        $this->assertSame(0, $employee->tokens()->count());
        $this->assertSame(1, BiometricDeviceCommand::where('command_type', 'DELETE_USER')->count());
        $this->assertNotNull($o->fresh()->lwd_processed_at);

        $this->runJob($o);
        $this->assertSame(1, BiometricDeviceCommand::where('command_type', 'DELETE_USER')->count());
    }

    public function test_job_exits_when_lwd_extended_after_dispatch(): void
    {
        $o = $this->makeOffboarding(now()->subDay()->toDateString());
        $employee = User::find($o->employee_id);
        $employee->createToken('mobile');
        $o->update(['last_working_date' => now()->addDays(10)->toDateString()]);

        $this->runJob($o);

        $this->assertSame(1, $employee->tokens()->count());
        $this->assertNull($o->fresh()->lwd_processed_at);
    }

    public function test_ticking_last_task_before_lwd_does_not_complete_or_delete(): void
    {
        $o = $this->makeOffboarding(now()->addDays(5)->toDateString());
        $task = $o->tasks()->create(['task' => 'Handover', 'status' => 'pending']);

        $res = $this->actingAs($this->hr)
            ->patchJson("/hr/offboarding/{$o->id}/tasks/{$task->id}", ['status' => 'completed'])
            ->assertOk();

        $this->assertNotEmpty($res->json('completion_blockers'));
        $this->assertSame(Offboarding::STATUS_IN_PROGRESS, $o->fresh()->status);
        $this->assertNull(User::withTrashed()->find($o->employee_id)->deleted_at);
    }

    public function test_assigned_assets_block_completion(): void
    {
        $o = $this->makeOffboarding(now()->subDay()->toDateString());
        $task = $o->tasks()->create(['task' => 'Handover', 'status' => 'pending']);
        Asset::create([
            'asset_code' => 'AST-1', 'name' => 'Laptop', 'category' => 'it_hardware',
            'assignee_id' => $o->employee_id, 'status' => Asset::STATUS_ASSIGNED,
        ]);

        $res = $this->actingAs($this->hr)
            ->patchJson("/hr/offboarding/{$o->id}/tasks/{$task->id}", ['status' => 'completed'])
            ->assertOk();

        $this->assertStringContainsString('asset', implode(' ', $res->json('completion_blockers')));
        $this->assertSame(Offboarding::STATUS_IN_PROGRESS, $o->fresh()->status);
        $this->assertNull(User::withTrashed()->find($o->employee_id)->deleted_at);
    }

    public function test_unpaid_settlement_blocks_only_when_flag_enabled(): void
    {
        $o = $this->makeOffboarding(now()->subDay()->toDateString());
        $this->complete($o);
        FinalSettlement::forceCreate([
            'offboarding_id' => $o->id, 'employee_id' => $o->employee_id,
            'last_working_date' => $o->last_working_date, 'status' => 'draft',
            'prepared_by' => $this->hr->employee_id,
        ]);

        $this->setFlag('hr_final_settlement', false);
        $this->assertSame([], $o->fresh()->completionBlockers());

        $this->setFlag('hr_final_settlement', true);
        $this->assertCount(1, $o->fresh()->completionBlockers());
    }

    public function test_explicit_completed_status_with_blockers_returns_422(): void
    {
        $o = $this->makeOffboarding(now()->addDays(5)->toDateString());

        $this->actingAs($this->hr)->putJson("/hr/offboarding/{$o->id}", [
            'initiation_date' => now()->toDateString(),
            'last_working_date' => $o->last_working_date->toDateString(),
            'reason' => 'resignation',
            'status' => 'completed',
        ])->assertStatus(422)->assertJsonStructure(['completion_blockers']);

        $this->assertSame(Offboarding::STATUS_IN_PROGRESS, $o->fresh()->status);
        $this->assertNull(User::withTrashed()->find($o->employee_id)->deleted_at);
    }

    public function test_completion_after_lwd_without_blockers_revokes_access_and_soft_deletes(): void
    {
        $o = $this->makeOffboarding(now()->subDay()->toDateString());
        $employee = User::find($o->employee_id);
        $employee->createToken('mobile');
        $task = $o->tasks()->create(['task' => 'Handover', 'status' => 'pending']);

        $res = $this->actingAs($this->hr)
            ->patchJson("/hr/offboarding/{$o->id}/tasks/{$task->id}", ['status' => 'completed'])
            ->assertOk();

        $this->assertSame([], $res->json('completion_blockers'));
        $o->refresh();
        $this->assertSame(Offboarding::STATUS_COMPLETED, $o->status);
        $this->assertNotNull($o->lwd_processed_at);
        $this->assertSame(0, $employee->tokens()->count());
        $this->assertNotNull(User::withTrashed()->find($o->employee_id)->deleted_at);

        // Reverse path: reopening restores the employee.
        $this->actingAs($this->hr)->putJson("/hr/offboarding/{$o->id}", [
            'initiation_date' => now()->toDateString(),
            'last_working_date' => $o->last_working_date->toDateString(),
            'reason' => 'resignation',
            'status' => 'in_progress',
        ])->assertOk();
        $this->assertNull(User::withTrashed()->find($o->employee_id)->deleted_at);
    }

    public function test_task_sync_cannot_modify_another_offboardings_task(): void
    {
        $a = $this->makeOffboarding(now()->addDays(5)->toDateString());
        $b = $this->makeOffboarding(now()->addDays(5)->toDateString());
        $foreign = $b->tasks()->create(['task' => 'B task', 'status' => 'pending']);

        $this->actingAs($this->hr)->putJson("/hr/offboarding/{$a->id}", [
            'initiation_date' => now()->toDateString(),
            'last_working_date' => $a->last_working_date->toDateString(),
            'reason' => 'resignation',
            'status' => 'in_progress',
            'tasks' => [['id' => $foreign->id, 'task' => 'HACKED', 'status' => 'completed']],
        ])->assertStatus(422);

        $foreign->refresh();
        $this->assertSame('B task', $foreign->task);
        $this->assertSame('pending', $foreign->status);
        $this->assertSame($b->id, $foreign->offboarding_id);
    }

    public function test_sweep_command_processes_due_offboarding_only(): void
    {
        $due = $this->makeOffboarding(now()->subDays(2)->toDateString());
        $future = $this->makeOffboarding(now()->addDays(2)->toDateString());
        $cancelled = $this->makeOffboarding(now()->subDays(2)->toDateString(), ['status' => Offboarding::STATUS_CANCELLED]);
        $dueEmployee = User::find($due->employee_id);
        $dueEmployee->createToken('mobile');

        $this->artisan('offboarding:process-due')->assertSuccessful();

        $this->assertNotNull($due->fresh()->lwd_processed_at);
        $this->assertSame(0, $dueEmployee->tokens()->count());
        $this->assertNull($future->fresh()->lwd_processed_at);
        $this->assertNull($cancelled->fresh()->lwd_processed_at);
    }
}
