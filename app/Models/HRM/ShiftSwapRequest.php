<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftSwapRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'requester_id', 'requester_date', 'type', 'counterparty_id', 'counterparty_date',
        'counterparty_status', 'requested_shift_id', 'reason', 'status', 'approval_chain', 'approved_by',
    ];

    protected $casts = [
        'requester_date' => 'date',
        'counterparty_date' => 'date',
        'approval_chain' => 'array',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counterparty_id');
    }
}
