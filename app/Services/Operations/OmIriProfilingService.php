<?php

namespace App\Services\Operations;

use App\Models\OmIriReading;
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
            // A reading without a measured IRI or a chainage carries no information: skip it rather than invent values.
            if (! isset($r['iri_value'], $r['chainage_km'])) {
                continue;
            }
            $iri = (float) $r['iri_value'];
            $band = 'smooth';
            if ($iri > 3.5) {
                $band = 'rough';
            } elseif ($iri >= 2.0) {
                $band = 'fair';
            }

            OmIriReading::create([
                'patrol_shift_id' => $patrolShiftId,
                'recorded_at' => $r['recorded_at'] ?? now(),
                'chainage_km' => $r['chainage_km'],
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
     * Roughness profile per 1-km chainage segment, aggregated from every recorded IRI reading for the carriageway.
     * Only segments that actually have readings are returned; an empty corridor yields available:false and an honest
     * reason, never illustrative segments or percentages (owner rule 2026-10-08).
     */
    public function getHeatmapProfile(string $direction = 'northbound'): array
    {
        $profile = [
            'available' => false,
            'reason' => 'No IRI readings have been recorded for this carriageway yet. The profile is built from patrol accelerometer telemetry.',
            'direction' => $direction,
            'average_iri' => null,
            'smooth_percentage' => null,
            'fair_percentage' => null,
            'rough_percentage' => null,
            'avg_network_iri' => null,
            'smooth_segments' => 0,
            'fair_segments' => 0,
            'rough_segments' => 0,
            'surveyed_segments' => 0,
            'segments' => [],
            'total_readings' => 0,
            'last_patrol_survey' => null,
        ];

        if (! Schema::hasTable('om_iri_readings')) {
            return $profile;
        }

        $buckets = [];
        $total = 0;
        OmIriReading::where('direction', $direction)
            ->select(['chainage_km', 'iri_value', 'speed_kmh', 'recorded_at'])
            ->orderBy('id')
            ->lazy(1000)
            ->each(function ($r) use (&$buckets, &$total) {
                $km = (int) floor((float) $r->chainage_km);
                $b = &$buckets[$km];
                $b['iri'] = ($b['iri'] ?? 0.0) + (float) $r->iri_value;
                $b['n'] = ($b['n'] ?? 0) + 1;
                if ($r->speed_kmh !== null) {
                    $b['speed'] = ($b['speed'] ?? 0.0) + (float) $r->speed_kmh;
                    $b['speed_n'] = ($b['speed_n'] ?? 0) + 1;
                }
                $at = $r->recorded_at?->toDateTimeString();
                if ($at !== null && $at > ($b['last'] ?? '')) {
                    $b['last'] = $at;
                }
                unset($b);
                $total++;
            });

        if ($total === 0) {
            return $profile;
        }

        ksort($buckets);
        $segments = [];
        foreach ($buckets as $km => $b) {
            $avg = round($b['iri'] / $b['n'], 2);
            $segments[] = [
                'chainage_km' => $km,
                'km_start' => $km,
                'km_end' => $km + 1,
                'chainage_label' => sprintf('KM %02d - %02d', $km, $km + 1),
                'label' => sprintf('KM %02d - %02d', $km, $km + 1),
                'iri_value' => $avg,
                'avg_iri' => $avg,
                // IRI classification bands used by the legend: smooth < 2.0, fair 2.0 to 3.5, rough > 3.5 m/km
                'condition' => $avg > 3.5 ? 'rough' : ($avg >= 2.0 ? 'fair' : 'smooth'),
                'band' => $avg > 3.5 ? 'rough' : ($avg >= 2.0 ? 'fair' : 'smooth'),
                'avg_speed_kmh' => isset($b['speed_n']) ? round($b['speed'] / $b['speed_n'], 1) : null,
                'survey_count' => $b['n'],
                'sample_count' => $b['n'],
                'last_surveyed_at' => $b['last'] ?? null,
            ];
        }

        $count = count($segments);
        $by = collect($segments)->countBy('condition');
        $avgNetwork = round(collect($segments)->avg('iri_value'), 2);

        return array_merge($profile, [
            'available' => true,
            'reason' => null,
            'average_iri' => $avgNetwork,
            'avg_network_iri' => $avgNetwork,
            'smooth_percentage' => round(($by['smooth'] ?? 0) / $count * 100, 1),
            'fair_percentage' => round(($by['fair'] ?? 0) / $count * 100, 1),
            'rough_percentage' => round(($by['rough'] ?? 0) / $count * 100, 1),
            'smooth_segments' => $by['smooth'] ?? 0,
            'fair_segments' => $by['fair'] ?? 0,
            'rough_segments' => $by['rough'] ?? 0,
            'surveyed_segments' => $count,
            'segments' => $segments,
            'total_readings' => $total,
            'last_patrol_survey' => collect($segments)->max('last_surveyed_at'),
        ]);
    }
}
