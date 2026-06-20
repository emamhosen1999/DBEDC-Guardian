<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompOffLedger extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'comp_off_ledger';

    protected $fillable = ['user_id', 'minutes', 'source_type', 'source_id', 'note', 'expires_at'];

    protected $casts = ['minutes' => 'integer', 'expires_at' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
