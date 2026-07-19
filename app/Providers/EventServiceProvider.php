<?php

namespace App\Providers;

use App\Events\BiometricDeviceConnected;
use App\Listeners\TriggerLogDownloadOnReconnect;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // NOTE: Registered => SendEmailVerificationNotification is deliberately
        // NOT listed here. Laravel 11's base EventServiceProvider wires that
        // pair itself in configureEmailVerification() (see the override below),
        // so declaring it here as well registered it twice and sent two
        // verification e-mails per registration. Email verification is still
        // fully wired — just once, by the framework.
        BiometricDeviceConnected::class => [
            TriggerLogDownloadOnReconnect::class,
        ],
        \Illuminate\Notifications\Events\NotificationSent::class => [
            \App\Listeners\WriteRealtimeNotificationSignal::class,
        ],

        // DOMAIN BUS. Registered against the App\Events\Domain\DomainEvent
        // INTERFACE, not a concrete class: Laravel's dispatcher resolves
        // listeners bound to an event's interfaces, so this one entry wires
        // every current and future domain event to the realtime signal writer.
        // Add new domain events by implementing the interface — no change here.
        \App\Events\Domain\DomainEvent::class => [
            \App\Listeners\Domain\EmitRealtimeSignal::class,
        ],
    ];

    /**
     * The subscriber classes to register.
     *
     * @var array<int, class-string>
     */
    protected $subscribe = [
        // Event subscribers will be registered here
    ];

    /**
     * Register the application's event listeners.
     *
     * ROOT-CAUSE FIX (double-firing listeners).
     *
     * bootstrap/app.php builds the app with Laravel 11's
     * Application::configure(), whose default chain unconditionally calls
     * ->withEvents(). That registers the framework's OWN base
     * Illuminate\Foundation\Support\Providers\EventServiceProvider with
     * auto-discovery ENABLED (its static $shouldDiscoverEvents defaults to
     * true, and discovery is active only for that base class). The base
     * provider scans app/Listeners and registers every listener as
     * "Listener@handle" — the SAME listeners THIS provider already registers
     * (as the bare class string) through its $listen map below.
     *
     * This app additionally keeps a legacy config/app.php `providers` array
     * (Laravel 10 style) that loads THIS subclass, so two event providers run
     * at once: our explicit map AND the framework's discovery. Every mapped
     * listener was therefore registered — and fired — TWICE.
     *
     * Overriding shouldDiscoverEvents() only silences discovery for THIS
     * subclass (which never discovers anyway — discovery is gated to the base
     * class), so it never stopped the duplicate. disableEventDiscovery()
     * flips the SHARED static flag the base provider actually reads, switching
     * its discovery off globally and leaving the explicit $listen map as the
     * single source of truth. Each listener now registers exactly once.
     */
    public function register(): void
    {
        self::disableEventDiscovery();

        parent::register();
    }

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * Kept false to document intent; the effective switch is
     * disableEventDiscovery() in register() (see the note there).
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }

    /**
     * Wire the email-verification listener.
     *
     * Intentionally a no-op HERE. Both event providers active in this app (this
     * one, and the framework base provider auto-registered by
     * Application::configure()->withEvents()) inherit this method, and each
     * would register Registered => SendEmailVerificationNotification, giving a
     * duplicate listener and two verification e-mails per registration.
     *
     * The base provider still runs its own copy, so the pair remains wired —
     * exactly once. Suppressing it on this subclass is the only side we control.
     */
    protected function configureEmailVerification(): void
    {
        //
    }
}
