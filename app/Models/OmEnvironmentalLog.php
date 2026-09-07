<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OmEnvironmentalLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'log_code',
        'log_date',
        'monitoring_type',
        'location',
        'chainage',
        'direction',
        'latitude',
        'longitude',
        'measured_value',
        'measured_unit',
        'regulatory_threshold',
        'compliance_status',
        'weather_condition',
        'description',
        'corrective_action_taken',
        'photo_paths',
        'reported_by',
    ];

    protected $casts = [
        'log_date' => 'date',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'measured_value' => 'decimal:2',
        'regulatory_threshold' => 'decimal:2',
        'photo_paths' => 'array',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
