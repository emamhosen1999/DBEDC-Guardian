<?php

namespace App\Models\HRM;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftVersion extends Model
{
    protected $fillable = [
        'shift_id',
        'effective_from',
        'start_time',
        'end_time',
        'crosses_midnight',
        'grace_in_minutes',
        'grace_out_minutes',
        'full_day_minutes',
        'half_day_minutes',
        'min_present_minutes',
        'break_minutes',
    ];

    protected $casts = [
        'effective_from' => 'date:Y-m-d',
        'crosses_midnight' => 'boolean',
        'grace_in_minutes' => 'integer',
        'grace_out_minutes' => 'integer',
        'full_day_minutes' => 'integer',
        'half_day_minutes' => 'integer',
        'min_present_minutes' => 'integer',
        'break_minutes' => 'integer',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
