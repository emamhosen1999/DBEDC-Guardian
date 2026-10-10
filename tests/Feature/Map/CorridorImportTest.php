<?php

namespace Tests\Feature\Map;

use App\Services\Corridor\CorridorGeometryResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** corridor:import - validation, versioning, idempotency, and that the resolver prefers the imported line. */
class CorridorImportTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../../fixtures/corridor/export.json';

    private function run_import(string $file = self::FIXTURE, array $options = []): int
    {
        return $this->artisan('corridor:import', ['file' => $file, '--source' => 'dhakabypass', '--date' => '2026-10-09'] + $options)->run();
    }

    public function test_imports_the_geometry_with_its_source_and_date(): void
    {
        $this->assertSame(0, $this->run_import());

        $row = DB::table('corridor_geometries')->first();
        $this->assertSame(1, (int) $row->version);
        $this->assertSame('dhakabypass', $row->source);
        $this->assertSame('2026-10-09', substr((string) $row->captured_on, 0, 10));
        $this->assertSame('Test export (fixture)', $row->attribution);
        $this->assertSame(12090, (int) $row->length_m);
        $this->assertSame(4, DB::table('corridor_geometry_points')->count());
        $this->assertSame(1, DB::table('corridor_features')->count());
        $this->assertSame(1, DB::table('corridor_geometries')->where('is_active', true)->count());
    }

    public function test_running_it_twice_is_a_no_op(): void
    {
        $this->run_import();
        $this->run_import();

        $this->assertSame(1, DB::table('corridor_geometries')->count());
        $this->assertSame(4, DB::table('corridor_geometry_points')->count());
        $this->assertSame(1, DB::table('corridor_features')->count());
    }

    public function test_a_changed_geometry_becomes_the_next_active_version(): void
    {
        $this->run_import();
        $changed = json_decode((string) file_get_contents(self::FIXTURE), true);
        $changed['corridor_geometry'][3]['lat'] = 23.9301;
        $path = tempnam(sys_get_temp_dir(), 'corridor').'.json';
        file_put_contents($path, json_encode($changed));

        $this->assertSame(0, $this->run_import($path));
        @unlink($path);

        $this->assertSame([1, 2], DB::table('corridor_geometries')->orderBy('version')->pluck('version')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(2, (int) DB::table('corridor_geometries')->where('is_active', true)->value('version'));
        $this->assertSame(1, DB::table('corridor_geometries')->where('is_active', true)->count());
    }

    public function test_refuses_illustrative_seed_data(): void
    {
        $seed = json_decode((string) file_get_contents(self::FIXTURE), true);
        $seed['corridor_geometry_source']['source'] = 'illustrative seed';
        $path = tempnam(sys_get_temp_dir(), 'corridor').'.json';
        file_put_contents($path, json_encode($seed));

        $this->assertSame(1, $this->run_import($path));
        @unlink($path);
        $this->assertSame(0, DB::table('corridor_geometries')->count());

        $this->assertSame(1, $this->artisan('corridor:import', ['file' => self::FIXTURE, '--source' => 'dhakabypass:illustrative', '--date' => '2026-10-09'])->run());
        $this->assertSame(0, DB::table('corridor_geometries')->count());
    }

    public function test_requires_source_and_date_and_a_readable_real_location(): void
    {
        $this->assertSame(1, $this->artisan('corridor:import', ['file' => self::FIXTURE])->run());
        $this->assertSame(1, $this->artisan('corridor:import', ['file' => self::FIXTURE, '--source' => 'x', '--date' => 'yesterday'])->run());

        $bad = json_decode((string) file_get_contents(self::FIXTURE), true);
        $bad['corridor_geometry'][1]['lat'] = 51.5; // not Bangladesh
        $path = tempnam(sys_get_temp_dir(), 'corridor').'.json';
        file_put_contents($path, json_encode($bad));
        $this->assertSame(1, $this->run_import($path));
        @unlink($path);
        $this->assertSame(0, DB::table('corridor_geometries')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->assertSame(0, $this->run_import(self::FIXTURE, ['--dry-run' => true]));
        $this->assertSame(0, DB::table('corridor_geometries')->count());
    }

    public function test_the_resolver_prefers_the_imported_centreline_and_falls_back_without_one(): void
    {
        $resolver = new CorridorGeometryResolver;
        $before = $resolver->resolve();
        $this->assertContains($before->source['key'], ['guardian_patrol_route', 'client_waypoints']);
        $this->assertSame(47611, $before->alignment->lengthM());

        $this->run_import();
        $after = (new CorridorGeometryResolver)->resolve();
        $this->assertSame('dhakabypass', $after->source['key']);
        $this->assertSame(12090, $after->alignment->lengthM());
        $this->assertSame('Vogra Toll Plaza', $after->features[0]['name']);
    }
}
