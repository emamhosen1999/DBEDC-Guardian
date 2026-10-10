<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RosterDayChange extends Model
{
    /**
     * created_at is stamped by the application (app timezone, like every other table), never by the column's
     * CURRENT_TIMESTAMP default: the production database runs on the server's own zone (US Eastern), which put
     * these rows 10-11 hours behind the rest of the system. There is no updated_at column.
     */
    public const UPDATED_AT = null;

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
