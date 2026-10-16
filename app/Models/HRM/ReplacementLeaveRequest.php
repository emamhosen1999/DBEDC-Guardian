<?php

namespace App\Models\HRM;

use App\Models\Concerns\HasClearAttachments;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/** A request to take a replacement day off (drawn first-expiring-first from the replacement credit). */
class ReplacementLeaveRequest extends Model implements HasMedia
{
    use HasClearAttachments;
    use InteractsWithMedia;

    protected $fillable = [
        'user_id', 'replacement_date', 'days', 'reason', 'status', 'approval_chain', 'current_approval_level',
        'approved_by', 'approved_at', 'allocations', 'roster_applied',
        'reupload_requested_at', 'reupload_requested_by', 'reupload_reason', 'reupload_count',
    ];

    protected $casts = [
        'replacement_date' => 'date:Y-m-d', 'days' => 'float', 'approval_chain' => 'array', 'current_approval_level' => 'integer',
        'approved_at' => 'datetime', 'allocations' => 'array', 'roster_applied' => 'boolean',
        'reupload_requested_at' => 'datetime', 'reupload_count' => 'integer',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->useDisk(config('attachments.disk'))
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png']);
        // Page images of a scanned PDF, for the inline viewer (the PDF stays the document of record).
        $this->addMediaCollection('attachment_previews')
            ->useDisk(config('attachments.disk'))
            ->acceptsMimeTypes(['image/jpeg']);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'employee_id');
    }
}
