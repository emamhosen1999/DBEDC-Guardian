<?php

namespace App\Models\HRM;

use App\Models\Concerns\HasClearAttachments;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Overtime claim. New claims are DAY-based (claim_type holiday | rest_day, credit 1 / 0.5); the original minute-based
 * requests stay as read-only history (claim_type legacy_hours).
 */
class OvertimeRequest extends Model implements HasMedia
{
    use HasClearAttachments;
    use HasFactory;
    use InteractsWithMedia;

    public const LEGACY = 'legacy_hours';

    protected $fillable = [
        'user_id', 'date', 'requested_minutes', 'reason', 'status',
        'approval_chain', 'current_approval_level', 'approved_by', 'approved_at', 'comp_off_granted',
        'claim_type', 'compensation', 'day_credit', 'original_day_credit', 'end_time_declared', 'declared_end_at',
        'credit_adjusted_by', 'credit_adjusted_at', 'credit_adjust_reason',
        'reupload_requested_at', 'reupload_requested_by', 'reupload_reason', 'reupload_count',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'requested_minutes' => 'integer',
        'approval_chain' => 'array',
        'current_approval_level' => 'integer',
        'approved_at' => 'datetime',
        'comp_off_granted' => 'boolean',
        'day_credit' => 'float',
        'original_day_credit' => 'float',
        'end_time_declared' => 'boolean',
        'declared_end_at' => 'datetime',
        'credit_adjusted_at' => 'datetime',
        'reupload_requested_at' => 'datetime',
        'reupload_count' => 'integer',
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

    public function isLegacy(): bool
    {
        return $this->claim_type === null || $this->claim_type === self::LEGACY;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'employee_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
