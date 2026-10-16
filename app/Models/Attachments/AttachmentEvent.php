<?php

namespace App\Models\Attachments;

use Illuminate\Database\Eloquent\Model;

/** Audit trail of attachment actions on an application: who asked for a re-upload (and why), and who re-uploaded. */
class AttachmentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['subject_type', 'subject_id', 'actor_id', 'action', 'reason', 'meta'];

    protected $casts = ['meta' => 'array'];
}
