<?php

namespace App\Enums;

/** Which carriageway a section or facility serves. NULL on a record means the same as Both. */
enum Carriageway: string
{
    case Both = 'both';
    case Northbound = 'northbound';
    case Southbound = 'southbound';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Two records contend for the same road when either serves both carriageways or they serve the same one. */
    public static function conflict(?self $a, ?self $b): bool
    {
        $a ??= self::Both;
        $b ??= self::Both;

        return $a === self::Both || $b === self::Both || $a === $b;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c): string => $c->value, self::cases());
    }
}
