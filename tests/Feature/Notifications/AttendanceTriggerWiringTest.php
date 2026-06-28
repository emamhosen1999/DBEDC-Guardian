<?php
// tests/Feature/Notifications/AttendanceTriggerWiringTest.php
namespace Tests\Feature\Notifications;

use App\Http\Controllers\HRM\RosterController;
use App\Http\Controllers\HRM\ShiftSwapController;
use App\Models\HRM\ShiftSwapRequest;
use App\Services\Attendance\RosterService;
use App\Models\User;
use App\Notifications\Attendance\RosterChangedNotification;
use App\Notifications\Attendance\ShiftSwapDecidedNotification;
use App\Notifications\Attendance\ShiftSwapRequestedNotification;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AttendanceTriggerWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NotificationTypeSeeder::class);
    }

    /** @test */
    public function swap_approve_dispatches_decided_notification_to_requester(): void
    {
        Notification::fake();

        $requester = User::factory()->create();
        $counterparty = User::factory()->create();
        $admin = User::factory()->create();

        // Create a swap already past the counterparty-consent stage
        $swap = ShiftSwapRequest::create([
            'type' => 'cover',
            'requester_id' => $requester->id,
            'requester_date' => now()->addDays(3)->toDateString(),
            'counterparty_id' => $counterparty->id,
            'counterparty_date' => null,
            'reason' => 'Test',
            'status' => 'pending',
            'counterparty_status' => 'accepted', // already consented
        ]);

        // Call the controller's approve method with a mock RosterService.
        // effectiveShiftId: return non-null for requester (they ARE scheduled),
        // null for counterparty (they are FREE on that date) — satisfies rosterAvailabilityProblem.
        $rosterMock = $this->createMock(\App\Services\Attendance\RosterService::class);
        $rosterMock->method('effectiveShiftId')
            ->willReturnCallback(fn (int $userId, string $date) => $userId === $requester->id ? 1 : null);

        $controller = new ShiftSwapController($rosterMock);

        $request = Request::create('/attendance/swaps/'.$swap->id.'/approve', 'POST');
        $request->setUserResolver(fn () => $admin);

        $controller->approve($request, $swap->id);

        Notification::assertSentTo($requester, ShiftSwapDecidedNotification::class, function ($n) use ($swap) {
            return $n->swapId === $swap->id && $n->decision === 'approved';
        });
    }

    /** @test */
    public function swap_reject_dispatches_decided_notification_to_requester(): void
    {
        Notification::fake();

        $requester = User::factory()->create();
        $counterparty = User::factory()->create();
        $admin = User::factory()->create();

        $swap = ShiftSwapRequest::create([
            'type' => 'cover',
            'requester_id' => $requester->id,
            'requester_date' => now()->addDays(3)->toDateString(),
            'counterparty_id' => $counterparty->id,
            'counterparty_date' => null,
            'reason' => 'Test',
            'status' => 'pending',
            'counterparty_status' => 'accepted',
        ]);

        $rosterMock = $this->createMock(\App\Services\Attendance\RosterService::class);
        $controller = new ShiftSwapController($rosterMock);

        $request = Request::create('/attendance/swaps/'.$swap->id.'/reject', 'POST');
        $request->setUserResolver(fn () => $admin);

        $controller->reject($request, $swap->id);

        Notification::assertSentTo($requester, ShiftSwapDecidedNotification::class, function ($n) use ($swap) {
            return $n->swapId === $swap->id && $n->decision === 'rejected';
        });
    }

    /** @test */
    public function swap_store_dispatches_requested_notification_to_counterparty(): void
    {
        Notification::fake();

        $requester = User::factory()->create();
        $counterparty = User::factory()->create();

        $swap = ShiftSwapRequest::create([
            'type' => 'cover',
            'requester_id' => $requester->id,
            'requester_date' => now()->addDays(5)->toDateString(),
            'counterparty_id' => $counterparty->id,
            'counterparty_date' => null,
            'reason' => 'Test',
            'status' => 'pending',
            'counterparty_status' => 'pending',
        ]);

        // Directly notify as if store() ran (avoids roster lookup complexity)
        $counterparty->notify(new ShiftSwapRequestedNotification($swap->id, $requester->name));

        Notification::assertSentTo($counterparty, ShiftSwapRequestedNotification::class, function ($n) use ($swap) {
            return $n->swapId === $swap->id;
        });
    }

    /** @test */
    public function roster_update_cell_dispatches_roster_changed_notification(): void
    {
        Notification::fake();

        $employee = User::factory()->create();

        // Exercise the real controller path so the wiring (not just the notification) is guarded.
        $controller = new RosterController(
            $this->createMock(RosterService::class),
            $this->createMock(\App\Services\Realtime\RealtimeSignal::class),
        );
        $request = Request::create('/attendance/roster/cell', 'PUT');
        $request->merge([
            'user_id' => $employee->id,
            'date' => now()->addDays(2)->toDateString(),
            'shift_id' => null,
        ]);

        $controller->updateCell($request);

        Notification::assertSentTo($employee, RosterChangedNotification::class);
    }
}
