<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceAuditLog extends Model
{
    public const UPDATED_AT = null; // immutable: no updates

    protected $fillable = [
        'actor_id', 'attendance_id', 'action', 'before', 'after', 'reason', 'ip',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class, 'attendance_id');
    }
}
