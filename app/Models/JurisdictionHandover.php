<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** An in-charge handover. Immutable: once written, the snapshot and the parties can never be changed or removed. */
class JurisdictionHandover extends Model
{
    protected $fillable = ['jurisdiction_id', 'effective_date', 'outgoing_employee_id', 'incoming_employee_id', 'note', 'open_works_count', 'open_work_ids', 'snapshot_at', 'created_by'];

    protected $casts = ['effective_date' => 'date:Y-m-d', 'open_work_ids' => 'array', 'open_works_count' => 'integer', 'snapshot_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A handover is immutable. Record a new handover or a transfer instead.'));
        static::deleting(fn () => throw new LogicException('A handover cannot be deleted.'));
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class)->withTrashed();
    }

    public function outgoing(): BelongsTo
    {
        return $this->belongsTo(User::class, 'outgoing_employee_id');
    }

    public function incoming(): BelongsTo
    {
        return $this->belongsTo(User::class, 'incoming_employee_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(JurisdictionHandoverTransfer::class, 'handover_id');
    }
}
