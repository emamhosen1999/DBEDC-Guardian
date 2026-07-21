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
        'counterparty_status', 'requested_shift_id', 'requester_shift_code', 'counterparty_shift_code',
        'reason', 'status', 'approval_chain', 'approved_by',
    ];

    protected $casts = [
        'requester_id' => 'integer',
        'counterparty_id' => 'integer',
        'requested_shift_id' => 'integer',
        'approved_by' => 'integer',
        'requester_date' => 'date:Y-m-d',
        'counterparty_date' => 'date:Y-m-d',
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
