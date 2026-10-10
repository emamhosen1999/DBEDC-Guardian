<?php

namespace App\Console\Commands;

use App\Support\Corridor\Alignment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports the real corridor centreline exported from the dhakabypass production database.
 *
 * Export file (JSON), keyed by dhakabypass's own table names:
 *   {
 *     "corridor_geometry":        [{"seq": 0, "lat": 23.98, "lng": 90.36, "chainage_m": 0}, ...],
 *     "corridor_geometry_source": {"source": "osm", "attribution": "...", "imported_at": "2026-09-05 06:16:05"},
 *     "interchanges":             [{"code": "TP-01", "kind": "toll_plaza", "name": "...", "lat": .., "lng": .., "chainage_m": 3218}]   (optional)
 *   }
 *
 * Idempotent: a file whose geometry is identical (sha256 of the ordered points) to an already imported
 * version is a no-op beyond making that version the active one; a changed geometry becomes the next version.
 * Refuses anything flagged illustrative (the dhakabypass seed is not real data).
 */
class CorridorImport extends Command
{
    protected $signature = 'corridor:import {file : Path to the JSON export} {--source= : Source key, e.g. dhakabypass} {--date= : Capture date (Y-m-d) of the export} {--attribution= : Attribution line} {--dry-run : Validate and report only}';

    protected $description = 'Import the corridor centreline, with chainage, from a dhakabypass export file (versioned, idempotent).';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $source = trim((string) $this->option('source'));
        $date = (string) $this->option('date');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("File not found or unreadable: {$path}");

            return self::FAILURE;
        }
        if ($source === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || strtotime($date) === false) {
            $this->error('--source and --date (Y-m-d) are required: every layer records where its data came from and when.');

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload) || ! is_array($payload['corridor_geometry'] ?? null)) {
            $this->error('The file must be JSON with a "corridor_geometry" array.');

            return self::FAILURE;
        }
        if (preg_match('/illustrative|seed/i', $source.' '.json_encode($payload['corridor_geometry_source'] ?? [])) === 1
            || ($payload['corridor']['illustrative'] ?? $payload['illustrative'] ?? false)) {
            $this->error('Refusing to import data flagged illustrative/seed: only real, surveyed or imported geometry is allowed.');

            return self::FAILURE;
        }

        $points = [];
        foreach ($payload['corridor_geometry'] as $i => $row) {
            foreach (['lat', 'lng', 'chainage_m'] as $key) {
                if (! is_numeric($row[$key] ?? null)) {
                    $this->error("Point #{$i} has no numeric {$key}.");

                    return self::FAILURE;
                }
            }
            $lat = (float) $row['lat'];
            $lng = (float) $row['lng'];
            if ($lat < 20.5 || $lat > 26.7 || $lng < 88.0 || $lng > 92.8) {
                $this->error("Point #{$i} ({$lat}, {$lng}) is outside Bangladesh.");

                return self::FAILURE;
            }
            $points[] = ['lat' => round($lat, 7), 'lng' => round($lng, 7), 'chainage_m' => max(0, (int) round((float) $row['chainage_m']))];
        }
        usort($points, fn (array $a, array $b): int => $a['chainage_m'] <=> $b['chainage_m']);
        if (count($points) < 2) {
            $this->error('At least two geometry points are required.');

            return self::FAILURE;
        }

        $features = [];
        foreach ((array) ($payload['interchanges'] ?? $payload['features'] ?? []) as $i => $f) {
            if (! is_numeric($f['lat'] ?? null) || ! is_numeric($f['lng'] ?? null) || ! is_numeric($f['chainage_m'] ?? null) || trim((string) ($f['name'] ?? '')) === '') {
                $this->error("Feature #{$i} needs name, lat, lng and chainage_m.");

                return self::FAILURE;
            }
            $features[] = [
                'code' => (string) ($f['code'] ?? 'F'.($i + 1)),
                'kind' => in_array($f['kind'] ?? '', ['interchange', 'toll_plaza', 'bridge', 'waypoint'], true) ? $f['kind'] : 'interchange',
                'name' => mb_substr((string) $f['name'], 0, 160),
                'lat' => round((float) $f['lat'], 7), 'lng' => round((float) $f['lng'], 7),
                'chainage_m' => (int) round((float) $f['chainage_m']),
                'estimated' => (bool) ($f['estimated'] ?? false),
                'note' => isset($f['note']) ? mb_substr((string) $f['note'], 0, 255) : null,
            ];
        }

        $checksum = hash('sha256', json_encode(array_map(fn (array $p): array => [$p['lat'], $p['lng'], $p['chainage_m']], $points)));
        $length = (new Alignment($points))->lengthM();
        $attribution = (string) ($this->option('attribution') ?: ($payload['corridor_geometry_source']['attribution'] ?? '')) ?: 'Imported from the dhakabypass corridor_geometry table';

        $this->info(sprintf('%d points, %d features, %.1f km, source "%s", dated %s.', count($points), count($features), $length / 1000, $source, $date));
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $outcome = DB::transaction(function () use ($points, $features, $checksum, $length, $source, $date, $attribution): string {
            $existing = DB::table('corridor_geometries')->where('checksum', $checksum)->first();
            if ($existing !== null) {
                DB::table('corridor_geometries')->where('id', '!=', $existing->id)->update(['is_active' => false]);
                DB::table('corridor_geometries')->where('id', $existing->id)->update(['is_active' => true, 'updated_at' => now()]);
                $this->syncFeatures((int) $existing->id, $features);

                return "unchanged: version {$existing->version} re-activated";
            }

            $version = (int) DB::table('corridor_geometries')->max('version') + 1;
            DB::table('corridor_geometries')->update(['is_active' => false]);
            $id = DB::table('corridor_geometries')->insertGetId([
                'version' => $version, 'source' => mb_substr($source, 0, 64), 'attribution' => mb_substr($attribution, 0, 255),
                'captured_on' => $date, 'chainage_basis' => 'surveyed', 'point_count' => count($points), 'length_m' => $length,
                'checksum' => $checksum, 'is_active' => true, 'imported_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_chunk(array_map(fn (array $p, int $seq): array => $p + ['geometry_id' => $id, 'seq' => $seq], $points, array_keys($points)), 500) as $chunk) {
                DB::table('corridor_geometry_points')->insert($chunk);
            }
            $this->syncFeatures($id, $features);

            return "imported as version {$version}";
        });

        $this->info($outcome.'.');

        return self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $features */
    private function syncFeatures(int $geometryId, array $features): void
    {
        if ($features === []) {
            return;
        }
        DB::table('corridor_features')->where('geometry_id', $geometryId)->delete();
        DB::table('corridor_features')->insert(array_map(fn (array $f): array => $f + ['geometry_id' => $geometryId], $features));
    }
}
