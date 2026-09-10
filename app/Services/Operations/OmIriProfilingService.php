<?php

namespace App\Services\Operations;

use App\Models\OmIriReading;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class OmIriProfilingService
{
    /**
     * Ingest batch of IRI telemetry readings from patrol smartphone accelerometer
     */
    public function recordTelemetryBatch(array $readings, ?int $patrolShiftId = null): int
    {
        if (! Schema::hasTable('om_iri_readings')) {
            return 0;
        }

        $count = 0;
        foreach ($readings as $r) {
            $iri = (float) ($r['iri_value'] ?? 2.0);
            $band = 'smooth';
            if ($iri > 3.5) {
                $band = 'rough';
            } elseif ($iri >= 2.0) {
                $band = 'fair';
            }

            OmIriReading::create([
                'patrol_shift_id' => $patrolShiftId,
                'recorded_at' => $r['recorded_at'] ?? now(),
                'chainage_km' => $r['chainage_km'] ?? 0.0,
                'direction' => $r['direction'] ?? 'northbound',
                'iri_value' => $iri,
                'speed_kmh' => $r['speed_kmh'] ?? null,
                'latitude' => $r['latitude'] ?? null,
                'longitude' => $r['longitude'] ?? null,
                'condition_band' => $band,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Get continuous 48-km linear roughness heatmap profile
     */
    public function getHeatmapProfile(string $direction = 'northbound'): array
    {
        if (! Schema::hasTable('om_iri_readings')) {
            return $this->getMockProfile($direction);
        }

        $latestReadings = OmIriReading::where('direction', $direction)
            ->latest('recorded_at')
            ->take(500)
            ->get();

        if ($latestReadings->isEmpty()) {
            return [
                'direction' => $direction,
                'avg_network_iri' => null,
                'smooth_segments' => 0,
                'fair_segments' => 0,
                'rough_segments' => 0,
                'segments' => [],
                'total_readings' => 0,
                'last_patrol_survey' => null,
            ];
        }

        // Group by 1-km segments (KM 00 to KM 48)
        $segments = [];
        for ($km = 0; $km <= 47; $km++) {
            $segReadings = $latestReadings->filter(function ($r) use ($km) {
                return (float) $r->chainage_km >= $km && (float) $r->chainage_km < ($km + 1);
            });

            if ($segReadings->isNotEmpty()) {
                $avgIri = round($segReadings->avg('iri_value'), 2);
                $band = $avgIri > 3.5 ? 'rough' : ($avgIri >= 2.0 ? 'fair' : 'smooth');
            } else {
                $avgIri = null;
                $band = 'unknown';
            }

            $segments[] = [
                'km_start' => $km,
                'km_end' => $km + 1,
                'label' => sprintf('KM %02d - %02d', $km, $km + 1),
                'avg_iri' => $avgIri,
                'band' => $band,
                'sample_count' => $segReadings->count(),
            ];
        }

        $validSegments = collect($segments)->whereNotNull('avg_iri');
        $avgNetworkIri = $validSegments->isNotEmpty() ? round($validSegments->avg('avg_iri'), 2) : null;
        $roughSegments = collect($segments)->where('band', 'rough')->count();
        $fairSegments = collect($segments)->where('band', 'fair')->count();
        $smoothSegments = collect($segments)->where('band', 'smooth')->count();

        return [
            'direction' => $direction,
            'avg_network_iri' => $avgNetworkIri,
            'smooth_segments' => $smoothSegments,
            'fair_segments' => $fairSegments,
            'rough_segments' => $roughSegments,
            'segments' => $segments,
            'last_patrol_survey' => $latestReadings->first()?->recorded_at?->toDateTimeString(),
        ];
    }
}
