<?php

namespace Tests\Feature\Events;

use App\Events\Domain\AttendancePunched;
use App\Events\Domain\DailyWorkStatusChanged;
use App\Events\Domain\DeviceSessionRevoked;
use App\Events\Domain\DomainEvent;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use App\Services\Realtime\RealtimeSignal;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * The domain bus: shape, after-commit guarantee, and the realtime listener.
 *
 * These tests deliberately use the REAL event dispatcher (not Event::fake),
 * because the whole point of ShouldDispatchAfterCommit lives inside it.
 */
class DomainEventBusTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    /** @return array<int, AttendancePunched> */
    private function recordPunches(): array
    {
        $seen = [];
        Event::listen(AttendancePunched::class, function (AttendancePunched $e) use (&$seen) {
            $seen[] = $e;
        });

        return $seen;
    }

    public function test_punch_in_emits_attendance_punched_only_after_the_transaction_commits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 09:00:00'));
        $user = User::factory()->create();

        $seen = [];
        Event::listen(AttendancePunched::class, function (AttendancePunched $e) use (&$seen) {
            $seen[] = $e;
        });

        DB::transaction(function () use ($user, &$seen) {
            app(AttendancePunchService::class)
                ->processPunch($user, Request::create('/', 'POST', ['check_type' => 'in']));

            // Still inside an open transaction: nothing may have escaped yet.
            $this->assertSame([], $seen, 'Domain event leaked before commit.');
        });

        $this->assertCount(1, $seen);
        $this->assertSame('attendance.punched', $seen[0]->eventName());
        $this->assertSame(AttendancePunched::ACTION_IN, $seen[0]->action);
        $this->assertSame($user->id, $seen[0]->actorId());
        $this->assertNotNull($seen[0]->subjectId());
        $this->assertSame('2026-06-19', $seen[0]->realtimeBucket());
        $this->assertSame('attendance', $seen[0]->realtimeEntity());
    }

    public function test_rolled_back_punch_emits_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 09:00:00'));
        $user = User::factory()->create();

        $seen = [];
        Event::listen(AttendancePunched::class, function (AttendancePunched $e) use (&$seen) {
            $seen[] = $e;
        });

        try {
            DB::transaction(function () use ($user) {
                app(AttendancePunchService::class)
                    ->processPunch($user, Request::create('/', 'POST', ['check_type' => 'in']));

                throw new \RuntimeException('forced rollback');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame([], $seen, 'A rolled-back write must never announce a domain event.');
        $this->assertSame(0, \App\Models\HRM\Attendance::where('user_id', $user->id)->count());
    }

    public function test_domain_event_carries_the_common_shape(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 09:00:00'));

        $event = new DailyWorkStatusChanged(actorId: 7, dailyWorkId: 42, from: 'new', to: 'completed');

        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertSame([
            'event' => 'dailywork.status_changed',
            'occurred_at' => Carbon::parse('2026-06-19 09:00:00')->toIso8601String(),
            'actor_id' => 7,
            'subject_id' => 42,
            'payload' => ['from' => 'new', 'to' => 'completed'],
        ], $event->toArray());
    }

    public function test_listener_translates_a_domain_event_into_a_realtime_signal(): void
    {
        $calls = [];
        $signal = Mockery::mock(RealtimeSignal::class);
        $signal->shouldReceive('touch')->andReturnUsing(function (...$args) use (&$calls) {
            $calls[] = $args;
        });
        $this->app->instance(RealtimeSignal::class, $signal);

        event(new DailyWorkStatusChanged(actorId: 7, dailyWorkId: 42, from: 'new', to: 'completed'));

        // Exactly ONE marker, despite this app registering each $listen entry twice.
        $this->assertSame([['dailywork', 'all', 7, 'status']], $calls);
    }

    public function test_listener_skips_events_with_no_signal_family(): void
    {
        $calls = [];
        $signal = Mockery::mock(RealtimeSignal::class);
        $signal->shouldReceive('touch')->andReturnUsing(function (...$args) use (&$calls) {
            $calls[] = $args;
        });
        $this->app->instance(RealtimeSignal::class, $signal);

        event(new DeviceSessionRevoked(actorId: 1, userDeviceId: 5, ownerId: 9, accessTokensRevoked: 2));

        $this->assertSame([], $calls, 'device.session_revoked has no signal family and must write nothing.');
    }
}
