<?php

namespace App\Models\HRM;

use App\Models\WorkLocation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoverageRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'work_location_id', 'shift_id', 'designation_id',
        'required_headcount', 'weekday', 'date', 'is_active',
    ];

    protected $casts = [
        'required_headcount' => 'integer',
        'weekday' => 'integer',
        'date' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }
}
