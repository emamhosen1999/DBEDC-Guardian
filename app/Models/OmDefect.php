<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OmDefect extends Model
{
    use HasFactory;

    protected $attributes = ['lock_version' => 0];

    protected $fillable = [
        'defect_number',
        'asset_id',
        'patrol_shift_id',
        'title',
        'distress_type',
        'chainage',
        'direction',
        'severity',
        'sla_hours',
        'sla_due_at',
        'status',
        'reported_by',
        'verified_by',
        'rectified_at',
        'verified_at',
        'latitude',
        'longitude',
        'description',
        'rectification_notes',
        'before_photos',
        'after_photos',
        'lock_version',
    ];

    protected $casts = [
        'sla_due_at' => 'datetime',
        'rectified_at' => 'datetime',
        'verified_at' => 'datetime',
        'sla_hours' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'before_photos' => 'array',
        'after_photos' => 'array',
        'lock_version' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(OmAsset::class, 'asset_id');
    }

    public function patrolShift(): BelongsTo
    {
        return $this->belongsTo(OmPatrolShift::class, 'patrol_shift_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function workOrder(): HasOne
    {
        return $this->hasOne(OmWorkOrder::class, 'defect_id');
    }
}
