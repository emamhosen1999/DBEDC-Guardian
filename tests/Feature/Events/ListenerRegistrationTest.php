<?php

namespace Tests\Feature\Events;

use App\Events\BiometricDeviceConnected;
use App\Events\Domain\DailyWorkStatusChanged;
use App\Events\Domain\DomainEvent;
use App\Listeners\Domain\EmitRealtimeSignal;
use App\Listeners\TriggerLogDownloadOnReconnect;
use App\Listeners\WriteRealtimeNotificationSignal;
use App\Services\Realtime\RealtimeSignal;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as FrameworkEventServiceProvider;
use Illuminate\Notifications\Events\NotificationSent;
use Mockery;
use Tests\TestCase;

/**
 * REGRESSION GUARD: every listener must be registered EXACTLY ONCE.
 *
 * The bug this locks down: bootstrap/app.php builds the app with Laravel 11's
 * Application::configure(), whose default chain always calls ->withEvents().
 * That registers the framework's own base EventServiceProvider with event
 * auto-discovery ENABLED, which scans app/Listeners and registers each listener
 * as "Listener@handle". This app ALSO loads App\Providers\EventServiceProvider
 * (via the legacy config/app.php `providers` array), which registers the very
 * same listeners as bare class strings through its $listen map.
 *
 * Result before the fix: two registrations per listener, so every listener ran
 * twice per dispatch. Because the two sources use DIFFERENT string forms
 * ("Listener" vs "Listener@handle"), the counting helper below normalises the
 * "@method" suffix — a naive comparison would not see the duplicate.
 */
class ListenerRegistrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Raw (unwrapped) listener entries the dispatcher holds for an event.
     *
     * @return array<int, mixed>
     */
    private function rawListenersFor(string $event): array
    {
        $dispatcher = $this->app->make('events');

        $property = (new \ReflectionObject($dispatcher))->getProperty('listeners');
        $property->setAccessible(true);

        return $property->getValue($dispatcher)[$event] ?? [];
    }

    /**
     * How many times $listenerClass is registered for $event, counting the
     * bare ("Listener") and discovered ("Listener@handle") forms alike.
     */
    private function countRegistrations(string $event, string $listenerClass): int
    {
        $count = 0;

        foreach ($this->rawListenersFor($event) as $entry) {
            if (is_string($entry) && strtok($entry, '@') === $listenerClass) {
                $count++;
            }
        }

        return $count;
    }

    public function test_explicitly_mapped_listener_is_registered_exactly_once(): void
    {
        $this->assertSame(
            1,
            $this->countRegistrations(BiometricDeviceConnected::class, TriggerLogDownloadOnReconnect::class),
            'TriggerLogDownloadOnReconnect must be registered exactly once for BiometricDeviceConnected.'
        );
    }

    public function test_notification_signal_listener_is_registered_exactly_once(): void
    {
        $this->assertSame(
            1,
            $this->countRegistrations(NotificationSent::class, WriteRealtimeNotificationSignal::class),
            'WriteRealtimeNotificationSignal must be registered exactly once for NotificationSent.'
        );
    }

    public function test_interface_registered_domain_listener_is_registered_exactly_once(): void
    {
        // The domain bus is wired against the DomainEvent INTERFACE, which the
        // dispatcher resolves for every concrete domain event.
        $this->assertSame(
            1,
            $this->countRegistrations(DomainEvent::class, EmitRealtimeSignal::class),
            'EmitRealtimeSignal must be registered exactly once for the DomainEvent interface.'
        );
    }

    public function test_email_verification_listener_is_registered_exactly_once(): void
    {
        // Both event providers inherit configureEmailVerification(); only the
        // framework's base provider may wire this pair.
        $this->assertSame(
            1,
            $this->countRegistrations(Registered::class, SendEmailVerificationNotification::class),
            'SendEmailVerificationNotification must be registered exactly once for Registered.'
        );
    }

    public function test_framework_event_auto_discovery_is_disabled(): void
    {
        // Root-cause guard: auto-discovery is the source that duplicated the
        // explicit $listen map. It must stay off for the base provider, which
        // is the only class discovery is ever active for.
        $providers = (new \ReflectionObject($this->app))->getProperty('serviceProviders');
        $providers->setAccessible(true);

        $base = null;

        foreach ($providers->getValue($this->app) as $provider) {
            if (get_class($provider) === FrameworkEventServiceProvider::class) {
                $base = $provider;
                break;
            }
        }

        $this->assertNotNull($base, "Laravel's base EventServiceProvider is expected to be registered by Application::configure()->withEvents().");
        $this->assertFalse(
            $base->shouldDiscoverEvents(),
            'Event auto-discovery must remain disabled, otherwise app/Listeners are registered a second time.'
        );
    }

    public function test_dispatching_a_domain_event_invokes_its_listener_exactly_once(): void
    {
        // End-to-end proof through the real dispatcher: one dispatch, one
        // realtime write. This asserts genuine single-firing — the listener no
        // longer carries a local dedupe guard to mask a double registration.
        $calls = [];

        $signal = Mockery::mock(RealtimeSignal::class);
        $signal->shouldReceive('touch')->andReturnUsing(function (...$args) use (&$calls) {
            $calls[] = $args;
        });
        $this->app->instance(RealtimeSignal::class, $signal);

        event(new DailyWorkStatusChanged(actorId: 7, dailyWorkId: 42, from: 'new', to: 'completed'));

        $this->assertSame([['dailywork', 'all', 7, 'status']], $calls);
    }
}
