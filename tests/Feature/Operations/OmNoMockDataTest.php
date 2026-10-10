<?php

namespace Tests\Feature\Operations;

use App\Models\OmEquipment;
use App\Models\OmIriReading;
use App\Models\OmWimFatigueLog;
use App\Services\Operations\OmAnalyticsService;
use App\Services\Operations\OmEnvironmentalService;
use App\Services\Operations\OmIriProfilingService;
use App\Services\Operations\OmRcmService;
use App\Services\Operations\OmSafetyService;
use App\Services\Operations\OmSlaService;
use App\Services\Operations\OmWimAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner rule (2026-10-08): no mock, demo, sample or illustrative figures. With nothing recorded every O&M service reports
 * an honest empty state (null / available:false, never a default 100% or an invented list); with records it computes
 * from them.
 */
class OmNoMockDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_wim_overview_is_an_honest_empty_state_without_logs(): void
    {
        $o = app(OmWimAnalysisService::class)->getWimFatigueOverview();

        $this->assertFalse($o['available']);
        $this->assertStringContainsString('No weigh-in-motion readings', $o['reason']);
        $this->assertSame(0, $o['total_heavy_vehicles']);
        $this->assertNull($o['overload_percentage']);
        $this->assertNull($o['total_esal_accumulated']);
        $this->assertNull($o['structural_damage_cost_bdt']);
        $this->assertSame([], $o['axle_class_breakdown']);
        $this->assertSame([], $o['lane_distribution']);
    }

    public function test_wim_overview_aggregates_only_recorded_logs(): void
    {
        $row = fn (string $lane, string $axle, float $overload, float $esal, float $cost) => OmWimFatigueLog::query()->create([
            'log_date' => '2026-10-01', 'toll_plaza' => 'P', 'lane_number' => $lane, 'axle_class' => $axle, 'gross_weight_tonnes' => 40,
            'statutory_weight_limit' => 38, 'overload_percentage' => $overload, 'fourth_power_damage_factor' => 2, 'esal_equivalent' => $esal,
            'estimated_damage_cost_bdt' => $cost,
        ]);
        $row('Lane 1', 'A', 10, 30, 100);
        $row('Lane 1', 'A', 0, 10, 0);
        $row('Lane 2', 'B', 0, 10, 0);

        $o = app(OmWimAnalysisService::class)->getWimFatigueOverview();

        $this->assertTrue($o['available']);
        $this->assertSame(3, $o['total_heavy_vehicles']);
        $this->assertSame(33.3, (float) $o['overload_percentage']);
        $this->assertSame(50.0, (float) $o['total_esal_accumulated']);
        $this->assertSame(100.0, (float) $o['structural_damage_cost_bdt']);
        $this->assertSame(['A', 'B'], array_column($o['axle_class_breakdown'], 'axle_class'));
        $this->assertSame(80.0, (float) $o['lane_distribution'][0]['esal_percentage']);
    }

    public function test_iri_profile_is_empty_without_readings_and_computed_with_them(): void
    {
        $service = app(OmIriProfilingService::class);
        $empty = $service->getHeatmapProfile('northbound');
        $this->assertFalse($empty['available']);
        $this->assertSame([], $empty['segments']);
        $this->assertNull($empty['average_iri']);
        $this->assertNull($empty['smooth_percentage']);
        $this->assertNotEmpty($empty['reason']);

        $read = fn (float $km, float $iri) => OmIriReading::query()->create([
            'recorded_at' => now(), 'chainage_km' => $km, 'direction' => 'northbound', 'iri_value' => $iri, 'condition_band' => 'smooth',
        ]);
        $read(3.2, 1.0);
        $read(3.8, 3.0);
        $read(7.5, 4.0);

        $p = $service->getHeatmapProfile('northbound');
        $this->assertTrue($p['available']);
        $this->assertCount(2, $p['segments'], 'only surveyed kilometres appear');
        $this->assertSame(2.0, (float) $p['segments'][0]['iri_value']);
        $this->assertSame('fair', $p['segments'][0]['condition']);
        $this->assertSame('rough', $p['segments'][1]['condition']);
        $this->assertSame(50.0, (float) $p['fair_percentage']);
        $this->assertSame(3.0, (float) $p['average_iri']);
        $this->assertFalse($service->getHeatmapProfile('southbound')['available']);
    }

    public function test_iri_ingest_skips_readings_without_measurements(): void
    {
        $count = app(OmIriProfilingService::class)->recordTelemetryBatch([['chainage_km' => 1.0], ['iri_value' => 2.2], ['chainage_km' => 2.0, 'iri_value' => 2.2]]);
        $this->assertSame(1, $count);
    }

    public function test_rcm_reports_the_register_and_no_reliability_figures(): void
    {
        $empty = app(OmRcmService::class)->getRcmDashboard();
        $this->assertFalse($empty['available']);
        $this->assertSame([], $empty['equipment']);
        $this->assertSame(0, $empty['stats']['total_monitored_assets']);
        $this->assertNull($empty['stats']['average_recorded_uptime_pct']);

        OmEquipment::query()->create(['equipment_code' => 'E1', 'name' => 'Cam 1', 'category' => 'cctv', 'location' => 'Plaza', 'status' => 'offline', 'uptime_pct' => 90, 'last_ping_at' => now()]);
        OmEquipment::query()->create(['equipment_code' => 'E2', 'name' => 'Cam 2', 'category' => 'cctv', 'location' => 'Plaza', 'status' => 'online']);

        $r = app(OmRcmService::class)->getRcmDashboard();
        $this->assertTrue($r['available']);
        $this->assertSame(2, $r['stats']['total_monitored_assets']);
        $this->assertSame(1, $r['stats']['reporting_assets']);
        $this->assertSame(90.0, (float) $r['stats']['average_recorded_uptime_pct'], 'the column default of a never-pinged item is not a measurement');
        $this->assertNull($r['stats']['average_mtbf_hours']);
        $this->assertNull($r['stats']['average_mttr_hours']);
        $this->assertNull($r['equipment'][1]['uptime_pct']);
        foreach (['subsystems', 'weibull', 'failure_modes', 'recommendations'] as $invented) {
            $this->assertArrayNotHasKey($invented, $r);
        }
    }

    public function test_rates_are_null_not_default_100_when_nothing_is_recorded(): void
    {
        $this->assertNull(app(OmSlaService::class)->getComplianceDashboard()['compliance_rate']);
        $this->assertNull(app(OmSafetyService::class)->getSafetyStats()['toolbox_compliance_pct']);

        $env = app(OmEnvironmentalService::class)->getEnvironmentalStats();
        $this->assertNull($env['compliance_rate']);
        $this->assertSame(0, $env['critical_violation_count']);
        $this->assertSame(0, $env['minor_exceedance_count']);

        // getKpis is private (the full analytics payload uses MySQL DATE_FORMAT, unavailable on the sqlite test database).
        $method = new \ReflectionMethod(OmAnalyticsService::class, 'getKpis');
        $kpis = $method->invoke(app(OmAnalyticsService::class), now()->subMonth());
        foreach (['mttr_hours', 'wo_completion_rate', 'defect_resolution_rate', 'avg_incident_response_min', 'sla_compliance_rate', 'inspection_pass_rate'] as $k) {
            $this->assertNull($kpis[$k], "{$k} must be null with no records");
        }
    }

    public function test_no_operations_source_contains_the_removed_mock_data(): void
    {
        $roots = ['app/Services/Operations', 'resources/js/Pages/Operations'];
        $literals = [
            'CCTV-PTZ-KNC', 'GENSET-250KVA', 'SE: Prodip', 'Habibur Rahman', 'Executive Briefing Dossier', 'Partly Cloudy',
            'Optical Fiber Backbone (48 km Ring)', 'Class 6 (3-Axle Heavy Truck)', 'Lane 1 (Outer Slow',
            '?? 14250', '?? 18.5', '?? 45600', '?? 2280000', '?? 99.42', '|| 88.5', '?? 2.1', '?? 75}', '|| 71',
        ];
        $offenders = [];
        foreach ($roots as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS)) as $file) {
                $name = $file->getPathname();
                if (! preg_match('/\.(php|jsx?)$/', $name)) {
                    continue;
                }
                // `// not-data: <reason>` marks a line holding a genuine constant (a definition or a published standard).
                $source = preg_replace('/^.*\bnot-data:\s*\S.*$/m', '', file_get_contents($name));
                $hit = preg_match('/Math\.random|faker|lorem ipsum|sample data|dummy data|mock data|getMock\w+\(/i', $source)
                    || (str_ends_with($name, '.jsx') && (
                        preg_match('/\?\?\s*[1-9]\d*(\.\d+)?\s*[%}`,)]/', $source)       // `?? 99.4` style invented fallbacks
                        || preg_match('/\|\|\s*\[\s*\{/', $source)                          // `|| [{ ... }]` literal sample arrays
                        || preg_match('/\b(?:value|title)\b.*\|\|\s*[1-9]\d*(\.\d+)?\b/', $source)));
                foreach ($literals as $literal) {
                    $hit = $hit || str_contains($source, $literal);
                }
                if ($hit) {
                    $offenders[] = str_replace(base_path().'/', '', $name);
                }
            }
        }

        $this->assertSame([], $offenders, 'Invented O&M data in: '.implode(', ', $offenders));
    }
}
