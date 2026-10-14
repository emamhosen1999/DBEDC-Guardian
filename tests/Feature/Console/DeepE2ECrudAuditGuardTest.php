<?php

namespace Tests\Feature\Console;

use App\Console\Commands\DeepE2ECrudAudit;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * audit:e2e-crud writes real records and trusts a rollback to remove them; production's MyISAM tables ignore that
 * rollback, so the command must refuse there before it touches anything.
 */
class DeepE2ECrudAuditGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DeepE2ECrudAudit::prohibit(false);
        parent::tearDown();
    }

    public function test_a_prohibited_run_refuses_before_writing_anything(): void
    {
        User::factory()->create();
        DeepE2ECrudAudit::prohibit();

        $this->artisan('audit:e2e-crud')
            ->expectsOutputToContain('prohibited from running')
            ->assertExitCode(1);

        // The device registration sits outside the transaction, so it is the first write a guard would miss.
        $this->assertSame(0, UserDevice::count());
    }

    public function test_it_is_allowed_outside_production(): void
    {
        $this->assertFalse($this->app->isProduction());
        $this->assertFalse((new \ReflectionProperty(DeepE2ECrudAudit::class, 'prohibitedFromRunning'))->getValue());
    }
}
