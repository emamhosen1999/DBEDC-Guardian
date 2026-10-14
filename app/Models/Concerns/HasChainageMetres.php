<?php

namespace App\Models\Concerns;

use App\Services\Corridor\ChainageResolver;
use App\Support\Corridor\Chainage;

/**
 * Keeps start_m / end_m (the numeric chainage of `location`) in step with `location` on every save - create, update
 * and the Excel import all go through the model. An unreadable location stores NULL, never a guess.
 */
trait HasChainageMetres
{
    public static function bootHasChainageMetres(): void
    {
        static::saving(function ($model): void {
            if (! $model->exists || $model->isDirty('location') || $model->getAttribute('start_m') === null) {
                $span = Chainage::locationSpan($model->getAttribute('location'));
                $model->setAttribute('start_m', $span['from'] ?? null);
                $model->setAttribute('end_m', $span['to'] ?? null);
            }
            // The jurisdiction ON THE WORK'S DATE (versions as of that date): later boundary changes never rewrite it.
            if (! $model->exists || $model->isDirty(['location', 'date']) || $model->getAttribute('jurisdiction_id') === null) {
                $date = $model->getAttribute('date');
                $day = $date === null ? null : substr($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date, 0, 10);
                $model->setAttribute('jurisdiction_id', app(ChainageResolver::class)->idForSpan($model->getAttribute('start_m'), $model->getAttribute('end_m'), $day));
            }
        });
    }
}
