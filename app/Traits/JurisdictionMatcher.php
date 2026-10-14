<?php

namespace App\Traits;

use App\Models\Jurisdiction;
use App\Services\Corridor\ChainageResolver;
use App\Services\Jurisdiction\JurisdictionTeamService;
use Illuminate\Support\Facades\Log;

trait JurisdictionMatcher
{
    /** Drop the cached jurisdiction versions (kept for callers of the old name). */
    protected function clearJurisdictionCache(): void
    {
        app(ChainageResolver::class)->forget();
    }

    /** The in-charge of $jurisdiction on $date (the team rows), falling back to the mirrored current in-charge. */
    protected function inChargeFor(Jurisdiction $jurisdiction, ?string $date = null): ?string
    {
        return app(JurisdictionTeamService::class)->inChargeAsOf($jurisdiction->id, $date) ?? ($jurisdiction->incharge !== null ? (string) $jurisdiction->incharge : null);
    }

    /**
     * Find the jurisdiction for a location string AS OF a work date (null = today). Same start-or-end rule as ever.
     */
    protected function findJurisdictionForLocation(string $location, ?string $date = null): ?Jurisdiction
    {
        // Regex for extracting start and end chainages
        // Matches patterns like: K30+560-K30+570, K24+800, K13 TOLL STATION
        $chainageRegex = '/([A-Z]*K[0-9]+(?:\+[0-9]+(?:\.[0-9]+)?)?)\s*-\s*([A-Z]*K[0-9]+(?:\+[0-9]+(?:\.[0-9]+)?)?)|([A-Z]*K[0-9]+(?:\+[0-9]+(?:\.[0-9]+)?)?)/';

        if (! preg_match($chainageRegex, $location, $matches)) {
            Log::debug('JurisdictionMatcher: No chainage pattern found in location', ['location' => $location]);

            return null;
        }

        // Extract start and end chainages based on match groups
        $startChainage = ! empty($matches[1]) ? $matches[1] : ($matches[3] ?? null);
        $endChainage = ! empty($matches[2]) ? $matches[2] : null;

        if (! $startChainage) {
            return null;
        }

        // Numbers and band test live in the one resolver, as of the work's date; this trait only cuts the tokens out of the text.
        $jurisdiction = app(ChainageResolver::class)->forTokens($startChainage, $endChainage, $date);
        if ($jurisdiction === null) {
            Log::debug('JurisdictionMatcher: No jurisdiction found', ['location' => $location]);
        }

        return $jurisdiction;
    }

    /**
     * Format chainage string to float for comparison
     *
     * @example K05+900 becomes 5.900
     * @example K30+560 becomes 30.560
     */
    protected function formatChainageToFloat(string $chainage): float
    {
        // Remove spaces and convert to uppercase
        $chainage = strtoupper(trim($chainage));

        // Extract K number and additional values
        if (preg_match('/K(\d+)(?:\+(\d+(?:\.\d+)?))?/', $chainage, $matches)) {
            $kNumber = (int) $matches[1];
            $additional = isset($matches[2]) ? (float) $matches[2] : 0;

            // Convert to a comparable format (e.g., K05+900 becomes 5.900)
            return $kNumber + ($additional / 1000);
        }

        return 0;
    }

    /**
     * Format chainage for display/storage
     */
    protected function formatChainageForDisplay(string $chainage): string
    {
        $chainage = strtoupper(trim($chainage));

        if (preg_match('/K(\d+)(?:\+(\d+(?:\.\d+)?))?/', $chainage, $matches)) {
            $kNumber = (int) $matches[1];
            $additional = isset($matches[2]) ? (int) $matches[2] : 0;

            return sprintf('K%02d+%03d', $kNumber, $additional);
        }

        return $chainage;
    }
}
