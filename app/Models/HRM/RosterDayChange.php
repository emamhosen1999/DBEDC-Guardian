<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RosterDayChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'roster_day_id',
        'actor_id',
        'field',
        'old_value',
        'new_value',
        'reason',
    ];

    public function rosterDay(): BelongsTo
    {
        return $this->belongsTo(RosterDay::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id', 'employee_id');
    }
}
