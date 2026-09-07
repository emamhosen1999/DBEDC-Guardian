<?php

namespace App\Services\Operations;

use App\Exceptions\StaleModelVersionException;
use Illuminate\Database\Eloquent\Model;

final class OmVersionGuard
{
    public static function assertMatches(Model $lockedModel, ?int $expectedVersion): void
    {
        // API v1 compatibility: already-installed mobile builds predate version
        // tokens. Updated web/mobile callers always send one; remove this rollout
        // fallback after the new minimum mobile version is enforced.
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
