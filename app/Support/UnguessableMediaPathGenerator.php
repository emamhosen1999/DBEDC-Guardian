<?php

namespace App\Support;

use Carbon\Carbon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

/**
 * Where uploaded files live. Media sits on the PUBLIC disk, which the web server serves without any login, so
 * a path built from the sequential media id plus a predictable file name (attendance photos are named
 * attendance_{user}_punchin_photo_{timestamp}) can be guessed and fetched by anyone. Files uploaded from
 * the cutover on live under a hash of the media's random UUID and the app key — unlistable and unguessable.
 * Older files keep their original path (they exist there); moving them is a separate data migration.
 */
class UnguessableMediaPathGenerator extends DefaultPathGenerator
{
    public const CUTOVER = '2026-10-01 00:00:00';

    protected function getBasePath(Media $media): string
    {
        if ($media->uuid && $media->created_at && $media->created_at->gte(Carbon::parse(self::CUTOVER))) {
            return substr(hash_hmac('sha256', (string) $media->uuid, (string) config('app.key')), 0, 40).'/'.$media->getKey();
        }

        return parent::getBasePath($media);
    }
}
