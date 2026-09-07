<?php

namespace App\Services\Concurrency;

use App\Exceptions\StaleModelVersionException;
use Illuminate\Database\Eloquent\Model;

final class VersionGuard
{
    public static function assertMatches(Model $lockedModel, ?int $expectedVersion): void
    {
        // API v1 rollout compatibility. Current web/mobile callers send a token;
        // remove this fallback after the minimum supported mobile version is
        // enforced in production.
        if ($expectedVersion === null) {
            return;
        }

        $currentVersion = (int) $lockedModel->getAttribute('lock_version');

        if ($currentVersion !== $expectedVersion) {
            throw new StaleModelVersionException($currentVersion);
        }
    }

    public static function next(Model $lockedModel): int
    {
        return (int) $lockedModel->getAttribute('lock_version') + 1;
    }
}
