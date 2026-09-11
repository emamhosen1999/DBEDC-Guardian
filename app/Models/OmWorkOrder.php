<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OmWorkOrder extends Model
{
    use HasFactory;

    protected $attributes = ['lock_version' => 0];

    protected $fillable = [
        'work_order_number',
        'defect_id',
        'asset_id',
        'preventive_schedule_id',
        'inspection_id',
        'title',
        'work_type',
        'category',
        'location',
        'priority',
        'status',
        'assigned_to',
        'contractor_name',
        'description',
        'reported_by',
        'assigned_by',
        'approved_by',
        'approved_at',
        'verified_by',
        'verified_at',
        'target_start_at',
        'target_end_at',
        'actual_start_at',
        'completed_at',
        'estimated_cost',
        'actual_cost',
        'requires_lane_closure',
        'qc_notes',
        'lock_version',
    ];

    protected $casts = [
        'target_start_at' => 'datetime',
        'target_end_at' => 'datetime',
        'actual_start_at' => 'datetime',
        'completed_at' => 'datetime',
        'approved_at' => 'datetime',
        'verified_at' => 'datetime',
        'estimated_cost' => 'decimal:2',
        'actual_cost' => 'decimal:2',
        'requires_lane_closure' => 'boolean',
        'lock_version' => 'integer',
    ];

    public function defect(): BelongsTo
    {
        return $this->belongsTo(OmDefect::class, 'defect_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(OmAsset::class, 'asset_id');
    }

    public function preventiveSchedule(): BelongsTo
    {
        return $this->belongsTo(OmPreventiveSchedule::class, 'preventive_schedule_id');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(OmInspection::class, 'inspection_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function laneClosurePermit(): HasOne
    {
        return $this->hasOne(OmLaneClosurePermit::class, 'work_order_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(OmWorkOrderMaterial::class, 'work_order_id');
    }

    public function crews(): HasMany
    {
        return $this->hasMany(OmWorkOrderCrew::class, 'work_order_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(OmWorkOrderPhoto::class, 'work_order_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(OmActivityLog::class, 'entity_id')
            ->where('entity_type', 'work_order');
    }
}
