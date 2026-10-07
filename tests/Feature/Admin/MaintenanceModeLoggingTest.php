<?php

namespace Tests\Feature\Admin;

use App\Models\ClientErrorLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The deploy window (php artisan down) answers API polls with 503. That is planned downtime, not a server
 * fault: it must not fill the log with error entries and stack traces.
 */
class MaintenanceModeLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->maintenanceMode()->deactivate();

        parent::tearDown();
    }

    public function test_api_polls_during_maintenance_are_not_logged_as_errors(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->level.': '.$e->message;
        });

        $this->app->maintenanceMode()->activate(['retry' => 60]);

        $this->getJson('/api/v1/auth/me')->assertStatus(503);

        $this->assertSame([], array_values(array_filter($logged, fn ($l) => preg_match('/^(error|warning|critical|alert|emergency):/', $l))));
        $this->assertSame(0, ClientErrorLog::count(), 'planned downtime is not captured as a server error');
    }
}
