<?php

namespace App\Services\Corridor;

use App\Support\Corridor\Alignment;

/**
 * A resolved corridor: the measured centreline, the structures on it and where they came from.
 *
 * @phpstan-type Feature array{code: string, kind: string, name: string, lat: float, lng: float, chainage_m: int, estimated: bool, note: ?string}
 */
final class CorridorGeometry
{
    /**
     * @param  array<int, array<string, mixed>>  $features
     * @param  array<string, mixed>  $source  key, label, captured_on, attribution, chainage_basis, version
     */
    public function __construct(
        public readonly Alignment $alignment,
        public readonly array $features,
        public readonly array $source,
    ) {}

    public function isUsable(): bool
    {
        return $this->alignment->isUsable();
    }
}
