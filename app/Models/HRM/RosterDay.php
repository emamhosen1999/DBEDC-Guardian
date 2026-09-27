<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RosterDay extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'date', 'shift_id', 'work_location_id', 'source', 'assignment_id', 'swap_request_id', 'note', 'locked'];

    protected $casts = ['date' => 'date:Y-m-d', 'locked' => 'boolean'];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'employee_id');
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(\App\Models\WorkLocation::class);
    }

    public function swapRequest(): BelongsTo
    {
        return $this->belongsTo(ShiftSwapRequest::class, 'swap_request_id');
    }

    public function changes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RosterDayChange::class, 'roster_day_id');
    }
}
