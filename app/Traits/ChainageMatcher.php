<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

/**
 * Trait for parsing and matching chainage strings.
 *
 * Chainage Format Examples:
 * - Single point: K35+897, K5+100, K05+560
 * - With side (ignored): K35+897-RHS, K36+987-LHS
 * - Range: K35+560-K36+120, K05+560 - K05+660
 * - Multiple specific: K35+897, K36+987, K40+200
 * - Any prefix: SCK0+260, DZ2+440, CK0+189.220, ZK27+612
 * - Decimal meters: K14+036.00, CK0+189.220
 */
trait ChainageMatcher
{
    /**
     * Parse a chainage string to meters.
     * Returns null if parsing fails.
     *
     * Supports any prefix (K, SCK, DZ, CK, ZK, etc.) followed by km+meters format.
     *
     * Examples:
     * - K35+897 → 35897
     * - K5+100 → 5100
     * - K05+560 → 5560
     * - K35+897-RHS → 35897 (side ignored)
     * - SCK0+260 → 260
     * - DZ2+440 → 2440
     * - CK0+189.220 → 189 (decimal truncated)
     * - ZK27+612 → 27612
     * - K14+036.00 → 14036
     *
     * @param  string|null  $chainage  The chainage string to parse
     * @return int|null The chainage in meters, or null if invalid
     */
    public function parseChainageToMeters(?string $chainage): ?int
    {
        if (empty($chainage)) {
            return null;
        }

        // Clean the string
        $cleaned = strtoupper(trim($chainage));

        // Remove side indicators at end (RHS, LHS, R, L, CL, etc.)
        $cleaned = preg_replace('/[\-\s]*(RHS|LHS|R|L|LEFT|RIGHT|SR|TR|CL|CENTER|CENTRE)\s*$/i', '', $cleaned);

        // Remove any prefix: letters at the start (K, SCK, DZ, CK, ZK, KM, etc.)
        // This handles ANY alphabetic prefix before the numeric part
        $cleaned = preg_replace('/^[A-Z]+\s*/i', '', $cleaned);

        // Try to parse format like "35+897" or "35+897.50" (with optional decimal)
        if (preg_match('/^(\d+)[\+](\d+)(?:\.(\d+))?$/', $cleaned, $matches)) {
            $km = (int) $matches[1];
            $meters = (int) $matches[2];
            // Decimal part is ignored (truncated to integer meters)

            // Normalize meters based on digit count
            $meterDigits = strlen($matches[2]);
            if ($meterDigits === 1) {
                $meters *= 100; // Single digit like +5 means +500
            } elseif ($meterDigits === 2) {
                $meters *= 10; // Two digits like +50 means +500
            }
            // Three digits like +897 stays as-is

            return ($km * 1000) + min($meters, 999);
        }

        // Try format with dot separator: "35.897" (some systems use dot instead of +)
        if (preg_match('/^(\d+)\.(\d{3})$/', $cleaned, $matches)) {
            $km = (int) $matches[1];
            $meters = (int) $matches[2];

            return ($km * 1000) + min($meters, 999);
        }

        // Try to parse just kilometers "35"
        if (preg_match('/^(\d+)$/', $cleaned, $matches)) {
            return (int) $matches[1] * 1000;
        }

        Log::debug('ChainageMatcher: Failed to parse chainage', ['input' => $chainage, 'cleaned' => $cleaned]);

        return null;
    }

    /**
     * Parse a location string that may be a single point or a range.
     * Returns an array with 'start' and 'end' meters (end is null for single points).
     *
     * Supports any prefix (K, SCK, DZ, CK, ZK, etc.)
     *
     * Examples:
     * - K35+897 → ['start' => 35897, 'end' => null, 'is_range' => false]
     * - K35+560-K36+120 → ['start' => 35560, 'end' => 36120, 'is_range' => true]
     * - SCK0+260-SCK0+290 → ['start' => 260, 'end' => 290, 'is_range' => true]
     * - DZ2+440-DZ2+475 → ['start' => 2440, 'end' => 2475, 'is_range' => true]
     *
     * @param  string|null  $location  The location string
     * @return array{start: int|null, end: int|null, is_range: bool}
     */
    public function parseLocationToMeters(?string $location): array
    {
        $result = ['start' => null, 'end' => null, 'is_range' => false];

        if (empty($location)) {
            return $result;
        }

        $cleaned = strtoupper(trim($location));

        // Check if it's a range - pattern: PREFIX###+### - PREFIX###+###
        // Support any letter prefix (K, SCK, DZ, CK, ZK, etc.)
        // Separators: hyphen (-), en-dash (–), em-dash (—), tilde (~)
        // Pattern matches: K35+560-K36+120, SCK0+260-SCK0+290, DZ2+440-DZ2+475, K35+500~K36+500
        if (preg_match('/^([A-Z]*\d+[\+\.]\d+(?:\.\d+)?)\s*[\-–—~]\s*([A-Z]*\d+[\+\.]\d+(?:\.\d+)?)/i', $cleaned, $matches)) {
            $result['start'] = $this->parseChainageToMeters($matches[1]);
            $result['end'] = $this->parseChainageToMeters($matches[2]);
            $result['is_range'] = $result['start'] !== null && $result['end'] !== null;

            // Ensure start <= end
            if ($result['is_range'] && $result['start'] > $result['end']) {
                $temp = $result['start'];
                $result['start'] = $result['end'];
                $result['end'] = $temp;
            }

            return $result;
        }

        // Single point
        $result['start'] = $this->parseChainageToMeters($location);

        return $result;
    }

