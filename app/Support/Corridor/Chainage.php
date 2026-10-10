<?php

namespace App\Support\Corridor;

/**
 * Highway chainage as integer metres, shown as K<km>+<mmm> (the notation engineers and the gazette use).
 *
 * The register tables spell it many ways ("K4+100", "Ch 24+500", "KM 00+000", "Km 37+120-37+400 LHS",
 * "K4+688.30-K6+691.3", bare metres). parse()/span() read only what is unambiguous: exactly one
 * km+m pair is a point, exactly two a span; anything else is reported as unplaceable by the caller
 * rather than guessed.
 */
final class Chainage
{
    /** Longest chainage accepted: the corridor is 48 km, with headroom for the nominal length. */
    public const MAX_M = 60000;

    private const PAIR = '/(?<![\d.])(\d{1,3})\s*\+\s*(\d{1,3}(?:\.\d+)?)(?![\d])/';

    public static function format(int $metres): string
    {
        $metres = max(0, $metres);

        return sprintf('K%d+%03d', intdiv($metres, 1000), $metres % 1000);
    }

    /** A single chainage point in metres, or null when the text is empty, a span, or not readable. */
    public static function parse(int|float|string|null $value): ?int
    {
        $span = self::span($value);

        return $span !== null && $span['from'] === $span['to'] ? $span['from'] : null;
    }

    /**
     * A point or a span as ['from' => m, 'to' => m] (equal for a point), or null when unreadable or ambiguous.
     * A bare integer is metres (the objection_chainages.chainage_meters / site_instructions convention).
     *
     * @return array{from: int, to: int}|null
     */
    public static function span(int|float|string|null $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1)) {
            $m = (int) $value;

            return $m >= 0 && $m <= self::MAX_M ? ['from' => $m, 'to' => $m] : null;
        }

        if (preg_match_all(self::PAIR, (string) $value, $found, PREG_SET_ORDER) < 1 || count($found) > 2) {
            return null;
        }

        $metres = [];
        foreach ($found as $match) {
            $m = (float) $match[2];
            if ($m >= 1000) {
                return null;
            }
            $total = (int) $match[1] * 1000 + (int) round($m);
            if ($total > self::MAX_M) {
                return null;
            }
            $metres[] = $total;
        }

        return ['from' => min($metres), 'to' => max($metres)];
    }

    /** Decimal kilometres (om_iri_readings.chainage_km) to metres. */
    public static function fromKm(int|float|string|null $km): ?int
    {
        if ($km === null || $km === '' || ! is_numeric($km)) {
            return null;
        }
        $m = (int) round((float) $km * 1000);

        return $m >= 0 && $m <= self::MAX_M ? $m : null;
    }
}
