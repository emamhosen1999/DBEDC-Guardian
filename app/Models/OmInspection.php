<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OmInspection extends Model
{
    use HasFactory;

    protected $fillable = [
        'inspection_number',
        'template_id',
        'asset_id',
        'preventive_schedule_id',
        'inspection_date',
        'inspector_id',
        'chainage',
        'direction',
        'latitude',
        'longitude',
        'checklist_responses',
        'total_score',
        'result',
        'overall_notes',
        'photo_paths',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'inspection_date' => 'date',
        'checklist_responses' => 'array',
        'photo_paths' => 'array',
        'total_score' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(OmInspectionTemplate::class, 'template_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(OmAsset::class, 'asset_id');
    }

    public function preventiveSchedule(): BelongsTo
    {
        return $this->belongsTo(OmPreventiveSchedule::class, 'preventive_schedule_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
