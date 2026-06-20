<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRegularization extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'date', 'attendance_id', 'type', 'requested_punchin', 'requested_punchout',
        'reason', 'status', 'approval_chain', 'current_approval_level', 'approved_by', 'approved_at', 'applied',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'requested_punchin' => 'datetime',
        'requested_punchout' => 'datetime',
        'approval_chain' => 'array',
        'current_approval_level' => 'integer',
        'approved_at' => 'datetime',
        'applied' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
