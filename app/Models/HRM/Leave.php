<?php

namespace App\Models\HRM;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Leave extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;

    protected $fillable = [
        'user_id',
        'leave_type',
        'from_date',
        'to_date',
        'no_of_days',
        'is_half_day',
        'half_day_session',
        'approved_by',
        'reason',
        'status',
        'approval_chain',
        'current_approval_level',
        'approved_at',
        'rejection_reason',
        'rejected_by',
        'submitted_at',
        'cancelled_at',
        'cancelled_by',
    ];

    protected $casts = [
        'id' => 'integer',
        'leave_type' => 'string',
        'from_date' => 'date', // Simplified casting
        'to_date' => 'date',   // Simplified casting
        'no_of_days' => 'decimal:1',
        'is_half_day' => 'boolean',
        'half_day_session' => 'string',
        'reason' => 'string',
        'status' => 'string',
        'approved_by' => 'integer',
        'approval_chain' => 'array',
        'current_approval_level' => 'integer',
        'approved_at' => 'datetime',
        'rejected_by' => 'integer',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'cancelled_by' => 'integer',
    ];

    /**
     * Supporting documents (e.g. medical certificates). Gated per leave type
     * via LeaveSetting.requires_attachment_days.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png']);
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Simplified date serialization to ensure consistent date format
     *
     * @return array
     */
    public function toArray()
    {
        $array = parent::toArray();

        // Ensure dates are in consistent Y-m-d format
        if (isset($array['from_date'])) {
            $array['from_date'] = Carbon::parse($array['from_date'])->format('Y-m-d');
        }

        if (isset($array['to_date'])) {
            $array['to_date'] = Carbon::parse($array['to_date'])->format('Y-m-d');
        }

        return $array;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function leaveSetting(): BelongsTo
    {
        return $this->belongsTo(LeaveSetting::class, 'leave_type');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // Accessors
    public function getStatusColorAttribute(): string
    {
        return match (strtolower((string) $this->status)) {
            'pending' => 'warning',
            'approved' => 'success',
            'rejected' => 'danger',
            'cancelled' => 'secondary',
            default => 'default'
        };
    }

    /**
     * Get the from_date attribute in consistent Y-m-d format.
     *
     * @param  string  $value
     * @return string
     */
    public function getFromDateAttribute($value)
    {
        return $value ? Carbon::parse($value)->format('Y-m-d') : $value;
    }

    /**
     * Get the to_date attribute in consistent Y-m-d format.
     *
     * @param  string  $value
     * @return string
     */
    public function getToDateAttribute($value)
    {
        return $value ? Carbon::parse($value)->format('Y-m-d') : $value;
    }
}
