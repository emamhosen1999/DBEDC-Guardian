<?php

namespace App\Enums;

/** What kind of facility a `facility` jurisdiction is. */
enum FacilitySubtype: string
{
    case Interchange = 'interchange';
    case TollPlaza = 'toll_plaza';
    case Bridge = 'bridge';
    case Depot = 'depot';
    case Office = 'office';
    case MonitoringCentre = 'monitoring_centre';

    public function label(): string
    {
        return match ($this) {
            self::Interchange => 'Interchange',
            self::TollPlaza => 'Toll plaza',
            self::Bridge => 'Bridge or structure',
            self::Depot => 'Depot',
            self::Office => 'Office',
            self::MonitoringCentre => 'Monitoring centre',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $s): string => $s->value, self::cases());
    }
}
