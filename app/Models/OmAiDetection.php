<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OmAiDetection extends Model
{
    use HasFactory;

    protected $table = 'om_ai_detections';

    protected $fillable = [
        'detection_code',
        'distress_type',
        'confidence_score',
        'chainage',
        'direction',
        'latitude',
        'longitude',
        'estimated_area_sqm',
        'severity',
        'image_path',
        'bounding_box',
        'patrol_shift_id',
        'status',
        'work_order_id',
        'reviewed_by',
        'reviewed_at',
        'notes',
    ];

    protected $casts = [
        'confidence_score' => 'decimal:2',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'estimated_area_sqm' => 'decimal:2',
        'bounding_box' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(OmWorkOrder::class, 'work_order_id');
    }

    public function patrolShift(): BelongsTo
    {
        return $this->belongsTo(OmPatrolShift::class, 'patrol_shift_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
