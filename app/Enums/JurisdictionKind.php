<?php

namespace App\Enums;

/**
 * What a jurisdiction is. A backed enum, never free text.
 *  - section:  a chainage band; the primary kind, and the one daily works are attributed to;
 *  - facility: a chainage point or short range with a subtype (interchange, toll plaza, depot, ...);
 *  - area:     a polygon (an existing attendance geofence, or uploaded GeoJSON).
 * Overlap is forbidden within a kind (and carriageway) and allowed across kinds.
 */
enum JurisdictionKind: string
{
    case Section = 'section';
    case Facility = 'facility';
    case Area = 'area';

    public function label(): string
    {
        return match ($this) {
            self::Section => 'Section',
            self::Facility => 'Facility',
            self::Area => 'Area',
        };
    }

    /** Sections and facilities are placed by chainage; areas by polygon. */
    public function usesChainage(): bool
    {
        return $this !== self::Area;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $k): string => $k->value, self::cases());
    }
}
