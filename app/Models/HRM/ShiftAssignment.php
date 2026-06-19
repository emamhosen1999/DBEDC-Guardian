<?php

namespace App\Models\HRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'scope_type', 'scope_id', 'shift_id', 'rotation_pattern_id',
        'anchor_date', 'effective_from', 'effective_to', 'priority', 'assigned_by',
    ];

    protected $casts = [
        'anchor_date' => 'date',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'priority' => 'integer',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function rotationPattern(): BelongsTo
    {
        return $this->belongsTo(ShiftRotationPattern::class);
    }
}
