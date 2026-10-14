<?php

namespace App\Services\Jurisdiction;

/**
 * Colour-blind-safe band colours: the Okabe-Ito qualitative palette the corridor map already uses for its layer groups
 * (map.css --cy-map-g-*). A jurisdiction names a token (or gets the next free one by position); the client maps the
 * token to its screen and print colours, and to a fill pattern so the map also reads in greyscale.
 */
final class JurisdictionPalette
{
    /** In assignment order. */
    public const TOKENS = ['blue', 'orange', 'green', 'vermillion', 'sky', 'purple', 'yellow'];

    /** @return array<int, string> */
    public static function tokens(): array
    {
        return self::TOKENS;
    }

    /** The token for the Nth jurisdiction (by chainage order) when none was chosen. */
    public static function forPosition(int $index): string
    {
        return self::TOKENS[$index % count(self::TOKENS)];
    }
}
