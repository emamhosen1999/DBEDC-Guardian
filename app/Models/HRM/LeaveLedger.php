<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveLedger extends Model
{
    protected $table = 'leave_ledger';

    public const UPDATED_AT = null; // immutable: append-only

    protected $fillable = [
        'user_id', 'leave_type', 'period_year', 'txn_type',
        'amount', 'balance_after', 'source_type', 'source_id', 'actor_id', 'reason',
    ];

    protected $casts = [
        'period_year' => 'integer',
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function leaveSetting(): BelongsTo
    {
        return $this->belongsTo(LeaveSetting::class, 'leave_type');
    }
}