    /**
     * Parse multiple chainages from a comma-separated string.
     *
     * Example: "K35+897, K36+987, K40+200-RHS" → [35897, 36987, 40200]
     *
     * @param  string|null  $chainages  Comma-separated chainages
     * @return array<int> Array of chainage values in meters
     */
    public function parseMultipleChainages(?string $chainages): array
    {
        if (empty($chainages)) {
            return [];
        }

        $result = [];
        $parts = preg_split('/\s*,\s*/', trim($chainages));

        foreach ($parts as $part) {
            $meters = $this->parseChainageToMeters($part);
            if ($meters !== null) {
                $result[] = $meters;
            }
        }

        return array_unique($result);
    }

    /**
     * Check if a point is within a range (inclusive).
     *
     * @param  int  $point  The point in meters
     * @param  int  $rangeStart  Range start in meters
     * @param  int  $rangeEnd  Range end in meters
     */
    public function isPointInRange(int $point, int $rangeStart, int $rangeEnd): bool
    {
        // Ensure range is ordered correctly
        if ($rangeStart > $rangeEnd) {
            $temp = $rangeStart;
            $rangeStart = $rangeEnd;
            $rangeEnd = $temp;
        }

        return $point >= $rangeStart && $point <= $rangeEnd;
    }

    /**
     * Check if two ranges overlap.
     *
     * @param  int  $start1  First range start
     * @param  int  $end1  First range end
     * @param  int  $start2  Second range start
     * @param  int  $end2  Second range end
     */
    public function doRangesOverlap(int $start1, int $end1, int $start2, int $end2): bool
    {
        // Ensure ranges are ordered correctly
        if ($start1 > $end1) {
            $temp = $start1;
            $start1 = $end1;
            $end1 = $temp;
        }
        if ($start2 > $end2) {
            $temp = $start2;
            $start2 = $end2;
            $end2 = $temp;
        }

        // Two ranges overlap if one starts before the other ends
        return $start1 <= $end2 && $start2 <= $end1;
    }

    /**
     * Normalize chainage string to standard format (K###+###).
     *
     * @param  string|null  $chainage  Input chainage
     * @return string|null Normalized format like K05+560
     */
    public function normalizeChainageFormat(?string $chainage): ?string
    {
        $meters = $this->parseChainageToMeters($chainage);

        if ($meters === null) {
            return null;
        }

        $km = intdiv($meters, 1000);
        $m = $meters % 1000;

        return sprintf('K%02d+%03d', $km, $m);
    }

    /**
     * Extract chainage from a location string, removing side indicators and other text.
     * Supports any prefix (K, SCK, DZ, CK, ZK, etc.)
     *
     * @param  string|null  $location  Raw location string
     * @return string|null Clean chainage or null
     */
    public function extractChainage(?string $location): ?string
    {
        if (empty($location)) {
            return null;
        }

        // Match chainage pattern: optional letters followed by digits, + and more digits
        // Supports: K35+897, SCK0+260, DZ2+440, ZK27+612, etc.
        if (preg_match('/([A-Z]*\d+(?:\+\d+)?)/i', $location, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * Check if an objection's chainages match an RFI's location.
     *
     * This method handles all matching scenarios:
     * - Objection specific points vs RFI single point (exact match)
     * - Objection specific points vs RFI range (point in range)
     * - Objection range vs RFI single point (point in range)
     * - Objection range vs RFI range (ranges overlap)
     *
     * @param  array<int>  $objectionSpecificMeters  Array of specific chainage meters
     * @param  int|null  $objectionRangeStart  Objection range start (null if no range)
     * @param  int|null  $objectionRangeEnd  Objection range end (null if no range)
     * @param  string|null  $rfiLocation  RFI location string
     * @return bool True if there's a match
     */
    public function doesObjectionMatchRfi(
        array $objectionSpecificMeters,
        ?int $objectionRangeStart,
        ?int $objectionRangeEnd,
        ?string $rfiLocation
    ): bool {
        $rfi = $this->parseLocationToMeters($rfiLocation);

        if ($rfi['start'] === null) {
            return false; // RFI has no valid location
        }

        // Check specific chainages against RFI
        foreach ($objectionSpecificMeters as $specificMeters) {
            if ($rfi['is_range']) {
                // Objection specific point vs RFI range: point must be in range
                if ($this->isPointInRange($specificMeters, $rfi['start'], $rfi['end'])) {
                    return true;
                }
            } else {
                // Objection specific point vs RFI single point: exact match
                if ($specificMeters === $rfi['start']) {
                    return true;
                }
            }
        }

        // Check objection range against RFI
        if ($objectionRangeStart !== null && $objectionRangeEnd !== null) {
            if ($rfi['is_range']) {
                // Objection range vs RFI range: check overlap
                if ($this->doRangesOverlap($objectionRangeStart, $objectionRangeEnd, $rfi['start'], $rfi['end'])) {
                    return true;
                }
            } else {
                // Objection range vs RFI single point: point must be in objection range
                if ($this->isPointInRange($rfi['start'], $objectionRangeStart, $objectionRangeEnd)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if an RFI's location matches an objection's chainages.
     * This is the reverse of doesObjectionMatchRfi for searching RFIs from objection context.
     *
     * @param  string|null  $rfiLocation  RFI location string
     * @param  array<int>  $objectionSpecificMeters  Array of specific chainage meters
     * @param  int|null  $objectionRangeStart  Objection range start
     * @param  int|null  $objectionRangeEnd  Objection range end
     */
    public function doesRfiMatchObjection(
        ?string $rfiLocation,
        array $objectionSpecificMeters,
        ?int $objectionRangeStart,
        ?int $objectionRangeEnd
    ): bool {
        // The logic is symmetric, so we can reuse the same method
        return $this->doesObjectionMatchRfi(
            $objectionSpecificMeters,
            $objectionRangeStart,
            $objectionRangeEnd,
            $rfiLocation
        );
    }
}
