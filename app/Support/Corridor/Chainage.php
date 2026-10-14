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

    /**
     * A register location as a span: a strict point or span first (span()), else the kilometre-only form the site
     * registers use for yards and plazas ("K27", "K30 YARD", "K13 TOLL STATION", "K-40"), read as the kilometre mark
     * itself (K27 = K27+000). A free-text location with no single kilometre token, or with a number glued to it
     * ("K17-396", "K03-500-K03-520"), is NOT guessed: null.
     *
     * @return array{from: int, to: int}|null
     */
    public static function locationSpan(int|float|string|null $value): ?array
    {
        $span = self::span($value);
        if ($span !== null || ! is_string($value)) {
            return $span;
        }
        if (preg_match_all('/(?<![A-Za-z])(?:SC|DZ|C|Z)?K(?:M)?\s*-?\s*(\d{1,2})(?!\s*[+\-.]?\s*\d)/i', $value, $found) !== 1) {
            return null;
        }
        $m = (int) $found[1][0] * 1000;

        return $m <= self::MAX_M ? ['from' => $m, 'to' => $m] : null;
    }

    /**
     * One chainage token as the validators spell it ("K05+900", "SCK0+260", "CK0+189.220", "K30") to metres.
     * Metres are read as metres (K5+90 is K5+090, never K5+900). Null when there is no K<km> in the text.
     */
    public static function token(?string $text): ?int
    {
        if ($text === null || preg_match('/[A-Z]*K\s*(\d+)(?:\s*\+\s*(\d+(?:\.\d+)?))?/i', $text, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 1000 + (int) floor((float) ($m[2] ?? 0));
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
