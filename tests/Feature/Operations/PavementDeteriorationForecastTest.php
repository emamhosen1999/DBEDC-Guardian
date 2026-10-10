<?php

namespace Tests\Feature\Operations;

use App\Services\Operations\OmPavementDeteriorationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Owner rule (2026-10-08): no illustrative figures. The forecast reports what is recorded until a real model exists. */
class PavementDeteriorationForecastTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_the_recorded_inputs_and_no_invented_forecast(): void
    {
        $forecast = app(OmPavementDeteriorationService::class)->getDeteriorationForecast();

        $this->assertFalse($forecast['available']);
        $this->assertSame(0, $forecast['condition_surveys']);
        $this->assertSame(0, $forecast['iri_readings']);
        $this->assertStringContainsString('None have been recorded yet', $forecast['reason']);
        foreach (['transition_matrix', 'projections', 'vulnerable_sections', 'lcca_summary', 'five_year_projection', 'critical_segments'] as $invented) {
            $this->assertArrayNotHasKey($invented, $forecast);
        }
    }
}
