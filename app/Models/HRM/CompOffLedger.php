<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Replacement-leave ledger, in DAYS. A credit (+days) is earned by an approved rest-day claim and expires 60 days after
 * the work date; a use ('used') or an expiry ('expired') is a negative row pointing at the credit it consumed.
 * The old minute column is kept for history only.
 */
class CompOffLedger extends Model
{
    public const UPDATED_AT = null;

    public const EXPIRED = 'expired';

    protected $table = 'comp_off_ledger';

    protected $fillable = ['user_id', 'minutes', 'days', 'work_date', 'credit_id', 'source_type', 'source_id', 'note', 'expires_at', 'reminded_14_at', 'reminded_3_at'];

    protected $casts = [
        'minutes' => 'integer', 'days' => 'float', 'expires_at' => 'date', 'work_date' => 'date',
        'reminded_14_at' => 'datetime', 'reminded_3_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'employee_id');
    }
}
