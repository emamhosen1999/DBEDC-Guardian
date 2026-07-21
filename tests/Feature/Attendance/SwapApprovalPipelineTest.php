<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Department;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\NotificationType;
use App\Models\User;
use App\Notifications\Attendance\ShiftSwapDecidedNotification;
use App\Services\Realtime\RealtimeSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The single ShiftSwapService pipeline must fire the SAME side effects — notify
 * the requester (ShiftSwapDecided) + a roster/all realtime signal — regardless of
 * whether the approval arrives through the WEB controller or the MOBILE controller.
 * This is the web-vs-mobile coupling that previously drifted (web approvals that
 * fired no notification / no realtime update in the Leave domain).
 */
class SwapApprovalPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Super Administrator']);
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.own.view']);

        // The ShiftSwapDecided notification resolves its channels from a
        // NotificationType registry row; without an active row the channel
        // resolver returns [] and Notification::fake() records nothing.
        NotificationType::firstOrCreate(
            ['key' => 'attendance.shift_swap_decided'],
            [
                'category' => 'attendance',
                'label' => 'Shift swap decided',
                'default_channels' => ['database'],
                'locked_channels' => ['database'],
                'is_active' => true,
            ]
        );

        $this->dept = Department::factory()->create();
        $this->shift = Shift::factory()->create(['code' => 'DAY']);
    }

    private function employee(): User
    {
        $u = User::factory()->create(['department_id' => $this->dept->id]);
        $u->assignRole('Employee');
        $u->givePermissionTo('attendance.own.view');

        return $u;
    }

    private function admin(): User
    {
        $a = User::factory()->create(['department_id' => $this->dept->id]);
        $a->assignRole('Super Administrator'); // Gate::before bypass (web) + isAdminLikeUser (mobile)

        return $a;
    }

    /**
     * A cover swap parked in the manager stage (counterparty already accepted),
     * with the requester rostered and the counterparty free on the date — so the
     * approval's roster re-check passes and applySwap succeeds.
     */
    private function coverSwapAwaitingManager(User $requester, User $counterparty, string $date): ShiftSwapRequest
    {
        RosterDay::create(['user_id' => $requester->id, 'date' => $date, 'shift_id' => $this->shift->id, 'source' => 'pattern']);

        return ShiftSwapRequest::create([
            'type' => 'cover',
            'requester_id' => $requester->id,
            'requester_date' => $date,
            'counterparty_id' => $counterparty->id,
            'counterparty_date' => null,
            'requester_shift_code' => 'DAY',
            'counterparty_shift_code' => null,
            'status' => 'pending',
            'counterparty_status' => 'accepted',
            'approval_chain' => [],
        ]);
    }

    /** Install a recording RealtimeSignal so we can assert the roster/all touch fired. */
    private function spyOnSignals(): object
    {
        $spy = new class extends RealtimeSignal
        {
            /** @var array<int, array{entity:string,bucket:string,actorId:?int,action:string}> */
            public array $calls = [];

            public function touch(string $entity, string $bucket, ?int $actorId, string $action = 'update'): void
            {
                $this->calls[] = compact('entity', 'bucket', 'actorId', 'action');
            }
        };

        $this->app->instance(RealtimeSignal::class, $spy);

        return $spy;
    }

    public function test_web_swap_approval_notifies_requester_and_signals_roster(): void
    {
        Notification::fake();
        $spy = $this->spyOnSignals();

        $requester = $this->employee();
        $counterparty = $this->employee();
        $admin = $this->admin();
        $swap = $this->coverSwapAwaitingManager($requester, $counterparty, '2026-08-10');

        $this->actingAs($admin)
            ->postJson(route('attendance.swaps.approve', $swap->id))
            ->assertOk();

        $this->assertSame('approved', $swap->fresh()->status);

        Notification::assertSentTo(
            $requester,
            ShiftSwapDecidedNotification::class,
            fn (ShiftSwapDecidedNotification $n) => $n->swapId === $swap->id && $n->decision === 'approved'
        );

        $this->assertTrue(
            collect($spy->calls)->contains(
                fn ($c) => $c['entity'] === 'roster' && $c['bucket'] === 'all'
                    && $c['action'] === 'swap_approved' && $c['actorId'] === $admin->id
            ),
            'Expected a roster/all swap_approved signal from the web approval.'
        );
    }

    public function test_mobile_swap_approval_notifies_requester_and_signals_roster(): void
    {
        Notification::fake();
        $spy = $this->spyOnSignals();

        $requester = $this->employee();
        $counterparty = $this->employee();
        $admin = $this->admin();
        $swap = $this->coverSwapAwaitingManager($requester, $counterparty, '2026-08-11');

        Sanctum::actingAs($admin);
        $this->postJson(route('api.v1.attendance.swaps.approve', $swap->id))
            ->assertOk();

        $this->assertSame('approved', $swap->fresh()->status);

        Notification::assertSentTo(
            $requester,
            ShiftSwapDecidedNotification::class,
            fn (ShiftSwapDecidedNotification $n) => $n->swapId === $swap->id && $n->decision === 'approved'
        );

        $this->assertTrue(
            collect($spy->calls)->contains(
                fn ($c) => $c['entity'] === 'roster' && $c['bucket'] === 'all'
                    && $c['action'] === 'swap_approved' && $c['actorId'] === $admin->id
            ),
            'Expected a roster/all swap_approved signal from the mobile approval.'
        );
    }
}
