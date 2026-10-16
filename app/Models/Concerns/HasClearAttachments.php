<?php

namespace App\Models\Concerns;

use App\Models\Attachments\AttachmentEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Supporting documents on a private disk. Every file that ever went in stays (history); a replaced file is only marked
 * superseded, so the application shows the current document and the audit trail can still reach the old ones.
 */
trait HasClearAttachments
{
    public static function attachmentSubjectType(): string
    {
        return class_basename(static::class);
    }

    /** The current (not superseded) attachments. */
    public function currentAttachments(): Collection
    {
        return $this->getMedia('attachments')->filter(fn (Media $m) => ! $m->getCustomProperty('superseded_at'))->values();
    }

    public function attachmentEvents(): Builder
    {
        return AttachmentEvent::query()->where('subject_type', static::attachmentSubjectType())->where('subject_id', $this->getKey());
    }

    /** pending + a re-upload request = "returned for re-upload" (the approval step is unchanged). */
    public function isReturnedForReupload(): bool
    {
        return $this->status === 'pending' && $this->reupload_requested_at !== null;
    }
}
