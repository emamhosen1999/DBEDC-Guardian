<?php

namespace Tests\Feature\Map;

use App\Services\Corridor\CorridorGeometryResolver;
use App\Services\Map\MapLayerRegistry;
use Tests\TestCase;

/**
 * Guard: the corridor map shows real data only. Every static data file carries its source, date and attribution,
 * nothing is flagged illustrative, and no layer or alignment describes itself as mock, demo or placeholder.
 */
class NoIllustrativeDataTest extends TestCase
{
    private const BANNED = '/illustrative|mock|demo|placeholder|lorem|sample data|fake/i';

    public function test_client_survey_data_is_real_dated_and_attributed(): void
    {
        $d = json_decode((string) file_get_contents(base_path(CorridorGeometryResolver::CLIENT_FILE)), true);
        $this->assertFalse($d['illustrative']);
        $this->assertSame('2026-09-02', $d['date']);
        $this->assertNotEmpty($d['source']);
        $this->assertNotEmpty($d['attribution']);
        $this->assertCount(8, $d['waypoints']);
        $this->assertDoesNotMatchRegularExpression(self::BANNED, json_encode($d['waypoints']).$d['source'].$d['attribution']);
        foreach ($d['features'] as $f) {
            $this->assertArrayHasKey('estimated', $f, 'a position that is only estimated must say so');
        }
    }

    public function test_district_boundaries_are_licensed_attributed_and_the_three_districts(): void
    {
        $d = json_decode((string) file_get_contents(base_path('resources/js/Components/Cyber/Map/data/districts.json')), true);
        $this->assertSame(['Gazipur', 'Dhaka', 'Narayanganj'], array_column($d['districts'], 'name'));
        $this->assertSame('CC BY 3.0 IGO', $d['license']);
        $this->assertStringContainsString('geoBoundaries', $d['attribution']);
        $this->assertNotEmpty($d['release']);
        $this->assertLessThan(200_000, filesize(base_path('resources/js/Components/Cyber/Map/data/districts.json')), 'committed simplified');
    }

    public function test_road_context_is_osm_with_attribution_and_not_flagged_illustrative(): void
    {
        $raw = (string) file_get_contents(base_path('resources/js/Components/Cyber/Map/data/map-context.json'));
        $d = json_decode($raw, true);
        $this->assertSame('OpenStreetMap', $d['source']);
        $this->assertStringContainsString('OpenStreetMap contributors', $d['attribution']);
        $this->assertNotEmpty($d['date']);
        $this->assertDoesNotMatchRegularExpression('/illustrative/i', $raw);
    }

    public function test_no_layer_or_alignment_describes_itself_as_mock_data(): void
    {
        foreach (app(MapLayerRegistry::class)->all() as $layer) {
            $this->assertDoesNotMatchRegularExpression(self::BANNED, $layer->label().' '.$layer->source().' '.implode(' ', $layer->fields()), $layer->key());
        }
        $source = app(CorridorGeometryResolver::class)->resolve()->source;
        $this->assertDoesNotMatchRegularExpression(self::BANNED, json_encode($source));
        $this->assertNotEmpty($source['attribution']);
    }

    public function test_the_dhakabypass_seed_is_never_referenced(): void
    {
        foreach (['app/Services/Map', 'app/Services/Corridor', 'app/Support/Corridor', 'app/Console/Commands/CorridorImport.php', 'resources/js/Components/Cyber/Map', 'resources/js/Components/Dashboard/Widgets/CorridorMapCard.jsx'] as $path) {
            $files = is_dir(base_path($path))
                ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($path), \FilesystemIterator::SKIP_DOTS))
                : [new \SplFileInfo(base_path($path))];
            foreach ($files as $file) {
                if (! $file->isFile() || str_ends_with($file->getFilename(), '.json') || str_contains($file->getPathname(), '__tests__')) {
                    continue;
                }
                $this->assertStringNotContainsString('seed-corridor', (string) file_get_contents($file->getPathname()), $file->getPathname());
            }
        }
    }
}
