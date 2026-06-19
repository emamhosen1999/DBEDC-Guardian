<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RosterDay extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'date', 'shift_id', 'source', 'assignment_id', 'note', 'locked'];

    protected $casts = ['date' => 'date:Y-m-d', 'locked' => 'boolean'];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
