<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pavement deterioration forecast. A Markov / life-cycle forecast is only meaningful when it is fitted to the corridor's
 * own condition history (condition surveys and IRI readings over time). Until that history exists this service reports
 * what has been recorded and that no forecast can be made yet; it never returns illustrative curves, costs or sections
 * (owner rule 2026-10-08: no mock data).
 */
class OmPavementDeteriorationService
{
    public function getDeteriorationForecast(): array
    {
        $surveys = Schema::hasTable('om_asset_condition_surveys') ? DB::table('om_asset_condition_surveys')->count() : 0;
        $iriReadings = Schema::hasTable('om_iri_readings') ? DB::table('om_iri_readings')->count() : 0;

        return [
            'available' => false,
            'condition_surveys' => $surveys,
            'iri_readings' => $iriReadings,
            'reason' => 'A deterioration forecast is fitted to the corridor\'s own condition history: pavement condition surveys and IRI roughness readings recorded over time. '
                .($surveys + $iriReadings === 0
                    ? 'None have been recorded yet.'
                    : "{$surveys} condition survey(s) and {$iriReadings} IRI reading(s) are recorded so far; the forecast model has not been calibrated on them yet."),
        ];
    }
}
