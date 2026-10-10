<?php

namespace App\Services\Map;

/** What one layer produced for one viewer and filter: placed features, how many could not be placed, and whether it was capped. */
final class LayerResult
{
    public const CAP = 600;

    /** @var array<int, array<string, mixed>> */
    public array $features = [];

    public int $total = 0;

    public int $unplaced = 0;

    /** @param array<string, mixed> $feature */
    public function add(array $feature): void
    {
        $this->total++;
        if (count($this->features) < self::CAP) {
            $this->features[] = $feature;
        }
    }

    public function skip(): void
    {
        $this->unplaced++;
    }

    public function truncated(): bool
    {
        return $this->total > count($this->features);
    }
}
